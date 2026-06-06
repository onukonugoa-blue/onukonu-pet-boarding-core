#!/usr/bin/env node
/**
 * Build script: creates onukonu-pet-boarding-core-v2.0.0.zip
 * Delegates to Python for ZIP creation — normalises pre-1980 timestamps
 * from Composer vendor files so the archive is always valid.
 *
 * Usage: node build-plugin-zip.js
 */

'use strict';

const { execSync } = require('child_process');
const fs   = require('fs');
const path = require('path');
const os   = require('os');

const VERSION     = '2.0.0';
const OUTPUT_NAME = `onukonu-pet-boarding-core-v${VERSION}.zip`;
const OUTPUT_PATH = path.resolve(__dirname, OUTPUT_NAME);
const PLUGIN_DIR  = path.resolve(__dirname, 'plugin');

if (fs.existsSync(OUTPUT_PATH)) {
  fs.unlinkSync(OUTPUT_PATH);
  console.log(`Removed existing ${OUTPUT_NAME}`);
}

// Write a temp Python file to avoid shell escaping nightmares
const tmpPy = path.join(os.tmpdir(), 'opb_build.py');

const pyCode = `import zipfile, os

PLUGIN_DIR = ${JSON.stringify(PLUGIN_DIR)}
OUTPUT     = ${JSON.stringify(OUTPUT_PATH)}
PREFIX     = 'onukonu-pet-boarding-core'

EXCLUDE_PREFIXES = ['tests/', 'app/', 'vendor/bin/', '.git/']
EXCLUDE_NAMES    = {
    'appcomposer.json', 'package.json', 'package-lock.json',
    'tsconfig.json', 'vite.config.ts', '.DS_Store', 'Thumbs.db',
    '.editorconfig', '.gitignore', '.gitattributes',
}
MIN_DATE = (1980, 1, 1, 0, 0, 0)

def exclude(rel):
    name = os.path.basename(rel)
    if name in EXCLUDE_NAMES:
        return True
    norm = rel.replace(os.sep, '/')
    return any(norm.startswith(p) for p in EXCLUDE_PREFIXES)

import time

count = 0
with zipfile.ZipFile(OUTPUT, 'w', zipfile.ZIP_DEFLATED, compresslevel=9) as zf:
    for root, dirs, files in os.walk(PLUGIN_DIR):
        dirs[:] = [d for d in sorted(dirs) if not d.startswith('.')]
        for fname in sorted(files):
            if fname.startswith('.'):
                continue
            abs_path = os.path.join(root, fname)
            rel      = os.path.relpath(abs_path, PLUGIN_DIR)
            if exclude(rel):
                continue
            arcname = os.path.join(PREFIX, rel)
            # Get file mtime and clamp to ZIP minimum (1980-01-01)
            mtime   = os.path.getmtime(abs_path)
            tup     = time.localtime(mtime)
            dt      = tup[:6]  # (year, month, day, hour, min, sec)
            if dt < MIN_DATE:
                dt = MIN_DATE
            info = zipfile.ZipInfo(arcname, date_time=dt)
            info.compress_type = zipfile.ZIP_DEFLATED
            with open(abs_path, 'rb') as fh:
                zf.writestr(info, fh.read())
            count += 1

kb = os.path.getsize(OUTPUT) / 1024
print(f"\\n  Built {os.path.basename(OUTPUT)}  ({kb:.1f} KB)  [{count} files]")
`;

fs.writeFileSync(tmpPy, pyCode, 'utf8');

try {
  execSync(`python3 "${tmpPy}"`, { stdio: 'inherit' });
} catch (e) {
  console.error('ZIP build failed:', e.message);
  process.exit(1);
} finally {
  try { fs.unlinkSync(tmpPy); } catch {}
}
