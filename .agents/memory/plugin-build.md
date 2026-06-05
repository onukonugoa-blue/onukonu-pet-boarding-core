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
`zip` binary is not available in the Nix environment. Use the Node.js ZIP creator code-execution script (raw ZIP format with DEFLATE via node:zlib).

Exclude from ZIP:
- `plugin/app/node_modules/`
- `plugin/app/src/`
- `plugin/app/public/`
- `plugin/app/package*.json`, `vite.config.ts`, `tsconfig*.json`, `postcss.config.js`, `tailwind.config.js`, `index.html`

**Version naming convention**: `onukonu-pet-boarding-core-v{VERSION}-{SHORT_COMMIT}.zip`
