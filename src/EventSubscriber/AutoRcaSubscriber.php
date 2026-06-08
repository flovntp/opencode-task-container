<?php

namespace App\EventSubscriber;

use App\Upsun\UpsunClientFactory;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Upsun\Api\ApiException;

final class AutoRcaSubscriber implements EventSubscriberInterface
{
    private const int MAX_ATTEMPTS = 3;
    private const int CACHE_TTL = 604_800;

    public function __construct(
        private readonly UpsunClientFactory $upsunClientFactory,
        private readonly CacheItemPoolInterface $cache,
        private readonly LoggerInterface $logger,
        private readonly string $upsunProjectId,
        private readonly string $upsunEnvironmentId,
        private readonly string $upsunRcaTaskId,
        private readonly bool $isProduction, 
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::EXCEPTION => ['onKernelException', -100]];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        if (!$this->isProduction) {
            return;
        }

        $throwable = $event->getThrowable();

        if ($this->isClientError($throwable)) {
            return;
        }

        $signature = $this->computeSignature($throwable);

        if (!$this->shouldProcess($signature)) {
            return;
        }

        $this->spawnTaskContainer($throwable, $event, $signature);
    }

    private function isClientError(\Throwable $throwable): bool
    {
        return $throwable instanceof HttpExceptionInterface
            && $throwable->getStatusCode() < 500;
    }

    private function shouldProcess(string $signature): bool
    {
        if ($this->cache->getItem('auto_rca.done.'.$signature)->isHit()) {
            $this->logger->debug('AutoRCA: already handled.', ['signature' => $signature]);

            return false;
        }

        $attemptsItem = $this->cache->getItem('auto_rca.attempts.'.$signature);
        $attempts = $attemptsItem->isHit() ? (int) $attemptsItem->get() : 0;

        if ($attempts >= self::MAX_ATTEMPTS) {
            $this->logger->warning('AutoRCA: max attempts reached.', compact('signature', 'attempts'));

            return false;
        }

        // Increment before the API call to prevent parallel spawns.
        $this->cache->save($attemptsItem->set($attempts + 1)->expiresAfter(self::CACHE_TTL));

        return true;
    }

    private function spawnTaskContainer(\Throwable $throwable, ExceptionEvent $event, string $signature): void
    {
        $incident = $this->buildIncidentPayload($throwable, $event, $signature);

        $context = [
            'signature'   => $signature,
            'project'     => $this->upsunProjectId,
            'environment' => $this->upsunEnvironmentId,
            'task'        => $this->upsunRcaTaskId,
        ];

        $this->logger->info('AutoRCA: spawning task container…', $context);

        try {
            $response = $this->upsunClientFactory->create()->taskContainers->run(
                projectId: $this->upsunProjectId,
                environmentId: $this->upsunEnvironmentId,
                taskId: $this->upsunRcaTaskId,
                variables: [
                    'env:INCIDENT_JSON'      => ['value' => json_encode($incident, \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR)],
                    'env:INCIDENT_SIGNATURE' => ['value' => $signature],
                ],
            );

            $this->logger->info('AutoRCA: task container spawned successfully.', $context + [
                'response' => method_exists($response, '__toString') ? (string) $response : null,
            ]);
        } catch (ApiException $e) {
            $this->logger->error('AutoRCA: Upsun API rejected the task run.', $context + [
                'http_status'   => $e->getCode(),
                'api_status'    => $e->getApiStatus(),
                'api_title'     => $e->getApiTitle(),
                'api_message'   => $e->getApiMessage(),
                'api_code'      => $e->getApiCode(),
                'error'         => $e->getMessage(),
                'response_body' => $e->getResponseBody(),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('AutoRCA: failed to spawn task container.', $context + [
                'exception' => $e::class,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    private function computeSignature(\Throwable $throwable): string
    {
        $normalizedMessage = preg_replace(
            ['/\b\d+\b/', '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i'],
            ['N', 'UUID'],
            $throwable->getMessage(),
        ) ?? $throwable->getMessage();

        return hash('sha256', implode('|', [
            $throwable::class,
            $throwable->getFile(),
            (string) $throwable->getLine(),
            $normalizedMessage,
        ]));
    }

    private function buildIncidentPayload(\Throwable $throwable, ExceptionEvent $event, string $signature): array
    {
        $request = $event->getRequest();

        return [
            'signature' => $signature,
            'exception' => [
                'class'      => $throwable::class,
                'message'    => $throwable->getMessage(),
                'file'       => $throwable->getFile(),
                'line'       => $throwable->getLine(),
                'trace_top5' => array_slice(
                    array_map(
                        static fn(array $frame): string => ($frame['file'] ?? '?').':'.($frame['line'] ?? '?').' '.($frame['function'] ?? ''),
                        $throwable->getTrace(),
                    ),
                    0, 5,
                ),
            ],
            'request'      => [
                'method'     => $request->getMethod(),
                'route'      => $request->attributes->get('_route', 'unknown'),
                'path'       => $request->getPathInfo(),
                'user_agent' => substr((string) $request->headers->get('User-Agent', ''), 0, 200),
            ],
            'triggered_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'upsun'        => [
                'project_id'     => $this->upsunProjectId,
                'environment_id' => $this->upsunEnvironmentId,
            ],
        ];
    }
}