'use strict';

/**
 * Mints a short-lived GitHub App installation access token at build time and
 * prints it (and only it) to stdout, so the build hook can do:
 *
 *   export GITHUB_API_TOKEN="$(node agent/mint-github-token.js || true)"
 *
 * Authenticating GitHub API calls moves them off the shared 60 req/h
 * unauthenticated bucket (keyed on Upsun's NAT egress IP, contended by every
 * project on the cluster) onto the App installation's own 5000 req/h quota.
 *
 * This is a zero-dependency Node port of src/Github/GitHubAppTokenMinter.php:
 * it builds an RS256 JWT with the built-in `crypto` module and exchanges it for
 * an installation token. It is best-effort: any missing config or failure
 * prints nothing to stdout and exits 0, leaving the build to proceed (and
 * possibly fall back to unauthenticated calls).
 */

const crypto = require('node:crypto');

const API_BASE = 'https://api.github.com';

function base64UrlEncode(buf) {
  return Buffer.from(buf)
    .toString('base64')
    .replace(/\+/g, '-')
    .replace(/\//g, '_')
    .replace(/=+$/, '');
}

function shortRepositoryName(repository) {
  const parts = String(repository).split('/');
  return parts[parts.length - 1] || repository;
}

function decodePrivateKey(value) {
  // Stored base64-encoded to survive the CLI/env layer; fall back to a raw PEM.
  const decoded = Buffer.from(value, 'base64').toString('utf8');
  if (decoded.includes('-----BEGIN')) {
    return decoded;
  }
  return value;
}

function buildJwt(appId, privateKeyPem) {
  const now = Math.floor(Date.now() / 1000);

  const header = base64UrlEncode(JSON.stringify({ alg: 'RS256', typ: 'JWT' }));
  const payload = base64UrlEncode(
    JSON.stringify({
      iat: now - 60, // tolerate minor clock drift
      exp: now + 540, // GitHub caps App JWT lifetime at 10 min
      iss: appId,
    }),
  );

  const signingInput = `${header}.${payload}`;
  const signature = crypto.sign('RSA-SHA256', Buffer.from(signingInput), privateKeyPem);

  return `${signingInput}.${base64UrlEncode(signature)}`;
}

async function main() {
  const appId = (process.env.GITHUB_APP_ID || '').trim();
  const installationId = (process.env.GITHUB_APP_INSTALLATION_ID || '').trim();
  const rawPrivateKey = (process.env.GITHUB_APP_PRIVATE_KEY || '').trim();
  const repository = (process.env.GITHUB_REPO || '').trim();

  if (!appId || !installationId || !rawPrivateKey) {
    console.error('[mint-github-token] GitHub App not configured; skipping (build will use unauthenticated calls).');
    return;
  }

  let jwt;
  try {
    jwt = buildJwt(appId, decodePrivateKey(rawPrivateKey));
  } catch (err) {
    console.error(`[mint-github-token] failed to sign App JWT: ${err && err.message}`);
    return;
  }

  try {
    const response = await fetch(
      `${API_BASE}/app/installations/${installationId}/access_tokens`,
      {
        method: 'POST',
        headers: {
          Authorization: `Bearer ${jwt}`,
          Accept: 'application/vnd.github+json',
          'X-GitHub-Api-Version': '2022-11-28',
          'User-Agent': 'auto-rca-build',
        },
        body: JSON.stringify({
          // Only used to raise the API rate limit for the asset install, so a
          // minimal scope is enough: metadata:read on the App's own repo.
          repositories: repository ? [shortRepositoryName(repository)] : undefined,
          permissions: { metadata: 'read' },
        }),
      },
    );

    const data = await response.json().catch(() => ({}));

    if (!response.ok || !data || !data.token) {
      console.error(
        `[mint-github-token] installation token request rejected (HTTP ${response.status}): ${JSON.stringify(data)}`,
      );
      return;
    }

    // Stdout MUST contain only the token so it can be captured by the shell.
    process.stdout.write(String(data.token));
    console.error('[mint-github-token] minted installation token (5000 req/h quota).');
  } catch (err) {
    console.error(`[mint-github-token] token exchange failed: ${err && err.message}`);
  }
}

main().then(
  () => {
    process.exitCode = 0;
  },
  (err) => {
    console.error(`[mint-github-token] unexpected error: ${err && err.message}`);
    process.exitCode = 0;
  },
);
