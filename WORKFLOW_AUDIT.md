# WORKFLOW AUDIT — Acceptance Testing Trace

**Date:** 2026-05-31  
**Plugin version:** 1.0.8  
**Goal:** Can a branch operator successfully process one real boarding visit end-to-end?  
**Verdict: NO** — three hard blockers prevent completion.

---

## Summary of blockers

| ID | Step | Description | Severity |
|---|---|---|---|
| **B1** | Booking | `boarding_service_id` is never collected — invoice revenue is always ₹0 | **HARD BLOCKER** |
| **B2** | Booking | `meal_type` values sent by UI don't match DB ENUM — breaks INSERT in strict mode | **HARD BLOCKER** |
| **D1** | Check-out | `stay_id` always resolves to 0 — no stay is ever marked Completed | **HARD BLOCKER** |
| **D2** | Check-out | Payment submitted at check-out is silently dropped by API | Workflow defect |
| **C1** | Check-in | Kennel, weight, companion data silently lost due to payload shape mismatch | Data loss |
| **C2** | Check-in | Multi-pet bookings: API checks in only ONE pet per call | Data loss |
| **E1** | Invoice | Payment button hidden because `due = ₹0` (caused by B1) | Downstream of B1 |
| **E2** | Invoice | Client link uses `booking_id` instead of `client_id` — navigation broken | UI defect |
| **P1** | Pet | `breed_size` dropdown offers `'Toy'` and `'X-Large'` — not in DB ENUM | Data integrity |

---

## Step-by-step trace

---

### Step 1 — Client

**UI:** `/clients/new` → `ClientForm.tsx`  
**API:** `POST /opb/v1/clients`  
**Tables:** `opb_clients`

**Form fields collected:** name ✅, phone ✅, email, address, home_branch_id (branch selector), local guardian, onboarding date, status, T&C checkbox.

**Defects:**
- `home_branch_id = 0` can be submitted — no front-end guard. API may reject or create an unscoped client. Minor; easily avoided by selecting a branch.

**Blockers:** None. Client creation works.

---

### Step 2 — Pet

**UI:** Client Profile → Pets tab → `+ Pet` → `PetForm.tsx` at `/clients/:id/pets/new`  
**API:** `POST /opb/v1/clients/{id}/pets`  
**Tables:** `opb_pets`

**Form fields collected:** name ✅, pet_type, breed, breed_size, gender, weight, birthday, coat, dietary preference, allergies, medication, neutered flag, vet, vaccination dates.

**Defect P1 — breed_size ENUM mismatch:**
```
UI dropdown options : ['Toy', 'Small', 'Medium', 'Large', 'X-Large']
DB ENUM             : ENUM('Small','Medium','Large')
```
- If user selects `'Toy'` or `'X-Large'`, MySQL in strict mode rejects the row update.
- Without strict mode, stores empty string — pricing engine breed size modifier silently skipped.
- **Impact:** Low for workflow completion if operator picks Small/Medium/Large. High for data integrity.

**Blockers:** None if operator picks a valid size. Pet creation works.

---

### Step 3 — Booking

**UI:** `/bookings/new` → `BookingCreate.tsx`  
**API:** `POST /opb/v1/bookings`  
**Tables written:** `opb_bookings`, `opb_booking_stays`, `opb_invoices`, `opb_invoice_line_items`

---

#### HARD BLOCKER B1 — `boarding_service_id` never collected

**Source — `BookingCreate.tsx` `StayForm` interface:**
```typescript
interface StayForm {
  pet_id: number
  boarding_type: 'DAY' | 'OVERNIGHT'
  check_in_date: string
  check_out_date: string
  check_in_slot: string
  check_out_slot: string
  kennel: string
  meal_type: string   // ← free-text label, not a catalogue ID
  notes: string
  // boarding_service_id is ABSENT
}
```
The form has no boarding service selector. The payload sent to the API never includes `boarding_service_id`.

