#!/usr/bin/env node
'use strict';

/**
 * Auto-RCA agent entry point.
 *
 * Launched by the Upsun task container (`command: node agent.js`).
 * Reads the incident sent by the Symfony app through the task `run()` variables
 * and hands it over to OpenCode for root-cause analysis.
 *
 * Variables are sent by the app with the `env:` prefix, so they arrive as real
 * environment variables (INCIDENT_JSON / INCIDENT_SIGNATURE). As a fallback we
 * also decode `$PLATFORM_VARIABLES` (base64 JSON) in case they were sent without
 * the prefix.
 */

const { spawnSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');

// Short-lived Upsun access token minted by setup.js (run before this script).
// It authenticates BOTH the remote Upsun MCP server (injected into the staged
// opencode.json header) and the local `upsun` CLI (mirrored into
// UPSUN_CLI_TOKEN). Falls back to the legacy env tokens when absent.
const MCP_TOKEN_FILE = process.env.UPSUN_MCP_TOKEN_FILE || '/tmp/upsun-mcp-token';

function readUpsunToken() {
  try {
    const minted = fs.readFileSync(MCP_TOKEN_FILE, 'utf8').trim();
    if (minted) return minted;
  } catch {
    /* no minted token: fall back to env */
  }
  return (
    process.env.RCA_UPSUN_TOKEN ||
    process.env.UPSUN_CLI_TOKEN ||
    process.env.UPSUN_API_TOKEN ||
    ''
  );
}

// A/B switch: when truthy, OpenCode runs with no token-optimisation plugins so
// the resulting token summary can be compared against a normal (plugins ON) run.
const PLUGINS_DISABLED = /^(1|true|yes|on)$/i.test(
  process.env.RCA_DISABLE_PLUGINS ?? '',
);

// Measurement switch: when truthy, the agent only analyses (no branch/commit/
// push/PR). Keeps A/B runs deterministic and avoids creating branches/PRs.
const ANALYSIS_ONLY = /^(1|true|yes|on)$/i.test(
  process.env.RCA_ANALYSIS_ONLY ?? '',
);

// TokenScope is the measurement instrument: it reads OpenCode's stored
// `step-finish` telemetry and writes an authoritative per-session token/cost
// report (token-usage-output.txt). It is NOT a token-optimisation plugin, so it
// is loaded on BOTH the plugins-ON and plugins-OFF (baseline) runs — otherwise
// there would be nothing to compare. https://github.com/ramtinJ95/opencode-tokenscope
const TOKENSCOPE_PLUGIN = '@ramtinj95/opencode-tokenscope@latest';
const TOKENSCOPE_REPORT = 'token-usage-output.txt';

function readIncident() {
  let raw = process.env.INCIDENT_JSON;
  let signature = process.env.INCIDENT_SIGNATURE;

  if (!raw && process.env.PLATFORM_VARIABLES) {
    try {
      const decoded = JSON.parse(
        Buffer.from(process.env.PLATFORM_VARIABLES, 'base64').toString('utf8'),
      );
      raw = decoded.INCIDENT_JSON ?? raw;
      signature = decoded.INCIDENT_SIGNATURE ?? signature;
    } catch (err) {
      console.error('Failed to decode PLATFORM_VARIABLES:', err.message);
    }
  }

  if (!raw) {
    console.error('No INCIDENT_JSON provided. Nothing to analyze.');
    process.exit(1);
  }

  let incident;
  try {
    incident = JSON.parse(raw);
  } catch (err) {
    console.error('INCIDENT_JSON is not valid JSON:', err.message);
    process.exit(1);
  }

  return { incident, signature: signature ?? incident.signature ?? 'unknown' };
}

/**
 * Rewrite deployment-absolute paths (/app/...) to repository-relative paths.
 *
 * The exception is captured in the *app* container, where the code lives under
 * /app. OpenCode, however, works inside a fresh checkout (its current working
 * directory) and treats /app as an external, read-only directory it must not
 * touch. Stripping the /app/ prefix turns "/app/src/Foo.php" into "src/Foo.php"
 * so every path in the prompt resolves inside OpenCode's workspace.
 */
function normalizeAppPaths(value) {
  if (typeof value === 'string') {
    return value.split('/app/').join('');
  }
  if (Array.isArray(value)) {
    return value.map(normalizeAppPaths);
  }
  if (value && typeof value === 'object') {
    return Object.fromEntries(
      Object.entries(value).map(([key, val]) => [key, normalizeAppPaths(val)]),
    );
  }
  return value;
}

function buildPrompt({ incident: rawIncident, signature }, workspace) {
  const incident = normalizeAppPaths(rawIncident);
  const ex = incident.exception ?? {};
  const shortSig = String(signature).slice(0, 12);

  const tasks = [
    'Your tasks:',
    '1. Analyse the exception and identify the most likely root cause.',
    '2. Gather runtime context from Upsun. PREFER THE "upsun" MCP SERVER: whenever',
    '   an MCP tool exists for what you need (e.g. list activities, get the',
    '   environment info, deployment state, environment logs, routes), call that',
    '   MCP tool — it is authenticated with a short-lived token. Pass the project',
    '   id "$PLATFORM_PROJECT" and the environment "$PLATFORM_BRANCH" as arguments.',
    '   Only fall back to the "upsun" CLI in bash for what the MCP does NOT expose.',
    '   In particular, metrics and resources have no MCP tool, so use the CLI,',
    '   non-interactively (it shares the same short-lived token):',
    '       upsun metrics:all -p "$PLATFORM_PROJECT" -e "$PLATFORM_BRANCH" --no-interaction',
    '       upsun resources:get -p "$PLATFORM_PROJECT" -e "$PLATFORM_BRANCH" --no-interaction',
    '   Use this evidence (environment state from MCP, metrics + resources from the',
    '   CLI) to confirm or refine the root cause (e.g. spot saturation, OOM, slow',
    '   queries, correlated 5xx). Application logs may not be',
    '   reachable from this task container; if an MCP call or CLI command fails,',
    '   note it in one line and move on \u2014 never block or retry, the evidence above',
    '   is enough context.',
    '3. Inspect the relevant source files in this repository.',
  ];

  if (workspace.canOpenPr) {
    tasks.push(
      `4. Apply a minimal, focused fix on a new branch named "auto-rca/${shortSig}".`,
      '   You MUST always produce a change set, even if you are not fully certain of',
      '   the root cause. If no obvious code bug exists (e.g. the exception was',
      '   thrown deliberately), still open a PROPOSAL pull request: add defensive',
      '   handling, a guard, a clarifying comment, or a short RCA note file, so the',
      '   branch always has at least one committed change for a human to review.',
      '5. Commit the change with a clear message, push the branch to "origin", and',
      `   ALWAYS open a pull request against "${workspace.baseBranch}". Never finish`,
      '   without opening the PR. Title it "[Auto-RCA] <summary>" and, in the body,',
      `   describe the root cause, the proposed fix, and your confidence level. Back`,
      '   your analysis with the Upsun observability evidence gathered in step 2. The',
      '   remote is already authenticated, so use plain git to push. To open the PR,',
      '   call the GitHub REST API with the token in the $GH_PR_TOKEN env variable:',
      `     curl -sS -X POST \\`,
      `       -H "Authorization: Bearer $GH_PR_TOKEN" \\`,
      `       -H "Accept: application/vnd.github+json" \\`,
      `       https://api.github.com/repos/${workspace.repo}/pulls \\`,
      `       -d '{"title":"[Auto-RCA] <summary>","head":"auto-rca/${shortSig}","base":"${workspace.baseBranch}","body":"<body>"}'`,
      '6. After the PR is open, make the CI green. Poll the GitHub Actions checks',
      '   for the head commit of your branch and fix any failing workflow before you',
      '   finish. Use $GH_PR_TOKEN to query the status (the head SHA comes from',
      '   `git rev-parse HEAD`):',
      `     curl -sS -H "Authorization: Bearer $GH_PR_TOKEN" \\`,
      `       -H "Accept: application/vnd.github+json" \\`,
      `       https://api.github.com/repos/${workspace.repo}/commits/<sha>/check-runs`,
      '   For every check whose conclusion is "failure", inspect its `output`',
      '   (title/summary/annotations) and the failing run, reproduce the problem',
      '   locally when possible (e.g. run the same lint/test command), apply a fix,',
      '   then commit and push to the SAME branch. Re-poll until every check has',
      '   conclusion "success" (allow a short wait between polls for runs to start).',
      '   Stop after at most 5 fix/push iterations; if checks are still red, leave a',
      '   PR comment summarising what is still failing and why.',
    );
  } else {
    tasks.push(
      '4. Propose a concrete fix and describe the root cause. Do NOT create a',
      '   branch, commit, push, or open a pull request — this is an analysis-only',
      '   run. Output your findings as text only.',
    );
  }

  return [
    'You are an automated Root-Cause-Analysis (RCA) agent running inside an Upsun task container.',
    '',
    'You are already inside a fresh checkout of the repository: your current working',
    'directory IS the repository root. Every path below is RELATIVE to that root.',
    'The /app directory is a separate, read-only deployment of this same code and is',
    'NOT accessible from here \u2014 never read, cd into, or run git against /app. Always use',
    'the files in your working directory instead.',
    '',
    'NEVER read or open secret/environment files (.env, .env.*, *.local, or any file',
    'holding credentials). They are intentionally restricted and any read is rejected,',
    'which wastes your turn \u2014 all configuration you need is already in your environment',
    'variables. Skip them and keep going; do not retry a rejected read.',
    '',
    `Incident signature: ${signature}`,
    `Exception: ${ex.class ?? 'unknown'}`,
    `Message: ${ex.message ?? 'n/a'}`,
    `Location: ${ex.file ?? '?'}:${ex.line ?? '?'}`,
    '',
    'Top stack frames:',
    ...(ex.trace_top5 ?? []).map((frame, i) => `  ${i + 1}. ${frame}`),
    '',
    'Full incident payload (JSON):',
    JSON.stringify(incident, null, 2),
    '',
    ...tasks,
  ].join('\n');
}

function git(args, options = {}) {
  return spawnSync('git', args, { stdio: 'inherit', ...options });
}

/**
 * Prepare the directory OpenCode will work in.
 *
 * When a short-lived GitHub token is provided (minted app-side per incident),
 * clone the repository so OpenCode has a real git repo with an authenticated
 * remote it can branch/commit/push. The token is injected through an HTTP
 * `extraheader` (never embedded in the remote URL) to keep it out of logs and
 * `git remote -v`. Without a token we fall back to analysing the deployed tree
 * in /app (read-only, no PR).
 *
 * @returns {{cwd: string, repo: string, baseBranch: string, canOpenPr: boolean}}
 */
function prepareWorkspace() {
  const token = process.env.GH_PR_TOKEN;
  const repo = process.env.GITHUB_REPO;
  const baseBranch = process.env.GITHUB_BASE_BRANCH || 'main';
  const fallback = { cwd: '/app', repo: repo ?? '', baseBranch, canOpenPr: false };

  if (!token || !repo) {
    console.error('No GH_PR_TOKEN/GITHUB_REPO: analysing /app only (no pull request).');
    return fallback;
  }

  if (ANALYSIS_ONLY) {
    console.log('RCA_ANALYSIS_ONLY set: cloning for analysis only (no branch/commit/PR).');
    fs.rmSync('/tmp/work', { recursive: true, force: true });
    const basic = Buffer.from(`x-access-token:${token}`).toString('base64');
    const authHeader = `http.https://github.com/.extraheader=AUTHORIZATION: basic ${basic}`;
    const clone = git([
      '-c', authHeader,
      'clone', '--depth', '50',
      `https://github.com/${repo}.git`, '/tmp/work',
    ]);
    if (clone.status !== 0 || !fs.existsSync(path.join('/tmp/work', '.git'))) {
      console.error(`git clone failed (status ${clone.status}); falling back to /app.`);
      return fallback;
    }
    console.log(`Cloned ${repo} into /tmp/work (analysis only).`);
    return { cwd: '/tmp/work', repo, baseBranch, canOpenPr: false };
  }
  console.log(`Preparing workspace: cloning ${repo} (base ${baseBranch}) into /tmp/work.`);

  const workdir = '/tmp/work';
  fs.rmSync(workdir, { recursive: true, force: true });

  const basic = Buffer.from(`x-access-token:${token}`).toString('base64');
  const authHeader = `http.https://github.com/.extraheader=AUTHORIZATION: basic ${basic}`;

  const clone = git([
    '-c', authHeader,
    'clone', '--depth', '50',
    `https://github.com/${repo}.git`, workdir,
  ]);

  if (clone.status !== 0 || !fs.existsSync(path.join(workdir, '.git'))) {
    console.error(`git clone failed (status ${clone.status}); falling back to /app (no pull request).`);
    return fallback;
  }

  console.log(`Cloned ${repo} into ${workdir}.`);

  // Persist auth for push, and set a commit identity.
  git(['-C', workdir, 'config', 'http.https://github.com/.extraheader', `AUTHORIZATION: basic ${basic}`], { stdio: 'ignore' });
  git(['-C', workdir, 'config', 'user.name', 'Upsun Auto-RCA']);
  git(['-C', workdir, 'config', 'user.email', 'auto-rca@users.noreply.github.com']);

  return { cwd: workdir, repo, baseBranch, canOpenPr: true };
}

function prepareOpenCodeEnv() {
  // opencode keeps a SQLite database under XDG_DATA_HOME/opencode and also
  // writes housekeeping files (e.g. a .gitignore) into its config dir on
  // startup. SQLite needs POSIX locking (distributed "storage" mounts do not
  // provide it -> "unable to open database file"), and the deployed app tree is
  // read-only inside the task container (-> "EROFS ... /app/.config/opencode").
  // Point every XDG dir at a guaranteed-writable local path (/tmp) so opencode
  // can always read/write, regardless of how mounts are configured.
  const base = '/tmp/opencode';
  const configHome = path.join(base, 'config');
  const dirs = {
    XDG_DATA_HOME: path.join(base, 'data'),
    XDG_CACHE_HOME: path.join(base, 'cache'),
    XDG_STATE_HOME: path.join(base, 'state'),
    XDG_CONFIG_HOME: configHome,
  };

  for (const dir of Object.values(dirs)) {
    fs.mkdirSync(dir, { recursive: true });
  }

  // OpenCode runs with cwd set to the freshly cloned repo (/tmp/work), so the
  // CLI/skill binaries installed at build time under /app/node_modules/.bin
  // (e.g. the `upsun` CLI) are no longer discoverable. Keep them on PATH so the
  // agent can still shell out to `upsun` regardless of the working directory.
  // `upsun` lives under /app/node_modules/.bin; `snip` (installed via
  // install-github-asset.sh) lives under /app/.global/bin. Keep both on PATH.
  const extraPaths = ['/app/node_modules/.bin', '/app/.global/bin'];
  const currentPath = process.env.PATH ?? '';
  const segments = currentPath.split(':');
  const missing = extraPaths.filter((p) => !segments.includes(p));
  const mergedPath = missing.length === 0
    ? currentPath
    : `${missing.join(':')}:${currentPath}`;

  // Seed the writable config dir with the bundled opencode.json (plugins +
  // permission policy). The deploy hook only writes it into the app container's
  // read-only /app/.config/opencode, which this separate task container cannot
  // use.
  //
  // A/B switch: set RCA_DISABLE_PLUGINS=1 to stage the config with an empty
  // `plugin` array. Running the same incident with and without plugins and
  // comparing the token summaries (see summarizeTokens) gives a real delta.
  const upsunToken = readUpsunToken();
  try {
    const configDir = path.join(configHome, 'opencode');
    fs.mkdirSync(configDir, { recursive: true });
    const config = JSON.parse(
      fs.readFileSync(path.join(__dirname, 'opencode.json'), 'utf8'),
    );
    // Optimisation plugins (openslimedit / opencode-dcp / opencode-snip) come
    // from the bundled config and are toggled by the A/B switch. TokenScope is
    // always appended so both runs produce a comparable token report.
    const optimisationPlugins = (Array.isArray(config.plugin) ? config.plugin : [])
      .filter((p) => !String(p).startsWith('@ramtinj95/opencode-tokenscope'));
    if (PLUGINS_DISABLED) {
      config.plugin = [TOKENSCOPE_PLUGIN];
      console.log('RCA_DISABLE_PLUGINS set: optimisation plugins OFF (baseline); tokenscope kept for measurement.');
    } else {
      config.plugin = [...optimisationPlugins, TOKENSCOPE_PLUGIN];
      console.log(`Running with plugins ON: ${optimisationPlugins.join(', ')} (+ tokenscope)`);
    }
    // Inject the short-lived Upsun token into the remote MCP header. This is the
    // JS equivalent of `envsubst < opencode.json`: the bundled config ships a
    // ${UPSUN_CLI_TOKEN} placeholder, but OpenCode does not expand env vars in
    // headers, so we substitute the real value here before staging the config.
    if (upsunToken && config.mcp?.upsun?.headers) {
      config.mcp.upsun.headers['upsun-api-token'] = upsunToken;
    }
    fs.writeFileSync(
      path.join(configDir, 'opencode.json'),
      JSON.stringify(config, null, 2),
    );
  } catch (err) {
    console.error('Could not stage opencode.json config:', err.message);
  }

  // The Upsun CLI reads its API token from UPSUN_CLI_TOKEN. The static
  // UPSUN_API_TOKEN was removed from the project, so reuse the same short-lived
  // token minted by setup.js for the CLI too (metrics/resources go through the
  // CLI). Falls back to any legacy env token if no token was minted.
  const tokenEnv = upsunToken ? { UPSUN_CLI_TOKEN: upsunToken } : {};

  return { ...process.env, ...dirs, ...tokenEnv, PATH: mergedPath };
}

/**
 * Recursively collect every *.json file under `dir`.
 */
function walkJson(dir, out = []) {
  let entries;
  try {
    entries = fs.readdirSync(dir, { withFileTypes: true });
  } catch {
    return out;
  }
  for (const entry of entries) {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) {
      walkJson(full, out);
    } else if (entry.isFile() && entry.name.endsWith('.json')) {
      out.push(full);
    }
  }
  return out;
}

