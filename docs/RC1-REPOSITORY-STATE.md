# OPB RC1 — Repository State Report

**Generated:** 2026-06-19  
**Phase:** 1 — Repository Audit

---

## 1. Repository Identity

| Field | Value |
|---|---|
| Branch | `main` |
| HEAD Commit | `697be4c` |
| Last Commit Message | `Add external cron support for reliable OPSMAIL scheduling` |
| Remote | `origin/main` |
| Ahead/Behind | Up to date (0 ahead, 0 behind) |

---

## 2. Working Tree Status

**Status: CLEAN**

- No uncommitted changes
- No untracked files
- No staged modifications
- Working tree matches HEAD exactly

---

## 3. Recent Commit History

Only one commit is visible (shallow clone / grafted):

| Hash | Message |
|---|---|
| `697be4c` | Add external cron support for reliable OPSMAIL scheduling |

---

## 4. Source of Truth Verification

The GitHub repository (`origin/main`) is the authoritative source.

| Source | Status |
|---|---|
| GitHub repository HEAD | ✅ Authoritative |
| Checked-out branch (`main`) | ✅ Matches origin |
| Committed source code | ✅ Clean working tree |
| Compiled production assets | ✅ Present in `plugin/assets/dist/` |
| Local working directory | ✅ No divergence |
| Running Node/Vite dev server | ℹ️ Documentation viewer only (not the plugin) |

---

## 5. Identified Artefacts

### Historical ZIP files (root directory)
The repository root contains a large collection of historical release ZIPs from v1.0.0 through v3.1.0. These are build artefacts, not source code, and are excluded from the RC1 ZIP.

Notable issue: Two ZIPs named with OPSMAIL branding exist in root:
- `opsmail-production-v3.1.0.zip`
- `opsmail-production-v3.2.0.zip`

These are pre-RC1 build artefacts and do not represent the product identity. They are excluded from RC1.

### Build scripts
- `build-plugin-zip.js` — Primary build script, VERSION=3.1.0, output: `onukonu-pet-boarding-core-v3.1.0.zip`
- `build-opsmail-production.js` — Legacy OPSMAIL-branded build script, VERSION=3.2.0 (version drift noted — see Build Audit)

---

## 6. Local-Only Modifications

None. Working tree is clean.

---

## 7. Untracked Files

None.

---

## 8. Experimental / Temporary Code

None identified in committed source.

---

## Conclusion

The repository is in a clean, consistent state. The working tree matches origin/main exactly. The authoritative source code is committed and ready for RC1 packaging.
