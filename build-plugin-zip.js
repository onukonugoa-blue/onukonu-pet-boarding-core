#!/usr/bin/env node
/**
 * Build script: creates onukonu-pet-boarding-core-v2.1.0.zip
 * Uses adm-zip (pure Node.js) — no Python dependency required.
 *
 * Usage: node build-plugin-zip.js
 */

'use strict';

const AdmZip = require('adm-zip');
const fs     = require('fs');
const path   = require('path');

const VERSION     = '2.7.0';
const OUTPUT_NAME = `onukonu-pet-boarding-core-v${VERSION}.zip`;
const OUTPUT_PATH = path.resolve(__dirname, OUTPUT_NAME);
const PLUGIN_DIR  = path.resolve(__dirname, 'plugin');
const PREFIX      = 'onukonu-pet-boarding-core';

// ── Exclusion rules ───────────────────────────────────────────────────────────

const EXCLUDE_PREFIXES = ['tests/', 'app/', 'vendor/bin/', '.git/'];
const EXCLUDE_NAMES = new Set([
  'appcomposer.json', 'package.json', 'package-lock.json',
  'tsconfig.json', 'vite.config.ts', '.DS_Store', 'Thumbs.db',
  '.editorconfig', '.gitignore', '.gitattributes',
  'build.sh', 'composer.json', 'composer.lock',
]);
// Dot-prefixed directories that are explicitly allowed
const ALLOW_DOTDIRS = new Set(['assets/dist/.vite']);

function isExcluded(rel) {
  const name = path.basename(rel);
  if (EXCLUDE_NAMES.has(name)) return true;
  const norm = rel.split(path.sep).join('/');
  return EXCLUDE_PREFIXES.some((p) => norm.startsWith(p));
}

function isDotDirAllowed(relDir) {
  const norm = relDir.split(path.sep).join('/');
  for (const d of ALLOW_DOTDIRS) {
    if (norm === d || norm.startsWith(d + '/')) return true;
  }
  return false;
}

// ── Walk plugin directory recursively ────────────────────────────────────────

function collectFiles(dir, relBase) {
  const results = [];
  const entries = fs.readdirSync(dir).sort();
  for (const entry of entries) {
    const abs  = path.join(dir, entry);
    const rel  = relBase ? path.join(relBase, entry) : entry;
    const stat = fs.statSync(abs);
    if (stat.isDirectory()) {
      if (entry.startsWith('.') && !isDotDirAllowed(rel)) continue;
      results.push(...collectFiles(abs, rel));
    } else {
      if (entry.startsWith('.')) continue;
      if (isExcluded(rel)) continue;
      results.push({ abs, rel });
    }
  }
  return results;
}

// ── Build ─────────────────────────────────────────────────────────────────────

if (fs.existsSync(OUTPUT_PATH)) {
  fs.unlinkSync(OUTPUT_PATH);
  console.log(`Removed existing ${OUTPUT_NAME}`);
}

const files = collectFiles(PLUGIN_DIR, '');
const zip   = new AdmZip();

for (const { abs, rel } of files) {
  // Normalise path separators for the archive entry name
  const arcEntry = (PREFIX + '/' + rel.split(path.sep).join('/')).replace(/\/+/g, '/');
  const buf = fs.readFileSync(abs);
  zip.addFile(arcEntry, buf);
}

zip.writeZip(OUTPUT_PATH);

const kb = fs.statSync(OUTPUT_PATH).size / 1024;
console.log(`\n  Built ${OUTPUT_NAME}  (${kb.toFixed(1)} KB)  [${files.length} files]`);