/**
 * Sum the per-message token usage and cost OpenCode persists under its data
 * dir, and print a summary. This is the measurable signal for whether the
 * token-optimisation plugins actually reduced usage (compare ON vs OFF runs).
 */
function summarizeTokens(dataHome) {
  try {
    const root = path.join(dataHome, 'opencode');
    const totals = {
      messages: 0,
      input: 0,
      output: 0,
      reasoning: 0,
      cacheRead: 0,
      cacheWrite: 0,
      cost: 0,
    };

    for (const file of walkJson(root)) {
      let parsed;
      try {
        parsed = JSON.parse(fs.readFileSync(file, 'utf8'));
      } catch {
        continue;
      }
      const info = parsed && parsed.info ? parsed.info : parsed;
      // An assistant message carries both a numeric `cost` and a `tokens`
      // object; that shape uniquely identifies billable turns and avoids
      // double-counting session-info files.
      if (!info || typeof info !== 'object') continue;
      const tokens = info.tokens;
      if (typeof info.cost !== 'number' || !tokens || typeof tokens !== 'object') {
        continue;
      }
      totals.messages += 1;
      totals.input += Number(tokens.input) || 0;
      totals.output += Number(tokens.output) || 0;
      totals.reasoning += Number(tokens.reasoning) || 0;
      totals.cacheRead += Number(tokens.cache && tokens.cache.read) || 0;
      totals.cacheWrite += Number(tokens.cache && tokens.cache.write) || 0;
      totals.cost += Number(info.cost) || 0;
    }

    const mode = PLUGINS_DISABLED ? 'OFF (baseline)' : 'ON';
    console.error('=== Token usage summary ===');
    console.error(`plugins:           ${mode}`);
    console.error(`assistant messages: ${totals.messages}`);
    console.error(`tokens.input:       ${totals.input}`);
    console.error(`tokens.output:      ${totals.output}`);
    console.error(`tokens.reasoning:   ${totals.reasoning}`);
    console.error(`tokens.cache.read:  ${totals.cacheRead}`);
    console.error(`tokens.cache.write: ${totals.cacheWrite}`);
    console.error(`cost (USD):         ${totals.cost.toFixed(6)}`);
    if (totals.messages === 0) {
      console.error('(no per-message usage found — OpenCode may not have persisted session data)');
    }
    console.error('=== end token usage summary ===');
  } catch (err) {
    console.error('Could not summarize token usage:', err.message);
  }
}

