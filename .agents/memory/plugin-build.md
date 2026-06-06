---
name: Plugin build process
description: How to build the React frontend and package the plugin ZIP
---

## Frontend build
```bash
cd plugin/app
npm install              # only needed once or after dependency changes
./node_modules/.bin/tsc  # type-check (no emit)
./node_modules/.bin/vite build  # outputs to plugin/assets/dist/
```

**Why `./node_modules/.bin/` prefix**: `tsc` is not in PATH globally; `npx tsc` installs the wrong package. Always use the local binary path.

Output: `plugin/assets/dist/assets/index.js` (~390 kB) + `main.css` (~49 kB).

## ZIP packaging

`zip` binary is NOT available in the Nix/Replit environment. Node.js `archiver` npm package also has a non-standard export shape (object with named classes, not a factory function) — do not use it.

**Use Python's `zipfile` module instead.** See `build-plugin-zip.js` at the repo root (it contains the Python script inline).

**Critical gotcha:** Composer vendor files (including mPDF) often carry pre-1980 timestamps. Python `zipfile.write()` will throw `ValueError: ZIP does not support timestamps before 1980`. Fix: construct `ZipInfo` manually and clamp `date_time` fields so `year >= 1980` and `month/day >= 1`.

```python
zi = zipfile.ZipInfo(arcname)
dt = time.localtime(os.path.getmtime(full))
zi.date_time = (max(dt.tm_year, 1980), max(dt.tm_mon, 1), max(dt.tm_mday, 1),
                dt.tm_hour, dt.tm_min, dt.tm_sec)
zi.compress_type = zipfile.ZIP_DEFLATED
zi.external_attr = (os.stat(full).st_mode & 0xFFFF) << 16
with open(full, 'rb') as fh: zf.writestr(zi, fh.read())
```

**Expected ZIP size for v2.0.0:** ~46 MB / 808 files — mPDF ships full font sets, this is normal.

## Exclusions from ZIP
- `plugin/tests/`
- `plugin/appcomposer.json`
- `plugin/app/node_modules/`, `plugin/app/src/`, `plugin/app/public/`, `plugin/app/package*.json`, `vite.config.ts`, `tsconfig*.json`
- `plugin/vendor/bin/`
- Dot-files, `.DS_Store`, `Thumbs.db`

## Composer (mPDF)
- Installed at `plugin/vendor/` via `composer require mpdf/mpdf` run inside `plugin/`
- `require_once OPB_PLUGIN_DIR . 'vendor/autoload.php'` must be the **first** require in the main plugin file — before all includes

## Version naming convention
`onukonu-pet-boarding-core-v{VERSION}.zip`
