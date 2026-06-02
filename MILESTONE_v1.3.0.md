# Milestone: Operational Portal Pre-Beta

**Branch:** `milestone/operational-portal-prebeta`
**Tag:** `v1.3.0-operational-portal`
**Commit:** `b6405300eb06fc90bfaff78c23272dcb46988160`
**Date:** 2026-06-02

---

## Summary

First fully operational portal milestone. The migration engine, core data model, and all primary workflows are functional. The system is running live with real production data.

---

## Validated Functionality

| Feature | Status |
|---------|--------|
| Migration engine | Operational |
| Clients imported | Operational |
| Pets imported | Operational |
| Bookings imported | Operational |
| Invoices imported | Operational |
| Payments imported | Operational |
| Expenses imported | Operational |
| Services imported | Operational |
| Add-ons imported | Operational |
| Dashboard | Functional |
| Reports | Functional |
| Check-in workflow | Functional |
| Check-out workflow | Functional |
| Invoice generation | Functional |
| Payment recording | Functional |
| Portal route | Functional |
| Mobile card layouts | Functional |

---

## Production Database State at Milestone

| Entity | Count |
|--------|------:|
| Branches | 3 |
| Clients | 685 |
| Pets | 685 |
| Bookings | 1,914 |
| Invoices | 1,895 |
| Payments | 1,710 |
| Expenses | 437 |
| Services | 54 |
| Add-ons | 17 |

---

## Known Incomplete — Deferred to Next Milestone

- Kennel model
- Kennel inventory
- Kennel occupancy engine
- Kennel assignment workflow
- Role model validation
- Branch scoping validation
- Staff onboarding workflow
- Capability matrix
- PWA installability verification

---

## Next Major Milestone

**Kennels & Occupancy**

Covers the full kennel data model, inventory management, occupancy engine, and kennel assignment workflow integrated into the booking and check-in/check-out flows.

---

## Archived Artefacts

| Artefact | File |
|----------|------|
| Production ZIP | `onukonu-pet-boarding-core-MILESTONE-v1.3.0-operational-portal-prebeta.zip` |
| Built JS | `plugin/assets/dist/assets/index.js` |
| Built CSS | `plugin/assets/dist/assets/main.css` |

---

## Notable Fixes in This Milestone Cycle

- Mobile scroll regression fixed (`h-dvh` / `min-h-0` layout)
- Reports page crash fixed — `expenses_by_category.category` null guard at data-preparation layer
- SQL `GROUP BY` alias ambiguity resolved for expenses category and branch name queries
- `ExpCategory.category` and `RevBranch.branch` typed `string | null` to enforce null handling at compile time
- `ErrorBoundary` component added as last-resort render safeguard
