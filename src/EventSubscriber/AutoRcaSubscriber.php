<?php

namespace App\EventSubscriber;

use App\Github\GitHubAppTokenMinter;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Upsun\Api\ApiException;
use Upsun\UpsunClient;

final class AutoRcaSubscriber implements EventSubscriberInterface
{
    private const int CACHE_TTL = 604_800;

    // How long a single-flight claim is held. If a spawn keeps failing, this is
    // also the minimum back-off before the same signature may be retried.
    private const int CLAIM_TTL = 600;

    public function __construct(
        private readonly UpsunClient $upsunClient,
        private readonly GitHubAppTokenMinter $tokenMinter,
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

        // Atomic single-flight guard: when hundreds of identical 500s arrive at
        // once (e.g. under load), only the first caller wins the claim and
        // spawns a task; all the others bail out. apcu_add is atomic across the
        // PHP-FPM workers, which the previous read-modify-write attempts counter
        // was not — hence the occasional duplicate spawns.
        if (!$this->claimSpawn($signature)) {
            $this->logger->debug('AutoRCA: spawn already claimed for this signature.', ['signature' => $signature]);

            return false;
        }

        return true;
    }

    private function claimSpawn(string $signature): bool
    {
        $key = 'auto_rca.claim.'.$signature;

        if (\function_exists('apcu_add') && apcu_enabled()) {
            // Returns true only for the first concurrent caller.
            return apcu_add($key, 1, self::CLAIM_TTL);
        }

        // Fallback for environments without APCu (local/CLI): best-effort and
        // non-atomic, but adequate outside the high-concurrency web path.
        $item = $this->cache->getItem($key);
        if ($item->isHit()) {
            return false;
        }

        $this->cache->save($item->set(1)->expiresAfter(self::CLAIM_TTL));

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

        $env = [
            'INCIDENT_JSON'      => json_encode($incident, \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR),
            'INCIDENT_SIGNATURE' => $signature,
        ];

        // Attach a short-lived, repo-scoped GitHub token so OpenCode can open a
        // pull request. Minting failures are non-fatal: the task still runs.
        $github = $this->tokenMinter->mintInstallationToken();
        if ($github !== null) {
            // Use a custom name (NOT GITHUB_TOKEN/GH_TOKEN): OpenCode's
            // github-copilot LLM provider authenticates with GITHUB_TOKEN, and a
            // GitHub App server-to-server token is rejected by that endpoint.
            $env['GH_PR_TOKEN'] = $github['token'];
            $env['GITHUB_REPO'] = $github['repository'];
        }

        try {
            $response = $this->upsunClient->taskContainers->run(
                projectId: $this->upsunProjectId,
                environmentId: $this->upsunEnvironmentId,
                taskId: $this->upsunRcaTaskId,
                variables: [
                    'env' => $env,
                ],
            );

            $this->logger->info('AutoRCA: task container spawned successfully.', $context + [
                'response' => method_exists($response, '__toString') ? (string) $response : null,
            ]);

            // Mark the incident as handled so identical exceptions don't spawn
            // additional task containers (and open duplicate pull requests).
            $this->cache->save(
                $this->cache->getItem('auto_rca.done.'.$signature)
                    ->set(true)
                    ->expiresAfter(self::CACHE_TTL),
            );
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