/**
 * Print the TokenScope report the agent wrote at the end of the session. This
 * is the authoritative token/cost measurement (it parses OpenCode's stored
 * step-finish telemetry), unlike summarizeTokens which is a best-effort
 * fallback over the raw message files.
 */
function dumpTokenScopeReport(cwd) {
  try {
    const file = path.join(cwd, TOKENSCOPE_REPORT);
    if (!fs.existsSync(file)) {
      console.error(`TokenScope report not found at ${file} (the agent may not have called the tokenscope tool).`);
      return;
    }
    const mode = PLUGINS_DISABLED ? 'OFF (baseline)' : 'ON';
    console.error(`=== TokenScope report (plugins: ${mode}) ===`);
    console.error(fs.readFileSync(file, 'utf8'));
    console.error('=== end TokenScope report ===');
  } catch (err) {
    console.error('Could not read TokenScope report:', err.message);
  }
}

function dumpOpenCodeLog(dataHome) {
  try {
    const logDir = path.join(dataHome, 'opencode', 'log');
    const latest = fs
      .readdirSync(logDir)
      .map((f) => path.join(logDir, f))
      .sort()
      .pop();

    if (latest) {
      console.error(`--- opencode log (${latest}) ---`);
      console.error(fs.readFileSync(latest, 'utf8'));
      console.error('--- end opencode log ---');
    }
  } catch (err) {
    console.error('Could not read opencode log:', err.message);
  }
}

