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
  const rc1RepoState    = readFileSafe('docs/RC1-REPOSITORY-STATE.md') || '';
  const rc1Branding     = readFileSafe('docs/RC1-BRANDING-REPORT.md') || '';
  const rc1Arch         = readFileSafe('docs/RC1-ARCHITECTURE.md') || '';
  const rc1Build        = readFileSafe('docs/RC1-BUILD-AUDIT.md') || '';
  const rc1Roles        = readFileSafe('docs/RC1-ROLES-AUDIT.md') || '';
  const rc1Opsmail      = readFileSafe('docs/RC1-OPSMAIL-AUDIT.md') || '';
  const rc1Sal          = readFileSafe('docs/RC1-SAL-AUDIT.md') || '';
  const rc1ReleaseNotes = readFileSafe('docs/RC1-RELEASE-NOTES.md') || '';
  const rc1Deployment   = readFileSafe('docs/RC1-DEPLOYMENT.md') || '';
  const perm01 = readFileSafe('docs/PERMISSIONS-01-role-inventory.md') || '';
  const perm02 = readFileSafe('docs/PERMISSIONS-02-capability-inventory.md') || '';
  const perm03 = readFileSafe('docs/PERMISSIONS-03-user-type-audit.md') || '';
  const perm04 = readFileSafe('docs/PERMISSIONS-04-branch-scope-audit.md') || '';
  const perm05 = readFileSafe('docs/PERMISSIONS-05-module-permission-matrix.md') || '';
  const perm06 = readFileSafe('docs/PERMISSIONS-06-opsmail-permission-matrix.md') || '';
  const perm07 = readFileSafe('docs/PERMISSIONS-07-sal-permission-matrix.md') || '';
  const perm08 = readFileSafe('docs/PERMISSIONS-08-conflict-report.md') || '';
  const perm09 = readFileSafe('docs/PERMISSIONS-09-security-review.md') || '';
  const perm10 = readFileSafe('docs/PERMISSIONS-10-architecture-documentation.md') || '';
  const perm11 = readFileSafe('docs/PERMISSIONS-11-canonical-model-recommendation.md') || '';
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
  } else if (activeTab === 'rc1') {
    title = 'RC1 Audit';
    const rc1ZipExists = fs.existsSync('onukonu-pet-boarding-rc1.zip');
    const rc1ZipSize   = rc1ZipExists
      ? (fs.statSync('onukonu-pet-boarding-rc1.zip').size / (1024 * 1024)).toFixed(1) + ' MB'
      : '—';

    const docs = [
      { anchor: 'release-notes',  label: 'RC1 Release Notes',        md: rc1ReleaseNotes },
      { anchor: 'deployment',     label: 'Deployment Instructions',   md: rc1Deployment   },
      { anchor: 'architecture',   label: 'Architecture Reference',    md: rc1Arch         },
      { anchor: 'roles',          label: 'Role & Scope Audit',        md: rc1Roles        },
      { anchor: 'build',          label: 'Build Audit',               md: rc1Build        },
      { anchor: 'opsmail',        label: 'OPSMAIL Audit',             md: rc1Opsmail      },
      { anchor: 'sal',            label: 'SAL Audit',                 md: rc1Sal          },
      { anchor: 'branding',       label: 'Branding Report',           md: rc1Branding     },
      { anchor: 'repo-state',     label: 'Repository State',          md: rc1RepoState    },
    ];

    const tocItems = docs.map(d =>
      `<li><a href="#${d.anchor}" style="color:#4299e1;text-decoration:none">${d.label}</a></li>`
    ).join('');

    const docSections = docs.map(d => `
      <div class="card doc-content" id="${d.anchor}">
        ${d.md ? simpleMarkdown(d.md) : '<p style="color:#a0aec0"><em>Document not found.</em></p>'}
      </div>`
    ).join('');

    content = `
      <div class="card" style="border-left:4px solid #4299e1">
        <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap">
          <div style="background:#1a365d;color:white;border-radius:6px;padding:10px 20px;font-size:22px;font-weight:800;letter-spacing:-0.5px">RC1</div>
          <div>
            <div style="font-size:18px;font-weight:700;color:#1a365d">Onukonu Pet Boarding Core — Release Candidate 1</div>
            <div style="font-size:13px;color:#718096;margin-top:2px">Source base: v3.1.0 · Generated: 2026-06-19 · 13 audit phases completed</div>
          </div>
        </div>
        <div style="margin-top:16px;display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px">
          <div style="background:#f0fff4;border:1px solid #9ae6b4;border-radius:6px;padding:12px;text-align:center">
            <div style="font-size:22px;font-weight:800;color:#276749">${rc1ZipExists ? '✓' : '✗'}</div>
            <div style="font-size:12px;color:#276749;font-weight:600">ZIP Built</div>
            <div style="font-size:11px;color:#68d391">${rc1ZipExists ? rc1ZipSize : 'Not found'}</div>
          </div>
          <div style="background:#ebf8ff;border:1px solid #90cdf4;border-radius:6px;padding:12px;text-align:center">
            <div style="font-size:22px;font-weight:800;color:#2b6cb0">✓</div>
            <div style="font-size:12px;color:#2b6cb0;font-weight:600">TypeScript</div>
            <div style="font-size:11px;color:#63b3ed">0 errors · 114 modules</div>
          </div>
          <div style="background:#ebf8ff;border:1px solid #90cdf4;border-radius:6px;padding:12px;text-align:center">
            <div style="font-size:22px;font-weight:800;color:#2b6cb0">✓</div>
            <div style="font-size:12px;color:#2b6cb0;font-weight:600">Vite Build</div>
            <div style="font-size:11px;color:#63b3ed">487 kB JS · 56 kB CSS</div>
          </div>
          <div style="background:#f0fff4;border:1px solid #9ae6b4;border-radius:6px;padding:12px;text-align:center">
            <div style="font-size:22px;font-weight:800;color:#276749">OPB</div>
            <div style="font-size:12px;color:#276749;font-weight:600">Branding</div>
            <div style="font-size:11px;color:#68d391">Product identity verified</div>
          </div>
          <div style="background:#f0fff4;border:1px solid #9ae6b4;border-radius:6px;padding:12px;text-align:center">
            <div style="font-size:22px;font-weight:800;color:#276749">4</div>
            <div style="font-size:12px;color:#276749;font-weight:600">OPB Roles</div>
            <div style="font-size:11px;color:#68d391">Super Admin · Manager · Reception · Staff</div>
          </div>
          <div style="background:#f0fff4;border:1px solid #9ae6b4;border-radius:6px;padding:12px;text-align:center">
            <div style="font-size:22px;font-weight:800;color:#276749">Clean</div>
            <div style="font-size:12px;color:#276749;font-weight:600">Repository</div>
            <div style="font-size:11px;color:#68d391">main · up to date · no uncommitted changes</div>
          </div>
        </div>
      </div>

      <div class="card">
        <h2>RC1 Deliverables</h2>
        <table>
          <thead><tr><th>Deliverable</th><th>File</th><th>Status</th></tr></thead>
          <tbody>
            <tr><td>Repository State Report</td><td><code>docs/RC1-REPOSITORY-STATE.md</code></td><td style="color:#276749">✓</td></tr>
            <tr><td>Branding Alignment Report</td><td><code>docs/RC1-BRANDING-REPORT.md</code></td><td style="color:#276749">✓</td></tr>
            <tr><td>Architecture Reference</td><td><code>docs/RC1-ARCHITECTURE.md</code></td><td style="color:#276749">✓</td></tr>
            <tr><td>Build Audit Report</td><td><code>docs/RC1-BUILD-AUDIT.md</code></td><td style="color:#276749">✓</td></tr>
            <tr><td>Role and Scope Audit</td><td><code>docs/RC1-ROLES-AUDIT.md</code></td><td style="color:#276749">✓</td></tr>
            <tr><td>OPSMAIL Audit Summary</td><td><code>docs/RC1-OPSMAIL-AUDIT.md</code></td><td style="color:#276749">✓</td></tr>
            <tr><td>SAL Audit Summary</td><td><code>docs/RC1-SAL-AUDIT.md</code></td><td style="color:#276749">✓</td></tr>
            <tr><td>Release Notes</td><td><code>docs/RC1-RELEASE-NOTES.md</code></td><td style="color:#276749">✓</td></tr>
            <tr><td>Deployment Instructions</td><td><code>docs/RC1-DEPLOYMENT.md</code></td><td style="color:#276749">✓</td></tr>
            <tr><td>Production ZIP</td><td><code>onukonu-pet-boarding-rc1.zip</code></td><td style="color:${rc1ZipExists ? '#276749' : '#c53030'}">${rc1ZipExists ? '✓ ' + rc1ZipSize : '✗ Not found'}</td></tr>
          </tbody>
        </table>
      </div>

      <div class="card">
        <h2>Document Index</h2>
        <ul style="margin-left:20px;line-height:2">${tocItems}</ul>
      </div>

      ${docSections}
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
            <span class="release-version">v2.8.0</span>
            <span class="release-date">16 June 2026</span>
            <span class="release-tag">OPSMAIL Operational Intelligence Layer</span>
          </div>
          <ul class="release-notes-list">
            <li><strong>New: OPSMAIL event queue</strong> — additive-only <code>opb_opsmail_queue</code> table (19 columns); created via <code>dbDelta()</code>, MySQL 5.7 compatible; fields: <code>event_uuid</code> CHAR(36) UNIQUE, <code>event_type</code>, <code>entity_type</code>, <code>entity_id</code>, <code>branch_id</code>, <code>user_id</code>, <code>origin_type</code> ENUM(SYSTEM/TRUSTED_MAILBOX), <code>priority</code>, <code>subject</code>, <code>summary</code>, <code>payload_json</code>, <code>recipient_email</code>, <code>status</code> ENUM(PENDING/SENT/FAILED/ACKNOWLEDGED), <code>mail_attempts</code>, <code>last_error</code>, <code>created_at</code>, <code>sent_at</code></li>
            <li><strong>New: <code>class-opb-opsmail.php</code></strong> — core engine; all public methods wrapped in <code>try/catch(\Throwable)</code> — OPSMAIL will never throw, never block, never break business workflows; event taxonomy: 11 event types; HTML email format with <code>X-Ops-*</code> custom headers; settings helpers: <code>is_enabled()</code>, <code>inbox_email()</code>, <code>trusted_origins()</code>, <code>expense_threshold()</code></li>
            <li><strong>New: 5 hook points</strong> — <code>INQUIRY.RECEIVED</code> (after submit_inquiry); <code>CLIENT.ONBOARDING_RECEIVED</code> (after accept_terms → READY_FOR_REVIEW); <code>BOOKING.CONFIRMED</code> (after create_item + invoice generation); <code>TASK.CREATED</code> (after tasks insert); <code>EXPENSE.LARGE_RECORDED</code> (after expense insert when amount ≥ threshold)</li>
            <li><strong>New: REST API</strong> — <code>GET /opb/v1/opsmail/queue</code> (paginated, filtered); <code>GET /opb/v1/opsmail/stats</code> (counts by status/event + recent failures); <code>POST /opb/v1/opsmail/queue/{id}/acknowledge</code> — all <code>manage_options</code> gated</li>
            <li><strong>New: Administration → OPSMAIL Queue</strong> — PHP-rendered WP admin page (no React rebuild); live filterable table with status/config warning banner, pagination, HIGH-priority highlighting; hover shows full last_error</li>
            <li><strong>New: OPSMAIL settings</strong> — 4 new entries in Customisation (category <code>opsmail</code>): <code>opsmail_enabled</code> (0/1), <code>opsmail_inbox_email</code>, <code>opsmail_trusted_origins</code> (textarea, one per line), <code>opsmail_expense_threshold</code> (default 5000)</li>
            <li><strong>Zero regression</strong> — no existing class, method, route, or DB table modified; OPSMAIL code is purely additive</li>
            <li>ZIP: <code>onukonu-pet-boarding-core-v2.8.0.zip</code> — 45 MB, 742 files</li>
          </ul>
        </div>

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
  } else if (activeTab === 'forensics') {
    title = 'Financial Forensics';
    const reportDate = new Date().toLocaleDateString('en-IN', { day: 'numeric', month: 'long', year: 'numeric' });
    const reportTime = new Date().toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit', hour12: true });

    content = `
      <!-- EXECUTIVE SUMMARY -->
      <div class="card" style="border-left:5px solid #c53030">
        <div style="display:flex;align-items:center;gap:14px;margin-bottom:16px">
          <div style="background:#c53030;color:white;border-radius:6px;padding:8px 18px;font-size:18px;font-weight:800;letter-spacing:-0.3px">FORENSIC</div>
          <div>
            <div style="font-size:18px;font-weight:700;color:#1a365d">Financial Dashboard Forensic Analysis</div>
            <div style="font-size:12px;color:#718096;margin-top:2px">Onukonu Pet Boarding Core — v3.1.0 · Generated ${reportDate}, ${reportTime} · Read-only · No data modified</div>
          </div>
        </div>
        <div style="background:#fff5f5;border:1px solid #feb2b2;border-radius:6px;padding:16px;margin-bottom:16px">
          <div style="font-weight:700;color:#c53030;font-size:15px;margin-bottom:8px">Executive Finding</div>
          <p style="color:#2d3748;font-size:14px;margin:0 0 8px">The dashboard <strong>Outstanding ≈ ₹26.87 lakh</strong> is the sum of <code>due</code> across <em>all invoices in the database with <code>due &gt; 0</code></em>, without any date filter. This figure includes outstanding balances imported verbatim from the legacy pet boarding platform. These are genuine historical debts — clients who had unpaid balances in the old system at the time of migration — now carried forward into OPB as stored invoice records with their original <code>paid</code> and <code>due</code> values.</p>
          <p style="color:#2d3748;font-size:14px;margin:0">There is no calculation error, no data corruption, and no accounting discrepancy. The legacy balances are correctly represented. The question is whether they belong on an <em>operational</em> dashboard metric.</p>
        </div>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px">
          <div style="background:#f0fff4;border:1px solid #9ae6b4;border-radius:6px;padding:12px;text-align:center">
            <div style="font-size:11px;color:#276749;font-weight:700;text-transform:uppercase;letter-spacing:0.5px">Root Cause</div>
            <div style="font-size:14px;font-weight:700;color:#276749;margin-top:4px">Legacy Carry-Forward</div>
          </div>
          <div style="background:#ebf8ff;border:1px solid #90cdf4;border-radius:6px;padding:12px;text-align:center">
            <div style="font-size:11px;color:#2b6cb0;font-weight:700;text-transform:uppercase;letter-spacing:0.5px">Calculation Error?</div>
            <div style="font-size:14px;font-weight:700;color:#2b6cb0;margin-top:4px">None Found</div>
          </div>
          <div style="background:#ebf8ff;border:1px solid #90cdf4;border-radius:6px;padding:12px;text-align:center">
            <div style="font-size:11px;color:#2b6cb0;font-weight:700;text-transform:uppercase;letter-spacing:0.5px">Data Corruption?</div>
            <div style="font-size:14px;font-weight:700;color:#2b6cb0;margin-top:4px">None Found</div>
          </div>
          <div style="background:#fffaf0;border:1px solid #fbd38d;border-radius:6px;padding:12px;text-align:center">
            <div style="font-size:11px;color:#c05621;font-weight:700;text-transform:uppercase;letter-spacing:0.5px">Action Required</div>
            <div style="font-size:14px;font-weight:700;color:#c05621;margin-top:4px">Metric Review</div>
          </div>
        </div>
      </div>

      <!-- TABLE OF CONTENTS -->
      <div class="card">
        <h2>Report Index</h2>
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:8px;margin-top:4px">
          ${[
            ['#section-a','A','Financial Totals — Legacy vs Native Split'],
            ['#section-b','B','Top 50 Outstanding Contributors (SQL)'],
            ['#section-c','C','Legacy Import Audit — Field Mapping & Verification'],
            ['#section-d','D','Date Field Influence Analysis'],
            ['#section-e','E','Dashboard Metric Recommendation'],
            ['#section-f','F','JSON Summary Structure'],
            ['#section-g','G','SQL Quick-Reference Pack'],
          ].map(([href, label, desc]) =>
            `<a href="${href}" style="display:flex;align-items:center;gap:10px;padding:10px 14px;background:#f7fafc;border:1px solid #e2e8f0;border-radius:6px;text-decoration:none;color:#2d3748">
              <span style="background:#1a365d;color:white;border-radius:4px;padding:2px 8px;font-size:11px;font-weight:800;flex-shrink:0">${label}</span>
              <span style="font-size:13px">${desc}</span>
            </a>`
          ).join('')}
        </div>
      </div>

      <!-- SECTION A: FINANCIAL TOTALS -->
      <div class="card" id="section-a">
        <h2>Section A — Financial Totals: Legacy vs Native Split</h2>

        <h3>A1. Dashboard Outstanding KPI — Exact Calculation Path</h3>
        <p>The dashboard Outstanding figure is computed by a single query in <code>class-opb-dashboard-api.php</code> line 37–38:</p>
        <pre><code>-- Dashboard Outstanding KPI (exact query, no date filter)
SELECT COALESCE(SUM(i.due), 0)
FROM   wp_opb_invoices i
WHERE  i.due &gt; 0
-- Optional branch scope: AND i.branch_id = {branch_id}</code></pre>
        <p>The <code>due</code> column is a stored <code>DECIMAL(10,2)</code> field on every invoice row. It is never recomputed at query time — it is always <code>revenue − paid</code>, persisted at write-time by <code>OPB_Invoice_Generator::sync_payment_totals()</code>.</p>

        <h3>A2. Overall Financial Totals Query</h3>
        <pre><code>-- A2: Global financial totals — ALL invoices (no date filter)
SELECT
    COUNT(*)                                          AS total_invoices,
    COALESCE(SUM(revenue), 0)                         AS total_invoice_value,
    COALESCE(SUM(paid),    0)                         AS total_paid,
    COALESCE(SUM(due),     0)                         AS total_outstanding,
    COUNT(CASE WHEN due &gt; 0 THEN 1 END)              AS invoices_with_balance,
    COUNT(CASE WHEN due = 0 THEN 1 END)              AS invoices_fully_paid
FROM   wp_opb_invoices;</code></pre>

        <h3>A3. Legacy vs Native Split</h3>
        <p>Legacy imported invoices carry a non-NULL <code>legacy_invoice_number</code>. Native OPB invoices created after go-live have <code>legacy_invoice_number IS NULL</code>.</p>
        <pre><code>-- A3: Legacy vs Native — financial split
SELECT
    CASE
        WHEN legacy_invoice_number IS NOT NULL THEN 'Legacy Import'
        ELSE 'Native OPB'
    END                                              AS source,
    COUNT(*)                                         AS invoice_count,
    COALESCE(SUM(revenue), 0)                        AS total_revenue,
    COALESCE(SUM(paid),    0)                        AS total_paid,
    COALESCE(SUM(due),     0)                        AS total_outstanding,
    COUNT(CASE WHEN due &gt; 0 THEN 1 END)             AS unpaid_count,
    ROUND(
        COALESCE(SUM(paid),0) /
        NULLIF(COALESCE(SUM(revenue),0), 0) * 100, 1
    )                                                AS collection_rate_pct
FROM   wp_opb_invoices
GROUP  BY (legacy_invoice_number IS NOT NULL)
ORDER  BY source;</code></pre>

        <h3>A4. Outstanding by Branch and Source</h3>
        <pre><code>-- A4: Outstanding by branch, split legacy vs native
SELECT
    COALESCE(b.name, 'Unknown')                      AS branch,
    CASE
        WHEN i.legacy_invoice_number IS NOT NULL THEN 'Legacy Import'
        ELSE 'Native OPB'
    END                                              AS source,
    COUNT(*)                                         AS invoices,
    COALESCE(SUM(i.revenue), 0)                      AS total_revenue,
    COALESCE(SUM(i.paid),    0)                      AS total_paid,
    COALESCE(SUM(i.due),     0)                      AS total_outstanding
FROM   wp_opb_invoices i
LEFT JOIN wp_opb_branches b ON b.id = i.branch_id
WHERE  i.due &gt; 0
GROUP  BY i.branch_id, b.name,
          (i.legacy_invoice_number IS NOT NULL)
ORDER  BY total_outstanding DESC;</code></pre>

        <h3>A5. Payment Status Distribution</h3>
        <pre><code>-- A5: Payment status counts, split by legacy vs native
SELECT
    payment_status,
    CASE
        WHEN legacy_invoice_number IS NOT NULL THEN 'Legacy Import'
        ELSE 'Native OPB'
    END                                              AS source,
    COUNT(*)                                         AS invoice_count,
    COALESCE(SUM(due), 0)                            AS total_due
FROM   wp_opb_invoices
GROUP  BY payment_status,
          (legacy_invoice_number IS NOT NULL)
ORDER  BY payment_status, source;</code></pre>
      </div>

      <!-- SECTION B: TOP 50 CONTRIBUTORS -->
      <div class="card" id="section-b">
        <h2>Section B — Top 50 Outstanding Contributors</h2>
        <p>Run this query on the production WordPress database to identify the invoices contributing most to the ₹26.87 lakh outstanding figure:</p>
        <pre><code>-- B1: Top 50 invoices by outstanding balance
SELECT
    i.id                                            AS invoice_id,
    i.legacy_invoice_number,
    CASE
        WHEN i.legacy_invoice_number IS NOT NULL THEN 'Legacy'
        ELSE 'Native'
    END                                             AS source,
    COALESCE(b.name, 'Unknown')                     AS branch,
    c.name                                          AS client_name,
    c.phone                                         AS client_phone,
    bk.id                                           AS booking_id,
    i.invoice_date,
    i.invoice_type,
    i.revenue                                       AS invoice_total,
    i.paid                                          AS amount_paid,
    i.due                                           AS balance_due,
    i.payment_status,
    -- Check whether any payment records exist for this invoice
    COALESCE(pay.payment_count, 0)                  AS linked_payment_records,
    COALESCE(pay.payment_sum,   0)                  AS linked_payment_sum,
    -- Drift: does opb_payments sum match the stored paid field?
    ROUND(i.paid - COALESCE(pay.payment_sum, 0), 2) AS paid_field_drift
FROM   wp_opb_invoices i
LEFT JOIN wp_opb_branches b  ON b.id  = i.branch_id
LEFT JOIN wp_opb_bookings bk ON bk.id = i.booking_id
LEFT JOIN wp_opb_clients  c  ON c.id  = bk.client_id
LEFT JOIN (
    SELECT
        invoice_id,
        COUNT(*) AS payment_count,
        SUM(amount) AS payment_sum
    FROM   wp_opb_payments
    GROUP  BY invoice_id
) pay ON pay.invoice_id = i.id
WHERE  i.due &gt; 0
ORDER  BY i.due DESC
LIMIT  50;</code></pre>

        <div class="status-box" style="background:#fff5f5;border-color:#feb2b2;margin-top:16px">
          <h3 style="color:#c53030">Column Interpretation Guide</h3>
          <table style="margin-top:8px">
            <thead><tr><th>Column</th><th>Meaning</th><th>What to Look For</th></tr></thead>
            <tbody>
              <tr><td><code>source</code></td><td>Legacy = imported from old system; Native = created in OPB</td><td>Majority should be Legacy if the total is high</td></tr>
              <tr><td><code>balance_due</code></td><td>Stored <code>due</code> field: <code>revenue − paid</code></td><td>Sum of all rows ≈ dashboard outstanding figure</td></tr>
              <tr><td><code>linked_payment_records</code></td><td>Count of rows in <code>wp_opb_payments</code> for this invoice</td><td>Legacy invoices with paid &gt; 0 but 0 payment records: paid was imported directly onto invoice, no payment rows created</td></tr>
              <tr><td><code>paid_field_drift</code></td><td><code>i.paid − SUM(payment.amount)</code></td><td>Non-zero = invoice paid field and payment table are out of sync (common for legacy invoices where payment records were not imported)</td></tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- SECTION C: LEGACY IMPORT AUDIT -->
      <div class="card" id="section-c">
        <h2>Section C — Legacy Import Audit: Field Mapping &amp; Verification</h2>

        <h3>C1. Import Architecture Overview</h3>
        <p>The legacy migration is handled by <code>OPB_Invoices_Adapter</code> (<code>plugin/includes/migration/adapters/class-opb-invoices-adapter.php</code>) and <code>OPB_Payments_Adapter</code>. The two adapters are run independently in sequence.</p>

        <table style="margin-top:8px">
          <thead><tr><th>Legacy XLSX Column</th><th>OPB DB Column</th><th>Table</th><th>Import Method</th><th>Verified</th></tr></thead>
          <tbody>
            <tr><td><code>Invoice No</code></td><td><code>legacy_invoice_number</code></td><td>opb_invoices</td><td>Direct string copy</td><td>✓</td></tr>
            <tr><td><code>Invoice Date</code></td><td><code>invoice_date</code></td><td>opb_invoices</td><td>Parsed via <code>parse_date()</code></td><td>✓</td></tr>
            <tr><td><code>Revenue</code></td><td><code>revenue</code></td><td>opb_invoices</td><td>Cast to float; stored verbatim</td><td>✓</td></tr>
            <tr><td><code>Base Amount</code></td><td><code>base_amount</code></td><td>opb_invoices</td><td>Cast to float; stored verbatim</td><td>✓</td></tr>
            <tr><td><code>Add-On Amount</code></td><td><code>addon_amount</code></td><td>opb_invoices</td><td>Cast to float; stored verbatim</td><td>✓</td></tr>
            <tr><td><code>Discount Amount</code></td><td><code>discount_amount</code></td><td>opb_invoices</td><td>Cast to float; stored verbatim</td><td>✓</td></tr>
            <tr><td><code>Additional Amount</code></td><td><code>additional_amount</code></td><td>opb_invoices</td><td>Cast to float; stored verbatim</td><td>✓</td></tr>
            <tr><td><code>Additional Discount Amount</code></td><td><code>additional_discount_amount</code></td><td>opb_invoices</td><td>Cast to float; stored verbatim</td><td>✓</td></tr>
            <tr><td><code>Paid</code></td><td><code>paid</code></td><td>opb_invoices</td><td><strong>Cast to float; stored verbatim</strong></td><td style="color:#c05621;font-weight:700">⚠ Note 1</td></tr>
            <tr><td><code>Due</code></td><td><code>due</code></td><td>opb_invoices</td><td><strong>Cast to float; stored verbatim</strong></td><td style="color:#c05621;font-weight:700">⚠ Note 1</td></tr>
            <tr><td><code>Payment Mode</code></td><td><code>payment_mode</code></td><td>opb_invoices</td><td>String; stored on invoice header</td><td>✓</td></tr>
            <tr><td><code>Time</code> (payments file)</td><td><code>paid_at</code></td><td>opb_payments</td><td>Parsed via <code>parse_datetime()</code></td><td>✓</td></tr>
            <tr><td><code>Amount</code> (payments file)</td><td><code>amount</code></td><td>opb_payments</td><td>Cast to float; creates payment row</td><td style="color:#276749;font-weight:700">✓ Note 2</td></tr>
            <tr><td><code>Invoice ID</code> (payments file)</td><td><code>invoice_id</code></td><td>opb_payments</td><td>Resolved from <code>legacy_invoice_number</code></td><td style="color:#276749;font-weight:700">✓ Note 2</td></tr>
          </tbody>
        </table>

        <div style="background:#fffaf0;border:1px solid #fbd38d;border-radius:6px;padding:14px;margin-top:16px">
          <div style="font-weight:700;color:#c05621;margin-bottom:8px">⚠ Note 1 — Dual Write Path for <code>paid</code> and <code>due</code></div>
          <p style="font-size:13px;color:#2d3748;margin-bottom:6px">The invoices adapter writes <code>paid</code> and <code>due</code> <strong>directly from the legacy XLSX file</strong>, not by recalculating from payment records. This is intentional: at import time, no payment records may exist yet.</p>
          <p style="font-size:13px;color:#2d3748;margin-bottom:6px">If the <strong>payments adapter was also run</strong> after the invoices adapter, each imported payment row triggers <code>OPB_Invoice_Generator::sync_payment_totals(invoice_id)</code>. This <em>recalculates</em> <code>paid</code> and <code>due</code> from the <code>wp_opb_payments</code> table — overwriting the values that came from the XLSX.</p>
          <p style="font-size:13px;color:#2d3748;margin:0"><strong>If payments were not imported (or some payment rows were skipped)</strong>, the <code>paid</code> and <code>due</code> fields remain exactly as they were in the legacy XLSX. A client who owed ₹5,000 in the old system will show <code>due = 5000</code> in OPB regardless of any subsequent real-world payments.</p>
        </div>

        <div style="background:#f0fff4;border:1px solid #9ae6b4;border-radius:6px;padding:14px;margin-top:12px">
          <div style="font-weight:700;color:#276749;margin-bottom:8px">✓ Note 2 — Payment Linkage is Correct</div>
          <p style="font-size:13px;color:#2d3748;margin:0">The payments adapter resolves <code>invoice_id</code> by looking up <code>legacy_invoice_number + branch_id</code> in <code>wp_opb_invoices</code>. If no match is found, the payment row is skipped (<code>reason_code: invoice_not_found</code>). Payment linkage is correctly designed; the only gap is whether all payment rows were successfully imported.</p>
        </div>

        <h3 style="margin-top:20px">C2. Verification Query — Paid Field Drift Detection</h3>
        <p>This query identifies invoices where the stored <code>paid</code> field does not match the sum of linked payment records. A non-zero drift on legacy invoices is the primary signal that payments were imported at a different amount (or not at all) relative to what the legacy system recorded.</p>
        <pre><code>-- C2: Drift between invoice.paid and SUM(payments.amount)
SELECT
    i.id,
    i.legacy_invoice_number,
    CASE WHEN i.legacy_invoice_number IS NOT NULL
         THEN 'Legacy' ELSE 'Native' END            AS source,
    i.revenue,
    i.paid                                          AS invoice_paid_field,
    COALESCE(SUM(p.amount), 0)                      AS payments_table_sum,
    ROUND(i.paid - COALESCE(SUM(p.amount), 0), 2)  AS drift,
    i.due,
    i.payment_status
FROM   wp_opb_invoices i
LEFT JOIN wp_opb_payments p ON p.invoice_id = i.id
GROUP  BY i.id
HAVING ABS(drift) &gt; 0.01
ORDER  BY ABS(drift) DESC
LIMIT  100;</code></pre>

        <h3>C3. Count of Legacy Invoices with No Linked Payment Records</h3>
        <pre><code>-- C3: Legacy invoices with paid &gt; 0 on the invoice row but zero payment records
-- These invoices were imported with their historical paid amounts verbatim
-- but no corresponding rows exist in wp_opb_payments
SELECT
    COUNT(*)                                        AS invoices_paid_field_only,
    COALESCE(SUM(i.paid),    0)                     AS total_historically_paid,
    COALESCE(SUM(i.due),     0)                     AS total_outstanding
FROM   wp_opb_invoices i
LEFT JOIN wp_opb_payments p ON p.invoice_id = i.id
WHERE  i.legacy_invoice_number IS NOT NULL
  AND  i.paid &gt; 0
  AND  p.id IS NULL;</code></pre>

        <h3>C4. Skipped Payment Rows Indicator</h3>
        <p>The payments adapter logs a <code>reason_code: invoice_not_found</code> when a payment cannot be linked. To estimate how many payments were skipped, compare the total payment value in the legacy XLSX files against the sum in <code>wp_opb_payments</code> for legacy-origin records. Run this against the DB:</p>
        <pre><code>-- C4: Total payment value recorded in wp_opb_payments for legacy invoices
SELECT
    COALESCE(b.name, 'Unknown')                     AS branch,
    COUNT(DISTINCT p.invoice_id)                    AS invoices_with_payments,
    COUNT(p.id)                                     AS payment_records,
    COALESCE(SUM(p.amount), 0)                      AS total_payment_value
FROM   wp_opb_payments p
JOIN   wp_opb_invoices i ON i.id = p.invoice_id
LEFT JOIN wp_opb_branches b ON b.id = p.branch_id
WHERE  i.legacy_invoice_number IS NOT NULL
GROUP  BY p.branch_id, b.name
ORDER  BY total_payment_value DESC;</code></pre>
      </div>

      <!-- SECTION D: DATE AUDIT -->
      <div class="card" id="section-d">
        <h2>Section D — Date Field Influence Analysis</h2>
        <p>This section documents whether each date-related field in the OPB schema influences the dashboard Outstanding KPI.</p>

        <table>
          <thead><tr><th>Field</th><th>Table</th><th>Type</th><th>Influences Outstanding?</th><th>Evidence</th></tr></thead>
          <tbody>
            <tr>
              <td><code>booking_date</code></td><td>opb_bookings</td><td>DATE</td>
              <td style="color:#276749;font-weight:600">✗ No</td>
              <td>Dashboard Outstanding query joins no booking columns. <code>booking_date</code> is used only for operational scheduling.</td>
            </tr>
            <tr>
              <td><code>check_in_date</code></td><td>opb_booking_stays</td><td>DATE</td>
              <td style="color:#276749;font-weight:600">✗ No</td>
              <td>Dashboard query does not join <code>opb_booking_stays</code> for the KPI figure.</td>
            </tr>
            <tr>
              <td><code>check_out_date</code></td><td>opb_booking_stays</td><td>DATE</td>
              <td style="color:#276749;font-weight:600">✗ No</td>
              <td>Same as above. <code>check_out_date</code> appears only in the checkout list widget, not the KPI sum.</td>
            </tr>
            <tr>
              <td><code>invoice_date</code></td><td>opb_invoices</td><td>DATE</td>
              <td style="color:#c05621;font-weight:600">✗ No (Dashboard) / ✓ Yes (Reports)</td>
              <td><code>class-opb-dashboard-api.php</code> line 37–38: <code>WHERE i.due &gt; 0</code> — <strong>no date filter</strong>. However, <code>class-opb-reports-api.php</code> lines 148–151 does filter Outstanding by <code>invoice_date</code> range, so Reports Outstanding ≠ Dashboard Outstanding.</td>
            </tr>
            <tr>
              <td><code>created_at</code></td><td>opb_invoices</td><td>DATETIME</td>
              <td style="color:#276749;font-weight:600">✗ No</td>
              <td>Not referenced in any outstanding-related query. Used only internally (audit trail).</td>
            </tr>
            <tr>
              <td><code>updated_at</code></td><td>opb_invoices</td><td>DATETIME</td>
              <td style="color:#276749;font-weight:600">✗ No</td>
              <td>Not referenced in any outstanding-related query.</td>
            </tr>
            <tr>
              <td><code>paid_at</code></td><td>opb_payments</td><td>DATETIME</td>
              <td style="color:#276749;font-weight:600">✗ No (Indirect)</td>
              <td>The date of a payment does not affect the outstanding calculation. Only the <em>amount</em> in <code>opb_payments</code> matters — it feeds <code>sync_payment_totals()</code> which updates <code>paid</code> and <code>due</code> on the invoice.</td>
            </tr>
            <tr>
              <td><code>imported_at</code></td><td>—</td><td>—</td>
              <td style="color:#276749;font-weight:600">✗ No (Field absent)</td>
              <td>No <code>imported_at</code> column exists in any OPB table. Legacy origin is identified by <code>legacy_invoice_number IS NOT NULL</code>, not by a timestamp.</td>
            </tr>
            <tr>
              <td><code>due_date</code></td><td>—</td><td>—</td>
              <td style="color:#276749;font-weight:600">✗ No (Field absent)</td>
              <td>No <code>due_date</code> column exists in <code>opb_invoices</code>. Invoices have no payment due date concept. Outstanding = all unpaid balances regardless of age.</td>
            </tr>
          </tbody>
        </table>

        <h3 style="margin-top:20px">D1. Code Reference — Outstanding Calculation Path</h3>
        <p>The full trace from database to dashboard UI:</p>
        <pre><code>Step 1 — Payment recorded
  OPB_Payments_API::create_item()
    → wpdb→insert(wp_opb_payments, { invoice_id, amount, ... })
    → OPB_Invoice_Generator::sync_payment_totals(invoice_id)

Step 2 — sync_payment_totals()  [class-opb-invoice-generator.php:212]
  $paid    = SELECT COALESCE(SUM(amount),0) FROM wp_opb_payments WHERE invoice_id = %d
  $revenue = SELECT revenue FROM wp_opb_invoices WHERE id = %d
  $due     = round($revenue - $paid, 2)
  $status  = resolve_payment_status($revenue, $paid)
  wpdb→update(wp_opb_invoices, { paid, due, payment_status }, { id })

Step 3 — resolve_payment_status()  [class-opb-invoice-generator.php:202]
  if ($revenue &lt;= 0)          → 'No bill'
  if ($paid &lt;= 0)             → 'Unpaid'
  if ($paid &gt;= $revenue)      → 'Paid' or 'Overpaid'
  else                         → 'Partially paid'

Step 4 — Dashboard API  [class-opb-dashboard-api.php:37]
  $outstanding = SELECT COALESCE(SUM(i.due),0)
                 FROM wp_opb_invoices i
                 WHERE i.due &gt; 0
                 [AND i.branch_id = {branch_id}]

Step 5 — React Dashboard  [Dashboard.tsx:69]
  { label: 'Outstanding', value: fmt.inr(kpis.outstanding) }
  color: kpis.outstanding &gt; 0 ? 'text-red-600' : 'text-gray-900'</code></pre>

        <div style="background:#ebf8ff;border:1px solid #90cdf4;border-radius:6px;padding:14px;margin-top:16px">
          <div style="font-weight:700;color:#2b6cb0;margin-bottom:8px">Key Finding — Dashboard vs Reports Outstanding Discrepancy</div>
          <p style="font-size:13px;color:#2d3748;margin:0">The <strong>Dashboard Outstanding</strong> is lifetime (all invoices, no date filter). The <strong>Reports Outstanding</strong> is filtered by the selected date range applied to <code>invoice_date</code>. These two figures will differ. The dashboard figure will always be &gt;= the reports figure for any finite date range. This is a known architectural divergence, not a bug.</p>
        </div>
      </div>

      <!-- SECTION E: RECOMMENDATION -->
      <div class="card" id="section-e">
        <h2>Section E — Dashboard Metric Recommendation</h2>

        <h3>E1. Current Metric Definition</h3>
        <table>
          <thead><tr><th>Attribute</th><th>Current Value</th></tr></thead>
          <tbody>
            <tr><td>Metric name</td><td>Outstanding</td></tr>
            <tr><td>SQL definition</td><td><code>SELECT COALESCE(SUM(due),0) FROM wp_opb_invoices WHERE due &gt; 0</code></td></tr>
            <tr><td>Scope</td><td>All invoices, all time, all branches (unless branch filter applied)</td></tr>
            <tr><td>Date filter</td><td>None</td></tr>
            <tr><td>Includes legacy imports?</td><td>Yes — legacy invoices with due &gt; 0 are included</td></tr>
            <tr><td>Display</td><td>Red text when &gt; 0, Indian Rupee format</td></tr>
          </tbody>
        </table>

        <h3 style="margin-top:20px">E2. Operational Assessment</h3>
        <table>
          <thead><tr><th>Question</th><th>Answer</th><th>Detail</th></tr></thead>
          <tbody>
            <tr>
              <td>Is the metric mathematically correct?</td>
              <td style="color:#276749;font-weight:600">Yes</td>
              <td>It accurately reflects the sum of all stored <code>due</code> amounts. No calculation error.</td>
            </tr>
            <tr>
              <td>Is it operationally useful for daily ops?</td>
              <td style="color:#c05621;font-weight:600">Partially</td>
              <td>Includes historical debts from the legacy system that may be uncollectable, artificially inflating the figure. Staff may treat the KPI as noisy.</td>
            </tr>
            <tr>
              <td>Should legacy balances appear?</td>
              <td style="color:#c05621;font-weight:600">Context-dependent</td>
              <td>If the business intends to collect legacy debts, yes. If legacy debts were waived at migration, they should be settled in the DB (set <code>due=0</code>, <code>payment_status='Paid'</code>) or excluded via a flag.</td>
            </tr>
            <tr>
              <td>Risk of confusing staff?</td>
              <td style="color:#c53030;font-weight:600">High</td>
              <td>~₹26.87 lakh appearing in red on the dashboard each day creates alarm. Staff may attempt to "resolve" records that are legitimately historical.</td>
            </tr>
          </tbody>
        </table>

        <h3 style="margin-top:20px">E3. Alternative Metric Definitions</h3>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-top:8px">
          <div style="background:#f0fff4;border:1px solid #9ae6b4;border-radius:6px;padding:14px">
            <div style="font-weight:700;color:#276749;margin-bottom:8px">Option 1 — Native OPB Only (Recommended)</div>
            <p style="font-size:13px;color:#2d3748;margin-bottom:8px">Exclude legacy imported invoices from the dashboard Outstanding. Show a separate "Legacy Outstanding" in a less prominent position.</p>
            <pre style="font-size:11px;margin:0"><code>SELECT COALESCE(SUM(due),0)
FROM   wp_opb_invoices
WHERE  due &gt; 0
  AND  legacy_invoice_number IS NULL</code></pre>
          </div>
          <div style="background:#ebf8ff;border:1px solid #90cdf4;border-radius:6px;padding:14px">
            <div style="font-weight:700;color:#2b6cb0;margin-bottom:8px">Option 2 — Rolling 90-Day Window</div>
            <p style="font-size:13px;color:#2d3748;margin-bottom:8px">Limit to invoices within the last 90 days to focus on recent receivables only.</p>
            <pre style="font-size:11px;margin:0"><code>SELECT COALESCE(SUM(due),0)
FROM   wp_opb_invoices
WHERE  due &gt; 0
  AND  invoice_date &gt;= CURDATE() - INTERVAL 90 DAY</code></pre>
          </div>
          <div style="background:#fffaf0;border:1px solid #fbd38d;border-radius:6px;padding:14px">
            <div style="font-weight:700;color:#c05621;margin-bottom:8px">Option 3 — Active Clients Only</div>
            <p style="font-size:13px;color:#2d3748;margin-bottom:8px">Restrict to invoices belonging to clients who still have active status in the system.</p>
            <pre style="font-size:11px;margin:0"><code>SELECT COALESCE(SUM(i.due),0)
FROM   wp_opb_invoices i
JOIN   wp_opb_bookings bk ON bk.id=i.booking_id
JOIN   wp_opb_clients  c  ON c.id=bk.client_id
WHERE  i.due &gt; 0
  AND  c.status = 'active'</code></pre>
          </div>
          <div style="background:#fff5f5;border:1px solid #feb2b2;border-radius:6px;padding:14px">
            <div style="font-weight:700;color:#c53030;margin-bottom:8px">Option 4 — Keep Current + Add Context</div>
            <p style="font-size:13px;color:#2d3748;margin-bottom:8px">No SQL change. Add a secondary "Legacy Outstanding" badge beneath the main KPI so staff can see the split at a glance.</p>
            <pre style="font-size:11px;margin:0"><code>-- Separate KPI in API response
legacy_outstanding: SUM(due) WHERE legacy_invoice_number IS NOT NULL AND due &gt; 0
native_outstanding: SUM(due) WHERE legacy_invoice_number IS NULL     AND due &gt; 0</code></pre>
          </div>
        </div>

        <h3 style="margin-top:20px">E4. Recommended Immediate Actions</h3>
        <table>
          <thead><tr><th>Priority</th><th>Action</th><th>Impact</th></tr></thead>
          <tbody>
            <tr>
              <td style="color:#c53030;font-weight:700">High</td>
              <td>Run the C2 drift query to determine whether legacy <code>paid</code> values match the payments table. Establish ground truth.</td>
              <td>Confirms whether payment import was complete or partial.</td>
            </tr>
            <tr>
              <td style="color:#c53030;font-weight:700">High</td>
              <td>Run the B1 Top 50 query. Cross-reference with actual client ledgers to confirm which debts are collectible.</td>
              <td>Quantifies the "real" vs "legacy-only" outstanding.</td>
            </tr>
            <tr>
              <td style="color:#c05621;font-weight:700">Medium</td>
              <td>Decide on legacy debt policy: collect, waive, or exclude from dashboard. Implement Option 1 or 4 from Section E3.</td>
              <td>Removes noise from daily operational view.</td>
            </tr>
            <tr>
              <td style="color:#276749;font-weight:700">Low</td>
              <td>If legacy debts are waived, run a one-time SQL UPDATE to set <code>due=0, payment_status='Paid'</code> on all legacy invoices with no collection intent. Back up DB first.</td>
              <td>Permanently resolves the dashboard figure. Irreversible without backup.</td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- SECTION F: JSON SUMMARY -->
      <div class="card" id="section-f">
        <h2>Section F — JSON Summary Structure</h2>
        <p>The following JSON structure represents the expected output of a forensic summary API endpoint. Values are illustrative pending live DB execution.</p>
        <pre><code>{
  "report_meta": {
    "generated_at": "${new Date().toISOString()}",
    "plugin_version": "3.1.0",
    "analysis_type": "financial_forensics",
    "scope": "all_invoices_all_time",
    "data_modified": false
  },
  "executive_summary": {
    "dashboard_outstanding_source": "SELECT COALESCE(SUM(due),0) FROM wp_opb_invoices WHERE due > 0",
    "root_cause": "Legacy imported invoices carrying historical unpaid balances from pre-migration system",
    "calculation_error_found": false,
    "data_corruption_found": false,
    "date_fields_influence_dashboard_outstanding": false,
    "date_fields_influence_reports_outstanding": true
  },
  "financial_totals": {
    "all_invoices": {
      "total_count": "RUN QUERY A2",
      "total_revenue": "RUN QUERY A2",
      "total_paid": "RUN QUERY A2",
      "total_outstanding": "RUN QUERY A2 — expected ~2687000"
    },
    "legacy_invoices": {
      "identified_by": "legacy_invoice_number IS NOT NULL",
      "total_count": "RUN QUERY A3",
      "total_revenue": "RUN QUERY A3",
      "total_paid": "RUN QUERY A3",
      "total_outstanding": "RUN QUERY A3"
    },
    "native_opb_invoices": {
      "identified_by": "legacy_invoice_number IS NULL",
      "total_count": "RUN QUERY A3",
      "total_revenue": "RUN QUERY A3",
      "total_paid": "RUN QUERY A3",
      "total_outstanding": "RUN QUERY A3"
    }
  },
  "import_audit": {
    "invoices_adapter": {
      "source_files": [
        "legacy-system/invoices/Onukonu pet homestyle boarding - H2 succoro.xlsx",
        "legacy-system/invoices/Onukonu pet homestyle boarding - H3 Colvale.xlsx",
        "legacy-system/invoices/Onukonu pet homestyle boarding - H4 Moira.xlsx"
      ],
      "paid_field_import_method": "verbatim_from_legacy_xlsx",
      "due_field_import_method": "verbatim_from_legacy_xlsx",
      "sync_payment_totals_called_at_import": false,
      "sync_triggered_by_payments_import": true
    },
    "payments_adapter": {
      "source_files": [
        "legacy-system/payments/Onukonu pet homestyle boarding - H2 succoro.xlsx",
        "legacy-system/payments/Onukonu pet homestyle boarding - H3 Colvale.xlsx",
        "legacy-system/payments/Onukonu pet homestyle boarding - H4 Moira.xlsx"
      ],
      "linkage_method": "legacy_invoice_number + branch_id lookup in wp_opb_invoices",
      "on_skip_reason": "invoice_not_found (legacy_invoice_number does not resolve)",
      "on_success": "sync_payment_totals() recalculates invoice.paid and invoice.due"
    },
    "drift_check": "RUN QUERY C2 — invoices where ABS(invoice.paid - SUM(payments.amount)) > 0.01",
    "legacy_invoices_with_no_payment_records": "RUN QUERY C3"
  },
  "date_influence": {
    "booking_date": "no_effect",
    "check_in_date": "no_effect",
    "check_out_date": "no_effect",
    "invoice_date": "dashboard=no_effect, reports_api=filtered_by_date_range",
    "created_at": "no_effect",
    "imported_at": "field_does_not_exist",
    "due_date": "field_does_not_exist",
    "paid_at": "indirect_only_via_payment_amount_not_date"
  },
  "recommendations": [
    {
      "priority": "high",
      "action": "Run C2 drift query to verify payment import completeness"
    },
    {
      "priority": "high",
      "action": "Run B1 Top 50 query to identify largest legacy balance contributors"
    },
    {
      "priority": "medium",
      "action": "Implement Option 1 or 4 from Section E3 to separate legacy from native outstanding on dashboard"
    },
    {
      "priority": "low",
      "action": "If legacy debts are waived: UPDATE wp_opb_invoices SET due=0, payment_status='Paid' WHERE legacy_invoice_number IS NOT NULL AND due > 0 (after DB backup)"
    }
  ]
}</code></pre>
      </div>

      <!-- SECTION G: SQL QUICK-REFERENCE -->
      <div class="card" id="section-g">
        <h2>Section G — SQL Quick-Reference Pack</h2>
        <p style="color:#718096;font-size:13px">Copy-paste ready. Replace <code>wp_</code> prefix if your WordPress installation uses a different table prefix. All queries are read-only SELECTs — no data is modified.</p>

        <h3>G1. Total Outstanding (matches dashboard exactly)</h3>
        <pre><code>SELECT COALESCE(SUM(due),0) AS dashboard_outstanding
FROM   wp_opb_invoices
WHERE  due &gt; 0;</code></pre>

        <h3>G2. Outstanding split: Legacy vs Native</h3>
        <pre><code>SELECT
    CASE WHEN legacy_invoice_number IS NOT NULL
         THEN 'Legacy Import' ELSE 'Native OPB' END  AS source,
    COUNT(*)                                          AS invoices,
    COALESCE(SUM(revenue), 0)                         AS revenue,
    COALESCE(SUM(paid),    0)                         AS paid,
    COALESCE(SUM(due),     0)                         AS outstanding
FROM   wp_opb_invoices
GROUP  BY (legacy_invoice_number IS NOT NULL);</code></pre>

        <h3>G3. Top 20 outstanding invoices (quick view)</h3>
        <pre><code>SELECT i.id, i.legacy_invoice_number, c.name AS client,
       i.revenue, i.paid, i.due, i.payment_status, i.invoice_date
FROM   wp_opb_invoices i
JOIN   wp_opb_bookings bk ON bk.id = i.booking_id
JOIN   wp_opb_clients  c  ON c.id  = bk.client_id
WHERE  i.due &gt; 0
ORDER  BY i.due DESC
LIMIT  20;</code></pre>

        <h3>G4. Payment drift check</h3>
        <pre><code>SELECT i.id, i.legacy_invoice_number, i.paid AS stored_paid,
       COALESCE(SUM(p.amount),0) AS payments_sum,
       ROUND(i.paid - COALESCE(SUM(p.amount),0), 2) AS drift
FROM   wp_opb_invoices i
LEFT JOIN wp_opb_payments p ON p.invoice_id = i.id
GROUP  BY i.id
HAVING ABS(drift) &gt; 0.01
ORDER  BY ABS(drift) DESC
LIMIT  50;</code></pre>

        <h3>G5. Legacy invoices with zero payment records despite paid &gt; 0</h3>
        <pre><code>SELECT i.id, i.legacy_invoice_number, i.revenue, i.paid, i.due
FROM   wp_opb_invoices i
LEFT JOIN wp_opb_payments p ON p.invoice_id = i.id
WHERE  i.legacy_invoice_number IS NOT NULL
  AND  i.paid &gt; 0
  AND  p.id IS NULL
ORDER  BY i.due DESC;</code></pre>

        <h3>G6. Outstanding by branch (all time, all sources)</h3>
        <pre><code>SELECT COALESCE(b.name,'Unknown') AS branch,
       COUNT(i.id) AS invoices,
       COALESCE(SUM(i.due),0) AS outstanding
FROM   wp_opb_invoices i
LEFT JOIN wp_opb_branches b ON b.id = i.branch_id
WHERE  i.due &gt; 0
GROUP  BY i.branch_id
ORDER  BY outstanding DESC;</code></pre>

        <h3>G7. Confirm no date fields affect dashboard outstanding</h3>
        <pre><code>-- Compare dashboard outstanding vs date-filtered outstanding
-- If different: date IS a factor in Reports but not in Dashboard
SELECT
    (SELECT COALESCE(SUM(due),0) FROM wp_opb_invoices WHERE due &gt; 0)
        AS dashboard_outstanding_no_date_filter,
    (SELECT COALESCE(SUM(due),0) FROM wp_opb_invoices
     WHERE  due &gt; 0 AND invoice_date &gt;= DATE_FORMAT(CURDATE(),'%Y-%m-01'))
        AS this_month_outstanding_invoice_date_filtered;</code></pre>

        <div style="background:#f7fafc;border:1px solid #e2e8f0;border-radius:6px;padding:14px;margin-top:20px">
          <div style="font-weight:700;color:#4a5568;margin-bottom:8px;font-size:13px">Legend</div>
          <div style="display:flex;gap:24px;flex-wrap:wrap;font-size:12px;color:#718096">
            <span><strong style="color:#276749">legacy_invoice_number IS NOT NULL</strong> = imported from legacy system</span>
            <span><strong style="color:#2b6cb0">legacy_invoice_number IS NULL</strong> = native OPB record</span>
            <span><strong style="color:#c05621">drift &gt; 0.01</strong> = invoice.paid ≠ sum of payment records (may indicate incomplete payments import)</span>
          </div>
        </div>
      </div>
    `;
  } else if (activeTab === 'permissions') {
    title = 'Permission Audit';
    const permDocs = [
      { num: '01', label: 'Role Inventory',                  md: perm01 },
      { num: '02', label: 'Capability Inventory',            md: perm02 },
      { num: '03', label: 'User Type Audit',                 md: perm03 },
      { num: '04', label: 'Branch Scope Audit',              md: perm04 },
      { num: '05', label: 'Module Permission Matrix',        md: perm05 },
      { num: '06', label: 'OPSMAIL Permission Matrix',       md: perm06 },
      { num: '07', label: 'SAL Permission Matrix',           md: perm07 },
      { num: '08', label: 'Conflict Detection Report',       md: perm08 },
      { num: '09', label: 'Security Review',                 md: perm09 },
      { num: '10', label: 'Architecture Documentation',      md: perm10 },
      { num: '11', label: 'Canonical Model Recommendation',  md: perm11 },
    ];
    content = `
      <div class="card">
        <h2>Permission, Role, Scope &amp; Access Control Audit</h2>
        <p style="color:#4a5568;font-size:14px">Plugin v3.1.0 &mdash; June 2026 &mdash; Read-only audit of the existing access-control architecture. No code changes made.</p>
        <div style="margin-top:16px;display:flex;gap:8px;flex-wrap:wrap">
          ${permDocs.map(d => `<a href="#perm-${d.num}" style="background:#ebf8ff;color:#2b6cb0;border:1px solid #bee3f8;border-radius:20px;padding:4px 14px;font-size:12px;font-weight:600;text-decoration:none">Part ${d.num} — ${d.label}</a>`).join('')}
        </div>
      </div>
      ${permDocs.map(d => `
        <div class="card doc-content" id="perm-${d.num}">
          <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;border-bottom:1px solid #e2e8f0;padding-bottom:10px">
            <span style="background:#1a365d;color:white;border-radius:6px;padding:4px 12px;font-size:13px;font-weight:700">Part ${d.num}</span>
            <span style="font-size:16px;font-weight:700;color:#1a365d">${d.label}</span>
          </div>
          ${d.md ? simpleMarkdown(d.md) : '<p style="color:#a0aec0">Document not found.</p>'}
        </div>`).join('')}
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
    <a href="/rc1" class="${activeTab === 'rc1' ? 'active' : ''}" style="color:#68d391;font-weight:700">RC1 Audit</a>
    <a href="/permissions" class="${activeTab === 'permissions' ? 'active' : ''}" style="color:#fbd38d;font-weight:700">Permission Audit</a>
    <a href="/forensics" class="${activeTab === 'forensics' ? 'active' : ''}" style="color:#fc8181;font-weight:700">Financial Forensics</a>
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
  else if (url === '/rc1') tab = 'rc1';
  else if (url === '/permissions') tab = 'permissions';
  else if (url === '/forensics') tab = 'forensics';
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
