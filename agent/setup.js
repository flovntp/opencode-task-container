#!/usr/bin/env node
'use strict';

/**
 * Mint a short-lived Upsun access token BEFORE the agent runs.
 *
 * The static UPSUN_API_TOKEN has been removed from the project, so nothing in
 * the container holds a long-lived credential anymore. The remote Upsun MCP
 * server (https://mcp.upsun.com/mcp) lives OUTSIDE the container and therefore
 * needs an explicit bearer token in its request header — it cannot rely on the
 * container's ambient auth the way the local `upsun` CLI can.
 *
 * This script obtains a short-lived OAuth2 access token from the container-local
 * credential service on http://localhost:8200 and hands it to agent.js through a
 * file. agent.js then:
 *   - injects the token into the MCP header of the staged opencode.json
 *     (the "envsubst" step, done in JS so it works against the writable /tmp
 *     config OpenCode actually reads — /app is read-only in the task container);
 *   - mirrors it into UPSUN_CLI_TOKEN so the `upsun` CLI is authenticated too.
 *
 * Equivalent shell:
 *   token=$(curl http://localhost:8200/oauth2/token \
 *             -d grant_type=client_credentials -d x-token-ttl=3600 \
 *           | jq -r .access_token)
 *
 * Best-effort: if the token cannot be minted we still exit 0 so the agent runs
 * (the CLI can fall back to the container's env:view authorisation).
 */

const fs = require('node:fs');

// Candidate token endpoints, tried in order. UPSUN_TOKEN_ENDPOINT overrides the
// list entirely. We try 127.0.0.1 BEFORE localhost on purpose: Node's fetch
// (undici) resolves `localhost` to IPv6 `::1` first, but the container-local
// credential broker only listens on IPv4 127.0.0.1 — so `localhost` yields
// `fetch failed` (ECONNREFUSED on ::1) while 127.0.0.1 connects. PHP/curl in the
// app container prefers IPv4, which is why the app could already mint a token.
const TOKEN_ENDPOINTS = process.env.UPSUN_TOKEN_ENDPOINT
  ? [process.env.UPSUN_TOKEN_ENDPOINT]
  : [
      'http://127.0.0.1:8200/oauth2/token',
      'http://localhost:8200/oauth2/token',
    ];
const TOKEN_FILE = process.env.UPSUN_MCP_TOKEN_FILE || '/tmp/upsun-mcp-token';
// Default TTL covers the whole task run (the task timeout is 3600s) so the MCP
// token does not expire mid-analysis.
const TOKEN_TTL = process.env.UPSUN_MCP_TOKEN_TTL || '3600';

async function mintFrom(endpoint) {
  const body = new URLSearchParams({
    grant_type: 'client_credentials',
    'x-token-ttl': TOKEN_TTL,
  });

  const res = await fetch(endpoint, {
    method: 'POST',
    headers: { 'content-type': 'application/x-www-form-urlencoded' },
    body,
  });
  if (!res.ok) {
    throw new Error(`HTTP ${res.status}`);
  }
  const data = await res.json();
  const token = data && data.access_token;
  if (!token) {
    throw new Error('response did not contain an access_token');
  }
  return token;
}

async function main() {
  let token;
  for (const endpoint of TOKEN_ENDPOINTS) {
    try {
      token = await mintFrom(endpoint);
      console.log(`[setup] Minted token via ${endpoint}`);
      break;
    } catch (err) {
      // err.cause?.code surfaces the real reason (ECONNREFUSED / ENOTFOUND / …)
      // behind a generic "fetch failed".
      const cause = err.cause && err.cause.code ? ` (${err.cause.code})` : '';
      console.error(`[setup] mint via ${endpoint} failed: ${err.message}${cause}`);
    }
  }

  if (!token) {
    console.error(
      '[setup] Continuing without an MCP token (the CLI will rely on the container auth).',
    );
    // Do NOT fail: the task command chains `setup.js && agent.js`, so a non-zero
    // exit would skip the agent entirely.
    process.exitCode = 0;
    return;
  }

  fs.writeFileSync(TOKEN_FILE, token, { mode: 0o600 });
  console.log(
    `[setup] Minted short-lived Upsun token (ttl=${TOKEN_TTL}s) -> ${TOKEN_FILE}`,
  );
}

main();
