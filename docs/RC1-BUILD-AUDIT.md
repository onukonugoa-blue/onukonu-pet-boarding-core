# OPB RC1 — Build Audit Report

**Generated:** 2026-06-19  
**Phase:** 5 — Build System Audit

---

## 1. Build System Overview

| Component | Tool | Status |
|---|---|---|
| TypeScript compilation | `tsc` (TypeScript 5.9.3) | ✅ |
| Frontend bundler | Vite 5.4.1 | ✅ |
| CSS processor | Tailwind CSS 3.4.10 + PostCSS | ✅ |
| PHP dependency manager | Composer | ✅ (vendor/ committed) |
| Plugin ZIP builder | `build-plugin-zip.js` (adm-zip) | ✅ |
| RC1 ZIP builder | `build-rc1.js` (adm-zip) | ✅ (created for RC1) |

---

## 2. React Build Result

**Command:** `cd plugin/app && npm run build` (tsc && vite build)

```
vite v5.4.21 building for production...
✓ 114 modules transformed.
../assets/dist/.vite/manifest.json    0.17 kB │ gzip:   0.12 kB
../assets/dist/assets/main.css       56.39 kB │ gzip:   8.58 kB
../assets/dist/assets/index.js      487.07 kB │ gzip: 125.88 kB
✓ built in 4.88s
```

**TypeScript:** No errors  
**Vite:** No warnings  
**Status:** ✅ PASS

---

## 3. Compiled Asset Inventory

| File | Size | Gzip |
|---|---|---|
| `plugin/assets/dist/assets/index.js` | 487.07 kB | 125.88 kB |
| `plugin/assets/dist/assets/main.css` | 56.39 kB | 8.58 kB |
| `plugin/assets/dist/.vite/manifest.json` | 0.17 kB | 0.12 kB |

---

## 4. Vite Configuration

**File:** `plugin/app/vite.config.ts`

| Setting | Value | Notes |
|---|---|---|
| `base` | `./` | Relative — correct for WordPress asset enqueuing |
| `outDir` | `../assets/dist` | Output lands in `plugin/assets/dist/` ✅ |
| `emptyOutDir` | `true` | Cleans stale assets on rebuild ✅ |
| `manifest` | `true` | Generates `.vite/manifest.json` for PHP enqueuing ✅ |
| `entryFileNames` | `assets/index.js` | Deterministic filename (no hash) ✅ |
| `assetFileNames` | `assets/[name][extname]` | Deterministic filenames ✅ |

Deterministic output filenames are correct — WordPress enqueues specific file paths, so hash-suffixed filenames would break enqueuing.

---

## 5. PHP Dependencies

Composer vendor directory committed at `plugin/vendor/`. No `composer install` required on deployment.

Key dependency: `mpdf/mpdf` — PDF generation for invoices.

---

## 6. Package.json Analysis

### Root (`package.json`)

| Dependency | Version | Purpose |
|---|---|---|
| `marked` | ^12.0.0 | Documentation viewer markdown rendering |
| `adm-zip` (dev) | ^0.5.17 | ZIP builder |
| `archiver` (dev) | ^8.0.0 | Alternative ZIP (unused in primary build) |
| `jimp` (dev) | ^0.22.12 | Icon generation (PWA) |

### React App (`plugin/app/package.json`)

| Dependency | Version |
|---|---|
| `react` | ^18.3.1 |
| `react-dom` | ^18.3.1 |
| `react-router-dom` | ^6.26.1 |
| `zustand` | ^4.5.4 |

All devDependencies are compile-time only and absent from the production ZIP.

---

## 7. Build Script Analysis

### `build-plugin-zip.js` (primary)
- **VERSION:** `3.1.0`
- **Output:** `onukonu-pet-boarding-core-v3.1.0.zip`
- **Exclusions:** `tests/`, `app/`, `vendor/bin/`, `.git/`, dev config files
- **Status:** ✅ Correct, OPB-branded

### `build-opsmail-production.js` (legacy)
- **VERSION:** `3.2.0` ⚠️ Version drift vs plugin 3.1.0
- **Output:** `opsmail-production-v3.2.0.zip` ❌ OPSMAIL-branded product label
- **Status:** ⚠️ Legacy script, OPSMAIL-branded, version drift — replaced by `build-rc1.js` for RC1

### `build-rc1.js` (RC1)
- **Output:** `onukonu-pet-boarding-rc1.zip`
- **Status:** ✅ Created for RC1, OPB-branded

---

## 8. Missing Assets

None. All required assets present:
- `plugin/assets/dist/assets/index.js` ✅
- `plugin/assets/dist/assets/main.css` ✅
- `plugin/assets/dist/.vite/manifest.json` ✅
- `plugin/assets/branding/` ✅
- `plugin/assets/icons/` ✅
- `plugin/assets/manifest.json` ✅
- `plugin/assets/sw.js` ✅

---

## 9. Broken Imports

None detected. TypeScript compilation succeeded with 0 errors across 114 modules.

---

## 10. Build Warnings

None.

---

## 11. No Node.js Runtime Dependency in Production

Confirmed. The production ZIP contains only:
- Compiled PHP source
- Compiled JS/CSS assets (`assets/dist/`)
- Composer vendor (mPDF)
- Static assets (icons, manifest, service worker)

WordPress production installation requires no Node.js, npm, tsc, or Vite.

---

## Conclusion

The build system is fully functional. TypeScript compiles cleanly, Vite produces deterministic assets, and the PHP Composer vendor is committed. A fresh developer with Node.js installed can reproduce the compiled assets with two commands:

```bash
cd plugin/app
npm install && npm run build
```
