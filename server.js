const http = require('http');
const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');

const PORT = 5000;
const HOST = '0.0.0.0';

function readFileSafe(filePath) {
  try {
    return fs.readFileSync(filePath, 'utf8');
  } catch {
    return null;
  }
}

function escapeHtml(str) {
  return str
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;');
}

function simpleMarkdown(md) {
  return md
    .replace(/^### (.+)$/gm, '<h3>$1</h3>')
    .replace(/^## (.+)$/gm, '<h2>$1</h2>')
    .replace(/^# (.+)$/gm, '<h1>$1</h1>')
    .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
    .replace(/`([^`]+)`/g, '<code>$1</code>')
    .replace(/^- (.+)$/gm, '<li>$1</li>')
    .replace(/(<li>.*<\/li>\n?)+/g, (m) => `<ul>${m}</ul>`)
    .replace(/```[\w]*\n([\s\S]*?)```/g, (_, code) => `<pre><code>${escapeHtml(code)}</code></pre>`)
    .replace(/^(?!<[hupol]|<\/[hupol]|<pre|<\/pre)(.+)$/gm, '<p>$1</p>')
    .replace(/<p><\/p>/g, '');
}

function getPluginTree() {
  function walk(dir, prefix = '') {
    let result = '';
    try {
      const entries = fs.readdirSync(dir, { withFileTypes: true });
      entries.forEach((entry, i) => {
        if (entry.name.startsWith('.')) return;
        const isLast = i === entries.length - 1;
        const connector = isLast ? '└── ' : '├── ';
        result += `${prefix}${connector}${entry.name}\n`;
        if (entry.isDirectory()) {
          result += walk(path.join(dir, entry.name), prefix + (isLast ? '    ' : '│   '));
        }
      });
    } catch {}
    return result;
  }
  return walk('.');
}

function getLegacyStats() {
  const legacyDir = 'legacy-system';
  const stats = {};
  try {
    const branches = fs.readdirSync(legacyDir, { withFileTypes: true });
    branches.forEach(b => {
      if (b.isDirectory()) {
        const files = fs.readdirSync(path.join(legacyDir, b.name));
        stats[b.name] = files;
      } else {
        stats[b.name] = null;
      }
    });
  } catch {}
  return stats;
}

function getPluginVersion() {
  const php = readFileSafe('plugin/onukonu-pet-boarding-core.php');
  if (!php) return null;
  const m = php.match(/\*\s+Version:\s+([^\r\n]+)/);
  return m ? m[1].trim() : null;
}

function getPluginMeta() {
  const php = readFileSafe('plugin/onukonu-pet-boarding-core.php');
  if (!php) return {};
  const get = (field) => {
    const m = php.match(new RegExp(`\\*\\s+${field}:\\s+([^\\r\\n]+)`));
    return m ? m[1].trim() : '';
  };
  return {
    version:     get('Version'),
    pluginName:  get('Plugin Name'),
    description: get('Description'),
    author:      get('Author'),
  };
}

function getGitLog() {
  try {
    const raw = execSync(
      'git --no-optional-locks log --format="%H|%h|%as|%s" --all',
      { encoding: 'utf8', timeout: 5000 }
    ).trim();

    if (!raw) return [];

    return raw.split('\n').map(line => {
      const [hash, shortHash, date, ...subjectParts] = line.split('|');
      return {
        hash:      hash.trim(),
        shortHash: shortHash.trim(),
        date:      date.trim(),
        subject:   subjectParts.join('|').trim(),
      };
    }).filter(c => c.hash);
  } catch {
    return [];
  }
}

function getGitHead() {
  try {
    return execSync('git --no-optional-locks rev-parse HEAD', { encoding: 'utf8', timeout: 3000 }).trim();
  } catch {
    return null;
  }
}

function formatDate(iso) {
  try {
    return new Date(iso).toLocaleDateString('en-IN', {
      day: 'numeric', month: 'long', year: 'numeric',
    });
  } catch {
    return iso;
  }
}

function renderPage(activeTab) {
  const readme = readFileSafe('README.md') || '# No README found';
  const architecture = readFileSafe('docs/ARCHITECTURE.md') || '# No ARCHITECTURE.md found';
  const analysis = readFileSafe('docs/ANALYSIS.md') || '# No ANALYSIS.md found';
  const legacyStats = getLegacyStats();
  const tree = getPluginTree();
  const pluginMeta = getPluginMeta();
  const pluginVersion = pluginMeta.version || '—';

  let content = '';
  let title = '';

  if (activeTab === 'overview') {
    title = 'Overview';
    content = `
      <div class="card">
        <h2>Project Overview</h2>
        ${simpleMarkdown(readme)}
      </div>
      <div class="card">
        <h2>Project Structure</h2>
        <pre><code>${escapeHtml(tree)}</code></pre>
      </div>
      <div class="card">
        <h2>Legacy Data Files</h2>
        <table>
          <thead><tr><th>Directory / File</th><th>Contents</th></tr></thead>
          <tbody>
            ${Object.entries(legacyStats).map(([dir, files]) =>
              files
                ? `<tr><td><strong>${dir}/</strong></td><td>${files.join(', ')}</td></tr>`
                : `<tr><td>${dir}</td><td><em>file</em></td></tr>`
            ).join('')}
          </tbody>
        </table>
      </div>
    `;
  } else if (activeTab === 'architecture') {
    title = 'Architecture';
    content = `<div class="card doc-content">${simpleMarkdown(architecture)}</div>`;
  } else if (activeTab === 'analysis') {
    title = 'Analysis';
    content = `<div class="card doc-content">${simpleMarkdown(analysis)}</div>`;
  } else if (activeTab === 'plugin') {
    title = `Plugin v${pluginVersion}`;
    content = `
      <div class="card">
        <h2>Plugin — v${escapeHtml(pluginVersion)}</h2>
        <p>The <code>plugin/</code> directory contains the complete WordPress plugin. Install by uploading
        <strong>onukonu-pet-boarding-core-v${escapeHtml(pluginVersion)}.zip</strong> via WP Admin → Plugins → Add New → Upload.</p>

        <h3>v1.8.0 — Customization Module</h3>
        <ul>
          <li><strong>New DB table</strong> — opb_customizations (key/value store for all configurable content)</li>
          <li><strong>22 configurable settings</strong> — across Facility Info, Legal &amp; T&amp;C, Onboarding Messages, and Inquiry Messages</li>
          <li><strong>Template engine</strong> — <code>OPB_Customizations::render()</code> replaces <code>{{PLACEHOLDER}}</code> tokens in all outbound messages</li>
          <li><strong>Preview mode</strong> — render any template with sample data before saving</li>
          <li><strong>Export endpoint</strong> — download all customizations as JSON snapshot</li>
          <li><strong>Access control</strong> — read: any staff; write: <code>opb_manage_settings</code> / administrator only</li>
        </ul>

        <h3>REST Endpoints (v1.8.0)</h3>
        <table>
          <thead><tr><th>Method</th><th>Endpoint</th><th>Auth</th><th>Purpose</th></tr></thead>
          <tbody>
            <tr><td>GET</td><td>/wp-json/opb/v1/settings/customizations</td><td>Staff</td><td>List all settings</td></tr>
            <tr><td>PUT</td><td>/wp-json/opb/v1/settings/customizations/{key}</td><td>Admin</td><td>Update one setting</td></tr>
            <tr><td>GET</td><td>/wp-json/opb/v1/settings/customizations/export</td><td>Admin</td><td>JSON export snapshot</td></tr>
            <tr><td>POST</td><td>/wp-json/opb/v1/settings/customizations/preview</td><td>Admin</td><td>Render template with sample data</td></tr>
          </tbody>
        </table>

        <h3>v1.7.0 — Inquiry &amp; Onboarding Pipeline</h3>
        <ul>
          <li><strong>5 new DB tables</strong> — opb_inquiries, opb_inquiry_notes, opb_onboarding_clients, opb_onboarding_pets, opb_onboarding_documents</li>
          <li><strong>Public Inquiry Form</strong> — standalone page at <code>/opb-inquiry/</code> (no login required)</li>
          <li><strong>Onboarding Portal</strong> — multi-step form at <code>/opb-onboard/{token}/</code> with document upload + T&amp;C acceptance</li>
          <li><strong>Staff Inquiries module</strong> — list, detail, notes, status management in the React SPA</li>
          <li><strong>Send Onboarding</strong> — WhatsApp wa.me link, Email (manual record), or Manual modes</li>
          <li><strong>Duplicate detection</strong> — phone + email cross-check against existing clients before conversion</li>
          <li><strong>Convert to Client</strong> — explicit staff action only; creates Client + Pets + Documents from staging tables</li>
        </ul>

        <h3>REST Endpoints (v1.7.0)</h3>
        <table>
          <thead><tr><th>Method</th><th>Endpoint</th><th>Auth</th><th>Purpose</th></tr></thead>
          <tbody>
            <tr><td>POST</td><td>/wp-json/opb/v1/public/inquiry</td><td>None</td><td>Submit public inquiry</td></tr>
            <tr><td>GET</td><td>/wp-json/opb/v1/public/onboarding/{token}</td><td>None</td><td>Fetch onboarding form data</td></tr>
            <tr><td>POST</td><td>/wp-json/opb/v1/public/onboarding/{token}</td><td>None</td><td>Submit onboarding form</td></tr>
            <tr><td>POST</td><td>/wp-json/opb/v1/public/onboarding/{token}/upload</td><td>None</td><td>Upload document</td></tr>
            <tr><td>POST</td><td>/wp-json/opb/v1/public/onboarding/{token}/accept-terms</td><td>None</td><td>Accept T&amp;C</td></tr>
            <tr><td>GET</td><td>/wp-json/opb/v1/inquiries</td><td>Staff</td><td>List inquiries</td></tr>
            <tr><td>GET</td><td>/wp-json/opb/v1/inquiries/{id}</td><td>Staff</td><td>Inquiry detail</td></tr>
            <tr><td>PUT</td><td>/wp-json/opb/v1/inquiries/{id}</td><td>Staff</td><td>Update status</td></tr>
            <tr><td>POST</td><td>/wp-json/opb/v1/inquiries/{id}/notes</td><td>Staff</td><td>Add note</td></tr>
            <tr><td>POST</td><td>/wp-json/opb/v1/inquiries/{id}/send-onboarding</td><td>Staff</td><td>Send onboarding link</td></tr>
            <tr><td>POST</td><td>/wp-json/opb/v1/inquiries/{id}/reject</td><td>Staff</td><td>Reject inquiry</td></tr>
            <tr><td>POST</td><td>/wp-json/opb/v1/inquiries/{id}/archive</td><td>Staff</td><td>Archive inquiry</td></tr>
            <tr><td>GET</td><td>/wp-json/opb/v1/inquiries/{id}/duplicate-check</td><td>Staff</td><td>Check for existing client</td></tr>
            <tr><td>POST</td><td>/wp-json/opb/v1/inquiries/{id}/convert</td><td>Manager+</td><td>Convert to client (irreversible)</td></tr>
          </tbody>
        </table>

        <h3>Pipeline Flow</h3>
        <pre><code>Public Inquiry Form (/opb-inquiry/)
  → NEW inquiry created in opb_inquiries
  → Staff reviews in SPA /inquiries
  → Staff sends onboarding link (WhatsApp / Email / Manual)
  → Status → ONBOARDING_SENT
  → Customer fills form at /opb-onboard/{token}/
      - Owner details, pet details, uploads, T&C acceptance
  → Status → ONBOARDING_COMPLETED → READY_FOR_REVIEW
  → Staff reviews all submitted data
  → Staff clicks "Convert to Client" (branch required)
      - Duplicate check shown (phone + email)
      - Creates opb_clients + opb_pets + copies documents
  → Status → CONVERTED, redirect to new client profile</code></pre>

        <h3>Architecture Notes</h3>
        <ul>
          <li>Operational records (clients, pets, bookings) are NEVER auto-created — only by explicit staff "Convert" action</li>
          <li>Token: 64-char hex (32 bytes), UNIQUE in opb_inquiries. URL: <code>/opb-onboard/{token}/</code></li>
          <li>Files stored in <code>wp-content/uploads/opb-onboarding/{token}/</code>. Max 10 MB, images + PDF only</li>
          <li>WhatsApp: wa.me link only (no Meta API). Opens browser with pre-filled message.</li>
          <li>T&amp;C version constant: <code>OPB_Onboarding_Handler::TC_VERSION = '1.0'</code></li>
        </ul>
      </div>
    `;
  } else if (activeTab === 'changelog') {
    title = 'Changelog';
    const commits = getGitLog();
    const head = getGitHead();

    const grouped = {};
    for (const c of commits) {
      if (!grouped[c.date]) grouped[c.date] = [];
      grouped[c.date].push(c);
    }

    const commitRows = commits.length === 0
      ? '<tr><td colspan="3"><em>No git history available.</em></td></tr>'
      : commits.map(c => {
          const isHead = head && c.hash === head;
          return `
            <tr>
              <td style="white-space:nowrap;font-family:monospace;font-size:12px;color:#718096">
                ${escapeHtml(c.shortHash)}
                ${isHead ? '<span class="tag-head">HEAD</span>' : ''}
              </td>
              <td style="white-space:nowrap;color:#718096;font-size:13px">${escapeHtml(formatDate(c.date))}</td>
              <td>${escapeHtml(c.subject)}</td>
            </tr>`;
        }).join('');

    const timelineItems = Object.entries(grouped)
      .sort(([a], [b]) => b.localeCompare(a))
      .map(([date, cs]) => {
        const items = cs.map(c => {
          const isHead = head && c.hash === head;
          return `
            <div class="tl-item">
              <div class="tl-dot${isHead ? ' tl-dot-head' : ''}"></div>
              <div class="tl-body">
                <code class="tl-hash">${escapeHtml(c.shortHash)}</code>
                ${isHead ? '<span class="tag-head">HEAD</span>' : ''}
                <span class="tl-subject">${escapeHtml(c.subject)}</span>
              </div>
            </div>`;
        }).join('');
        return `
          <div class="tl-date-group">
            <div class="tl-date-label">${escapeHtml(formatDate(date))}</div>
            ${items}
          </div>`;
      }).join('');

    content = `
      <div class="card">
        <h2>Plugin Version</h2>
        <div class="version-row">
          <div class="version-box">
            <div class="version-num">v${escapeHtml(pluginVersion)}</div>
            <div class="version-sub">${escapeHtml(pluginMeta.pluginName || 'Onukonu Pet Boarding Core')}</div>
          </div>
          <div class="version-meta">
            <p>${escapeHtml(pluginMeta.description || '')}</p>
            ${head ? `<p style="margin-top:6px;font-family:monospace;font-size:12px;color:#718096">HEAD: ${escapeHtml(head)}</p>` : ''}
          </div>
        </div>
      </div>

      <div class="card">
        <h2>Release Notes</h2>

        <div class="release-entry">
          <div class="release-header">
            <span class="release-version">v2.7.0</span>
            <span class="release-date">12 June 2026</span>
            <span class="release-tag">Expense Management Enhancement</span>
          </div>
          <ul class="release-notes-list">
            <li><strong>New: Expense Category Management</strong> — Settings → Expense Categories; Super Admin / Admin only; create, rename, archive and restore categories; 8 default categories seeded on activation (Food, Medical, Grooming, Maintenance, Salary, Transport, Marketing, Other); archived categories hidden from the Add Expense form but historical records remain intact</li>
            <li><strong>New: Dynamic category filter</strong> — filter dropdown on Expenses page now generated from <code>GET /opb/v1/expenses/categories</code> which returns DISTINCT actual values from expense data; covers legacy imported categories that were never in the hard-coded list; no hard-coded list remains</li>
            <li><strong>New: Default date range (current month)</strong> — Expenses page defaults to the current calendar month on load instead of showing all records; Clear button resets to current month, not blank</li>
            <li><strong>New: Quick date filters</strong> — Today · This Month · Last Month · This Year preset buttons above the filter row</li>
            <li><strong>New: Expense summary panel</strong> — three KPI cards above the table: Total Expenses (₹), Number of Entries, Top Category; computed from the same filtered dataset; no charts, no analytics</li>
            <li><strong>New: User attribution (Created By)</strong> — new column <code>recorded_by_name VARCHAR(150)</code> added to <code>opb_expenses</code>; populated automatically on <code>create_item()</code> from WordPress <code>display_name</code>; cannot be overridden by client; <code>GET /expenses</code> also JOINs <code>wp_users</code> as fallback for existing records where <code>recorded_by</code> is set but <code>recorded_by_name</code> is null; imported records with no <code>recorded_by</code> display "System Import"</li>
            <li><strong>Add expense form</strong> — Category dropdown now sourced from active managed categories (<code>GET /opb/v1/expense-categories</code>); immediately reflects newly added categories</li>
            <li><strong>Schema</strong> — new table <code>opb_expense_categories</code> (id, name, is_active, sort_order, created_at, UNIQUE KEY on name); new column <code>recorded_by_name VARCHAR(150) NULL</code> on <code>opb_expenses</code>; both applied via idempotent <code>add_col()</code> and <code>CREATE TABLE IF NOT EXISTS</code> compatible with MySQL 5.7</li>
            <li><strong>New REST endpoints</strong> — <code>GET /opb/v1/expense-categories</code>, <code>POST /opb/v1/expense-categories</code>, <code>PUT /opb/v1/expense-categories/{id}</code>, <code>DELETE /opb/v1/expense-categories/{id}</code> (soft archive), <code>GET /opb/v1/expenses/categories</code> (distinct filter values)</li>
            <li><strong>Reports unaffected</strong> — <code>expenses_by_category</code> in Reports continues to group by the raw <code>category</code> string; no changes to reports logic</li>
            <li><strong>Existing data preserved</strong> — no migration of expense records; imported records retain their original category strings; <code>recorded_by</code> / <code>recorded_by_name</code> remain NULL on imported rows</li>
            <li>PHP syntax validated ✓ &nbsp;|&nbsp; TypeScript validated ✓ &nbsp;|&nbsp; Vite build: 110 modules, 0 warnings ✓</li>
            <li>ZIP: <code>onukonu-pet-boarding-core-v2.7.0.zip</code> — 45 MB, 740 files</li>
          </ul>
        </div>

        <div class="release-entry">
          <div class="release-header">
            <span class="release-version">v2.6.1</span>
            <span class="release-date">11 June 2026</span>
            <span class="release-tag">Lifecycle Integrity Patch</span>
          </div>
          <ul class="release-notes-list">
            <li><strong>Fix: Archived clients appearing in operational workflows</strong> — <code>GET /opb/v1/clients</code> list and search now exclude records where <code>status = 'archived'</code> by default; archived clients no longer appear in client selectors, booking creation, or staff search. Data Management retains full access via its own endpoints.</li>
            <li><strong>Fix: Archived inquiries appearing in operational queue</strong> — <code>GET /opb/v1/inquiries</code> now excludes <code>status = 'ARCHIVED'</code> by default; archived inquiries no longer surface in the active queue, review queue, or any unfiltered operational view. Dashboard inquiry count and New Inquiries banner were already correct and are unchanged.</li>
            <li><strong>No change — Pets</strong> — <code>GET /clients/{id}/pets</code> already filtered <code>is_active = 1</code>, correctly excluding archived pets from booking workflows. Confirmed correct; no modification needed.</li>
            <li><strong>No change — Bookings</strong> — Cancelled bookings remain visible in the operational list per existing lifecycle design; historical records are not hidden.</li>
            <li><strong>Fix: Currency symbol in Data Management Bookings tab</strong> — replaced <code>₱</code> (Philippine Peso) with <code>₹</code> (Indian Rupee) in <code>DataManagement.tsx</code>; format updated to <code>toLocaleString('en-IN')</code> for consistent Indian numeral grouping. All other pages (Reports, Dashboard, Invoices) were already using <code>₹</code> correctly.</li>
            <li>Files modified: <code>class-opb-clients-api.php</code>, <code>class-opb-inquiries-api.php</code>, <code>DataManagement.tsx</code></li>
            <li>PHP syntax validated ✓ &nbsp;|&nbsp; TypeScript validated ✓ &nbsp;|&nbsp; Vite build: 108 modules, 0 warnings ✓</li>
            <li>ZIP: <code>onukonu-pet-boarding-core-v2.6.1.zip</code> — 45 MB, 739 files</li>
          </ul>
        </div>

        <div class="release-entry">
          <div class="release-header">
            <span class="release-version">v2.6.0</span>
            <span class="release-date">11 June 2026</span>
            <span class="release-tag">Admin Data Management Module</span>
          </div>
          <ul class="release-notes-list">
            <li><strong>New module: Admin Data Management</strong> — super-admin-only panel for controlled archive, restore, and cancellation operations across all four entity types: Clients, Pets, Bookings, and Inquiries</li>
            <li><strong>Access control</strong> — gated exclusively to <code>opb_super_admin</code> role (<code>opb_manage_settings</code> capability) and WordPress <code>administrator</code>; Branch Managers and Reception have no access; enforced at the REST API layer on every endpoint</li>
            <li><strong>Clients — archive / restore</strong> — soft-archive sets <code>is_archived = 1</code>; archived clients excluded from all operational selectors (booking creation, inquiries, etc.); historical references (invoices, bookings, stays) preserved intact</li>
            <li><strong>Pets — archive / restore</strong> — soft-archive sets <code>is_archived = 1</code>; archived pets hidden from booking workflows and the Client Portal My Pets view; all historical booking and stay records retained</li>
            <li><strong>Bookings — cancel / restore</strong> — new <code>status</code> column (<code>VARCHAR(20) DEFAULT 'Active'</code>) added to <code>opb_bookings</code> via <code>INFORMATION_SCHEMA</code> migration (MySQL 5.7 safe); cancel sets <code>status = 'Cancelled'</code>; restore sets <code>status = 'Active'</code>; idempotent guards on both directions; existing invoices and reports are unaffected</li>
            <li><strong>Inquiries — archive / restore</strong> — soft-archive sets <code>is_archived = 1</code>; archived inquiries excluded from the dashboard inquiry count and New Inquiries banner; pipeline and conversion records preserved</li>
            <li><strong>12 new REST endpoints</strong> — all under <code>/wp-json/opb/v1/admin/*</code>; GET list (with search + pagination), PUT archive/cancel, PUT restore for each entity type</li>
            <li><strong>React UI</strong> — <code>DataManagement.tsx</code> (789 lines): tabbed interface (Clients / Pets / Bookings / Inquiries), search, paginated tables, archive/cancel/restore action buttons, confirmation modals; <code>dataManagement.ts</code> API client</li>
            <li><strong>Historical reporting integrity</strong> — all archive and cancel operations are non-destructive; revenue, expenses, and booking counts in Reports remain accurate against historical data</li>
            <li>ZIP: <code>onukonu-pet-boarding-core-v2.6.0.zip</code> — 45 MB, 739 files</li>
          </ul>
        </div>

        <div class="release-entry">
          <div class="release-header">
            <span class="release-version">v2.5.1</span>
            <span class="release-date">10 June 2026</span>
            <span class="release-tag">Dashboard Visual Polish</span>
          </div>
          <ul class="release-notes-list">
            <li><strong>New Inquiries Banner</strong> — replaced KPI card with a slim amber-tinted full-width banner below the page heading; only visible when inquiries &gt; 0; entire banner navigates to /inquiries; icon + count + label + "Review →" affordance; understated amber-50 palette</li>
            <li><strong>Tasks Due KPI card</strong> — visually distinct from standard cards; blue-50 background, 4px blue-400 left accent border, "View tasks →" link, hover state; data and position unchanged</li>
            <li><strong>KPI grid</strong> — reduced from 7 to 6 cards (<code>lg:grid-cols-6</code>); tablet breakpoint improved to <code>md:grid-cols-3</code></li>
            <li><strong>Pet Birthdays</strong> — moved to bottom of dashboard, below Open Tasks; replaced full card with lightweight compact section; pink-50 pill chips per pet; low visual weight</li>
            <li>No API, query, schema, or logic changes; visual-only patch</li>
          </ul>
        </div>

        <div class="release-entry">
          <div class="release-header">
            <span class="release-version">v2.5.0</span>
            <span class="release-date">10 June 2026</span>
            <span class="release-tag">Task Assignees &amp; Kennel Tasks</span>
          </div>
          <ul class="release-notes-list">
            <li><strong>Feature 1 — User assignee dropdown</strong>: Tasks form replaces the free-text assignee field with a dropdown sourced from OPB users (<code>staffOptions</code> endpoint). Assignee is stored as <code>display_name</code> in the existing <code>VARCHAR(150)</code> column — no schema change. Existing tasks are unaffected.</li>
            <li><strong>Feature 2 — Auto-task on kennel assignment</strong>: When a staff member is assigned to a kennel via Kennel Settings, a task (<em>Manage Kennel {code}</em>, status <em>Open</em>, priority <em>Medium</em>) is automatically created for the assigned user in the kennel's branch. Duplicate protection: if a task with the same title already exists in that branch, no new task is created. No synchronisation — the task is created once at assignment time and is independent thereafter.</li>
            <li>Files: <code>class-opb-kennels-api.php</code>, <code>pages/Tasks.tsx</code></li>
            <li>No new tables, no new REST endpoints, no schema migrations</li>
          </ul>
        </div>

        <div class="release-entry">
          <div class="release-header">
            <span class="release-version">v2.4.0</span>
            <span class="release-date">9 June 2026</span>
            <span class="release-tag">Dashboard Enhancement</span>
          </div>
          <ul class="release-notes-list">
            <li><strong>New Inquiries KPI</strong> — counts inquiries in <code>NEW</code> or <code>READY_FOR_REVIEW</code> status; shown in the dashboard KPI row; amber when &gt; 0; not branch-scoped (pipeline is pre-branch)</li>
            <li><strong>Today's Pet Birthdays</strong> — section below check-ins/check-outs; single JOIN query (<code>opb_pets</code> → <code>opb_clients</code>); shows pet name, owner name, age turning today; "No pet birthdays today" when empty</li>
            <li>KPI grid updated from <code>lg:grid-cols-6</code> to <code>lg:grid-cols-7</code>; mobile layout unchanged (<code>grid-cols-2</code>)</li>
            <li>No new tables, no new statuses, no schema changes</li>
            <li>Files: <code>class-opb-dashboard-api.php</code>, <code>api/dashboard.ts</code>, <code>pages/Dashboard.tsx</code></li>
          </ul>
        </div>

        <div class="release-entry">
          <div class="release-header">
            <span class="release-version">v2.3.1</span>
            <span class="release-date">9 June 2026</span>
            <span class="release-tag">Bug Fix</span>
          </div>
          <ul class="release-notes-list">
            <li><strong>Fix: Reports KPI cards returning 0 on branch filter</strong> — summary <code>get_var()</code> queries for Revenue, Expenses and Outstanding lacked table aliases (<code>i</code>, <code>e</code>) in their <code>FROM</code> clause; MySQL rejected <code>i.branch_id</code> as unknown, <code>$wpdb</code> returned <code>null</code>, cast to <code>0.0</code></li>
            <li><strong>Fix: Revenue by Branch ignoring branch filter</strong> — <code>$inv_w</code> fragment was absent from the <code>revenue_by_branch</code> query's <code>WHERE</code> clause; all branches were always returned regardless of selection</li>
            <li>Bookings count was unaffected (its query already had the correct <code>bk</code> alias)</li>
            <li>File changed: <code>includes/api/class-opb-reports-api.php</code> — 2 targeted edits</li>
          </ul>
        </div>

        <div class="release-entry">
          <div class="release-header">
            <span class="release-version">v2.3.0</span>
            <span class="release-date">9 June 2026</span>
            <span class="release-tag">Login Branding</span>
          </div>
          <ul class="release-notes-list">
            <li><strong>Dedicated SVG logo</strong> — <code>assets/branding/login-logo.svg</code> — transparent, vector-crisp at all resolutions; PWA icon assets untouched</li>
            <li><strong>Title</strong> — "Onukonu Operations Login" injected via <code>login_message</code> filter, between logo and form</li>
            <li><strong>Footer</strong> — "Onukonu Pet Boarding • Operations Platform" injected via <code>login_footer</code> action, beneath nav links</li>
            <li><strong>Form refinement</strong> — cleaner input borders, navy focus rings, brand-navy submit button, muted links with hover state</li>
            <li><strong>Design language</strong> — quiet luxury: soft navy-tinted background, subtle card shadow, restrained typography; no glassmorphism, no animations, no geometry changes</li>
            <li><strong>Zero layout ownership</strong> — WordPress retains all layout, positioning, dimensions and responsiveness; Loginizer untouched</li>
            <li>ZIP generated: <code>onukonu-pet-boarding-core-v2.3.0.zip</code> — 45.96 MB, 738 files</li>
          </ul>
        </div>

        <div class="release-entry">
          <div class="release-header">
            <span class="release-version">v2.2.0</span>
            <span class="release-date">9 June 2026</span>
            <span class="release-tag">Production Baseline</span>
          </div>
          <div class="release-hash">Commit: <code>c9bfcc18ca467b8b1f96dd1ddb87757d7fea4e47</code></div>
          <ul class="release-notes-list">
            <li>Restored clean v2.2.0 production baseline — all login-page branding experiments reverted</li>
            <li>Full pre-build validation passed: PHP syntax, TypeScript, Vite build (106 modules), PWA assets, MySQL 5.7 compatibility</li>
            <li>ZIP generated: <code>onukonu-pet-boarding-core-v2.2.0.zip</code> — 44.88 MB, 735 files</li>
            <li>All 50 PHP includes verified; no duplicate classes; no missing dependencies</li>
            <li>Client portal (OTP, Staff Preview, Support), invoice engine (PDF, WhatsApp, email), customization module — all confirmed present</li>
          </ul>
        </div>

      </div>

      <div class="card">
        <h2>Commit Timeline</h2>
        ${commits.length === 0
          ? '<p style="color:#718096">No git history available.</p>'
          : `<div class="timeline">${timelineItems}</div>`}
      </div>

      <div class="card">
        <h2>All Commits</h2>
        <table>
          <thead>
            <tr>
              <th style="width:90px">Hash</th>
              <th style="width:160px">Date</th>
              <th>Message</th>
            </tr>
          </thead>
          <tbody>${commitRows}</tbody>
        </table>
      </div>
    `;
  }

  return `<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Onukonu Pet Boarding Core — ${title}</title>
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f5f6fa; color: #2d3748; line-height: 1.6; }
    .header { background: #1a365d; color: white; padding: 16px 24px; display: flex; align-items: center; gap: 16px; }
    .header-logo { font-size: 24px; }
    .header-title { font-size: 20px; font-weight: 700; }
    .header-sub { font-size: 13px; color: #90cdf4; margin-top: 2px; }
    .nav { background: #2d3748; display: flex; gap: 0; overflow-x: auto; }
    .nav a { display: block; padding: 12px 20px; color: #a0aec0; text-decoration: none; font-size: 14px; font-weight: 500; white-space: nowrap; border-bottom: 3px solid transparent; transition: all 0.15s; }
    .nav a:hover { color: white; background: #4a5568; }
    .nav a.active { color: white; border-bottom-color: #4299e1; }
    .main { max-width: 1100px; margin: 0 auto; padding: 24px 16px; }
    .card { background: white; border-radius: 8px; padding: 24px; margin-bottom: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
    .card h2 { font-size: 18px; color: #1a365d; margin-bottom: 16px; border-bottom: 1px solid #e2e8f0; padding-bottom: 8px; }
    .card h3 { font-size: 15px; color: #2d3748; margin: 16px 0 8px; }
    .card h4 { font-size: 14px; color: #4a5568; margin: 12px 0 6px; }
    .card p { margin-bottom: 10px; color: #4a5568; font-size: 14px; }
    .card ul { margin: 8px 0 12px 20px; }
    .card li { margin-bottom: 4px; font-size: 14px; color: #4a5568; }
    .card code { background: #edf2f7; padding: 2px 6px; border-radius: 3px; font-family: monospace; font-size: 13px; color: #c53030; }
    .card pre { background: #1a202c; color: #e2e8f0; padding: 16px; border-radius: 6px; overflow-x: auto; margin: 12px 0; }
    .card pre code { background: none; padding: 0; color: inherit; font-size: 13px; }
    .card strong { color: #2d3748; }
    table { width: 100%; border-collapse: collapse; font-size: 14px; }
    th { background: #edf2f7; padding: 10px 12px; text-align: left; font-weight: 600; color: #4a5568; }
    td { padding: 8px 12px; border-bottom: 1px solid #e2e8f0; color: #4a5568; }
    tr:last-child td { border-bottom: none; }
    .status-box { background: #ebf8ff; border: 1px solid #90cdf4; border-radius: 6px; padding: 16px; margin-top: 16px; }
    .status-box h3 { color: #2b6cb0; margin-bottom: 8px; }
    .doc-content h1 { font-size: 22px; color: #1a365d; margin: 24px 0 12px; }
    .doc-content h2 { font-size: 18px; border-bottom: 1px solid #e2e8f0; }
    .doc-content h3 { font-size: 15px; }
    .badges { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 20px; }
    .badge { background: #ebf8ff; color: #2b6cb0; border: 1px solid #bee3f8; border-radius: 20px; padding: 4px 12px; font-size: 12px; font-weight: 600; }
    .badge.green { background: #f0fff4; color: #276749; border-color: #9ae6b4; }
    .badge.orange { background: #fffaf0; color: #c05621; border-color: #fbd38d; }

    /* Release notes */
    .release-entry { border-left: 3px solid #4299e1; padding-left: 16px; margin-bottom: 8px; }
    .release-header { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; margin-bottom: 6px; }
    .release-version { font-size: 17px; font-weight: 800; color: #1a365d; }
    .release-date { font-size: 13px; color: #718096; }
    .release-tag { background: #f0fff4; color: #276749; border: 1px solid #9ae6b4; border-radius: 20px; padding: 2px 10px; font-size: 11px; font-weight: 700; }
    .release-hash { font-size: 12px; color: #a0aec0; margin-bottom: 8px; }
    .release-notes-list { margin: 0 0 0 18px; }
    .release-notes-list li { font-size: 13px; color: #4a5568; margin-bottom: 4px; }

    /* Changelog */
    .version-row { display: flex; align-items: flex-start; gap: 24px; flex-wrap: wrap; }
    .version-box { background: #1a365d; color: white; border-radius: 8px; padding: 16px 24px; min-width: 140px; text-align: center; }
    .version-num { font-size: 28px; font-weight: 800; letter-spacing: -0.5px; }
    .version-sub { font-size: 11px; color: #90cdf4; margin-top: 4px; }
    .version-meta { flex: 1; padding-top: 4px; }
    .tag-head { display: inline-block; background: #4299e1; color: white; font-size: 10px; font-weight: 700; border-radius: 3px; padding: 1px 5px; margin-left: 6px; vertical-align: middle; letter-spacing: 0.3px; }
    .timeline { padding-left: 4px; }
    .tl-date-group { margin-bottom: 20px; }
    .tl-date-label { font-size: 12px; font-weight: 700; color: #a0aec0; text-transform: uppercase; letter-spacing: 0.6px; margin-bottom: 8px; padding-left: 22px; }
    .tl-item { display: flex; align-items: flex-start; gap: 10px; margin-bottom: 8px; padding-left: 4px; }
    .tl-dot { width: 10px; height: 10px; border-radius: 50%; background: #cbd5e0; border: 2px solid #a0aec0; margin-top: 4px; flex-shrink: 0; }
    .tl-dot-head { background: #4299e1; border-color: #2b6cb0; }
    .tl-body { display: flex; align-items: baseline; gap: 8px; flex-wrap: wrap; }
    .tl-hash { background: #edf2f7; color: #718096; padding: 1px 5px; border-radius: 3px; font-size: 11px; }
    .tl-subject { font-size: 14px; color: #2d3748; }
  </style>
</head>
<body>
  <div class="header">
    <div class="header-logo">🐾</div>
    <div>
      <div class="header-title">Onukonu Pet Boarding Core</div>
      <div class="header-sub">WordPress Plugin · PHP 8.2 · MySQL 8.0 · Hostinger</div>
    </div>
  </div>
  <nav class="nav">
    <a href="/" class="${activeTab === 'overview' ? 'active' : ''}">Overview</a>
    <a href="/architecture" class="${activeTab === 'architecture' ? 'active' : ''}">Architecture</a>
    <a href="/analysis" class="${activeTab === 'analysis' ? 'active' : ''}">Analysis</a>
    <a href="/plugin" class="${activeTab === 'plugin' ? 'active' : ''}">Plugin</a>
    <a href="/changelog" class="${activeTab === 'changelog' ? 'active' : ''}">Changelog</a>
  </nav>
  <main class="main">
    <div class="badges">
      <span class="badge">3 Branches</span>
      <span class="badge">893 Clients</span>
      <span class="badge">~1,912 Bookings</span>
      <span class="badge green">Docs Ready</span>
      <span class="badge orange">v${escapeHtml(pluginVersion)}</span>
    </div>
    ${content}
  </main>
</body>
</html>`;
}

const server = http.createServer((req, res) => {
  const url = req.url.split('?')[0];
  let tab = 'overview';
  if (url === '/architecture') tab = 'architecture';
  else if (url === '/analysis') tab = 'analysis';
  else if (url === '/plugin') tab = 'plugin';
  else if (url === '/changelog') tab = 'changelog';
  else if (url !== '/') {
    res.writeHead(301, { Location: '/' });
    res.end();
    return;
  }

  res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
  res.end(renderPage(tab));
});

server.listen(PORT, HOST, () => {
  console.log(`Onukonu Pet Boarding Core dev server running at http://${HOST}:${PORT}`);
});
