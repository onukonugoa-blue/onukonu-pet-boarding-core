# OPB Permission Audit — Part 5: Module Permission Matrix

**Plugin Version:** 3.1.0  
**Audit Date:** June 2026  
**Scope:** Every OPB module — which roles can VIEW, CREATE, EDIT, DELETE, EXPORT, and ADMINISTER

---

## Legend

| Symbol | Meaning |
|---|---|
| ✅ | Permitted |
| ❌ | Not permitted |
| ⚠ | Permitted but inconsistency noted (see footnotes) |
| `pc` | Requires `permission_check` (any OPB role or WP admin, logged in) |
| `pm(cap)` | Requires `permission_manage(cap)` — specific capability or manage_options |

---

## Matrix — By Module

### 1. Branches

Branches are the top-level organisational units. Create/edit requires `opb_manage_settings`.

| Operation | Endpoint | Super Admin | Branch Manager | Reception | Staff | WP Admin |
|---|---|:---:|:---:|:---:|:---:|:---:|
| VIEW all branches | `GET /branches` | ✅ `pc` | ✅ `pc` | ✅ `pc` | ✅ `pc` | ✅ |
| VIEW single branch | `GET /branches/{id}` | ✅ `pc` | ✅ `pc` | ✅ `pc` | ✅ `pc` | ✅ |
| CREATE branch | `POST /branches` | ✅ | ❌ | ❌ | ❌ | ✅ |
| EDIT branch | `PUT /branches/{id}` | ✅ | ❌ | ❌ | ❌ | ✅ |
| DELETE branch | — | ❌ | ❌ | ❌ | ❌ | ❌ |
| ADMINISTER | Create/edit | ✅ | ❌ | ❌ | ❌ | ✅ |

> No hard-delete endpoint exists. Branch deactivation is managed via `is_active` flag through the edit endpoint.

---

### 2. Clients

| Operation | Endpoint | Super Admin | Branch Manager | Reception | Staff | WP Admin |
|---|---|:---:|:---:|:---:|:---:|:---:|
| VIEW list | `GET /clients` | ✅ `pc` | ✅ `pc` (own branch) | ✅ `pc` (own branch) | ✅ `pc` (own branch) | ✅ |
| VIEW single | `GET /clients/{id}` | ✅ `pc` | ✅ `pc` | ✅ `pc` | ✅ `pc` | ✅ |
| VIEW pets of client | `GET /clients/{id}/pets` | ✅ `pc` | ✅ `pc` | ✅ `pc` | ✅ `pc` | ✅ |
| VIEW bookings of client | `GET /clients/{id}/bookings` | ✅ `pc` | ✅ `pc` | ✅ `pc` | ✅ `pc` | ✅ |
| CREATE client | via Inquiries convert | ✅ | ✅ | ✅ | ❌ | ✅ |
| EDIT client | `PUT /clients/{id}` | ✅ | ✅ | ✅ | ❌ | ✅ |
| ARCHIVE client | `PUT /data-management/clients/{id}/archive` | ✅ | ❌ | ❌ | ❌ | ✅ |
| RESTORE client | `PUT /data-management/clients/{id}/restore` | ✅ | ❌ | ❌ | ❌ | ✅ |
| DELETE | — | ❌ | ❌ | ❌ | ❌ | ❌ |
| CREATE pet | `POST /clients/{id}/pets` | ✅ | ✅ | ✅ | ❌ | ✅ |

---

### 3. Pets

| Operation | Endpoint | Super Admin | Branch Manager | Reception | Staff | WP Admin |
|---|---|:---:|:---:|:---:|:---:|:---:|
| VIEW pet | `GET /pets/{id}` | ✅ `pc` | ✅ `pc` | ✅ `pc` | ✅ `pc` | ✅ |
| EDIT pet | `PUT /pets/{id}` | ✅ | ✅ | ✅ | ❌ | ✅ |
| VIEW documents | `GET /pets/{id}/documents` | ✅ `pc` | ✅ `pc` | ✅ `pc` | ✅ `pc` | ✅ |
| UPLOAD document | `POST /pets/{id}/documents` | ✅ | ✅ | ✅ | ❌ | ✅ |
| DELETE document | `DELETE /pets/{id}/documents/{doc_id}` | ✅ | ✅ | ✅ | ❌ | ✅ |
| ARCHIVE pet | `PUT /data-management/pets/{id}/archive` | ✅ | ❌ | ❌ | ❌ | ✅ |
| RESTORE pet | `PUT /data-management/pets/{id}/restore` | ✅ | ❌ | ❌ | ❌ | ✅ |

