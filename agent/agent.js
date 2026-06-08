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

const { spawn } = require('node:child_process');

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

function buildPrompt({ incident, signature }) {
  const ex = incident.exception ?? {};

  return [
    'You are an automated Root-Cause-Analysis (RCA) agent running inside an Upsun task container.',
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
    'Your tasks:',
    '1. Analyse the exception and identify the most likely root cause.',
    '2. Inspect the relevant source files in this repository.',
    '3. Propose a concrete fix and open a pull request describing the root cause.',
  ].join('\n');
}

function runOpenCode(prompt) {
  // Non-interactive OpenCode run; inherit stdio so logs stream to the task output.
  const child = spawn('opencode', ['run', prompt], {
    stdio: 'inherit',
    env: process.env,
  });

  child.on('error', (err) => {
    console.error('Failed to start opencode:', err.message);
    process.exit(1);
  });

  child.on('exit', (code) => {
    console.log(`opencode exited with code ${code ?? 0}`);
    process.exit(code ?? 0);
  });
}

const data = readIncident();
console.log(`Starting Auto-RCA for signature ${data.signature}`);
runOpenCode(buildPrompt(data));
