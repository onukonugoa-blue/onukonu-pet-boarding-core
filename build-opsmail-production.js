#!/usr/bin/env node
/**
 * OPSMAIL Production Build Script
 *
 * Produces: opsmail-production-v3.1.0.zip
 *
 * This script packages the Onukonu Pet Boarding Core plugin (which contains
 * the OPSMAIL Situational Awareness Layer) as a production-ready WordPress
 * plugin ZIP.
 *
 * Exclusions (beyond the standard build):
 *   - All development/test fixtures
 *   - Source maps
 *   - Build caches
 *   - Stub/adapter files not wired into production code paths
 *
 * Usage: node build-opsmail-production.js
 */

'use strict';

const AdmZip = require('adm-zip');
const fs     = require('fs');
const path   = require('path');

const VERSION     = '3.1.0';
const LABEL       = 'opsmail-production';
const OUTPUT_NAME = `${LABEL}-v${VERSION}.zip`;
const OUTPUT_PATH = path.resolve(__dirname, OUTPUT_NAME);
const PLUGIN_DIR  = path.resolve(__dirname, 'plugin');
const PREFIX      = 'onukonu-pet-boarding-core';

// ── Exclusion rules ────────────────────────────────────────────────────────────

const EXCLUDE_PREFIXES = [
  'tests/',
  'app/',
  'vendor/bin/',
  '.git/',
  '.github/',
  '.vscode/',
  'coverage/',
];

const EXCLUDE_NAMES = new Set([
  'appcomposer.json', 'package.json', 'package-lock.json',
  'tsconfig.json', 'vite.config.ts', '.DS_Store', 'Thumbs.db',
  '.editorconfig', '.gitignore', '.gitattributes',
  'build.sh', 'composer.json', 'composer.lock',
  // Dead / stub files — present in repo but never loaded by the plugin
  'class-opb-loader.php',
  'class-opb-woocommerce-adapter.php',
]);

const EXCLUDE_EXTENSIONS = new Set([
  '.map',    // source maps
  '.log',    // debug logs
  '.bak',    // backup files
  '.orig',   // patch originals
  '.swp',    // vim swap
]);

// Dot-prefixed directories that are explicitly allowed
const ALLOW_DOTDIRS = new Set(['assets/dist/.vite']);

function isExcluded(rel) {
  const name = path.basename(rel);
  const ext  = path.extname(name).toLowerCase();

  if (EXCLUDE_NAMES.has(name))       return true;
  if (EXCLUDE_EXTENSIONS.has(ext))   return true;

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

// ── Walk plugin directory recursively ─────────────────────────────────────────

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

// ── Build ──────────────────────────────────────────────────────────────────────

if (fs.existsSync(OUTPUT_PATH)) {
  fs.unlinkSync(OUTPUT_PATH);
  console.log(`Removed existing ${OUTPUT_NAME}`);
}

console.log(`\nCollecting files from ${PLUGIN_DIR}…`);
const files = collectFiles(PLUGIN_DIR, '');
const zip   = new AdmZip();

for (const { abs, rel } of files) {
  const arcEntry = (PREFIX + '/' + rel.split(path.sep).join('/')).replace(/\/+/g, '/');
  const buf = fs.readFileSync(abs);
  zip.addFile(arcEntry, buf);
}

zip.writeZip(OUTPUT_PATH);

const kb = fs.statSync(OUTPUT_PATH).size / 1024;
console.log(`\n  ✅  Built ${OUTPUT_NAME}  (${kb.toFixed(1)} KB)  [${files.length} files]\n`);

// ── Manifest summary ───────────────────────────────────────────────────────────

const byDir = {};
for (const { rel } of files) {
  const dir = rel.split(path.sep)[0] || '(root)';
  byDir[dir] = (byDir[dir] || 0) + 1;
}
console.log('  Files by top-level directory:');
for (const [dir, count] of Object.entries(byDir).sort()) {
  console.log(`    ${dir.padEnd(30)} ${count}`);
}
console.log('');
