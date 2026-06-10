<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\EventSubscriber;

use App\Github\GitHubAppTokenMinter;
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
        private readonly LoggerInterface $logger,
        private readonly string $upsunProjectId,
        private readonly string $upsunEnvironmentId,
        private readonly string $upsunRcaTaskId,
        private readonly string $sharedStateDir,
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
        // Dedup is keyed on the incident signature (class+file+line+normalized
        // message), NOT on "is any RCA task running". Two *different* 500s have
        // different signatures and therefore each get their own RCA agent;
        // only repeats of the *same* incident are suppressed.
        $claimDir = $this->sharedStateDir.'/claim/'.$signature;
        $doneFile = $this->sharedStateDir.'/done/'.$signature;

        // Identical incident already handled recently → don't open a duplicate PR.
        if (is_file($doneFile) && (time() - (int) @filemtime($doneFile)) < self::CACHE_TTL) {
            $this->logger->debug('AutoRCA: already handled.', ['signature' => $signature]);

            return false;
        }

        if (!is_dir($this->sharedStateDir.'/claim')) {
            @mkdir($this->sharedStateDir.'/claim', 0o775, true);
        }

        // Atomic single-flight claim, shared across every instance and PHP-FPM
        // worker via the persistent /var/share storage mount. mkdir() either
        // creates the directory (we win) or fails with EEXIST (someone already
        // owns the claim) — unlike the previous per-instance APCu/cache guard,
        // which let duplicates through under load and across instances.
        if (@mkdir($claimDir, 0o775, true)) {
            return true;
        }

        // The claim already exists. If it is older than the back-off window the
        // previous attempt likely crashed mid-spawn, so allow a retry.
        if ((time() - (int) @filemtime($claimDir)) >= self::CLAIM_TTL) {
            @touch($claimDir);

            return true;
        }

        $this->logger->debug('AutoRCA: spawn already claimed for this signature.', ['signature' => $signature]);

        return false;
    }

    private function markHandled(string $signature): void
    {
        $doneDir = $this->sharedStateDir.'/done';
        if (!is_dir($doneDir)) {
            @mkdir($doneDir, 0o775, true);
        }

        @touch($doneDir.'/'.$signature);
    }

    private function spawnTaskContainer(\Throwable $throwable, ExceptionEvent $event, string $signature): void
    {
        $incident = $this->buildIncidentPayload($throwable, $event, $signature);

        $context = [
            'signature' => $signature,
            'project' => $this->upsunProjectId,
            'environment' => $this->upsunEnvironmentId,
            'task' => $this->upsunRcaTaskId,
        ];

        $this->logger->info('AutoRCA: spawning task container…', $context);

        $env = [
            'INCIDENT_JSON' => json_encode($incident, \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR),
            'INCIDENT_SIGNATURE' => $signature,
        ];

        // Attach a short-lived, repo-scoped GitHub token so OpenCode can open a
        // pull request. Minting failures are non-fatal: the task still runs.
        $github = $this->tokenMinter->mintInstallationToken();
        if (null !== $github) {
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
            $this->markHandled($signature);
        } catch (ApiException $e) {
            $this->logger->error('AutoRCA: Upsun API rejected the task run.', $context + [
                'http_status' => $e->getCode(),
                'api_status' => $e->getApiStatus(),
                'api_title' => $e->getApiTitle(),
                'api_message' => $e->getApiMessage(),
                'api_code' => $e->getApiCode(),
                'error' => $e->getMessage(),
                'response_body' => $e->getResponseBody(),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('AutoRCA: failed to spawn task container.', $context + [
                'exception' => $e::class,
                'error' => $e->getMessage(),
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
                'class' => $throwable::class,
                'message' => $throwable->getMessage(),
                'file' => $throwable->getFile(),
                'line' => $throwable->getLine(),
                'trace_top5' => \array_slice(
                    array_map(
                        static fn (array $frame): string => ($frame['file'] ?? '?').':'.($frame['line'] ?? '?').' '.($frame['function'] ?? ''),
                        $throwable->getTrace(),
                    ),
                    0, 5,
                ),
            ],
            'request' => [
                'method' => $request->getMethod(),
                'route' => $request->attributes->get('_route', 'unknown'),
                'path' => $request->getPathInfo(),
                'user_agent' => substr((string) $request->headers->get('User-Agent', ''), 0, 200),
            ],
            'triggered_at' => new \DateTimeImmutable()->format(\DateTimeInterface::ATOM),
            'upsun' => [
                'project_id' => $this->upsunProjectId,
                'environment_id' => $this->upsunEnvironmentId,
            ],
        ];
    }
}