---

### 4. Bookings

| Operation | Endpoint | Super Admin | Branch Manager | Reception | Staff | WP Admin |
|---|---|:---:|:---:|:---:|:---:|:---:|
| VIEW list | `GET /bookings` | ✅ `pc` | ✅ `pc` (own branch) | ✅ `pc` (own branch) | ✅ `pc` (own branch) | ✅ |
| VIEW single | `GET /bookings/{id}` | ✅ `pc` | ✅ `pc` | ✅ `pc` | ✅ `pc` | ✅ |
| CREATE booking | `POST /bookings` | ✅ | ✅ | ✅ | ❌ | ✅ |
| EDIT booking | `PUT /bookings/{id}` | ✅ | ✅ | ✅ | ❌ | ✅ |
| CHECK IN | `POST /bookings/{id}/checkin` | ✅ | ✅ | ✅ | ❌ | ✅ |
| CHECK OUT | `POST /bookings/{id}/checkout` | ✅ | ✅ | ✅ | ❌ | ✅ |
| ADD ADDON | `POST /bookings/{id}/addons` | ✅ | ✅ | ✅ | ❌ | ✅ |
| REMOVE ADDON | `DELETE /bookings/{id}/addons` | ✅ | ✅ | ✅ | ❌ | ✅ |
| CANCEL booking | `PUT /data-management/bookings/{id}/cancel` | ✅ | ❌ | ❌ | ❌ | ✅ |
| RESTORE booking | `PUT /data-management/bookings/{id}/restore` | ✅ | ❌ | ❌ | ❌ | ✅ |
| DELETE | — | ❌ | ❌ | ❌ | ❌ | ❌ |

---

### 5. Boarding (Kennel Board)

| Operation | Endpoint | Super Admin | Branch Manager | Reception | Staff | WP Admin |
|---|---|:---:|:---:|:---:|:---:|:---:|
| VIEW kennel board | `GET /bookings/kennel-board` | ✅ `pc` | ✅ `pc` | ✅ `pc` | ✅ `pc` | ✅ |
| ASSIGN KENNEL | `POST /bookings/{id}/assign-kennel` | ✅ | ✅ | ✅ | ❌ | ✅ |

---

### 6. Kennels (Configuration)

| Operation | Endpoint | Super Admin | Branch Manager | Reception | Staff | WP Admin |
|---|---|:---:|:---:|:---:|:---:|:---:|
| VIEW list | `GET /kennels` | ✅ `pc` | ✅ `pc` | ✅ `pc` | ✅ `pc` | ✅ |
| VIEW staff options | `GET /kennels/staff-options` | ✅ `pc` | ✅ `pc` | ✅ `pc` | ✅ `pc` | ✅ |
| CREATE kennel | `POST /kennels` | ✅ | ❌ | ❌ | ❌ | ✅ |
| EDIT kennel | `PUT /kennels/{id}` | ✅ | ❌ | ❌ | ❌ | ✅ |
| DELETE kennel | `DELETE /kennels/{id}` | ✅ | ❌ | ❌ | ❌ | ✅ |
| ASSIGN STAFF | `POST /kennels/{id}/staff` | ✅ | ❌ | ❌ | ❌ | ✅ |
| REMOVE STAFF | `DELETE /kennels/{id}/staff` | ✅ | ❌ | ❌ | ❌ | ✅ |
| REORDER | `POST /kennels/reorder` | ✅ | ❌ | ❌ | ❌ | ✅ |

---

### 7. Invoices

