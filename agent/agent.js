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

const { spawn, spawnSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');

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
    '2. Gather runtime context from Upsun via the "upsun" MCP server (already',
    '   authenticated — do NOT shell out to the `upsun` CLI, it has no token). For',
    '   this project ("$PLATFORM_PROJECT") and environment ("$PLATFORM_BRANCH"),',
    '   pull the recent application logs, the error/HTTP metrics and the current',
    '   resource allocation around the time of the incident. Use this evidence to',
    '   confirm or refine the root cause (e.g. spot saturation, OOM, slow queries,',
    '   correlated 5xx). If the MCP is unavailable, note it in one line and continue',
    '   with static code analysis — never block on it.',
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
      '4. Propose a concrete fix and describe the root cause. (No GitHub token was',
      '   provided, so do not attempt to push or open a pull request.)',
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
  // MCP/skill binaries installed at build time under /app/node_modules/.bin
  // (e.g. `upsun-mcp`) are no longer discoverable. Keep them on PATH so the
  // upsun MCP server still starts regardless of the working directory.
  // `upsun-mcp` lives under /app/node_modules/.bin; `snip` (installed via
  // install-github-asset.sh) lives under /app/.global/bin. Keep both on PATH.
  const extraPaths = ['/app/node_modules/.bin', '/app/.global/bin'];
  const currentPath = process.env.PATH ?? '';
  const segments = currentPath.split(':');
  const missing = extraPaths.filter((p) => !segments.includes(p));
  const mergedPath = missing.length === 0
    ? currentPath
    : `${missing.join(':')}:${currentPath}`;

  // Seed the writable config dir with the bundled opencode.json (MCP setup).
  // The deploy hook only writes it into the app container's read-only
  // /app/.config/opencode, which this separate task container cannot use.
  try {
    const configDir = path.join(configHome, 'opencode');
    fs.mkdirSync(configDir, { recursive: true });
    fs.copyFileSync(
      path.join(__dirname, 'opencode.json'),
      path.join(configDir, 'opencode.json'),
    );
  } catch (err) {
    console.error('Could not stage opencode.json config:', err.message);
  }

  return { ...process.env, ...dirs, PATH: mergedPath };
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

function runOpenCode(prompt, cwd) {
  const env = prepareOpenCodeEnv();

  // The task starts in /app, so process.env.PWD is /app. OpenCode resolves its
  // project directory from $PWD rather than the real cwd, so without this it
  // stays in /app and treats the freshly cloned /tmp/work as an *external*
  // directory (every access is auto-rejected). Align $PWD with the spawn cwd.
  env.PWD = cwd;

  console.log(`Launching opencode in ${cwd}`);

  // Non-interactive OpenCode run; inherit stdio so logs stream to the task output.
  const child = spawn('opencode', ['run', prompt], {
    stdio: 'inherit',
    cwd,
    env,
  });

  child.on('error', (err) => {
    console.error('Failed to start opencode:', err.message);
    process.exit(1);
  });

  child.on('exit', (code) => {
    console.log(`opencode exited with code ${code ?? 0}`);
    // Always surface the OpenCode log: a clean exit can still mean "did nothing"
    // (e.g. no PR opened), and the log is the only window into what it decided.
    dumpOpenCodeLog(env.XDG_DATA_HOME);
    process.exit(code ?? 0);
  });
}

const data = readIncident();
console.log(`Starting Auto-RCA for signature ${data.signature}`);
const workspace = prepareWorkspace();
runOpenCode(buildPrompt(data, workspace), workspace.cwd);
