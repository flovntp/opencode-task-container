# RCA: Traffic overload on run uymlndqb

## Incident Signature
`bbf7a6c259bffcbafa892d377c978c058c79a93e9ab8368187b66acdf6956ea0`

## Root Cause
**Deliberate exception from the Auto-RCA traffic simulation harness.**

The `RuntimeException` was thrown on line 150 of
`src/Controller/TrafficSimulationController.php` when two concurrent requests
to the `/admin/auto-rca/simulate-traffic-exception` endpoint exceeded the
configured `TRAFFIC_SIM_MAX_CONCURRENCY` threshold of 1. This is the
**intended** behaviour of the endpoint:

- `simulate-traffic.sh` sends multiple concurrent requests (default 10) to
  exercise the Auto-RCA pipeline.
- The controller burns CPU for ~20 seconds per request, keeping the worker
  occupied.
- When a second request arrives while the first is still processing, the APCu
  in-flight counter reaches 2, exceeding the threshold of 1, and
  `assertWithinThreshold()` throws the RuntimeException.
- `AutoRcaSubscriber::onKernelException()` catches the 500, computes a
  deduplication signature, and spawns a task container to perform this RCA.

## Upsun Observability Evidence
- **App resources**: 1 instance, Size 0.5 (224 MB RAM, 0.5 CPU), 512 MB disk
- **Metrics at 09:36**: CPU dropped to 0% (PHP-FPM workers saturated by the
  long-running simulation requests). Memory steady at ~50%.
- **Activities**: Two RCA task containers spawned at 09:36:29 and 09:36:33
  (the `AutoRcaSubscriber` correctly deduplicated and the single-flight claim
  prevented further duplicate spawns).

## Why No Code Bug Exists
The exception is the **expected** outcome of the traffic simulation test. The
controller's docblock explicitly states it is a "Public load-testing endpoint
used to exercise the Auto-RCA pipeline under stress." The incident proves the
pipeline works end-to-end: 500 → subscriber → dedup → task spawn.

## Proposed Improvement
No code bug needs fixing. This PR adds an early-exit guard in `process()`:
if the in-flight count already exceeds the threshold before the CPU burn
loop starts, throw immediately rather than wasting resources. This makes the
failure mode more efficient without changing the observable behaviour.

## Confidence Level
**High** — the source code, the traffic script, and the Upsun observability
data all confirm this was a deliberate test trigger.
