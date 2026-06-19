# OPB RC1 — Branding Alignment Report

**Generated:** 2026-06-19  
**Phase:** 2 — Product Identity Audit

---

## 1. Product Identity

| Field | Correct Value |
|---|---|
| Product Name | Onukonu Pet Boarding |
| Short Name | OPB |
| Plugin Slug | `onukonu-pet-boarding-core` |
| Text Domain | `opb` |

**OPSMAIL, SAL, Telegram, and Gemini are internal operational subsystems — not product names.**

---

## 2. Plugin Header

**File:** `plugin/onukonu-pet-boarding-core.php`

| Field | Value | Status |
|---|---|---|
| Plugin Name | Onukonu Pet Boarding Core | ✅ Correct |
| Description | Replacement platform for the discontinued boarding SaaS… | ✅ Correct |
| Version | 3.1.0 | ✅ Correct |
| Text Domain | opb | ✅ Correct |

---

## 3. Documentation Branding

| File | Status | Issue |
|---|---|---|
| `docs/ARCHITECTURE.md` | ✅ | "Onukonu Pet Boarding Core — Plugin Architecture" |
| `docs/ANALYSIS.md` | ✅ | "Onukonu Pet Homestyle Boarding — System Analysis" |
| `docs/AUDIT-REPORT-v3.1.0.md` | ✅ | "OPB v3.1.0 — Pre-Release Audit Report" |
| `docs/RELEASE-NOTES-v3.1.0.md` | ⚠️ | Title: "OPB / OPSMAIL Production Release — v3.1.0"; Label: `opsmail-production-v3.1.0` |
| `RELEASE_NOTES.md` | ✅ | "OPB Release Notes" |
| `CHANGELOG.md` | ✅ (header) | "CHANGELOG.md — Onukonu Pet Boarding Core" — OPSMAIL referenced correctly as subsystem within entries |

---

## 4. Build Script Branding

| File | Status | Issue |
|---|---|---|
| `build-plugin-zip.js` | ✅ | Outputs `onukonu-pet-boarding-core-v3.1.0.zip` |
| `build-opsmail-production.js` | ⚠️ | OPSMAIL-branded build script, VERSION=3.2.0 (version drift vs plugin 3.1.0) |

---

## 5. ZIP Naming

| ZIP | Status |
|---|---|
| `onukonu-pet-boarding-core-v3.1.0.zip` | ✅ Correct naming convention |
| `opsmail-production-v3.1.0.zip` | ❌ Incorrect — OPSMAIL is a subsystem, not the product |
| `opsmail-production-v3.2.0.zip` | ❌ Incorrect — OPSMAIL branding + version drift |

---

## 6. React UI

The React frontend correctly uses OPSMAIL as a subsystem label:
- Sidebar entry: "OPSMAIL Queue" ✅ (subsystem, not product)
- Sidebar entry: "Gemini Lab" ✅ (subsystem, not product)
- Route: `/admin/opsmail` ✅ (internal admin path)
- Route: `/admin/sal` ✅ (internal admin path)

No product-identity drift found in the React UI.

---

## 7. RC1 Corrections Applied

| Item | Correction |
|---|---|
| `docs/RELEASE-NOTES-v3.1.0.md` | Title and release label corrected to OPB branding |
| RC1 ZIP name | `onukonu-pet-boarding-rc1.zip` |
| RC1 build script | `build-rc1.js` — OPB-branded, no OPSMAIL reference in script name |

---

## Conclusion

The product correctly identifies itself as **Onukonu Pet Boarding Core (OPB)** in all primary surfaces (plugin header, main docs, React UI). OPSMAIL is correctly positioned as a subsystem in code and most documentation. Two areas required correction: the `docs/RELEASE-NOTES-v3.1.0.md` title and the legacy `build-opsmail-production.js` script. Both are addressed in RC1.
