# Onukonu Pet Boarding Core — Plugin Architecture

**Version:** 1.1 — Pre-build approval document  
**Target:** WordPress + PHP 8.2 + MySQL 8.0 on Hostinger  
**Authoritative business model:** ANALYSIS.md  
**UI reference:** Legacy SaaS screenshots & screen recordings  
**Status:** AWAITING APPROVAL — no code generated

**Changelog:**
- v1.1 — Added WhatsApp integration (invoice sharing + onboarding form)

---

## Table of Contents

1. [Information Architecture](#1-information-architecture)
2. [Screen Inventory](#2-screen-inventory)
3. [Navigation Structure](#3-navigation-structure)
4. [Database Schema](#4-database-schema)
5. [ERD Summary](#5-erd-summary)
6. [User Roles & Permissions](#6-user-roles--permissions)
7. [Migration Plan](#7-migration-plan)
8. [Wireframes](#8-wireframes)
9. [Technical Stack & Plugin Structure](#9-technical-stack--plugin-structure)
10. [WhatsApp Integration](#10-whatsapp-integration)

---

## 1. Information Architecture

### 1.1 Application Model

The system is a single-page React application served from a single WordPress page template. WordPress handles authentication and REST API security only. The React SPA owns all routing, state, and rendering.

```
WordPress (Host)
├── Authentication       wp_login / wp-json auth
├── REST API             /wp-json/opb/v1/*
├── File Storage         wp_upload_dir() or Hostinger Object Storage
└── React SPA            Served from /opb/ page template
    ├── Branch Context   (selected branch persisted in localStorage + server session)
    ├── Dashboard
    ├── Clients Module
    ├── Pets Module
    ├── Bookings Module
    ├── Kennel Board
    ├── Invoices Module
    ├── Payments Module
    ├── Tasks Module
    ├── Expenses Module
    └── Settings Module
```

### 1.2 Module Map

| Module | Primary Entity | Key Actions |
|---|---|---|
| Dashboard | — | Overview cards, today's activity |
| Clients | CLIENT | List, create, view, edit, archive |
| Pets | PET | List per client, create, view, edit, upload docs |
| Bookings | BOOKING + BOOKING_STAY | List, create, check-in, check-out |
| Kennel Board | BOOKING_STAY | Visual occupancy grid |
| Invoices | INVOICE + LINE_ITEMS | View, adjust, print/download |
| Payments | PAYMENT | Record payment, view history |
| Tasks | TASK | List, create, assign, update status |
| Expenses | EXPENSE | Record, list, category filter |
| Settings | BOARDING_SERVICE, ADDON_SERVICE, BRANCH | Configure catalogues, manage staff |

### 1.3 Data Flow

```
Client Search / Creation
        │
        ▼
  Pet Profile (per client)
        │
        ▼
  Booking Creation ──────► Invoice (auto-generated)
        │                        │
        ▼                        ▼
  Check-In (Stay Active)    Payments recorded
        │
        ▼
  Active Stay Management
  (tasks, notes, weight)
        │
        ▼
  Check-Out ──────────────► Invoice finalised
        │                        │
        ▼                        ▼
  Stay Completed           Balance collected
```

---

## 2. Screen Inventory

### S01 — Login
Single-field login (WP credentials). Redirect to Dashboard after auth.

### S02 — Dashboard
KPI cards + today's activity + outstanding tasks. Branch-filtered.

### S03 — Client List
Searchable, filterable table of all clients. Columns: name, phone, home branch, pets count, last booking, status, outstanding balance.

### S04 — Client Profile
Full client record with tabs: Details · Pets · Bookings · Invoices · Payments · Notes.
WhatsApp button in the header to send onboarding message to client.

### S05 — Client Create / Edit
Form: contact info, address, local guardian, home branch, T&C checkbox.
After saving a new client, prompt staff to send WhatsApp onboarding message.

### S06 — Pet Profile
Full pet record with tabs: Details · Health · Vaccinations · Documents · Booking History.

### S07 — Pet Create / Edit
Multi-section form: basics, health profile, dietary, vaccinations, vet info, walk schedule.

### S08 — Pet Document Upload
Drag-and-drop file upload for photo and vaccination certificates. Preview grid.

### S09 — Booking List
Table of all bookings for selected branch. Filter by date range, status, payment status. Quick search by pet name or client phone.

### S10 — Booking Detail
Header summary (client, dates, payment status, amount) + stay cards (one per pet) + add-on services + invoice summary + payment history.
WhatsApp button to send invoice summary to client.

### S11 — Booking Create
Step-by-step flow:
- Step 1: Select client + pet(s)
- Step 2: Dates, branch, boarding type, meal type
- Step 3: Add-on services
- Step 4: Billing preview + confirm

### S12 — Check-In Flow
Triggered from Booking Detail or Kennel Board. Records: actual check-in time, weight, kennel assignment.

### S13 — Check-Out Flow
Triggered from Booking Detail or Kennel Board. Records: check-out time, final weight, any add-ons added during stay. Shows final invoice. Collect payment.

### S14 — Kennel Board
Visual grid. Rows = kennel slots. Columns = today + 14 days. Cells = booked/free/arriving/departing. Tap cell to open booking.

### S15 — Invoice Detail
Full invoice with line-item breakdown. Actions: add manual adjustment, record payment, print PDF, send via WhatsApp.

### S16 — Invoice List
Table of all invoices. Filter by date, payment status, branch. Totals in footer.

### S17 — Payment Modal
Inline modal from invoice or booking. Select mode (Cash/UPI/Other), enter amount, optional transaction ID.

### S18 — Task List
Kanban board or table view. Filter by assignee, priority, branch, due date. Quick-add task button.

### S19 — Task Detail / Edit
Full task form with comments thread.

### S20 — Expense List
Table of expenses per branch. Filter by date range, category, mode. Monthly total in header.

### S21 — Expense Create
Simple form: description, amount, category, mode, date.

### S22 — Settings — Branches
View/edit branch details.

### S23 — Settings — Boarding Catalogue
Table of boarding service rows per branch. View/edit pricing. Add new catalogue entry.

### S24 — Settings — Add-on Catalogue
List of add-on services per branch. Edit prices, visibility, descriptions.

### S25 — Settings — Staff / Users
List WP users. Assign OPB role per user (Super Admin / Branch Manager / Reception / Staff). Branch access assignment.

### S26 — Migration Import
Admin-only screen. Upload CSVs / XLSXs, run import, view validation report. Only visible to Super Admin.

### S27 — Reports (future / V1 basic)
Basic revenue report by branch and date range. Occupancy rate per branch.

---

## 3. Navigation Structure

### 3.1 Global Layout

```
┌─────────────────────────────────────────────────────────────┐
│  ☰  ONUKONU PET BOARDING         [Branch Selector ▼]  [👤] │
├──────────┬──────────────────────────────────────────────────┤
│          │                                                   │
│  SIDEBAR │   MAIN CONTENT AREA                              │
│          │                                                   │
│  ● Dashboard                                                 │
│  ─────────                                                   │
│  ● Clients                                                   │
│  ● Pets                                                      │
│  ─────────                                                   │
│  ● Bookings                                                  │
│  ● Kennel Board                                              │
│  ─────────                                                   │
│  ● Invoices                                                  │
│  ● Payments                                                  │
│  ─────────                                                   │
│  ● Tasks                                                     │
│  ● Expenses                                                  │
│  ─────────                                                   │
│  ● Settings                                                  │
│                                                              │
└──────────┴──────────────────────────────────────────────────┘
```

### 3.2 Mobile Navigation

On mobile, the sidebar collapses to a bottom tab bar with the 5 most-used modules:

```
[Dashboard] [Bookings] [Kennel] [Clients] [More ···]
```

"More" opens a slide-up sheet with all remaining modules.

### 3.3 Branch Selector

A persistent dropdown in the top bar. Branch selection is global — all lists, cards, and forms default to the selected branch. Super Admins and Branch Managers can switch branches. Reception and Staff see only their assigned branch.

### 3.4 Deep Link Routes (SPA)

```
/                         → Dashboard
/clients                  → Client List (S03)
/clients/new              → Client Create (S05)
/clients/:id              → Client Profile (S04)
/clients/:id/edit         → Client Edit (S05)
/clients/:id/pets/new     → Pet Create (S07)
/pets/:id                 → Pet Profile (S06)
/pets/:id/edit            → Pet Edit (S07)
/bookings                 → Booking List (S09)
/bookings/new             → Booking Create (S11)
/bookings/:id             → Booking Detail (S10)
/bookings/:id/checkin     → Check-In Flow (S12)
/bookings/:id/checkout    → Check-Out Flow (S13)
/kennel                   → Kennel Board (S14)
/invoices                 → Invoice List (S16)
/invoices/:id             → Invoice Detail (S15)
/tasks                    → Task List (S18)
/tasks/:id                → Task Detail (S19)
/expenses                 → Expense List (S20)
/settings                 → Settings root
/settings/branches        → Branch Settings (S22)
/settings/boarding        → Boarding Catalogue (S23)
/settings/addons          → Add-on Catalogue (S24)
/settings/staff           → Staff Management (S25)
/settings/import          → Migration Import (S26)
```

---

## 4. Database Schema

All tables use `InnoDB`, `utf8mb4_unicode_ci`, and `DECIMAL(10,2)` for all monetary fields.  
Auto-generated `created_at` and `updated_at` on all tables.

---

### 4.1 `wp_opb_branches`

```sql
CREATE TABLE wp_opb_branches (
  id            TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code          VARCHAR(10)  NOT NULL,          -- 'H2', 'H3', 'H4'
  name          VARCHAR(100) NOT NULL,
  location      VARCHAR(100) NOT NULL,
  address       TEXT,
  phone         VARCHAR(20),
  email         VARCHAR(100),
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_branch_code (code)
) ENGINE=InnoDB;
```

---

### 4.2 `wp_opb_clients`

```sql
CREATE TABLE wp_opb_clients (
  id                       INT UNSIGNED NOT NULL AUTO_INCREMENT,
  legacy_id                INT UNSIGNED,                          -- original Pet ID from CSV
  wp_user_id               BIGINT UNSIGNED,                      -- nullable WP user link
  home_branch_id           TINYINT UNSIGNED NOT NULL,
  name                     VARCHAR(150) NOT NULL,
  phone                    VARCHAR(25)  NOT NULL,
  email                    VARCHAR(150),
  address                  TEXT,
  local_guardian_name      VARCHAR(150),
  local_guardian_contact   VARCHAR(25),
  status                   ENUM('active','archived') NOT NULL DEFAULT 'active',
  archive_reason           TEXT,
  onboarding_date          DATE,
  tc_accepted              TINYINT(1)   NOT NULL DEFAULT 0,
  wallet_balance           DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  outstanding_balance      DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  notes                    TEXT,
  created_at               DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at               DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_phone (phone),
  KEY idx_branch (home_branch_id),
  KEY idx_legacy (legacy_id),
  CONSTRAINT fk_client_branch FOREIGN KEY (home_branch_id) REFERENCES wp_opb_branches(id)
) ENGINE=InnoDB;
```

---

### 4.3 `wp_opb_pets`

```sql
CREATE TABLE wp_opb_pets (
  id                         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  client_id                  INT UNSIGNED NOT NULL,
  legacy_id                  INT UNSIGNED,
  name                       VARCHAR(100) NOT NULL,
  pet_type                   ENUM('Dog','Cat','Other') NOT NULL,
  breed                      VARCHAR(100),
  gender                     ENUM('Male','Female','Unknown'),
  breed_size                 ENUM('Small','Medium','Large'),
  coat                       VARCHAR(50),
  weight_kg                  DECIMAL(5,2),
  birthday                   DATE,
  microchip_number           VARCHAR(50),
  neutered_or_spayed         TINYINT(1),
  last_heat_month            TINYINT UNSIGNED,                   -- 1–12
  last_heat_year             SMALLINT UNSIGNED,
  adoption_status            VARCHAR(50),
  social_media_handle        VARCHAR(100),
  consent_photos             TINYINT(1) DEFAULT 0,
  special_occasion           VARCHAR(100),
  special_occasion_date      DATE,
  -- Health
  vaccination_status         ENUM('Vaccinated','Not vaccinated','Unknown') DEFAULT 'Unknown',
  anti_rabies_date           DATE,
  dhppil_date                DATE,
  corona_date                DATE,
  kennel_cough_date          DATE,
  tick_prevention            TINYINT(1) DEFAULT 0,
  last_tick_prevention_date  DATE,
  tick_prevention_method     VARCHAR(100),
  ongoing_medication         TINYINT(1) DEFAULT 0,
  medication_detail          TEXT,
  major_illness_history      TEXT,
  deworming_date             DATE,
  vet_name                   VARCHAR(150),
  vet_contact                VARCHAR(25),
  -- Care preferences
  dietary_preference         VARCHAR(100),
  additional_meals           TEXT,
  preferences_or_allergies   TEXT,
  first_walk_schedule        VARCHAR(100),
  second_walk_schedule       VARCHAR(100),
  third_walk_schedule        VARCHAR(100),
  -- Status
  is_active                  TINYINT(1) NOT NULL DEFAULT 1,
  created_at                 DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at                 DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_client (client_id),
  KEY idx_legacy (legacy_id),
  CONSTRAINT fk_pet_client FOREIGN KEY (client_id) REFERENCES wp_opb_clients(id)
) ENGINE=InnoDB;
```

---

### 4.4 `wp_opb_pet_documents`

```sql
CREATE TABLE wp_opb_pet_documents (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  pet_id       INT UNSIGNED NOT NULL,
  doc_type     ENUM('photo','vaccination','other') NOT NULL,
  label        VARCHAR(150),                                     -- e.g. "Anti-Rabies Certificate"
  file_url     TEXT NOT NULL,
  file_mime    VARCHAR(100),
  seq_number   TINYINT UNSIGNED NOT NULL DEFAULT 1,
  uploaded_by  BIGINT UNSIGNED,                                  -- WP user ID
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_pet (pet_id),
  CONSTRAINT fk_doc_pet FOREIGN KEY (pet_id) REFERENCES wp_opb_pets(id) ON DELETE CASCADE
) ENGINE=InnoDB;
```

---

### 4.5 `wp_opb_boarding_services`

```sql
CREATE TABLE wp_opb_boarding_services (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  branch_id           TINYINT UNSIGNED NOT NULL,
  catalogue_name      VARCHAR(150) NOT NULL,
  boarding_type       ENUM('DAY','OVERNIGHT') NOT NULL,
  pet_type            ENUM('DOG','CAT','ANY') NOT NULL,
  row_type            VARCHAR(50) NOT NULL,                      -- FLAGS, DAY_BASE, OVERNIGHT_BASE, BREED_SIZE, LONGEVITY, MEAL, KENNEL_CATEGORY
  amount              DECIMAL(10,2),
  discount_type       VARCHAR(50),
  breed_size          ENUM('Small','Medium','Large'),
  kennel_category     VARCHAR(50),
  meal_name           VARCHAR(100),
  meal_type           VARCHAR(50),
  price_type          VARCHAR(50),
  modifies_base_bill  TINYINT(1) DEFAULT 0,
  min_pets            TINYINT UNSIGNED,
  days                SMALLINT UNSIGNED,
  min_age_months      SMALLINT UNSIGNED,
  max_age_months      SMALLINT UNSIGNED,
  breed               VARCHAR(100),
  extra_info          TEXT,                                      -- raw FLAGS JSON from legacy
  is_active           TINYINT(1) NOT NULL DEFAULT 1,
  sort_order          SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_branch_cat (branch_id, catalogue_name),
  CONSTRAINT fk_bs_branch FOREIGN KEY (branch_id) REFERENCES wp_opb_branches(id)
) ENGINE=InnoDB;
```

---

### 4.6 `wp_opb_addon_services`

```sql
CREATE TABLE wp_opb_addon_services (
  id                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  branch_id             TINYINT UNSIGNED NOT NULL,
  name                  VARCHAR(100) NOT NULL,
  description           TEXT,
  service_type          ENUM('FLAT','DISTANCE_SLAB') NOT NULL DEFAULT 'FLAT',
  base_amount           DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  visibility            ENUM('PUBLIC','PRIVATE') NOT NULL DEFAULT 'PUBLIC',
  applicable_services   TEXT,
  distance_up_to        DECIMAL(8,2),
  distance_slab_amount  DECIMAL(10,2),
  is_active             TINYINT(1) NOT NULL DEFAULT 1,
  sort_order            SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_branch (branch_id),
  CONSTRAINT fk_addon_branch FOREIGN KEY (branch_id) REFERENCES wp_opb_branches(id)
) ENGINE=InnoDB;
```

---

### 4.7 `wp_opb_bookings`

```sql
CREATE TABLE wp_opb_bookings (
  id                      INT UNSIGNED NOT NULL AUTO_INCREMENT,
  legacy_id               INT UNSIGNED,
  branch_id               TINYINT UNSIGNED NOT NULL,
  client_id               INT UNSIGNED NOT NULL,
  booking_date            DATE NOT NULL,
  payment_status          ENUM('Unpaid','Partially paid','Paid','Overpaid','No bill') NOT NULL DEFAULT 'Unpaid',
  total_billing_amount    DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  service_types           VARCHAR(100),
  booking_source          VARCHAR(100),
  notes                   TEXT,
  additional_instruction  TEXT,
  created_by              BIGINT UNSIGNED,
  created_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at              DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_branch (branch_id),
  KEY idx_client (client_id),
  KEY idx_date (booking_date),
  KEY idx_legacy (legacy_id),
  CONSTRAINT fk_booking_branch FOREIGN KEY (branch_id) REFERENCES wp_opb_branches(id),
  CONSTRAINT fk_booking_client FOREIGN KEY (client_id) REFERENCES wp_opb_clients(id)
) ENGINE=InnoDB;
```

---

### 4.8 `wp_opb_booking_stays`

```sql
CREATE TABLE wp_opb_booking_stays (
  id                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  booking_id            INT UNSIGNED NOT NULL,
  pet_id                INT UNSIGNED NOT NULL,
  boarding_service_id   INT UNSIGNED,
  status                ENUM('Upcoming','Active','Completed','No show') NOT NULL DEFAULT 'Upcoming',
  boarding_type         ENUM('DAY','OVERNIGHT') NOT NULL,
  check_in_date         DATE NOT NULL,
  check_out_date        DATE NOT NULL,
  actual_check_in_at    DATETIME,
  actual_check_out_at   DATETIME,
  check_in_slot         VARCHAR(50),
  check_out_slot        VARCHAR(50),
  weight_at_checkin     DECIMAL(5,2),
  weight_at_checkout    DECIMAL(5,2),
  meal_type             ENUM('BOARDING_MEALS','PARENT_SUPPLIED_MEAL'),
  kennel                VARCHAR(50),
  final_amount          DECIMAL(10,2),
  late_checkout_fees    DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  refund_amount         DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  refund_reason         TEXT,
  companion_name        VARCHAR(150),
  companion_phone       VARCHAR(25),
  notes                 TEXT,
  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_booking (booking_id),
  KEY idx_pet (pet_id),
  KEY idx_checkin_date (check_in_date),
  KEY idx_checkout_date (check_out_date),
  KEY idx_status (status),
  CONSTRAINT fk_stay_booking FOREIGN KEY (booking_id) REFERENCES wp_opb_bookings(id),
  CONSTRAINT fk_stay_pet     FOREIGN KEY (pet_id)     REFERENCES wp_opb_pets(id),
  CONSTRAINT fk_stay_service FOREIGN KEY (boarding_service_id) REFERENCES wp_opb_boarding_services(id)
) ENGINE=InnoDB;
```

---

### 4.9 `wp_opb_booking_addons`

```sql
CREATE TABLE wp_opb_booking_addons (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  booking_id      INT UNSIGNED NOT NULL,
  addon_id        INT UNSIGNED NOT NULL,
  count           SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  distance        DECIMAL(8,2),
  days            SMALLINT UNSIGNED,
  final_amount    DECIMAL(10,2),
  notes           TEXT,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_booking (booking_id),
  CONSTRAINT fk_ba_booking FOREIGN KEY (booking_id) REFERENCES wp_opb_bookings(id),
  CONSTRAINT fk_ba_addon   FOREIGN KEY (addon_id)   REFERENCES wp_opb_addon_services(id)
) ENGINE=InnoDB;
```

---

### 4.10 `wp_opb_invoices`

```sql
CREATE TABLE wp_opb_invoices (
  id                          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  booking_id                  INT UNSIGNED NOT NULL,
  branch_id                   TINYINT UNSIGNED NOT NULL,
  legacy_invoice_number       VARCHAR(50),
  invoice_type                ENUM('Booking','Manual') NOT NULL DEFAULT 'Booking',
  invoice_date                DATE NOT NULL,
  revenue                     DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  base_amount                 DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  addon_amount                DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  discount_amount             DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  additional_amount           DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  additional_discount_amount  DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  paid                        DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  due                         DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  payment_mode                VARCHAR(50),
  notes                       TEXT,
  created_at                  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at                  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_booking (booking_id),
  KEY idx_branch (branch_id),
  KEY idx_date (invoice_date),
  CONSTRAINT fk_inv_booking FOREIGN KEY (booking_id) REFERENCES wp_opb_bookings(id),
  CONSTRAINT fk_inv_branch  FOREIGN KEY (branch_id)  REFERENCES wp_opb_branches(id)
) ENGINE=InnoDB;
```

---

### 4.11 `wp_opb_invoice_line_items`

```sql
CREATE TABLE wp_opb_invoice_line_items (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  invoice_id     INT UNSIGNED NOT NULL,
  service        VARCHAR(100),
  sku            VARCHAR(100),
  sku_id         VARCHAR(50),
  category       VARCHAR(100),
  sub_category   VARCHAR(100),
  quantity       DECIMAL(8,2) NOT NULL DEFAULT 1.00,
  amount         DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  discount       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  subtotal       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  total          DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  bill_item_name TEXT,
  bill_section   ENUM('Base','Add-on','Discount','Additional') NOT NULL DEFAULT 'Base',
  is_return      TINYINT(1) NOT NULL DEFAULT 0,
  breed          VARCHAR(100),
  breed_size     VARCHAR(50),
  coat_length    VARCHAR(50),
  staff_name     VARCHAR(150),
  hsn_sac_code   VARCHAR(20),
  sort_order     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_invoice (invoice_id),
  CONSTRAINT fk_li_invoice FOREIGN KEY (invoice_id) REFERENCES wp_opb_invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB;
```

---

### 4.12 `wp_opb_payments`

```sql
CREATE TABLE wp_opb_payments (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  invoice_id     INT UNSIGNED NOT NULL,
  branch_id      TINYINT UNSIGNED NOT NULL,
  paid_at        DATETIME NOT NULL,
  amount         DECIMAL(10,2) NOT NULL,
  mode           ENUM('Cash','UPI','Card','Other') NOT NULL DEFAULT 'Cash',
  source         ENUM('Manual','Online') NOT NULL DEFAULT 'Manual',
  transaction_id VARCHAR(100),
  notes          TEXT,
  recorded_by    BIGINT UNSIGNED,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_invoice (invoice_id),
  KEY idx_branch  (branch_id),
  KEY idx_paid_at (paid_at),
  CONSTRAINT fk_pay_invoice FOREIGN KEY (invoice_id) REFERENCES wp_opb_invoices(id),
  CONSTRAINT fk_pay_branch  FOREIGN KEY (branch_id)  REFERENCES wp_opb_branches(id)
) ENGINE=InnoDB;
```

---

### 4.13 `wp_opb_tasks`

```sql
CREATE TABLE wp_opb_tasks (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  branch_id    TINYINT UNSIGNED NOT NULL,
  client_id    INT UNSIGNED,
  booking_id   INT UNSIGNED,
  title        VARCHAR(255) NOT NULL,
  description  TEXT,
  status       ENUM('Open','In Progress','Done') NOT NULL DEFAULT 'Open',
  priority     ENUM('Low','Medium','High') NOT NULL DEFAULT 'Medium',
  due_date     DATE,
  assigned_to  BIGINT UNSIGNED,
  assigned_by  BIGINT UNSIGNED,
  completed_at DATETIME,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_branch   (branch_id),
  KEY idx_assignee (assigned_to),
  KEY idx_status   (status),
  KEY idx_due      (due_date),
  CONSTRAINT fk_task_branch  FOREIGN KEY (branch_id)  REFERENCES wp_opb_branches(id),
  CONSTRAINT fk_task_client  FOREIGN KEY (client_id)  REFERENCES wp_opb_clients(id),
  CONSTRAINT fk_task_booking FOREIGN KEY (booking_id) REFERENCES wp_opb_bookings(id)
) ENGINE=InnoDB;
```

---

### 4.14 `wp_opb_task_comments`

```sql
CREATE TABLE wp_opb_task_comments (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  task_id    INT UNSIGNED NOT NULL,
  author_id  BIGINT UNSIGNED NOT NULL,
  body       TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_task (task_id),
  CONSTRAINT fk_tc_task FOREIGN KEY (task_id) REFERENCES wp_opb_tasks(id) ON DELETE CASCADE
) ENGINE=InnoDB;
```

---

### 4.15 `wp_opb_expenses`

```sql
CREATE TABLE wp_opb_expenses (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  branch_id       TINYINT UNSIGNED NOT NULL,
  description     VARCHAR(255) NOT NULL,
  expense_date    DATE NOT NULL,
  mode            ENUM('Cash','UPI','Card','Other') NOT NULL DEFAULT 'Cash',
  category        VARCHAR(100),
  amount          DECIMAL(10,2) NOT NULL,
  amount_inc_tax  DECIMAL(10,2) NOT NULL,
  receipt_url     TEXT,
  recorded_by     BIGINT UNSIGNED,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_branch (branch_id),
  KEY idx_date   (expense_date),
  CONSTRAINT fk_exp_branch FOREIGN KEY (branch_id) REFERENCES wp_opb_branches(id)
) ENGINE=InnoDB;
```

---

### 4.16 `wp_opb_user_branch_access`

```sql
CREATE TABLE wp_opb_user_branch_access (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  wp_user_id BIGINT UNSIGNED NOT NULL,
  branch_id  TINYINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_user_branch (wp_user_id, branch_id),
  CONSTRAINT fk_uba_branch FOREIGN KEY (branch_id) REFERENCES wp_opb_branches(id)
) ENGINE=InnoDB;
```

---

## 5. ERD Summary

```
BRANCH (3 rows)
  │
  ├──< BOARDING_SERVICE (catalogue rows)
  ├──< ADDON_SERVICE    (add-on catalogue)
  ├──< BOOKING
  │      │
  │      ├──< BOOKING_STAY ──> PET ──> PET_DOCUMENT
  │      ├──< BOOKING_ADDON ──> ADDON_SERVICE
  │      └──1 INVOICE
  │              │
  │              ├──< INVOICE_LINE_ITEM
  │              └──< PAYMENT
  ├──< EXPENSE
  └──< TASK ──> CLIENT (optional)

CLIENT
  └──< PET
         └──< PET_DOCUMENT
         └──< BOOKING_STAY (via booking)
```

Full Mermaid ERD is in ANALYSIS.md §1.

---

## 6. User Roles & Permissions

### 6.1 Role Definitions

Four custom WordPress roles are added by the plugin:

| Role Slug | Display Name | Description |
|---|---|---|
| `opb_super_admin` | OPB Super Admin | Owner/operator. Full access to all branches, all data, settings, migration. |
| `opb_manager` | OPB Branch Manager | Full operational access to assigned branches. Cannot manage staff or run imports. |
| `opb_reception` | OPB Reception | Create/manage bookings, check-in/out, record payments. No settings access. |
| `opb_staff` | OPB Staff | View bookings and tasks, update task status, record stay notes. Read-only on financials. |

### 6.2 Permission Matrix

| Action | Super Admin | Manager | Reception | Staff |
|---|---|---|---|---|
| View all branches | ✅ | Own only | Own only | Own only |
| Switch branch | ✅ | ✅ | ❌ | ❌ |
| **Clients** | | | | |
| View client list | ✅ | ✅ | ✅ | ❌ |
| Create / edit client | ✅ | ✅ | ✅ | ❌ |
| Archive client | ✅ | ✅ | ❌ | ❌ |
| **Pets** | | | | |
| View pet profile | ✅ | ✅ | ✅ | ✅ |
| Create / edit pet | ✅ | ✅ | ✅ | ❌ |
| Upload documents | ✅ | ✅ | ✅ | ❌ |
| **Bookings** | | | | |
| View bookings | ✅ | ✅ | ✅ | ✅ |
| Create booking | ✅ | ✅ | ✅ | ❌ |
| Check-in | ✅ | ✅ | ✅ | ✅ |
| Check-out | ✅ | ✅ | ✅ | ✅ |
| Cancel / No show | ✅ | ✅ | ❌ | ❌ |
| **Invoices** | | | | |
| View invoices | ✅ | ✅ | ✅ | ❌ |
| Add manual adjustment | ✅ | ✅ | ❌ | ❌ |
| Print / download | ✅ | ✅ | ✅ | ❌ |
| **Payments** | | | | |
| Record payment | ✅ | ✅ | ✅ | ❌ |
| View payment history | ✅ | ✅ | ✅ | ❌ |
| **Tasks** | | | | |
| View all tasks | ✅ | ✅ | ✅ | Own only |
| Create / assign task | ✅ | ✅ | ✅ | ❌ |
| Update task status | ✅ | ✅ | ✅ | ✅ |
| **Expenses** | | | | |
| View expenses | ✅ | ✅ | ❌ | ❌ |
| Record expense | ✅ | ✅ | ❌ | ❌ |
| **Settings** | | | | |
| Branch settings | ✅ | View only | ❌ | ❌ |
| Boarding catalogue | ✅ | ✅ | ❌ | ❌ |
| Add-on catalogue | ✅ | ✅ | ❌ | ❌ |
| Staff management | ✅ | ❌ | ❌ | ❌ |
| **Migration / Import** | ✅ | ❌ | ❌ | ❌ |
| **Reports** | ✅ | ✅ | ❌ | ❌ |

### 6.3 Branch Access Enforcement

The `wp_opb_user_branch_access` table stores which branches each user can access. Every REST API endpoint filters results by `WHERE branch_id IN (/* user's allowed branch IDs */)`. Super Admins bypass this filter.

The branch selector dropdown in the UI is also limited to the user's allowed branches.

---

## 7. Migration Plan

### 7.1 Phases

```
Phase 0  WordPress + Plugin Setup
Phase 1  Reference Data (branches, catalogues)
Phase 2  Clients & Pets
Phase 3  Pet Documents (file upload)
Phase 4  Bookings, Stays, Add-ons
Phase 5  Invoices, Line Items
Phase 6  Payments
Phase 7  Expenses
Phase 8  Validation & Reconciliation
```

### 7.2 Import Interface (S26)

The Migration Import screen (Super Admin only) provides:
- File upload fields for each data source
- Branch assignment selector (applied to all imported rows)
- Dry-run mode: validates and reports errors without inserting
- Live-run mode: inserts with rollback on fatal errors
- Results report: rows imported, rows skipped, warnings, errors

### 7.3 Field Mappings

**clients.csv → wp_opb_clients + wp_opb_pets**

| CSV Column | Target Table | Target Column | Transform |
|---|---|---|---|
| Pet ID | pets | legacy_id | int |
| Name | clients | name | trim |
| Phone Number | clients | phone | E.164 format |
| Email | clients | email | lowercase |
| Address | clients | address | trim |
| Home Outlet | clients | home_branch_id | map string → branch.id |
| Onboarding Date | clients | onboarding_date | parse "Jun 21, 2024" → DATE |
| T&C Accepted | clients | tc_accepted | "Yes" → 1 |
| Wallet Balance | clients | wallet_balance | DECIMAL |
| Outstanding Balance | clients | outstanding_balance | DECIMAL |
| Local Guardian Name | clients | local_guardian_name | trim |
| Local Guardian Contact | clients | local_guardian_contact | trim |
| Status | clients | status | "Active" → active |
| Pet Name | pets | name | trim |
| Pet Type | pets | pet_type | titlecase |
| Breed | pets | breed | trim |
| Gender | pets | gender | titlecase |
| Pet Birthday | pets | birthday | parse → DATE |
| Coat | pets | coat | trim |
| Breed Size | pets | breed_size | titlecase |
| Weight (kg) | pets | weight_kg | DECIMAL |
| Neutered Or Spayed | pets | neutered_or_spayed | "Yes/No" → 1/0 |
| Last Heat Month | pets | last_heat_month | month name → 1-12 |
| Last Heat Year | pets | last_heat_year | int |
| Vaccination Status | pets | vaccination_status | passthrough |
| Anti Rabies | pets | anti_rabies_date | parse date |
| DHPPiL (9-in-1) | pets | dhppil_date | parse date |
| Corona | pets | corona_date | parse date |
| Kennel Cough | pets | kennel_cough_date | parse date |
| Tick Prevention | pets | tick_prevention | "Yes/No" → 1/0 |
| Last Tick Prevention Date | pets | last_tick_prevention_date | parse date |
| Tick Prevention Method | pets | tick_prevention_method | trim |
| Ongoing Medication | pets | ongoing_medication | "Yes/No" → 1/0 |
| Medication Detail | pets | medication_detail | trim |
| Major Illness History | pets | major_illness_history | trim |
| Deworming Date | pets | deworming_date | parse date |
| Veterinarian Name | pets | vet_name | trim |
| Veterinarian Contact | pets | vet_contact | trim |
| Dietary Preference | pets | dietary_preference | trim |
| Additional Meals | pets | additional_meals | trim |
| Preferences Or Allergies | pets | preferences_or_allergies | trim |
| First/Second/Third Walk Schedule | pets | walk_schedule_* | trim |
| Microchip Number | pets | microchip_number | trim |
| Adoption Status | pets | adoption_status | trim |
| Pet Social Media Handle | pets | social_media_handle | trim |
| Consent To Use Pet Photos | pets | consent_photos | "Yes" → 1 |
| Special Occasion | pets | special_occasion | trim |
| Special Occasion Date | pets | special_occasion_date | parse date |

**bookings/{branch}.xlsx "Bookings" → wp_opb_bookings**

| XLSX Column | Target Column | Transform |
|---|---|---|
| Booking ID | legacy_id | int |
| Booking Date | booking_date | parse → DATE |
| Phone | → join to client_id | lookup clients by phone |
| Invoice Number | — | used to link invoice later |
| Payment Status | payment_status | passthrough |
| Total Billing Amount | total_billing_amount | DECIMAL |
| Service Types | service_types | trim |
| Notes | notes | trim |
| Additional Instruction | additional_instruction | trim |
| Created At | created_at | parse datetime |

**bookings/{branch}.xlsx "Boarding" → wp_opb_booking_stays**

| XLSX Column | Target Column | Transform |
|---|---|---|
| Booking ID | booking_id | join via legacy_id |
| Pet Name | pet_id | join clients by Phone + Pet Name |
| Status | status | passthrough |
| Boarding Type | boarding_type | DAY / OVERNIGHT |
| Check-In Date | check_in_date | DATE |
| Check-Out Date | check_out_date | DATE |
| Meal Type | meal_type | passthrough |
| Kennel | kennel | trim |
| Final Amount | final_amount | DECIMAL |
| Late Checkout Fees | late_checkout_fees | DECIMAL |
| Refund Amount | refund_amount | DECIMAL |
| Refund Reason | refund_reason | trim |
| Weight | weight_at_checkin | DECIMAL |
| Check-Out Weight | weight_at_checkout | DECIMAL |

### 7.4 Validation Rules

1. **Phone uniqueness:** Duplicate phone numbers in clients.csv → flag for manual merge.
2. **Pet name + phone match for stays:** If no match found → log warning, insert stay without `pet_id` (nullable), flag for manual resolution.
3. **Date parsing:** All date fields must parse cleanly. Unparseable dates → null with warning.
4. **Invoice–booking join:** `Invoice No = Booking ID` must be 1:1. Any orphan invoices logged.
5. **Payment amounts:** Sum of payments per invoice must not exceed invoice revenue by more than ₹10 (tolerance for rounding).
6. **Negative outstanding balance:** Flag clients where `outstanding_balance < 0`.
7. **File MIME check:** Pet documents — reject executables. Accept: jpg, jpeg, png, gif, pdf, heic, mp4, mov.

### 7.5 Run Order (dependency-safe)

```
1. branches (hardcoded)
2. addon_services      (no deps)
3. boarding_services   (no deps)
4. clients             (needs branches)
5. pets                (needs clients)
6. bookings            (needs clients + branches)
7. booking_stays       (needs bookings + pets)
8. booking_addons      (needs bookings + addon_services)
9. invoices            (needs bookings)
10. invoice_line_items  (needs invoices)
11. payments            (needs invoices + branches)
12. expenses            (needs branches)
```

---

## 8. Wireframes

Key screens described below with ASCII wireframes. Actual visual design follows the legacy SaaS aesthetic.

---

### W-01 Dashboard

```
┌────────────────────────────────────────────────────────────┐
│  🐾 Onukonu Pet Boarding        [H2 Succoro ▼]    [RM 👤] │
├────────────────────────────────────────────────────────────┤
│                                                            │
│  Good morning, Rahul.  Wednesday 29 May 2026               │
│                                                            │
│  ┌──────────────┐ ┌──────────────┐ ┌──────────────┐       │
│  │ CHECK-INS    │ │ CHECK-OUTS   │ │ ACTIVE STAYS │       │
│  │    TODAY     │ │    TODAY     │ │              │       │
│  │              │ │              │ │              │       │
│  │     3        │ │     5        │ │     22       │       │
│  │              │ │              │ │              │       │
│  │ View →       │ │ View →       │ │ Kennel →     │       │
│  └──────────────┘ └──────────────┘ └──────────────┘       │
│                                                            │
│  ┌──────────────┐ ┌──────────────┐ ┌──────────────┐       │
│  │ REVENUE      │ │ OUTSTANDING  │ │ TASKS DUE    │       │
│  │  THIS MONTH  │ │  PAYMENTS    │ │    TODAY     │       │
│  │              │ │              │ │              │       │
│  │  ₹1,42,000   │ │  ₹18,400     │ │     4        │       │
│  │              │ │              │ │              │       │
│  │ Reports →    │ │ View →       │ │ View →       │       │
│  └──────────────┘ └──────────────┘ └──────────────┘       │
│                                                            │
│  TODAY'S CHECK-INS ─────────────────────────── [+ New]    │
│  ┌────────────────────────────────────────────────────┐   │
│  │ Buddy (Labrador) · Arjun Sharma · K3   10:00 AM    │   │
│  │ Coco (Cat) · Meena D'Souza · —         02:00 PM    │   │
│  │ Max (GSD) · Priya Naik · K7            04:00 PM    │   │
│  └────────────────────────────────────────────────────┘   │
│                                                            │
│  TODAY'S CHECK-OUTS ───────────────────────────────────── │
│  ┌────────────────────────────────────────────────────┐   │
│  │ Rocky (Indie Dog) · Reuben Dias · UNPAID  ₹4,200  │   │
│  │ Kitty (Cat) · Nisha Rao                   PAID    │   │
│  └────────────────────────────────────────────────────┘   │
│                                                            │
└────────────────────────────────────────────────────────────┘
```

---

### W-02 Kennel Board

```
┌────────────────────────────────────────────────────────────┐
│  Kennel Board                  [H2 Succoro ▼]  [+ Booking] │
├────────────────────────────────────────────────────────────┤
│  ← Prev Week   29 May – 11 Jun 2026   Next Week →          │
├────────┬────┬────┬────┬────┬────┬────┬────┬────┬──────────┤
│ KENNEL │ 29 │ 30 │ 31 │ 1  │ 2  │ 3  │ 4  │ 5  │ …        │
├────────┼────┼────┼────┼────┼────┼────┼────┼────┼──────────┤
│  K1    │████│████│████│████│    │    │    │    │          │
│        │ Buddy (Labrador)   │    │    │    │    │          │
├────────┼────┼────┼────┼────┼────┼────┼────┼────┼──────────┤
│  K2    │    │    │████│████│████│████│████│    │          │
│        │    │    │ Max (GSD) · Arjun  │    │          │
├────────┼────┼────┼────┼────┼────┼────┼────┼────┼──────────┤
│  K3    │    │ ▶  │    │    │    │    │    │    │          │
│        │    │ ARRIVING      │    │    │    │    │          │
├────────┼────┼────┼────┼────┼────┼────┼────┼────┼──────────┤
│  K4    │████│████│████│ ◀  │    │    │    │    │          │
│        │ Rocky · leaving today      │    │    │          │
├────────┼────┼────┼────┼────┼────┼────┼────┼────┼──────────┤
│  CAT1  │    │    │████│████│████│    │    │    │          │
│        │    │    │ Coco        │    │    │    │          │
├────────┴────┴────┴────┴────┴────┴────┴────┴────┴──────────┤
│  ██ Occupied  ▶ Arriving  ◀ Departing  □ Available         │
└────────────────────────────────────────────────────────────┘
```

---

### W-03 Booking Detail

```
┌────────────────────────────────────────────────────────────┐
│  ← Back    Booking #847                     [Check-Out]    │
├────────────────────────────────────────────────────────────┤
│  Arjun Sharma  +91 98765 43210   H2 Succoro                │
│  15 May – 22 May 2026  (7 nights)  ●  ACTIVE              │
│                                      PARTIALLY PAID        │
├────────────────────────────────────────────────────────────┤
│  PETS IN THIS BOOKING                                      │
│  ┌──────────────────────────────────────────────────────┐ │
│  │  🐕 Buddy  (Labrador · Large)                        │ │
│  │  Kennel K1  ·  Overnight  ·  Parent-supplied meal    │ │
│  │  Check-in: 10:30 AM 15 May   Weight: 28 kg           │ │
│  │  Check-out: —                                        │ │
│  └──────────────────────────────────────────────────────┘ │
│                                                            │
│  ADD-ON SERVICES ──────────────────────── [+ Add Service] │
│  • Daily Grooming × 7 days             ₹0                 │
│  • Vet Visit × 1                       ₹400               │
│                                                            │
│  INVOICE SUMMARY ──────────────────────────────────────── │
│  Base (7 nights × ₹1,600)             ₹11,200             │
│  Add-ons                               ₹400               │
│  Discount (Long stay 12.5%)           –₹1,400             │
│  ─────────────────────────────────────────────            │
│  Total                                ₹10,200             │
│  Paid                                  ₹5,000             │
│  Due                                   ₹5,200  🔴         │
│                                                            │
│  PAYMENTS ─────────────────────────── [+ Record Payment]  │
│  Cash  15 May  ₹5,000                                      │
│                                                            │
│  NOTES ─────────────────────────────────────────────────  │
│  "Call Arjun before checkout to confirm pickup time"      │
│                                                            │
└────────────────────────────────────────────────────────────┘
```

---

### W-04 Booking Create (Step 1 — Client & Pets)

```
┌────────────────────────────────────────────────────────────┐
│  New Booking                           Step 1 of 4         │
│  ● Client & Pets  ○ Dates  ○ Services  ○ Confirm           │
├────────────────────────────────────────────────────────────┤
│                                                            │
│  Search client by name or phone                            │
│  ┌──────────────────────────────────────────────────────┐ │
│  │  🔍  Arjun…                                          │ │
│  └──────────────────────────────────────────────────────┘ │
│  ┌──────────────────────────────────────────────────────┐ │
│  │  Arjun Sharma  +91 98765 43210  H2 Succoro  ✓ Select │ │
│  └──────────────────────────────────────────────────────┘ │
│                                                            │
│  SELECT PETS FOR THIS BOOKING                              │
│  ┌──────────────────────────────────────────────────────┐ │
│  │  ☑  Buddy  (Labrador · Large · Dog)                  │ │
│  │  ☐  Biscuit (Indie · Small · Dog)                    │ │
│  └──────────────────────────────────────────────────────┘ │
│                                                            │
│  + Add new pet                                             │
│                                                            │
│  ─────────────────────────────────────── [Cancel] [Next →]│
└────────────────────────────────────────────────────────────┘
```

---

### W-05 Check-In Flow

```
┌────────────────────────────────────────────────────────────┐
│  Check-In — Booking #847                                   │
├────────────────────────────────────────────────────────────┤
│                                                            │
│  Confirming check-in for:  🐕 Buddy (Labrador)            │
│                                                            │
│  Actual Check-In Time                                      │
│  ┌──────────────────────┐                                  │
│  │  15 May 2026  10:30  │  (auto-filled, editable)         │
│  └──────────────────────┘                                  │
│                                                            │
│  Current Weight (kg)                                       │
│  ┌──────────┐                                              │
│  │  28.0    │                                              │
│  └──────────┘                                              │
│                                                            │
│  Kennel Assignment                                         │
│  ┌────────────────────────────────────────────────────┐   │
│  │  K1  ✓   K2 (Occupied)   K3 ✓   K4 (Occupied)     │   │
│  └────────────────────────────────────────────────────┘   │
│                                                            │
│  Meal Type                                                 │
│  ◉ Parent-supplied meal   ○ Boarding meals                │
│                                                            │
│  Notes                                                     │
│  ┌────────────────────────────────────────────────────┐   │
│  │  Buddy was a bit anxious on arrival                │   │
│  └────────────────────────────────────────────────────┘   │
│                                                            │
│  ──────────────────────────── [Cancel]  [Confirm Check-In]│
└────────────────────────────────────────────────────────────┘
```

---

### W-06 Client Profile

```
┌────────────────────────────────────────────────────────────┐
│  ← Back    Arjun Sharma          [📱 WhatsApp] [Edit] [···]│
│            +91 98765 43210 · arjun@email.com               │
│            H2 Succoro  ·  Active  ·  Since 12 Mar 2024     │
├────────────────────────────────────────────────────────────┤
│  [Details]  [Pets]  [Bookings]  [Invoices]  [Notes]        │
├────────────────────────────────────────────────────────────┤
│                                                            │
│  PETS ───────────────────────────── [+ Add Pet]           │
│  ┌──────────────────────────────────────────────────────┐ │
│  │  🐕 Buddy    Labrador  ·  Large  ·  Male   → View    │ │
│  │  🐕 Biscuit  Indie     ·  Small  ·  Female → View    │ │
│  └──────────────────────────────────────────────────────┘ │
│                                                            │
│  WALLET                           ₹0.00                   │
│  OUTSTANDING                      ₹5,200  🔴              │
│                                                            │
│  RECENT BOOKINGS ──────────────────────────────────────── │
│  #847  15 May  Active    Buddy          PARTIALLY PAID     │
│  #721  Jan 12  Completed Buddy          PAID               │
│  #612  Oct 04  Completed Buddy, Biscuit PAID               │
│                                                            │
└────────────────────────────────────────────────────────────┘
```

---

### W-07 Invoice Detail

```
┌────────────────────────────────────────────────────────────┐
│  ← Back    Invoice #847    [📱 WhatsApp] [Print PDF] [+ Adjust] │
├────────────────────────────────────────────────────────────┤
│  Arjun Sharma  +91 98765 43210                             │
│  Booking #847  ·  H2 Succoro  ·  22 May 2026               │
│                                                            │
│  BASE CHARGES ─────────────────────────────────────────── │
│  H2 Dog Overnight – 7 nights                  ₹11,200     │
│  Long Stay Discount (12.5%)                   –₹1,400     │
│                                                            │
│  ADD-ONS ──────────────────────────────────────────────── │
│  Daily Grooming × 7                               ₹0     │
│  Vet Visit × 1                                   ₹400     │
│                                                            │
│  ─────────────────────────────────────────────────────── │
│  TOTAL                                         ₹10,200    │
│  PAID                                           ₹5,000    │
│  DUE                                            ₹5,200 🔴 │
│                                                            │
│  PAYMENT HISTORY ──────────────────────────────────────── │
│  15 May  Cash  ₹5,000                                      │
│                          [📱 WhatsApp] [+ Record Payment]  │
└────────────────────────────────────────────────────────────┘
```

---

### W-08 Pet Profile

```
┌────────────────────────────────────────────────────────────┐
│  ← Back    Buddy                              [Edit]        │
│            Labrador · Large · Male · 3y 2m                 │
│  [Details] [Health] [Vaccinations] [Docs] [History]        │
├────────────────────────────────────────────────────────────┤
│  📷 [Pet photo]                                            │
│                                                            │
│  Owner: Arjun Sharma                                       │
│  Home Branch: H2 Succoro                                   │
│  Breed Size: Large   Coat: Short                           │
│  Weight: 28.0 kg   Microchip: —                           │
│  Neutered: No   Diet: Non-vegetarian                       │
│                                                            │
│  HEALTH ───────────────────────────────────────────────── │
│  Vaccination: ✅ Vaccinated                                │
│  Anti-Rabies: 10 Jan 2024                                  │
│  DHPPiL:      15 Jan 2024                                  │
│  Tick Prevention: Yes · Nexgard · Last: 01 May 2026        │
│  Ongoing Medication: No                                    │
│  Vet: Dr. Estibeiro  +91 8956 613733                      │
│                                                            │
│  WALK SCHEDULE ─────────────────────────────────────────  │
│  Morning 7am  ·  Evening 6pm                              │
│                                                            │
│  DOCUMENTS ──────────────────────────────── [+ Upload]    │
│  📷 Photo.jpg          ✅ Anti-Rabies cert.pdf             │
│  ✅ DHPPiL cert.jpg    ✅ Kennel Cough cert.jpg            │
│                                                            │
└────────────────────────────────────────────────────────────┘
```

---

## 9. Technical Stack & Plugin Structure

### 9.1 Plugin Directory Structure

```
onukonu-pet-boarding/
├── onukonu-pet-boarding.php        Main plugin file, register hooks
├── includes/
│   ├── class-activator.php         DB creation via dbDelta()
│   ├── class-deactivator.php
│   ├── class-roles.php             Register WP roles & capabilities
│   ├── class-rest-api.php          Register all REST routes
│   ├── api/
│   │   ├── class-branches-api.php
│   │   ├── class-clients-api.php
│   │   ├── class-pets-api.php
│   │   ├── class-bookings-api.php
│   │   ├── class-invoices-api.php
│   │   ├── class-payments-api.php
│   │   ├── class-tasks-api.php
│   │   ├── class-expenses-api.php
│   │   └── class-settings-api.php
│   ├── models/
│   │   ├── class-client.php
│   │   ├── class-pet.php
│   │   ├── class-booking.php
│   │   └── (etc.)
│   ├── class-pricing-engine.php    Boarding price calculation logic
│   ├── class-invoice-generator.php Auto-generate invoice from booking
│   └── class-importer.php          Migration import logic
├── admin/
│   └── class-admin-page.php        Single WP admin page serving React
├── app/                             React SPA source
│   ├── src/
│   │   ├── main.tsx
│   │   ├── router.tsx
│   │   ├── api/                    API client (fetch wrappers)
│   │   ├── components/             Shared UI components
│   │   ├── pages/                  One file per screen (S01–S27)
│   │   ├── hooks/                  React hooks
│   │   └── store/                  Zustand global state
│   ├── public/
│   │   ├── manifest.json           PWA manifest
│   │   └── sw.js                   Service worker
│   ├── tailwind.config.ts
│   ├── vite.config.ts
│   └── package.json
├── assets/
│   └── dist/                       Built React bundle (committed or CI-built)
└── languages/                      i18n .pot file
```

### 9.2 REST API Namespacing

All endpoints at: `/wp-json/opb/v1/`

```
GET    /branches
GET    /clients                   ?branch_id&search&status&page
POST   /clients
GET    /clients/:id
PUT    /clients/:id
GET    /clients/:id/pets
POST   /clients/:id/pets
GET    /pets/:id
PUT    /pets/:id
POST   /pets/:id/documents
GET    /bookings                  ?branch_id&date_from&date_to&status
POST   /bookings
GET    /bookings/:id
PUT    /bookings/:id
POST   /bookings/:id/checkin
POST   /bookings/:id/checkout
GET    /kennel-board              ?branch_id&from&to
GET    /invoices                  ?branch_id&date_from&date_to
GET    /invoices/:id
PUT    /invoices/:id/adjust
POST   /invoices/:id/payments
GET    /tasks                     ?branch_id&assignee&status
POST   /tasks
PUT    /tasks/:id
POST   /tasks/:id/comments
GET    /expenses                  ?branch_id&date_from&date_to
POST   /expenses
GET    /settings/boarding         ?branch_id
GET    /settings/addons           ?branch_id
POST   /import/dry-run
POST   /import/run
GET    /dashboard                 ?branch_id
```

### 9.3 PWA Configuration

- `manifest.json`: name, short_name, theme_color (brand green), background_color, display: standalone, icons (192px + 512px)
- Service worker: Cache-first for static assets; network-first for API; offline fallback page for navigation
- Offline caching: Dashboard data (15-min TTL), Client search index, Booking list (today ± 7 days)
- Install prompt triggered after second visit

### 9.4 Pricing Engine Logic

```
calculateStayAmount(pet, service_catalogue, check_in, check_out, meal_type, kennel_category):
  1. Load FLAGS row → determine which modifiers are active
  2. nights = check_out - check_in (DAY: always 1)
  3. base = DAY_BASE or OVERNIGHT_BASE amount × nights
  4. if FLAGS.breedSize: apply BREED_SIZE row for pet.breed_size
  5. if FLAGS.meal and meal_type != PARENT_SUPPLIED_MEAL: apply MEAL row
  6. if FLAGS.kennelCategory and kennel_category: apply KENNEL_CATEGORY row
  7. if FLAGS.longevity and nights >= threshold: apply LONGEVITY discount
     - if FLAGS.longevityModifiesBaseBill: subtract from base; else show as separate line
  8. return line_items[]
```

---

---

## 10. WhatsApp Integration

No WhatsApp API account, Business API, or third-party service is required. All buttons construct a standard `wa.me` deep-link that opens WhatsApp on the device with the recipient and message pre-filled. The user taps Send inside WhatsApp — nothing is sent automatically.

### 10.1 Phone Number Normalisation

All phone numbers stored in `wp_opb_clients.phone` are in E.164 format (e.g. `+919876543210`). The `wa.me` scheme requires the number with no `+` prefix and no spaces.

```
normaliseForWhatsApp(phone):
  1. Strip all non-digit characters
  2. If the number starts with '0', drop the leading zero
  3. If the number is 10 digits (Indian mobile), prepend '91'
  4. Return the resulting digit string

Examples:
  "+91 98765 43210"  →  "919876543210"
  "09876543210"      →  "919876543210"
  "+919876543210"    →  "919876543210"
```

### 10.2 Use Cases

#### UC-1 — Send Invoice to Client

**Trigger locations:**
- Invoice Detail screen (S15) — primary action button `[📱 WhatsApp]`
- Booking Detail screen (S10) — action in invoice summary section
- Payment confirmation toast — "Send receipt via WhatsApp?" quick action

**Message template:**

```
Hi {client.name},

Here is your invoice from Onukonu Pet Homestyle Boarding.

📋 Invoice #: {invoice.legacy_invoice_number or invoice.id}
🐾 Pet: {pet.name} ({pet.breed})
📅 Stay: {check_in_date} – {check_out_date}
🏠 Branch: {branch.name}

💰 Total: ₹{invoice.revenue}
✅ Paid:  ₹{invoice.paid}
🔴 Due:   ₹{invoice.due}

Please make the balance payment at check-out.

Thank you!
Onukonu Pet Boarding
```

If `invoice.due` is zero, the last two lines are replaced with:

```
Your account is fully settled. Thank you!

Onukonu Pet Boarding
```

**URL construction:**
```
https://wa.me/{normaliseForWhatsApp(client.phone)}?text={encodeURIComponent(message)}
```

Opened in a new browser tab (`target="_blank" rel="noopener"`).

---

#### UC-2 — Send Onboarding Message to New Client

**Trigger locations:**
- After successfully saving a new client (S05) — post-save banner with "Send onboarding message via WhatsApp" button
- Client Profile header (S04) — `[📱 WhatsApp]` button opens a dropdown with message type choices: "Onboarding message" · "Send invoice" (if active booking exists)

**Message template:**

```
Hi {client.name}, welcome to Onukonu Pet Homestyle Boarding! 🐾

We have registered {pet.name} at our {branch.name} branch.

To complete your pet's profile, please share the following with us:
• Recent vaccination certificates
• A clear photo of {pet.name}
• Any dietary or medical notes we should know

You can WhatsApp these directly to this number or bring them on your first visit.

If you have any questions, feel free to reach out.

See you soon!
Onukonu Pet Boarding
```

If the client has multiple pets, `{pet.name}` is replaced with a comma-separated list of pet names.

---

### 10.3 Button Design

WhatsApp buttons use the standard WhatsApp green (`#25D366`) with a white WhatsApp icon. They appear as:

- **Primary context** (only WhatsApp action on screen): full-width green button with icon + label "Send via WhatsApp"
- **Secondary context** (alongside Print, Adjust, etc.): icon-only button with `title="Send via WhatsApp"` tooltip, same green colour

On mobile the button is always full-width below the action row.

### 10.4 Message Customisation (Settings)

A simple message template editor is added to Settings → Branches (S22). Staff can edit the body text of each template. The placeholder tokens `{client.name}`, `{pet.name}`, `{branch.name}`, `{invoice.revenue}`, etc. are documented inline.

This is a textarea-per-template stored in `wp_opb_branches.whatsapp_templates` as JSON:

```json
{
  "invoice": "Hi {client.name}, ...",
  "onboarding": "Hi {client.name}, welcome ..."
}
```

### 10.5 Implementation Notes

- **No server-side component.** URL generation is entirely client-side in a `useWhatsApp(client, type, context)` React hook.
- **Phone validation guard.** If `client.phone` is blank or cannot be normalised to a valid mobile number, the WhatsApp button is disabled with tooltip "No valid phone number on file".
- **URL length.** WhatsApp supports `wa.me` text up to ~4,096 characters. All templates are well within this limit. No truncation logic required.
- **No read receipts, no delivery tracking.** This is a one-way open-link. No tracking or confirmation that the message was sent.

---

## Approval Checklist

Before coding begins, confirm:

- [ ] Information architecture matches expected workflows
- [ ] Screen inventory is complete — no missing screens
- [ ] Navigation structure is clear for staff
- [ ] Database schema columns are correct and complete
- [ ] User roles match real staff structure (job titles, access levels)
- [ ] Migration field mappings are correct
- [ ] Wireframe layouts match legacy SaaS feel
- [ ] Plugin directory structure is acceptable
- [ ] REST API surface covers all required operations
- [ ] PWA offline behaviour meets field requirements
- [ ] WhatsApp message templates match the tone used with clients
- [ ] WhatsApp onboarding message content is correct
