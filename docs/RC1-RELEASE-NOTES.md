# Onukonu Pet Boarding Core — RC1 Release Notes

**Product:** Onukonu Pet Boarding Core (OPB)  
**Release:** RC1  
**Source base:** v3.1.0  
**Plugin slug:** `onukonu-pet-boarding-core`  
**Plugin version constant:** `OPB_VERSION = '3.1.0'`  
**Release date:** 2026-06-19  
**PHP requirement:** 8.2+  
**WordPress requirement:** 6.4+  
**MySQL requirement:** 5.7+ (Hostinger shared hosting compatible)  
**ZIP:** `onukonu-pet-boarding-rc1.zip`

---

## What Is RC1

RC1 (Release Candidate 1) is the first formally audited, documentation-aligned, production-packaged release of Onukonu Pet Boarding Core.

This release does not introduce new features. It establishes:
- A verified repository baseline
- Canonical product architecture documentation
- Aligned branding (OPB as product, OPSMAIL/SAL/Telegram/Gemini as subsystems)
- A clean, reproducible build process
- Complete deployment instructions

---

## Product Architecture

```
OPB Core
│
├── Dashboard
├── Clients
├── Pets
├── Bookings
├── Boarding
├── Occupancy
├── Invoices
├── Payments
├── Expenses
├── Tasks
├── Documents
├── Users
└── Reports

Operational Layer
│
├── OPSMAIL
├── Queue
├── Telegram
├── Gemini
├── SAL
└── Scheduler
```

---

## Feature State at RC1

### Core Modules (all operational)

| Module | Status |
|---|---|
| Dashboard | ✅ Occupancy, check-ins/outs, task summary, revenue |
| Clients | ✅ CRUD, branch-scoped, client portal with Email OTP |
| Pets | ✅ CRUD, boarding history |
| Bookings | ✅ Create, check-in, check-out, kennel assignment |
| Boarding | ✅ Catalogue management, add-ons, pricing engine |
| Occupancy | ✅ Grid board and linear timeline views |
| Invoices | ✅ Auto-generation, PDF (mPDF), email delivery, audit trail |
| Payments | ✅ Payment recording against invoices |
| Expenses | ✅ Recording with category management |
| Tasks | ✅ Create, assign, complete |
| Documents | ✅ Client onboarding documents, public invoice portal |
| Users | ✅ Role assignment, branch scoping |
| Reports | ✅ Financial summaries, occupancy, business metrics |
| Import | ✅ XLSX/CSV legacy data migration |

### Operational Subsystems (all operational)

| Subsystem | Status |
|---|---|
| OPSMAIL queue | ✅ Event capture for 5+ business events |
| Telegram transport | ✅ Queue consumer, direct delivery, test endpoint |
| Gemini integration | ✅ Classification, formatting, Gemini Lab UI |
| SAL Morning Brief | ✅ Configurable time, factual, Gemini + fallback |
| SAL Evening Brief | ✅ Configurable time, factual, Gemini + fallback |
| SAL Accounts Snapshot | ✅ Configurable time, factual, Gemini + fallback |
| Scheduler | ✅ WP Cron + external cron support |
| Cron health monitoring | ✅ Per-component health, external cron detection |

---

## Roles

| Role | Capabilities |
|---|---|
| OPB Super Admin | Full access, all branches |
| OPB Branch Manager | Operational access, assigned branch |
| OPB Reception | Bookings, clients, invoices, payments, tasks |
| OPB Staff | Tasks only |

---

## Known Limitations

1. **WP-Cron dependency** — Brief and queue delivery fires on WP-Cron triggers. Low-traffic sites should configure an external server cron (see Deployment Instructions).
2. **SAL brief history not auto-pruned** — History table grows indefinitely. Pruning planned for a future release.
3. **Single SAL Telegram destination** — All briefs go to one chat ID. Per-brief routing not yet supported.
4. **Gemini rate limits** — Three simultaneous brief generation calls in the same cron tick if all briefs share the same configured hour. Each has an independent fallback.

---

## Database Baseline at RC1

All tables created by `OPB_Activator::activate()` or `opb_maybe_create_tables()`:

| Table | Notes |
|---|---|
| `opb_branches` | |
| `opb_clients` | |
| `opb_pets` | |
| `opb_kennels` | |
| `opb_bookings` | Includes `status` column (added v2.6.0) |
| `opb_stays` | |
| `opb_booking_addons` | |
| `opb_boarding_catalogue` | |
| `opb_addons_catalogue` | |
| `opb_invoices` | |
| `opb_invoice_items` | |
| `opb_invoice_audit` | |
| `opb_payments` | |
| `opb_expenses` | |
| `opb_expense_categories` | |
| `opb_tasks` | |
| `opb_customizations` | |
| `opb_client_sessions` | |
| `opb_client_otp_tokens` | |
| `opb_client_inquiries` | |
| `opb_opsmail_queue` | `source_system` column: `OPSMAIL` / `SAL` |
| `opb_sal_brief_history` | Added v3.1.0 |

---

## RC1 Audit Summary

All 13 audit phases completed:

| Phase | Result |
|---|---|
| Repository audit | ✅ Clean, up to date with origin |
| Branding audit | ✅ OPB is product; OPSMAIL/SAL are subsystems |
| Architecture audit | ✅ Canonical reference produced |
| Role and scope audit | ✅ 4 roles, branch scoping verified |
| Build system audit | ✅ TypeScript clean, Vite build passes, 114 modules |
| WordPress integration audit | ✅ Activation/deactivation/upgrade/cron/REST verified |
| OPSMAIL audit | ✅ All components operational |
| SAL audit | ✅ All brief types operational, factual-only confirmed |
| Cron reliability | ✅ External cron support documented and implemented |
| Documentation alignment | ✅ All docs updated, canonical architecture published |
| RC1 build | ✅ `onukonu-pet-boarding-rc1.zip` produced |
| Release validation | ✅ Asset integrity verified, no missing files |
| Deliverables | ✅ All 10 deliverables produced |