| Operation | Endpoint | Super Admin | Branch Manager | Reception | Staff | WP Admin |
|---|---|:---:|:---:|:---:|:---:|:---:|
| VIEW list | `GET /invoices` | ✅ `pc` | ✅ `pc` (own branch) | ✅ `pc` (own branch) | ✅ `pc` (own branch) | ✅ |
| VIEW single | `GET /invoices/{id}` | ✅ `pc` | ✅ `pc` | ✅ `pc` | ✅ `pc` | ✅ |
| ADJUST invoice | `PUT /invoices/{id}/adjust` | ✅ | ✅ | ✅ | ❌ | ✅ |
| RECORD PAYMENT | `POST /invoices/{id}/payment` | ✅ | ✅ | ✅ | ❌ | ✅ |
| GENERATE PDF | `POST /invoice-delivery/{id}/generate` | ✅ | ✅ | ✅ | ❌ | ✅ |
| SEND via email | `POST /invoice-delivery/{id}/send` | ✅ | ✅ | ✅ | ❌ | ✅ |
| VIEW public summary | `GET /invoice-delivery/{id}/public` | ✅ `pc` | ✅ `pc` | ✅ `pc` | ✅ `pc` | ✅ |
| VIEW audit trail | `GET /invoice-delivery/{id}/audit` | ✅ `pc` | ✅ `pc` | ✅ `pc` | ✅ `pc` | ✅ |

---

### 8. Payments

| Operation | Endpoint | Super Admin | Branch Manager | Reception | Staff | WP Admin |
|---|---|:---:|:---:|:---:|:---:|:---:|
| VIEW list | `GET /payments` | ✅ `pc` | ✅ `pc` (own branch) | ✅ `pc` (own branch) | ✅ `pc` (own branch) | ✅ |
| DELETE payment | `DELETE /payments/{id}` | ✅ | ✅ | ✅ | ❌ | ✅ |
| CREATE payment | via `POST /invoices/{id}/payment` | ✅ | ✅ | ✅ | ❌ | ✅ |

---

### 9. Expenses

| Operation | Endpoint | Super Admin | Branch Manager | Reception | Staff | WP Admin |
|---|---|:---:|:---:|:---:|:---:|:---:|
| VIEW list | `GET /expenses` | ✅ `pc` | ✅ `pc` (own branch) | ✅ `pc` (own branch) | ✅ `pc` (own branch) | ✅ |
| VIEW categories | `GET /expenses/categories` | ✅ `pc` | ✅ `pc` | ✅ `pc` | ✅ `pc` | ✅ |
| VIEW single expense | `GET /expenses/{id}` | ✅ `pc` | ✅ `pc` | ✅ `pc` | ✅ `pc` | ✅ |
| CREATE expense | `POST /expenses` | ✅ | ✅ | ❌ | ❌ | ✅ |
| DELETE expense | `DELETE /expenses/{id}` | ✅ | ✅ | ❌ | ❌ | ✅ |
| ADMINISTER categories | `POST/PUT/DELETE /expense-categories` | ✅ | ❌ | ❌ | ❌ | ✅ |

> ⚠ **Note:** `opb_reception` can VIEW the expenses list (permission_check passes), but cannot CREATE or DELETE expenses. The read and write gates are split.

---

### 10. Tasks

| Operation | Endpoint | Super Admin | Branch Manager | Reception | Staff | WP Admin |
|---|---|:---:|:---:|:---:|:---:|:---:|
| VIEW list | `GET /tasks` | ✅ `pc` | ✅ `pc` (own branch) | ✅ `pc` (own branch) | ✅ `pc` (own branch) | ✅ |
| VIEW single | `GET /tasks/{id}` | ✅ `pc` | ✅ `pc` | ✅ `pc` | ✅ `pc` | ✅ |
| CREATE task | `POST /tasks` | ✅ | ✅ | ✅ | ✅ | ✅ |
| EDIT task | `PUT /tasks/{id}` | ✅ | ✅ | ✅ | ✅ | ✅ |
| DELETE task | `DELETE /tasks/{id}` | ✅ | ✅ | ✅ | ✅ | ✅ |

---

### 11. Documents (Pet Documents)

Covered under Pets — see Section 3. OPB has no standalone Documents module; documents are always attached to pet records.