**Source — `class-opb-bookings-api.php` line 162:**
```php
'boarding_service_id' => (int)($stay['boarding_service_id']??0) ?: null,
```
Stores `NULL` for every new stay.

**Source — `class-opb-invoice-generator.php`:**
```php
foreach ( $stays as $stay ) {
    if ( ! $stay['boarding_service_id'] ) continue;  // ← always skipped
    $result = OPB_Pricing_Engine::calculate(...);
    ...
}
```
`OPB_Pricing_Engine::calculate()` is never called. Zero line items are produced.

**Downstream cascade:**
```
boarding_service_id = NULL
→ pricing engine skipped
→ base_amount = 0, revenue = 0
→ invoice.payment_status = 'No bill'
→ invoice.due = 0
→ "+ Payment" button hidden (condition: invoice.due > 0)
→ operator cannot record payment at all
```

**Every single new booking has invoice revenue = ₹0. The entire billing chain is broken.**

---

#### HARD BLOCKER B2 — `meal_type` values don't match DB ENUM

**Source — `BookingCreate.tsx`:**
```typescript
const MEALS = ['Vegetarian', 'Non-Vegetarian', 'Home Food', 'Royal Canin', 'Other']
// Default: meal_type: 'Vegetarian'
```

**Source — `class-opb-activator.php` `opb_booking_stays` table definition:**
```sql
meal_type ENUM('BOARDING_MEALS','PARENT_SUPPLIED_MEAL')
```

**Source — `class-opb-bookings-api.php` line 168:**
```php
'meal_type' => sanitize_text_field($stay['meal_type']??'PARENT_SUPPLIED_MEAL'),
```
`sanitize_text_field('Vegetarian')` returns `'Vegetarian'` unchanged. MySQL then receives a value that is not in the ENUM.

- **MySQL strict mode ON** (`STRICT_TRANS_TABLES`): INSERT fails. **Booking creation returns a DB error.** Operator sees a generic failure message.
- **MySQL strict mode OFF**: Stores `''` (empty string — MySQL ENUM default on invalid). Pricing engine checks `$meal_type === 'BOARDING_MEALS'`, gets `''`, meals never charged.

WordPress on most shared/managed hosts enables strict mode. On those hosts, booking creation fails on the stays insert, making the booking form completely non-functional.

---

**Other booking defects (non-blocking if B1/B2 are fixed):**
- No add-on service selector in the booking form. Add-ons can only be added post-creation from Booking Detail (UI exists but undiscoverable from the creation flow).
- No `booking_source` field in the creation form.

---

### Step 4 — Stay (created with booking)

**Tables:** `opb_booking_stays` — stay record exists with `status = 'Upcoming'`

