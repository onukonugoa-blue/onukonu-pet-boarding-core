const http = require('http');
const fs = require('fs');
const path = require('path');

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

function renderPage(activeTab) {
  const readme = readFileSafe('README.md') || '# No README found';
  const architecture = readFileSafe('docs/ARCHITECTURE.md') || '# No ARCHITECTURE.md found';
  const analysis = readFileSafe('docs/ANALYSIS.md') || '# No ANALYSIS.md found';
  const legacyStats = getLegacyStats();
  const tree = getPluginTree();

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
    title = 'Plugin';
    const licenseContent = readFileSafe('plugin/License.md');
    content = `
      <div class="card">
        <h2>Plugin Directory</h2>
        <p>The <code>plugin/</code> directory is where the WordPress plugin code will be built. Currently contains:</p>
        <ul>
          <li><strong>License.md</strong> — License placeholder</li>
        </ul>
        <div class="status-box">
          <h3>Plugin Status</h3>
          <p>The WordPress plugin code is yet to be scaffolded. The architecture documentation is complete and approved.</p>
          <h4>Planned Plugin Structure:</h4>
          <pre><code>plugin/
├── onukonu-pet-boarding-core.php   (main plugin file)
├── includes/
│   ├── class-opb-activator.php     (DB table creation)
│   ├── class-opb-deactivator.php
│   ├── class-opb-loader.php
│   └── class-opb-api.php           (REST API registration)
├── admin/
│   └── class-opb-admin.php         (WP admin page + SPA mount)
├── api/
│   ├── endpoints/
│   │   ├── class-opb-clients-api.php
│   │   ├── class-opb-pets-api.php
│   │   ├── class-opb-bookings-api.php
│   │   ├── class-opb-invoices-api.php
│   │   └── ...
│   └── class-opb-rest-controller.php
├── frontend/
│   ├── src/                         (React SPA source)
│   │   ├── App.tsx
│   │   ├── pages/
│   │   ├── components/
│   │   └── api/
│   ├── dist/                        (built assets, enqueued by WP)
│   └── package.json
└── migration/
    └── class-opb-import.php         (CSV/XLSX migration engine)</code></pre>
        </div>
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
  </nav>
  <main class="main">
    <div class="badges">
      <span class="badge">3 Branches</span>
      <span class="badge">893 Clients</span>
      <span class="badge">~1,912 Bookings</span>
      <span class="badge green">Docs Ready</span>
      <span class="badge orange">Plugin: In Progress</span>
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
