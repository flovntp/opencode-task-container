#!/usr/bin/env node
'use strict';

/**
 * Retrieve the short-lived Upsun token forwarded by the app, BEFORE the agent runs.
 *
 * The static UPSUN_API_TOKEN was removed from the project, and the container-local
 * credential broker (localhost:8200) exists ONLY in the app/web container — it is
 * unreachable from the task container at BOTH build and runtime (confirmed:
 * ECONNREFUSED on 127.0.0.1 and localhost). So the task cannot mint its own token.
 *
 * Instead the app mints a short-lived access token (it CAN reach 8200) and forwards
 * it as a task variable named RCA_UPSUN_TOKEN — deliberately NOT `UPSUN_CLI_TOKEN`:
 * Upsun reserves the `UPSUN_`/`PLATFORM_` env prefixes and strips such variables, so
 * a forwarded `UPSUN_CLI_TOKEN` never reaches the task process.
 *
 * This script writes that token to a file; agent.js then:
 *   - injects it into the MCP header of the staged opencode.json (the writable /tmp
 *     config OpenCode actually reads — /app is read-only in the task container);
 *   - mirrors it into UPSUN_CLI_TOKEN, an OS-level env var for the `upsun` CLI child
 *     process (set inside the container, so NOT subject to Upsun's prefix filtering).
 *
 * Best-effort: if no token was forwarded we still exit 0 so the agent runs (the CLI
 * falls back to the container's env:view authorisation).
 */

const fs = require('node:fs');

// File agent.js reads the Upsun token from.
const TOKEN_FILE = process.env.UPSUN_MCP_TOKEN_FILE || '/tmp/upsun-mcp-token';

function main() {
  // The app forwards a short-lived Upsun access token as RCA_UPSUN_TOKEN (a
  // non-reserved name; UPSUN_*-prefixed task variables are stripped by Upsun).
  const forwarded = (process.env.RCA_UPSUN_TOKEN || '').trim();

  if (!forwarded) {
    console.error(
      '[setup] No RCA_UPSUN_TOKEN forwarded; continuing without an Upsun token (CLI relies on container auth).',
    );
    // Do NOT fail: the task command chains `setup.js && agent.js`, so a non-zero
    // exit would skip the agent entirely.
    process.exitCode = 0;
    return;
  }

  fs.writeFileSync(TOKEN_FILE, forwarded, { mode: 0o600 });
  console.log(`[setup] Wrote forwarded Upsun token (RCA_UPSUN_TOKEN) -> ${TOKEN_FILE}`);
}

main();
