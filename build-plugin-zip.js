#!/usr/bin/env node
/**
 * Build script: creates onukonu-pet-boarding-core-v2.0.0.zip
 * from the plugin/ directory, excluding dev-only files.
 *
 * Usage: node build-plugin-zip.js
 */

'use strict';

const { create: archiver } = require('archiver');
const fs       = require('fs');
const path     = require('path');

const PLUGIN_DIR  = path.resolve(__dirname, 'plugin');
const VERSION     = '2.0.0';
const OUTPUT_NAME = `onukonu-pet-boarding-core-v${VERSION}.zip`;
const OUTPUT_PATH = path.resolve(__dirname, OUTPUT_NAME);

// Paths inside plugin/ to exclude from the ZIP
const EXCLUDE_GLOBS = [
  // Dev artefacts
  'tests/**',
  'appcomposer.json',
  // Composer dev tools (keep vendor runtime, exclude scripts dir)
  'vendor/bin/**',
  'vendor/composer/installed.json', // optional, safe to keep or drop
  // OS noise
  '**/.DS_Store',
  '**/Thumbs.db',
  // Editor config
  '**/.editorconfig',
  '**/.gitignore',
  '**/.gitattributes',
];

if (fs.existsSync(OUTPUT_PATH)) {
  fs.unlinkSync(OUTPUT_PATH);
  console.log(`Removed existing ${OUTPUT_NAME}`);
}

const output  = fs.createWriteStream(OUTPUT_PATH);
const archive = archiver('zip', { zlib: { level: 9 } });

output.on('close', () => {
  const kb = (archive.pointer() / 1024).toFixed(1);
  console.log(`\n✅  Built ${OUTPUT_NAME}  (${kb} KB)`);
});

archive.on('warning', (err) => {
  if (err.code === 'ENOENT') {
    console.warn('[warn]', err.message);
  } else {
    throw err;
  }
});

archive.on('error', (err) => { throw err; });

archive.pipe(output);

// Add everything inside plugin/ under the folder name "onukonu-pet-boarding-core/"
archive.glob('**', {
  cwd:    PLUGIN_DIR,
  dot:    false,
  ignore: EXCLUDE_GLOBS,
}, { prefix: 'onukonu-pet-boarding-core' });

archive.finalize();