/**
 * Find the id of the MAIN OpenCode session for this run. We need it to point the
 * TokenScope measurement pass at the agent's real session rather than the
 * throwaway measurement one.
 *
 * Primary source: OpenCode's persisted session metadata. Every session is stored
 * as a JSON file whose `id` starts with "ses_"; the main session is the one
 * without a `parentID` (subagent/child sessions carry their parent's id). When
 * several exist we take the earliest-created one. We fall back to scraping the
 * run log (`message=created id=ses_…`) when storage can't be read — the log file
 * is sometimes empty, so storage is preferred.
 */
function extractMainSessionId(dataHome) {
  const root = path.join(dataHome, 'opencode');

  // 1) Persisted session metadata (source of truth).
  try {
    const sessions = [];
    for (const file of walkJson(root)) {
      let parsed;
      try {
        parsed = JSON.parse(fs.readFileSync(file, 'utf8'));
      } catch {
        continue;
      }
      const info = parsed && parsed.info ? parsed.info : parsed;
      if (!info || typeof info !== 'object') continue;
      if (typeof info.id !== 'string' || !info.id.startsWith('ses_')) continue;
      const created = Number(info.time && info.time.created) || 0;
      sessions.push({ id: info.id, parentID: info.parentID, created });
    }
    const mains = sessions.filter((s) => !s.parentID);
    const pick = (mains.length ? mains : sessions).sort((a, b) => a.created - b.created)[0];
    if (pick) return pick.id;
  } catch {
    /* fall through to log scraping */
  }

  // 2) Fallback: scrape the run log.
  try {
    const logDir = path.join(root, 'log');
    const files = fs
      .readdirSync(logDir)
      .map((f) => path.join(logDir, f))
      .sort();
    for (const file of files.reverse()) {
      const content = fs.readFileSync(file, 'utf8');
      const match = content.match(/\bid=(ses_[A-Za-z0-9]+)/);
      if (match) return match[1];
    }
  } catch {
    /* ignore */
  }

  return null;
}

