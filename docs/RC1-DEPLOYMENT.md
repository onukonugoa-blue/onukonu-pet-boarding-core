# OPB RC1 — Deployment Instructions

**Product:** Onukonu Pet Boarding Core (OPB)  
**Release:** RC1  
**Generated:** 2026-06-19

---

## Prerequisites

| Requirement | Version |
|---|---|
| WordPress | 6.4+ |
| PHP | 8.2+ |
| MySQL | 5.7+ (or MariaDB 10.4+) |
| Hosting | Hostinger shared hosting or equivalent |

---

## 1. Fresh Installation

1. Download `onukonu-pet-boarding-rc1.zip`
2. WordPress admin → **Plugins → Add New → Upload Plugin**
3. Choose `onukonu-pet-boarding-rc1.zip` → **Install Now**
4. Click **Activate Plugin**
5. The activator runs automatically:
   - Creates all `opb_*` database tables
   - Registers OPB roles and capabilities
   - Schedules all WP Cron hooks

---

## 2. Upgrade from v3.0.x or v3.1.0

1. Deactivate the existing plugin
2. Delete the existing plugin (or upload over it)
3. Upload and activate `onukonu-pet-boarding-rc1.zip`
4. The activator detects `opb_db_version` mismatch and runs upgrade routines
5. `opb_sal_brief_history` table created if not present
6. All existing data, settings, and configuration preserved

---

## 3. Post-Activation Configuration

### Step 1 — OPB Roles
Navigate to **WordPress Users**. Assign OPB roles to your team:
- Super Admin → owner/operator
- Branch Manager → assign `opb_branch_id` user meta to branch ID
- Reception → branch assignment required
- Staff → branch assignment required

### Step 2 — Branches
**OPB → Settings → Branches** — verify or create your branch records.

### Step 3 — OPSMAIL + Telegram
**OPB → Settings → Customization → OPSMAIL**:
- `Telegram Bot Token` — from @BotFather on Telegram
- `Telegram Chat ID` — group or channel chat ID for operational alerts
- `Gemini API Key` — from Google AI Studio
- `OPSMAIL Enabled` — set to `1` to activate event emission

Click **Test Telegram** to verify connectivity.

### Step 4 — SAL
**OPB → Operations → SAL**:
- Enable/disable individual brief types
- Set delivery times (default: Morning 07:00, Evening 19:00, Accounts 09:00)
- Optionally set a dedicated SAL chat ID (otherwise uses global Telegram chat ID)

Click **Send Now** for any brief type to confirm end-to-end delivery.

### Step 5 — External Cron (Recommended)
Configure a server-level cron job for reliable scheduling. See Section 4.

---

## 4. External Cron Configuration

### Why

WP-Cron only fires when a visitor loads a WordPress page. On low-traffic sites, this means SAL briefs may be delayed and the OPSMAIL queue may not process promptly.

### Hostinger Cron Setup

1. Log in to hPanel
2. Navigate to **Advanced → Cron Jobs**
3. Add a new cron job:

**Command:**
```bash
wget -q -O /dev/null "https://yourdomain.com/?doing_wp_cron" > /dev/null 2>&1
```

Or using WP-CLI if available:
```bash
/usr/local/bin/php /home/username/public_html/wp-cron.php > /dev/null 2>&1
```

**Schedule:** Every 5 minutes
```
*/5 * * * *
```

4. Add a second cron at `:00` of each brief hour (7, 9, 19) for SAL precision:
```
0 7,9,19 * * *
```

### Disabling WP-Cron Visitor Triggering

Once external cron is configured, add to `wp-config.php`:
```php
define( 'DISABLE_WP_CRON', true );
```

OPB's cron health monitor detects this constant and confirms external cron is active.

### Verification

**OPB → Operations → OPSMAIL Queue** → Scheduler Health panel shows:
- ✅ External cron detected (if `DISABLE_WP_CRON` is true or interval < 8 min)
- ✅ Queue consumer: Healthy
- ✅ Mailbox: Healthy
- ✅ SAL: Healthy

---

## 5. Post-Activation Verification Checklist

```
[ ] OPB → Dashboard loads without errors
[ ] OPB → Clients — create a test client
[ ] OPB → Bookings — create a test booking
[ ] OPB → Operations → OPSMAIL Queue → Test Telegram → "success"
[ ] OPB → Operations → OPSMAIL Queue → Test Gemini → classification result returned
[ ] OPB → Operations → SAL → Preview (Morning Brief) → brief text generated
[ ] OPB → Operations → SAL → Send Now (Morning Brief) → message appears in Telegram
[ ] OPB → Operations → OPSMAIL Queue → queue row appears with source_system = SAL
[ ] OPB → Settings → Scheduler Health → all components show "Healthy"
```

---

## 6. Building from Source

A developer building from the repository:

```bash
# 1. Clone the repository
git clone <repo-url>
cd onukonu-pet-boarding-core

# 2. Install root dependencies (docs server + build tools)
npm install

# 3. Install React app dependencies
cd plugin/app
npm install

# 4. Build compiled assets
npm run build
# Output: plugin/assets/dist/assets/index.js + main.css

# 5. Return to root and build the RC1 ZIP
cd ../..
node build-rc1.js
# Output: onukonu-pet-boarding-rc1.zip
```

**PHP Composer vendor is committed.** No `composer install` required.

---

## 7. File Exclusions from Production ZIP

The following are excluded from `onukonu-pet-boarding-rc1.zip`:

| Excluded | Reason |
|---|---|
| `plugin/app/` | React source — compiled assets are included |
| `plugin/tests/` | Test fixtures |
| `plugin/vendor/bin/` | Composer CLI tools |
| `node_modules/` | Build tooling |
| `.git/` | Version control |
| `*.zip` | Historical build artefacts |
| `legacy-system/` | Legacy migration data |
| `attached_assets/` | Project context documents |
| `docs/` | Development documentation |
| Dev config files | `tsconfig.json`, `vite.config.ts`, `package.json`, etc. |

---

## 8. Rollback

To rollback to the previous version:
1. Deactivate OPB
2. Upload the previous ZIP
3. Activate — the activator will adjust to the previous `OPB_VERSION`

No destructive schema changes are performed on upgrade. All tables use `CREATE TABLE IF NOT EXISTS` and `ADD COLUMN IF NOT EXISTS` equivalents (via `INFORMATION_SCHEMA` checks for MySQL 5.7 compatibility).
