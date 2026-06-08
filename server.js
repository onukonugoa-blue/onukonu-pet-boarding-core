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

        <h3>v2.3.0 — Login Page Branding</h3>
        <ul>
          <li><strong>New file</strong> — <code>assets/branding/login-logo.png</code> — 120×120 paw-print logo, transparent PNG, dedicated to the login page (separate from PWA icons)</li>
          <li><strong>New file</strong> — <code>assets/login.css</code> — premium CSS skin: deep navy radial gradient background, white card, rounded corners, styled inputs/buttons/alerts</li>
          <li><strong>New class</strong> — <code>OPB_Login</code> (<code>includes/class-opb-login.php</code>) — registers four WordPress hooks: <code>login_enqueue_scripts</code>, <code>login_footer</code>, <code>login_headerurl</code>, <code>login_headertext</code></li>
          <li><strong>Branding text</strong> — "Onukonu Pet Boarding / Operations Portal" injected below logo via <code>login_footer</code></li>
          <li><strong>Loginizer compatible</strong> — lockout messages, 2FA challenge, forgot-password, and reset-password forms all covered</li>
          <li><strong>Mobile responsive</strong> — card reflows to full-width below 460 px</li>
          <li><strong>No auth changes</strong> — static CSS + hooks only; authentication, redirects, sessions, and Loginizer untouched</li>
        </ul>

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