/**
 * Deterministic token measurement. TokenScope only exposes a `tokenscope` TOOL
 * (no CLI), so it must be invoked by an LLM turn. Relying on the main RCA agent
 * to call it as a final step is unreliable — the agent can finish (or abort on a
 * denied permission) before it gets there. Instead we run a second, dedicated
 * single-purpose OpenCode session whose ONLY job is to call `tokenscope` for the
 * main session's id. It reads the same XDG_DATA_HOME, so the prior session's
 * stored step-finish telemetry is available, and writes token-usage-output.txt.
 */
function runTokenScopePass(env, cwd, sessionId) {
  if (!sessionId) {
    console.error('No main session id found in the OpenCode log; skipping the TokenScope measurement pass.');
    return;
  }
  console.log(`Running TokenScope measurement pass for session ${sessionId}.`);
  const prompt = [
    `Call the \`tokenscope\` tool exactly once with sessionID="${sessionId}" and`,
    'includeSubagents=true. Call the tool directly — do NOT delegate it to a',
    'subagent. As soon as the tool returns, stop immediately and do nothing else.',
  ].join('\n');
  const result = spawnSync('opencode', ['run', prompt], { stdio: 'inherit', cwd, env });
  if (result.error) {
    console.error('TokenScope measurement pass failed to start:', result.error.message);
  }
}