---

### 12. Reports

| Operation | Endpoint | Super Admin | Branch Manager | Reception | Staff | WP Admin |
|---|---|:---:|:---:|:---:|:---:|:---:|
| VIEW report | `GET /reports` | ✅ | ✅ | ❌ | ❌ | ✅ |

> `permission_callback` uses `permission_manage('opb_view_reports')`. `opb_reception` and `opb_staff` receive HTTP 403.

---

### 13. Inquiries / Onboarding

| Operation | Endpoint | Super Admin | Branch Manager | Reception | Staff | WP Admin |
|---|---|:---:|:---:|:---:|:---:|:---:|
| VIEW list | `GET /inquiries` | ✅ `pc` | ✅ `pc` | ✅ `pc` | ✅ `pc` | ✅ |
| VIEW single | `GET /inquiries/{id}` | ✅ `pc` | ✅ `pc` | ✅ `pc` | ✅ `pc` | ✅ |
| UPDATE inquiry | `PUT /inquiries/{id}` | ✅ | ✅ | ✅ | ❌ | ✅ |
| ADD NOTE | `POST /inquiries/{id}/notes` | ✅ `pc` | ✅ `pc` | ✅ `pc` | ✅ `pc` | ✅ |
| SEND onboarding link | `POST /inquiries/{id}/send-onboarding` | ✅ | ✅ | ✅ | ❌ | ✅ |
| RESEND onboarding link | `POST /inquiries/{id}/resend-onboarding` | ✅ | ✅ | ✅ | ❌ | ✅ |
| REJECT inquiry | `POST /inquiries/{id}/reject` | ✅ | ✅ | ✅ | ❌ | ✅ |
| ARCHIVE inquiry | `POST /inquiries/{id}/archive` | ✅ | ✅ | ✅ | ❌ | ✅ |
| RESTORE inquiry | `PUT /data-management/inquiries/{id}/restore` | ✅ | ❌ | ❌ | ❌ | ✅ |
| CONVERT to client | `POST /inquiries/{id}/convert` | ✅ | ✅ | ✅ | ❌ | ✅ |
| DUPLICATE CHECK | `GET /inquiries/{id}/duplicate-check` | ✅ `pc` | ✅ `pc` | ✅ `pc` | ✅ `pc` | ✅ |

---

### 14. Settings (Boarding Catalogue & Addon Services)

| Operation | Endpoint | Super Admin | Branch Manager | Reception | Staff | WP Admin |
|---|---|:---:|:---:|:---:|:---:|:---:|
| VIEW boarding services | `GET /settings/boarding` | ✅ `pc` | ✅ `pc` | ✅ `pc` | ✅ `pc` | ✅ |
| CREATE boarding service | `POST /settings/boarding` | ✅ | ❌ | ❌ | ❌ | ✅ |
| EDIT boarding service | `PUT/DELETE /settings/boarding/{id}` | ✅ | ❌ | ❌ | ❌ | ✅ |
| VIEW addon services | `GET /settings/addons` | ✅ `pc` | ✅ `pc` | ✅ `pc` | ✅ `pc` | ✅ |
| CREATE addon service | `POST /settings/addons` | ✅ | ❌ | ❌ | ❌ | ✅ |
| EDIT addon service | `PUT/DELETE /settings/addons/{id}` | ✅ | ❌ | ❌ | ❌ | ✅ |
| VIEW staff | `GET /settings/staff` | ✅ | ❌ | ❌ | ❌ | ✅ |
| EDIT staff | `PUT /settings/staff/{id}` | ✅ | ❌ | ❌ | ❌ | ✅ |

---

### 15. Customizations

| Operation | Endpoint | Super Admin | Branch Manager | Reception | Staff | WP Admin |
|---|---|:---:|:---:|:---:|:---:|:---:|
| VIEW customization keys | `GET /customizations` | ✅ `pc` | ✅ `pc` | ✅ `pc` | ✅ `pc` | ✅ |
| VIEW single key | `GET /customizations/{key}` | ✅ `pc` | ✅ `pc` | ✅ `pc` | ✅ `pc` | ✅ |
| SET key | `POST /customizations` | ✅ | ❌ | ❌ | ❌ | ✅ |
| UPDATE key | `PUT /customizations/{key}` | ✅ | ❌ | ❌ | ❌ | ✅ |
| DELETE key | `DELETE /customizations/{key}` | ✅ | ❌ | ❌ | ❌ | ✅ |

