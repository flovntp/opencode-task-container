#!/usr/bin/env bash
#
# simulate-traffic.sh — drive concurrent traffic at the Auto-RCA load endpoint
# to trigger application-level 500 errors (caught by AutoRcaSubscriber) and a
# CPU spike visible in the Upsun observability suite.
#
# Usage:
#   ./simulate-traffic.sh <URL> <TOKEN> [CONCURRENCY] [DURATION_SECONDS]
#
# Example:
#   ./simulate-traffic.sh \
#     "https://main-bvxea6i-m4kqny2hy3gry.eu-5.platformsh.site/fr/admin/auto-rca/simulate-traffic-exception" \
#     "my-secret-token" \
#     10 \
#     60
#
# Arguments:
#   URL          Full endpoint URL (without the ?key= query; it is appended).
#   TOKEN        Shared secret (TRAFFIC_SIM_TOKEN). Sent as ?key= and X-Sim-Token.
#   CONCURRENCY  Parallel in-flight requests. Set ABOVE the server threshold
#                (TRAFFIC_SIM_MAX_CONCURRENCY) to provoke 500s. Default: 10.
#   DURATION     How long to keep sending traffic, in seconds. Default: 60.
#
# Live tuning: append query params to the URL to override server defaults for a
# run (the token is still required), e.g. to force a 500 with minimal overlap:
#   .../simulate-traffic-exception?threshold=1&seconds=15
#
# The script is POSIX-friendly and works with the stock bash 3.2 on macOS.
# Press Ctrl-C to stop early; in-flight workers are terminated.

set -u

URL="${1:-}"
TOKEN="${2:-}"
CONCURRENCY="${3:-10}"
DURATION="${4:-60}"

if [ -z "$URL" ] || [ -z "$TOKEN" ]; then
  echo "Usage: $0 <URL> <TOKEN> [CONCURRENCY] [DURATION_SECONDS]" >&2
  exit 2
fi

# Build the request URL with the token query parameter.
case "$URL" in
  *\?*) REQ_URL="${URL}&key=${TOKEN}" ;;
  *)    REQ_URL="${URL}?key=${TOKEN}" ;;
esac

# Fresh per-invocation run token (letters only, to survive the subscriber's
# digit normalisation). A new token = a NEW Auto-RCA signature, so every run of
# this script re-triggers the pipeline; within a run all 500s share it and spawn
# a single task. Override with RUN=... in the environment if needed.
RUN="${RUN:-$(LC_ALL=C tr -dc 'a-z' </dev/urandom 2>/dev/null | head -c 8)}"
[ -n "$RUN" ] || RUN="run$$"
case "$REQ_URL" in
  *run=*) ;;
  *) REQ_URL="${REQ_URL}&run=${RUN}" ;;
esac

# Temp dir for per-worker result counters.
WORKDIR="$(mktemp -d "${TMPDIR:-/tmp}/simtraffic.XXXXXX")"
PIDS=""

cleanup() {
  # Terminate any still-running worker loops and curl children.
  for pid in $PIDS; do
    kill "$pid" 2>/dev/null
  done
  wait 2>/dev/null
}
trap 'echo; echo "Stopping…"; cleanup; summarize; rm -rf "$WORKDIR"; exit 0' INT TERM

# A single worker: hammer the endpoint sequentially until the deadline, logging
# each response's HTTP status to its own counter file and echoing non-200s live.
worker() {
  local id="$1" deadline="$2" out="$WORKDIR/worker-$1.log"
  : >"$out"
  while [ "$(date +%s)" -lt "$deadline" ]; do
    code="$(curl -s -o /dev/null \
      --max-time 60 \
      -H "X-Sim-Token: ${TOKEN}" \
      -w '%{http_code}' \
      "$REQ_URL" 2>/dev/null)"
    code="${code:-000}"
    echo "$code" >>"$out"
    # Surface 500s (and other non-200s) as they happen.
    case "$code" in
      200) ;;
      000) printf '[w%-2s] 000 (no response: edge timeout / FPM saturation)\n' "$id" ;;
      *)   printf '[w%-2s] HTTP %s\n' "$id" "$code" ;;
    esac
  done
}

summarize() {
  echo
  echo "──────── Summary ────────"
  if ! ls "$WORKDIR"/worker-*.log >/dev/null 2>&1; then
    echo "No responses recorded."
    return
  fi
  cat "$WORKDIR"/worker-*.log \
    | sort \
    | uniq -c \
    | awk '{ printf "  HTTP %-4s : %s requests\n", $2, $1 }'
  total="$(cat "$WORKDIR"/worker-*.log | wc -l | tr -d ' ')"
  echo "  ----"
  echo "  Total      : ${total} requests"
}

echo "Target      : $URL"
echo "Run token   : $RUN"
echo "Concurrency : $CONCURRENCY"
echo "Duration    : ${DURATION}s"
echo "Starting load… (Ctrl-C to stop)"
echo

START="$(date +%s)"
DEADLINE=$((START + DURATION))

i=1
while [ "$i" -le "$CONCURRENCY" ]; do
  worker "$i" "$DEADLINE" &
  PIDS="$PIDS $!"
  i=$((i + 1))
done

wait
summarize
rm -rf "$WORKDIR"
