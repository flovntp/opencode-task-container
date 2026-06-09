<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public load-testing endpoint used to exercise the Auto-RCA pipeline under
 * stress. It is intentionally NOT behind ROLE_ADMIN so an external traffic
 * generator (see simulate-traffic.sh) can reach it; access is gated by a shared
 * secret token instead.
 *
 * Behaviour:
 *   1. Reject requests without the correct token with a 403 (a client error,
 *      so AutoRcaSubscriber ignores it).
 *   2. Track the number of in-flight requests with an atomic APCu counter.
 *   3. Run a CPU-intensive loop for at least ~20 seconds (visible as a CPU
 *      spike in the observability suite).
 *   4. While processing, if the concurrency exceeds the configured threshold,
 *      throw a RuntimeException → an uncaught 500 caught by AutoRcaSubscriber.
 *
 * The exception message is kept stable (no random token) so the subscriber's
 * signature dedupe spawns a single RCA task container despite hundreds of 500s.
 */
final class TrafficSimulationController extends AbstractController
{
    private const string INFLIGHT_KEY = 'traffic_sim.inflight';

    public function __construct(
        #[Autowire('%env(TRAFFIC_SIM_TOKEN)%')]
        private readonly string $expectedToken,
        #[Autowire('%env(int:TRAFFIC_SIM_MAX_CONCURRENCY)%')]
        private readonly int $maxConcurrency,
        #[Autowire('%env(int:TRAFFIC_SIM_DURATION)%')]
        private readonly int $processingSeconds,
    ) {
    }

    #[Route(
        '/admin/auto-rca/simulate-traffic-exception',
        name: 'traffic_simulation_overload',
        methods: ['GET'],
    )]
    public function overload(Request $request): Response
    {
        if (!$this->isTokenValid($request)) {
            throw $this->createAccessDeniedException('Invalid or missing simulation token.');
        }

        if (!\function_exists('apcu_inc') || !apcu_enabled()) {
            throw new \RuntimeException('Traffic simulation requires the APCu extension to be enabled.');
        }

        // Optional live tuning (token already required): ?threshold= and ?seconds=
        // let us calibrate against the real PHP-FPM worker count without a redeploy.
        $threshold = max(1, $request->query->getInt('threshold', $this->maxConcurrency));
        $duration  = min(25, max(1, $request->query->getInt('seconds', $this->processingSeconds)));

        $inFlight = (int) apcu_inc(self::INFLIGHT_KEY);

        try {
            return $this->process($inFlight, $threshold, $duration);
        } finally {
            apcu_dec(self::INFLIGHT_KEY);
        }
    }

    private function isTokenValid(Request $request): bool
    {
        // Reject when no token is configured: never run unauthenticated.
        if ('' === $this->expectedToken) {
            return false;
        }

        $provided = $request->query->get('key')
            ?? $request->headers->get('X-Sim-Token', '');

        return hash_equals($this->expectedToken, $provided);
    }

    /**
     * Burns CPU for at least $duration seconds, periodically re-checking the
     * in-flight counter. Throws once the concurrency threshold is exceeded.
     */
    private function process(int $inFlightAtStart, int $threshold, int $duration): Response
    {
        $deadline  = microtime(true) + $duration;
        $start = microtime(true);
        $iterations = 0;
        $peak = $inFlightAtStart;
        $accumulator = 'seed';

        $this->assertWithinThreshold($inFlightAtStart, $threshold, 0.0);

        while (microtime(true) < $deadline) {
            // CPU-intensive busy work so the request shows up as CPU usage. The
            // result is chained into $accumulator so it cannot be optimised away.
            for ($i = 0; $i < 50_000; ++$i) {
                $accumulator = hash('sha256', $accumulator.$iterations.$i);
            }
            ++$iterations;

            $current = (int) (apcu_fetch(self::INFLIGHT_KEY) ?: 0);
            $peak = max($peak, $current);

            $this->assertWithinThreshold($current, $threshold, round(microtime(true) - $start, 2));
        }

        return new JsonResponse([
            'status'           => 'ok',
            'processed_seconds' => $duration,
            'iterations'       => $iterations,
            'peak_concurrency' => $peak,
            'threshold'        => $threshold,
        ]);
    }

    private function assertWithinThreshold(int $current, int $threshold, float $elapsed): void
    {
        if ($current <= $threshold) {
            return;
        }

        // Stable message → stable AutoRcaSubscriber signature (the digits are
        // normalised to "N" by the subscriber), so a single RCA task is spawned.
        throw new \RuntimeException(sprintf(
            '[Auto-RCA test] Traffic overload: %d concurrent requests exceeded the threshold of %d '
            .'during slow processing (elapsed %ss). The endpoint shed load by failing fast.',
            $current,
            $threshold,
            $elapsed,
        ));
    }
}