State at this point (assuming B2 doesn't block creation):
- `boarding_service_id = NULL` (Blocker B1)
- `meal_type = ''` or invalid (Blocker B2)
- `status = 'Upcoming'`
- `kennel` may be pre-filled from booking form, or empty

---

### Step 5 — Check-in

**UI:** Booking Detail → `Check In` button → `/bookings/:id/checkin` → `CheckIn.tsx`  
**API:** `POST /opb/v1/bookings/{id}/checkin`  
**Tables:** `opb_booking_stays`

---

#### Defect C1 — Kennel, weight, companion data silently lost

**Source — `CheckIn.tsx` `handleSubmit`:**
```typescript
await bookingsApi.checkin(Number(id), {
  stays: upcomingStays.map((s) => ({
    stay_id: s.id,
    kennel: stayData[s.id]?.kennel,
    weight_at_checkin: ...,
    companion_name: ...,
    companion_phone: ...,
    notes: ...
  }))
})
```
UI wraps all data in a `stays[]` array.

**Source — `class-opb-bookings-api.php` line 211:**
```php
$stay_id = (int)($d['stay_id']??0);
// fallback: find first Upcoming stay for this booking
$where = $stay_id ? ['id'=>$stay_id,'booking_id'=>$id] : ['booking_id'=>$id,'status'=>'Upcoming'];
$stay  = $wpdb->get_row(...);
$stay_id = $stay_id ?: ($stay['id']??0);

$wpdb->update([
    'status'            => 'Active',
    'kennel'            => sanitize_text_field($d['kennel']??''),        // reads top-level
    'weight_at_checkin' => isset($d['weight_at_checkin'])?(float)$d['weight_at_checkin']:null, // top-level
    'companion_name'    => sanitize_text_field($d['companion_name']??''), // top-level
    'companion_phone'   => sanitize_text_field($d['companion_phone']??''), // top-level
    'notes'             => sanitize_textarea_field($d['notes']??''),      // top-level
], ['id'=>$stay_id]);
```

`$d['stay_id']` → `null` (it's inside `stays[0]`). Fallback fires and finds the first Upcoming stay. Status changes to `'Active'`.

But `$d['kennel']`, `$d['weight_at_checkin']`, `$d['companion_name']`, `$d['companion_phone']`, `$d['notes']` are all `null`/empty because the data is nested inside `stays[0]`, not at the top level.

**Result:** Stay status becomes `Active`. All other check-in data (kennel assignment, check-in weight, companion info, notes) is silently discarded.

---

#### Defect C2 — Multi-pet bookings: only one stay checked in per call

The API processes exactly one stay per `POST /checkin` call. The UI sends all stays in a single call. The API's fallback (`['booking_id'=>$id,'status'=>'Upcoming']`) selects the first match and updates it. The remaining pets stay `Upcoming`.

After the single check-in call, the booking detail shows some pets `Active`, others `Upcoming`. The `Check Out` button appears (because `stays.some(s => s.status === 'Active')`), but checking out while other pets are still `Upcoming` leaves the booking in a permanently inconsistent state.

**Impact for single-pet:** Status changes, data is lost — but workflow can continue.  
**Impact for multi-pet:** Only one pet checked in. Blocker for multi-pet visits.

---

### Step 6 — Check-out

**UI:** Booking Detail → `Check Out` button → `/bookings/:id/checkout` → `CheckOut.tsx`  
**API:** `POST /opb/v1/bookings/{id}/checkout`  
**Tables:** `opb_booking_stays`, `opb_invoices`, `opb_invoice_line_items`, `opb_payments`

---

#### HARD BLOCKER D1 — No stay is ever marked Completed

**Source — `CheckOut.tsx` `handleSubmit`:**
```typescript
const checkoutData = {
  stays: activeStays.map((s) => ({
    stay_id: s.id,
    weight_at_checkout: weights[s.id] ? Number(weights[s.id]) : null
  })),
  payment: { amount, mode, notes }   // if amount > 0
}
await bookingsApi.checkout(Number(id), checkoutData)
```

**Source — `class-opb-bookings-api.php` line 238:**
```php
$stay_id = (int)($d['stay_id']??0);   // ← reads top-level, gets 0
$wpdb->update([
    'status'             => 'Completed',
    'actual_check_out_at'=> ...,
    'weight_at_checkout' => ...,
    'late_checkout_fees' => ...,
], ['id' => $stay_id, 'booking_id' => $id]);
//  ^^^^^^^^^^^^^^^^
//  id=0 matches zero rows. No update occurs.
```

Unlike the check-in endpoint, the checkout endpoint has **no fallback**. It does not search for the first Active stay when `stay_id = 0`. The `UPDATE` runs with `WHERE id=0 AND booking_id=N` — this matches nothing.

The API then calls `OPB_Invoice_Generator::recalculate()` (which produces ₹0 because of B1) and returns the booking object unchanged.

**The call appears to succeed from the UI's perspective** (HTTP 200, navigates back to Booking Detail). But no stay is ever marked `Completed`. Every stay remains `'Active'` permanently. The operator is never aware that checkout failed.

**This is an invisible hard failure.**

---

#### Defect D2 — Payment at check-out silently dropped

The UI sends `checkoutData.payment = { amount, mode, notes }` in the checkout payload.

The checkout API reads `$d['stay_id']`, `$d['weight_at_checkout']`, `$d['late_checkout_fees']`. It never reads `$d['payment']`. No payment insert occurs. No error is returned.

**Result:** Any payment entered on the check-out screen is silently discarded. Invoice remains Unpaid.

**Note:** Even if this were fixed, Blocker B1 means `invoice.due = 0` and the payment panel doesn't render. Both issues must be resolved.

---

### Step 7 — Invoice

**UI:** Booking Detail → `Full Invoice →` → `/invoices/:id` → `InvoiceDetail.tsx`  
**API:** `GET /opb/v1/invoices/{id}`  
**Tables read:** `opb_invoices`, `opb_invoice_line_items`, `opb_payments`

**State at this point (caused by B1):**
- `revenue = 0.00`
- `base_amount = 0.00`
- `line_items = []` (empty — pricing engine was never called)
- `due = 0.00`
- `payment_status = 'No bill'`

**Defect E1 — Payment button hidden:**
```tsx
{invoice.due > 0 && <button onClick={() => setPayModal(true)} className="btn-primary btn-sm">+ Payment</button>}
```
When `due = 0`, the button does not render. Operator cannot record a payment from this screen.

This is a direct downstream consequence of Blocker B1. Fix B1 and E1 resolves itself.

---

**Defect E2 — Client link uses wrong ID:**
```tsx
<Link to={`/clients/${invoice.booking_id}`} className="hover:underline">
  {invoice.client_name}
</Link>
```
`invoice.booking_id` is the booking's integer ID. The client link should use `invoice.client_id` (or navigate via the booking). Clicking the client name on an invoice navigates to a booking URL interpreted as a client, producing a "Client not found" error.

---

### Step 8 — Payment

**UI:** Invoice Detail → `+ Payment` modal, or Check-out screen (Defect D2 above)  
**API:** `POST /opb/v1/invoices/{id}/payments`  
**Tables:** `opb_payments`, `opb_invoices` (sync)

**Defect — Payment mode options mismatch:**
```
CheckOut.tsx MODES : ['Cash', 'UPI', 'Card', 'Bank Transfer', 'Other']
opb_payments.mode  : ENUM('Cash', 'UPI', 'Other')
```
`'Card'` and `'Bank Transfer'` are not valid ENUM values. MySQL strict mode will reject payment inserts using these modes.

**The payment recording API itself (`POST /invoices/{id}/payments`) works correctly** when it receives a valid payload with `amount > 0` and a valid mode. `OPB_Invoice_Generator::sync_payment_totals()` is called on success and correctly updates `paid`, `due`, and `payment_status` on both the invoice and the booking.

**This step is reachable and functional ONLY if:**
1. Blocker B1 is fixed (so `revenue > 0` and `due > 0`)
2. The `+ Payment` button becomes visible
3. A valid mode (`Cash` or `UPI`) is used

---

## Defect inventory

| ID | File | Description | Type |
|---|---|---|---|
| B1 | `BookingCreate.tsx` | No `boarding_service_id` selector — invoice always ₹0 | Hard blocker |
| B2 | `BookingCreate.tsx` / `class-opb-bookings-api.php` | `meal_type` UI values don't match `ENUM('BOARDING_MEALS','PARENT_SUPPLIED_MEAL')` | Hard blocker (strict mode) |
| C1 | `CheckIn.tsx` / `class-opb-bookings-api.php` | UI sends `stays[]` array; API reads flat top-level fields — kennel/weight/companion lost | Data loss |
| C2 | `class-opb-bookings-api.php` | Check-in processes one stay per API call — multi-pet visits only partially checked in | Data loss |
| D1 | `CheckOut.tsx` / `class-opb-bookings-api.php` | `stay_id=0` + no fallback — no stay ever marked Completed | Hard blocker |
| D2 | `CheckOut.tsx` / `class-opb-bookings-api.php` | Payment in checkout payload never processed by API | Workflow defect |
| E1 | `InvoiceDetail.tsx` | `+ Payment` hidden when `due = 0` (downstream of B1) | Downstream |
| E2 | `InvoiceDetail.tsx` | Client `<Link>` uses `invoice.booking_id` instead of `invoice.client_id` | UI defect |
| P1 | `PetForm.tsx` | `breed_size` offers `'Toy'` / `'X-Large'`; DB ENUM is `('Small','Medium','Large')` | Data integrity |
| PM1 | `CheckOut.tsx` | Payment modes `'Card'` / `'Bank Transfer'` not in `payments.mode` ENUM | Data integrity |

---

## Fix order for unblocking one boarding visit

Fix these three in sequence. Everything else is secondary until these pass.

### Fix 1 — Add boarding service selector to BookingCreate (B1)

Add a `boarding_service_id` field to `StayForm` and render a `<select>` populated from `GET /opb/v1/settings/boarding?branch_id={branchId}`. Pass it in the stays payload. When a service is selected, the pricing engine is called at booking creation, the invoice gets real line items, and the entire billing chain becomes functional.

### Fix 2 — Correct meal_type values (B2)

Change `BookingCreate.tsx` MEALS options to the two valid DB values:

```typescript
// Before
const MEALS = ['Vegetarian', 'Non-Vegetarian', 'Home Food', 'Royal Canin', 'Other']

// After — display labels mapped to DB values
const MEAL_OPTIONS = [
  { label: 'Boarding Meals',      value: 'BOARDING_MEALS' },
  { label: 'Parent Supplied Meal', value: 'PARENT_SUPPLIED_MEAL' },
]
```

Update the default in `StayForm` and the select accordingly.

### Fix 3 — Fix check-out payload shape (D1)

Two sub-fixes needed — choose one of:

**Option A (preferred): Fix the UI to send flat payload (matches existing API contract)**

```typescript
// Current — broken
await bookingsApi.checkout(Number(id), {
  stays: activeStays.map((s) => ({ stay_id: s.id, weight_at_checkout: ... })),
  payment: { ... }
})

// Fixed — single-stay checkout, call sequentially for multi-pet
for (const s of activeStays) {
  await bookingsApi.checkout(Number(id), {
    stay_id: s.id,
    weight_at_checkout: weights[s.id] ? Number(weights[s.id]) : null,
  })
}
// Then record payment separately via invoicesApi.recordPayment(...)
```

**Option B: Fix the API to accept the `stays[]` array**

Iterate `$d['stays']` in the PHP checkout handler and update each stay_id in a loop.

Either way, also fix check-in (C1) the same way to restore kennel/weight/companion data.

---

## Fixes that can wait (post-unblocking)

Once the three hard blockers above are resolved and a boarding visit can complete end-to-end:

| Priority | Defect | Fix |
|---|---|---|
| High | C2 (multi-pet check-in) | Fix alongside D1 fix (same pattern) |
| High | D2 (payment dropped at checkout) | Record payment in invoicesApi after checkout, not inside checkout payload |
| Medium | E2 (invoice client link) | Change `invoice.booking_id` to `invoice.client_id` in `InvoiceDetail.tsx` |
| Medium | PM1 (payment modes mismatch) | Remove `'Card'` / `'Bank Transfer'` from `CheckOut.tsx MODES` or add them to the DB ENUM |
| Low | P1 (breed_size mismatch) | Remove `'Toy'` / `'X-Large'` from PetForm or add them to the DB ENUM |
| Low | C1 (data loss on check-in) | Fix alongside D1; same payload pattern |
