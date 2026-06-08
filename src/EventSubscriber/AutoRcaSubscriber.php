<?php

namespace App\EventSubscriber;

use App\Upsun\UpsunClientFactory;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Phase 0 — Auto-RCA trigger.
 * 
 * Note: 
 *  Micka: lancer un tous les vendredis un import de données --> container planifié pour exec un gros traitement
 *  Flo: détecter automatiquement les erreurs 5xx en production --> container Auto-RCA pour analyse et création de PRs d'incidents
 *  Micka: rédacteur d'articles de blog --> container avec un formulaire de rédaction + génération de l'article via l'API Upsun 
 *  Nico: admin agent simple
 *  Nico: paperclip ou Hernest
 *  Flo Activity script to trigger a task container run on demand on failed deploy
 * 
 *  tester tous les calls d'API
 *      - rate limiting ? (cf. paperclip qui est un monstre)
 *      - test charge (ex: spawn 100 containers en parallèle, ou un container qui exec 100 tâches en parallèle)
 *  Task container a un log poussé dans le log explorer (Console Upsun + API) pour monitorer les exécutions, les erreurs, etc. (cf. paperclip qui log tout)
 *  Log forwarding ?
 *  Authorization pas clair dans la doc
 *  API taskContainer.cancel is missing in openapi spec
 * 
 * 
 *
 * When a 5xx error occurs in production:
 *   1. Computes the error signature (for deduplication)
 *   2. Checks idempotency (a PR already exists for this signature → skip)
 *   3. Checks the throttle (max attempts per signature → anti-loop guard)
 *   4. Spawns the opencode task container via the Upsun API
 */
final class AutoRcaSubscriber implements EventSubscriberInterface
{
    /**
     * Maximum number of spawn attempts per error signature.
     * Beyond this limit the error is silently ignored to prevent an infinite loop.
     */
    private const MAX_ATTEMPTS = 3;

    /**
     * TTL for throttle/idempotency cache keys (7 days).
     */
    private const int CACHE_TTL = 604800;

    public function __construct(
        private readonly UpsunClientFactory $upsunClientFactory,
        private readonly CacheItemPoolInterface $cache,
        private readonly LoggerInterface $logger,
        private readonly string $upsunProjectId,
        private readonly string $upsunEnvironmentId,
        private readonly string $upsunRcaTaskId,
        private readonly string $appEnvType,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // Negative priority: let other subscribers handle the exception first.
        return [
            KernelEvents::EXCEPTION => ['onKernelException', -100],
        ];
    }

    public function onKernelException(ExceptionEvent $event): void
    {
        // Guard 1 — production only
        if ($this->appEnvType !== 'production') {
            return;
        }

        $throwable = $event->getThrowable();

        // Guard 2 — 5xx errors only (not 404, 403, etc.)
        if ($throwable instanceof HttpExceptionInterface && $throwable->getStatusCode() < 500) {
            return;
        }

        $signature = $this->computeSignature($throwable);
        $throttleKey = 'auto_rca.attempts.' . $signature;
        $doneKey = 'auto_rca.done.' . $signature;

        // Guard 3 — idempotency: PR or issue already created for this signature
        if ($this->cache->getItem($doneKey)->isHit()) {
            $this->logger->debug('AutoRCA: signature already handled, skipping.', ['signature' => $signature]);

            return;
        }

        // Guard 4 — anti-loop throttle
        $attemptsItem = $this->cache->getItem($throttleKey);
        $attempts = $attemptsItem->isHit() ? (int) $attemptsItem->get() : 0;

        if ($attempts >= self::MAX_ATTEMPTS) {
            $this->logger->warning('AutoRCA: max attempts reached for signature, stopping.', [
                'signature' => $signature,
                'attempts' => $attempts,
            ]);

            return;
        }

        // Increment before the API call (not after) to prevent parallel spawns
        // during traffic spikes.
        $attemptsItem->set($attempts + 1)->expiresAfter(self::CACHE_TTL);
        $this->cache->save($attemptsItem);

        $incident = $this->buildIncidentPayload($throwable, $event, $signature);

        try {
            $client = $this->upsunClientFactory->create();
            $client->tasksContainer->run(
                projectId: $this->upsunProjectId,
                environmentId: $this->upsunEnvironmentId,
                taskId: $this->upsunRcaTaskId,
                variables: [
                    'INCIDENT_JSON' => ['value' => json_encode($incident, \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR)],
                    'INCIDENT_SIGNATURE' => ['value' => $signature],
                ],
            );

            $this->logger->info('AutoRCA: task container spawned.', [
                'signature' => $signature,
                'task_id' => $this->upsunRcaTaskId,
                'attempt' => $attempts + 1,
            ]);
        } catch (\Throwable $e) {
            // Never let a subscriber error shadow the original exception.
            $this->logger->error('AutoRCA: failed to spawn task container.', [
                'signature' => $signature,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Deterministic signature: hash(class + file + line + normalised message).
     * The message is normalised to deduplicate variations (IDs, timestamps, etc.).
     */
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

    /**
     * Builds the incident JSON payload forwarded to the opencode task container.
     * No sensitive data (PII, secrets) is included.
     */
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
                'trace_top5' => array_slice(
                    array_map(
                        static fn (array $frame): string => ($frame['file'] ?? '?') . ':' . ($frame['line'] ?? '?') . ' ' . ($frame['function'] ?? ''),
                        $throwable->getTrace(),
                    ),
                    0,
                    5,
                ),
            ],
            'request' => [
                'method' => $request->getMethod(),
                'route' => $request->attributes->get('_route', 'unknown'),
                // URL path only — no query string to avoid leaking PII
                'path' => $request->getPathInfo(),
                'user_agent' => substr($request->headers->get('User-Agent', ''), 0, 200),
            ],
            'triggered_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'upsun' => [
                'project_id' => $this->upsunProjectId,
                'environment_id' => $this->upsunEnvironmentId,
            ],
        ];
    }
}
