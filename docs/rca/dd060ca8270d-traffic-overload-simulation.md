# Auto-RCA Incident dd060ca8270d

## Exception

```
RuntimeException: [Auto-RCA test] Traffic overload on run izaormrc:
2 concurrent requests exceeded the threshold of 1 during slow processing (elapsed 0s).
The endpoint shed load by failing fast.
```

## Root Cause

The exception was **deliberately thrown** by `TrafficSimulationController::assertWithinThreshold()` (`src/Controller/TrafficSimulationController.php:150`).

This controller is a **load-testing endpoint** (not production logic) designed to exercise the Auto-RCA pipeline:

1. A traffic generator (`simulate-traffic.sh`) sent concurrent GET requests to `/fr/admin/auto-rca/simulate-traffic-exception`
2. The controller uses an APCu atomic counter to track in-flight requests
3. The default max concurrency (`TRAFFIC_SIM_MAX_CONCURRENCY`) is **1**
4. Two requests arrived concurrently: the first incremented the counter to 1 (within threshold), the second incremented it to 2 → exceeded threshold → `RuntimeException` thrown

## Upsun Observability Evidence

| Source | Finding |
|---|---|
| **Resources** | Single `app` instance at 0.5 CPU profile (224 MB RAM). No horizontal scaling — any concurrency >1 hits the PHP-FPM worker limit. |
| **Metrics (09:54 UTC)** | App CPU spiked to **17%** (baseline ~1%), memory jumped to **73.6%** (baseline ~51%). Router showed 4.3% CPU (baseline 0%). Consistent with the simulated CPU-intensive SHA-256 busy loop. |
| **Activities** | Task `l6fa4em776z7i` (09:54:35) "ran task myagent" — this was the traffic generator run. No prior deployment, resource change, or configuration activity that would explain a real load issue. |

## Assessment

- **Intentional**: The exception is part of the Auto-RCA end-to-end test suite
- **No production impact**: The endpoint is gated by a shared secret token
- **The pipeline works**: `AutoRcaSubscriber` correctly caught the 500, computed a stable signature (deduping all 500s within run `izaormrc`), and spawned a single RCA task container

## Recommendation

No code change required — the behaviour is by design. If the 0-second elapsed time in the message is undesirable, consider checking concurrency only after a minimum processing window (e.g. 5 seconds) to avoid instant failures. However, this would change the test semantics; the current design cleanly exercises the "fail fast" load-shedding path.