function runOpenCode(prompt, cwd) {
  const env = prepareOpenCodeEnv();

  // The task starts in /app, so process.env.PWD is /app. OpenCode resolves its
  // project directory from $PWD rather than the real cwd, so without this it
  // stays in /app and treats the freshly cloned /tmp/work as an *external*
  // directory (every access is auto-rejected). Align $PWD with the spawn cwd.
  env.PWD = cwd;

  console.log(`Launching opencode in ${cwd}`);

  // Non-interactive OpenCode run; inherit stdio so logs stream to the task output.
  const result = spawnSync('opencode', ['run', prompt], { stdio: 'inherit', cwd, env });
  if (result.error) {
    console.error('Failed to start opencode:', result.error.message);
    process.exit(1);
  }
  const code = result.status ?? 0;
  console.log(`opencode exited with code ${code}`);

  // Always surface the OpenCode log: a clean exit can still mean "did nothing"
  // (e.g. no PR opened), and the log is the only window into what it decided.
  dumpOpenCodeLog(env.XDG_DATA_HOME);

  // Deterministic measurement: invoke tokenscope for the main session in a
  // dedicated second OpenCode turn, then print the report it wrote.
  runTokenScopePass(env, cwd, extractMainSessionId(env.XDG_DATA_HOME));
  dumpTokenScopeReport(cwd);

  // Best-effort fallback totals straight from the persisted message files.
  summarizeTokens(env.XDG_DATA_HOME);

  // Do NOT call process.exit() here. stdout/stderr are pipes to the Upsun task
  // log collector, and a hard exit drops whatever is still buffered — which
  // truncated the TokenScope report and token summary (the last writes before
  // the exit) from the task log. Setting exitCode lets Node drain both streams
  // and then exit naturally; spawnSync is synchronous so nothing keeps the
  // event loop alive afterwards.
  process.exitCode = code;
}

const data = readIncident();
console.log(`Starting Auto-RCA for signature ${data.signature}`);
const workspace = prepareWorkspace();
runOpenCode(buildPrompt(data, workspace), workspace.cwd);