---

### 16. Import

| Operation | Endpoint | Super Admin | Branch Manager | Reception | Staff | WP Admin |
|---|---|:---:|:---:|:---:|:---:|:---:|
| DRY RUN import | `POST /import/dry-run` | ✅ | ❌ | ❌ | ❌ | ✅ |
| RUN import | `POST /import/run` | ✅ | ❌ | ❌ | ❌ | ✅ |
| VIEW status | `GET /import/status` | ✅ | ❌ | ❌ | ❌ | ✅ |
| VIEW history | `GET /import/history` | ✅ | ❌ | ❌ | ❌ | ✅ |

---

### 17. Data Management

| Operation | Endpoint | Super Admin | Branch Manager | Reception | Staff | WP Admin |
|---|---|:---:|:---:|:---:|:---:|:---:|
| LIST archived clients | `GET /data-management/clients` | ✅ | ❌ | ❌ | ❌ | ✅ |
| ARCHIVE / RESTORE client | `PUT /data-management/clients/{id}/*` | ✅ | ❌ | ❌ | ❌ | ✅ |
| LIST archived pets | `GET /data-management/pets` | ✅ | ❌ | ❌ | ❌ | ✅ |
| ARCHIVE / RESTORE pet | `PUT /data-management/pets/{id}/*` | ✅ | ❌ | ❌ | ❌ | ✅ |
| LIST cancelled bookings | `GET /data-management/bookings` | ✅ | ❌ | ❌ | ❌ | ✅ |
| CANCEL / RESTORE booking | `PUT /data-management/bookings/{id}/*` | ✅ | ❌ | ❌ | ❌ | ✅ |
| LIST archived inquiries | `GET /data-management/inquiries` | ✅ | ❌ | ❌ | ❌ | ✅ |
| ARCHIVE / RESTORE inquiry | `PUT /data-management/inquiries/{id}/*` | ✅ | ❌ | ❌ | ❌ | ✅ |

*Gate: `opb_manage_settings || manage_options` — direct `current_user_can()` check in the API class constructor closure.*

---

### 18. Health

| Operation | Endpoint | Super Admin | Branch Manager | Reception | Staff | WP Admin |
|---|---|:---:|:---:|:---:|:---:|:---:|
| RUN health check | `GET /health` | ✅ `pc` | ✅ `pc` | ✅ `pc` | ✅ `pc` | ✅ |

---

### 19. Dashboard

| Operation | Endpoint | Super Admin | Branch Manager | Reception | Staff | WP Admin |
|---|---|:---:|:---:|:---:|:---:|:---:|
| VIEW dashboard | `GET /dashboard` | ✅ `pc` | ✅ `pc` (own branch) | ✅ `pc` (own branch) | ✅ `pc` (own branch) | ✅ |

---

### 20. Public & Client Portal Endpoints (No OPB Auth Required)

| Endpoint | Auth | Accessible By |
|---|---|---|
| `POST /public/inquiry` | None (`__return_true`) | Anyone (public) |
| `GET /public/boarding-services` | None (`__return_true`) | Anyone (public) |
| `GET/POST /public/onboarding-link/{token}` | None (`__return_true`) | Anyone with valid token |
| `POST /public/onboarding-submit/{token}` | None (`__return_true`) | Anyone with valid token |
| `POST /client/auth/request-otp` | None (`__return_true`) | Anyone (client self-service) |
| `POST /client/auth/verify-otp` | None (`__return_true`) | Anyone (client self-service) |
| `POST /client/auth/logout` | None (`__return_true`) | Anyone |
| `GET /client/me` | None at WP level (`__return_true`) + internal session check | Valid session cookie holder |
| `GET /clients/{id}/portal-preview` | `permission_check` (OPB staff) | Any logged-in OPB staff |
