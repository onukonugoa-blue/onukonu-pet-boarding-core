---
name: Plugin build process
description: How to build the React frontend and package the plugin ZIP
---

## Frontend build
```bash
cd plugin/app
npm install              # only needed once or after dependency changes
npx tsc --noEmit         # type-check
npx vite build           # outputs to plugin/assets/dist/
```

Output: `plugin/assets/dist/assets/index.js` (~400 kB) + `main.css` (~50 kB).

## ZIP packaging

**Python3 segfaults in this Replit/Nix environment** — do NOT use Python for ZIP creation.
`zip` binary is also NOT available. Node.js `archiver` npm package has a non-standard export shape.

**Use `adm-zip` npm package instead.** See `build-plugin-zip.js` at the repo root.

```bash
node build-plugin-zip.js
```

`adm-zip` 0.5.x API: `zip.addFile(entryName, buffer)` — no date override needed; uses current timestamp which is acceptable for deployment.

**Note on pre-1980 Composer timestamps:** adm-zip silently uses current date when mtime is invalid, so no manual clamping required (unlike the Python approach).

**Expected ZIP size for v2.1.0:** ~46 MB / 735 files — mPDF ships full font sets, this is normal.

## VERSION bump checklist (every release)
1. `plugin/onukonu-pet-boarding-core.php` — Plugin header `Version:` comment
2. `plugin/onukonu-pet-boarding-core.php` — `define( 'OPB_VERSION', '...' )`
3. `build-plugin-zip.js` — `const VERSION = '...'`

## Exclusions from ZIP
- `plugin/app/` — React source (`assets/dist/` compiled output IS included)
- `plugin/tests/`, `plugin/vendor/bin/`
- Dotfiles/dotdirs (except `assets/dist/.vite/`)
- `package.json`, `composer.json`, `composer.lock`, `tsconfig.json`, `vite.config.ts`, etc.

## Composer (mPDF)
- Installed at `plugin/vendor/` via `composer require mpdf/mpdf` inside `plugin/`
- `require_once OPB_PLUGIN_DIR . 'vendor/autoload.php'` must be the **first** require in the main plugin file
