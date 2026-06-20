'use strict';
const PDFDocument = require('pdfkit');
const fs = require('fs');
const path = require('path');
const { Document, Packer, Paragraph, TextRun, Table, TableRow, TableCell, HeadingLevel, AlignmentType, WidthType, BorderStyle, ShadingType } = require('docx');

const OUT = __dirname;
const NAVY  = '#1A365D';
const AMBER = '#D97706';
const TEAL  = '#0F766E';
const LGRAY = '#F8FAFC';
const DGRAY = '#1F2937';
const MGRAY = '#6B7280';

// ─── PDF HELPERS ─────────────────────────────────────────────────────────────
function pdfCover(doc) {
  const W = doc.page.width; const H = doc.page.height;
  doc.rect(0, 0, W, H).fill(NAVY);
  // Gold accent band
  doc.rect(0, H * 0.62, W, 4).fill(AMBER);
  // Paw print motif (circles)
  [[80,80],[120,50],[55,50],[70,110]].forEach(([x,y]) => doc.circle(x,y,12).fill('#243654').fillOpacity(0.5));
  // Title
  doc.fillOpacity(1).fill('#FFFFFF').font('Helvetica-Bold').fontSize(11)
     .text('ONUKONU PET BOARDING', 0, 140, { width: W, align: 'center', characterSpacing: 3 });
  doc.fill(AMBER).font('Helvetica-Bold').fontSize(36)
     .text('Core', 0, 158, { width: W, align: 'center' });
  doc.fill('#FFFFFF').font('Helvetica-Bold').fontSize(22)
     .text('Management Platform', 0, 202, { width: W, align: 'center' });
  doc.fill('#94A3B8').font('Helvetica').fontSize(13)
     .text('Purpose-Built for Multi-Branch Pet Boarding Operations', 0, 238, { width: W, align: 'center' });

  // Three value props
  const vp = [
    { icon: '🐾', title: 'AI Executive Briefings', sub: 'SAL — Situational Awareness Layer' },
    { icon: '🏢', title: 'Multi-Branch Native', sub: 'Unified operations, branch-scoped data' },
    { icon: '📄', title: 'Intelligent Invoicing', sub: 'PDF, Email, WhatsApp delivery' },
  ];
  vp.forEach((v, i) => {
    const x = 40 + i * 170; const y = H * 0.38;
    doc.rect(x, y, 158, 80).fill('#243654');
    doc.fill(AMBER).font('Helvetica-Bold').fontSize(18).text(v.icon, x, y + 10, { width: 158, align: 'center' });
    doc.fill('#FFFFFF').font('Helvetica-Bold').fontSize(10).text(v.title, x, y + 38, { width: 158, align: 'center' });
    doc.fill('#94A3B8').font('Helvetica').fontSize(8).text(v.sub, x, y + 54, { width: 158, align: 'center' });
  });

  doc.fill('#CBD5E0').font('Helvetica').fontSize(10)
     .text('Version 3.3.0  ·  PHP 8.2 + React 18 + MySQL  ·  WordPress Plugin', 0, H * 0.68, { width: W, align: 'center' });
  doc.fill('#718096').font('Helvetica').fontSize(9)
     .text(`Confidential  ·  ${new Date().toLocaleDateString('en-IN', { year: 'numeric', month: 'long' })}`, 0, H * 0.72, { width: W, align: 'center' });
}

function pdfSectionDivider(doc, num, title, subtitle) {
  doc.addPage();
  doc.rect(0, 0, doc.page.width, 8).fill(AMBER);
  doc.rect(0, 8, doc.page.width, doc.page.height - 8).fill(NAVY);
  doc.fill('#475569').font('Helvetica-Bold').fontSize(11)
     .text(`0${num}`, 60, 160, { characterSpacing: 2 });
  doc.fill('#FFFFFF').font('Helvetica-Bold').fontSize(30)
     .text(title, 60, 178);
  doc.fill('#94A3B8').font('Helvetica').fontSize(14)
     .text(subtitle, 60, 222, { width: doc.page.width - 120 });
  doc.rect(60, 268, 80, 3).fill(AMBER);
  doc.addPage();
}

function pdfH1(doc, text) {
  const W = doc.page.width - 80;
  if (doc.y > doc.page.height - 120) doc.addPage();
  doc.moveDown(0.5);
  doc.rect(40, doc.y, W, 30).fill(NAVY);
  const ty = doc.y + 8;
  doc.fill('#FFFFFF').font('Helvetica-Bold').fontSize(13).text(text, 52, ty, { lineBreak: false });
  doc.y = ty + 32;
}

function pdfH2(doc, text) {
  if (doc.y > doc.page.height - 80) doc.addPage();
  doc.moveDown(0.4);
  doc.fill(NAVY).font('Helvetica-Bold').fontSize(11).text(text, 40);
  doc.rect(40, doc.y + 2, 60, 2).fill(AMBER);
  doc.moveDown(0.3);
}

function pdfBody(doc, text) {
  doc.fill(DGRAY).font('Helvetica').fontSize(10).text(text, 40, doc.y, { width: doc.page.width - 80, lineGap: 3 });
  doc.moveDown(0.3);
}

function pdfBullets(doc, items) {
  const W = doc.page.width - 96;
  items.forEach(item => {
    if (doc.y > doc.page.height - 50) doc.addPage();
    doc.rect(48, doc.y + 4, 5, 5).fill(AMBER);
    doc.fill(DGRAY).font('Helvetica').fontSize(10).text(item, 62, doc.y, { width: W, lineGap: 2 });
    doc.moveDown(0.15);
  });
  doc.moveDown(0.2);
}

function pdfFeatureBox(doc, title, items) {
  const W = doc.page.width - 80;
  if (doc.y + 30 + items.length * 16 > doc.page.height - 50) doc.addPage();
  const sy = doc.y;
  const boxH = 24 + items.length * 16 + 10;
  doc.rect(40, sy, W, boxH).fill(LGRAY).stroke('#E5E7EB');
  doc.fill(NAVY).font('Helvetica-Bold').fontSize(10).text(title, 52, sy + 8, { lineBreak: false });
  items.forEach((item, i) => {
    doc.fill(MGRAY).font('Helvetica').fontSize(8.5).text(`• ${item}`, 52, sy + 24 + i * 16, { lineBreak: false });
  });
  doc.y = sy + boxH + 8;
}

function pdfScreenshot(doc, label) {
  const W = doc.page.width - 80;
  const sh = 140;
  if (doc.y + sh > doc.page.height - 50) doc.addPage();
  doc.rect(40, doc.y, W, sh).fill('#F1F5F9').stroke('#CBD5E0');
  doc.rect(40, doc.y, W, 24).fill('#E2E8F0');
  doc.fill(NAVY).font('Helvetica-Bold').fontSize(9).text('OPB Dashboard', 52, doc.y + 8, { lineBreak: false });
  doc.fill(MGRAY).font('Helvetica-BoldOblique').fontSize(9)
     .text(`[ Screenshot: ${label} ]`, 40, doc.y + 60, { width: W, align: 'center', lineBreak: false });
  doc.fill('#94A3B8').font('Helvetica').fontSize(8)
     .text('This section shows the live ' + label.toLowerCase() + ' as it appears in the OPB management console.',
       52, doc.y + 80, { width: W - 24 });
  doc.y += sh + 12;
}

function pdfTwoColFeatures(doc, features) {
  const col1X = 40; const col2X = 40 + (doc.page.width - 80) / 2 + 6;
  const colW  = (doc.page.width - 80) / 2 - 6;
  let col = 0; let leftY = doc.y; let rightY = doc.y;
  features.forEach(f => {
    const x = col === 0 ? col1X : col2X;
    const y = col === 0 ? leftY : rightY;
    if (y + 55 > doc.page.height - 50) { doc.addPage(); leftY = doc.y; rightY = doc.y; }
    const cy = col === 0 ? leftY : rightY;
    doc.rect(x, cy, colW, 52).fill('#F0FDF4').stroke('#BBF7D0');
    doc.fill(TEAL).font('Helvetica-Bold').fontSize(9).text(f.title, x + 8, cy + 7, { width: colW - 16, lineBreak: false });
    doc.fill(DGRAY).font('Helvetica').fontSize(8).text(f.body, x + 8, cy + 22, { width: colW - 16 });
    if (col === 0) { leftY = cy + 58; col = 1; } else { rightY = cy + 58; col = 0; }
    doc.y = Math.max(leftY, rightY);
  });
  doc.y = Math.max(leftY, rightY) + 4;
}

function pdfPageNumbers(doc) {
  const range = doc.bufferedPageRange();
  const W = doc.page.width - 80;
  for (let i = range.start + 1; i < range.start + range.count; i++) {
    doc.switchToPage(i);
    doc.rect(0, doc.page.height - 30, doc.page.width, 30).fill('#F8FAFC');
    doc.fill(MGRAY).font('Helvetica').fontSize(8)
       .text(`OPB Core  ·  Product Feature Brochure  ·  Confidential`, 40, doc.page.height - 20, { lineBreak: false });
    doc.fill(MGRAY).font('Helvetica').fontSize(8)
       .text(`Page ${i - range.start} of ${range.count - 1}`, 40, doc.page.height - 20, { width: W, align: 'right', lineBreak: false });
  }
}

// ─── DOCX HELPERS ─────────────────────────────────────────────────────────────
function docxH1(text) {
  return new Paragraph({ heading: HeadingLevel.HEADING_1, spacing: { before: 300, after: 120 }, children: [new TextRun({ text, bold: true, color: '1A365D', size: 32 })] });
}
function docxH2(text) {
  return new Paragraph({ heading: HeadingLevel.HEADING_2, spacing: { before: 240, after: 80 }, children: [new TextRun({ text, bold: true, color: '0F766E', size: 26 })] });
}
function docxH3(text) {
  return new Paragraph({ heading: HeadingLevel.HEADING_3, spacing: { before: 160, after: 60 }, children: [new TextRun({ text, bold: true, color: '374151', size: 22 })] });
}
function docxBody(text) {
  return new Paragraph({ spacing: { after: 120 }, children: [new TextRun({ text, size: 20, color: '1F2937' })] });
}
function docxBullet(text) {
  return new Paragraph({ bullet: { level: 0 }, spacing: { after: 60 }, children: [new TextRun({ text, size: 20, color: '374151' })] });
}
function docxBullet2(label, text) {
  return new Paragraph({ bullet: { level: 0 }, spacing: { after: 60 }, children: [new TextRun({ text: label + ': ', bold: true, size: 20 }), new TextRun({ text, size: 20, color: '374151' })] });
}
function docxScreenshot(label) {
  return new Paragraph({
    spacing: { before: 160, after: 160 },
    shading: { type: ShadingType.SOLID, color: 'EEF2FF' },
    border: { top: { style: BorderStyle.SINGLE, size: 8, color: 'C7D2FE' }, bottom: { style: BorderStyle.SINGLE, size: 8, color: 'C7D2FE' }, left: { style: BorderStyle.SINGLE, size: 8, color: 'C7D2FE' }, right: { style: BorderStyle.SINGLE, size: 8, color: 'C7D2FE' } },
    children: [new TextRun({ text: `[ Screenshot: ${label} ]`, italics: true, color: '4338CA', size: 20 })],
  });
}
function docxSpacer() { return new Paragraph({ children: [new TextRun({ text: '' })] }); }

function docxFeatureTable(rows) {
  return new Table({
    width: { size: 100, type: WidthType.PERCENTAGE },
    borders: { top: { style: BorderStyle.NONE }, bottom: { style: BorderStyle.NONE }, left: { style: BorderStyle.NONE }, right: { style: BorderStyle.NONE }, insideH: { style: BorderStyle.SINGLE, color: 'E5E7EB', size: 4 }, insideV: { style: BorderStyle.NONE } },
    rows: [
      new TableRow({
        tableHeader: true,
        children: ['Feature', 'Description', 'Available To'].map(h =>
          new TableCell({
            width: { size: h === 'Description' ? 55 : 22, type: WidthType.PERCENTAGE },
            shading: { type: ShadingType.SOLID, color: '1A365D' },
            children: [new Paragraph({ children: [new TextRun({ text: h, bold: true, color: 'FFFFFF', size: 18 })] })],
          })
        ),
      }),
      ...rows.map((r, i) => new TableRow({
        children: [r.feature, r.desc, r.roles].map((v, ci) =>
          new TableCell({
            width: { size: ci === 1 ? 55 : 22, type: WidthType.PERCENTAGE },
            shading: { type: ShadingType.SOLID, color: i % 2 === 0 ? 'FFFFFF' : 'F9FAFB' },
            children: [new Paragraph({ children: [new TextRun({ text: v, size: 18, color: '374151' })] })],
          })
        ),
      })),
    ],
  });
}

// ─── BROCHURE CONTENT ─────────────────────────────────────────────────────────
async function generateBrochure() {

  // ── PDF ──────────────────────────────────────────────────────────────────
  const doc = new PDFDocument({ size: 'A4', margins: { top: 50, bottom: 50, left: 40, right: 40 }, autoFirstPage: false, info: { Title: 'OPB Core – Product Feature Brochure', Author: 'Onukonu Pet Boarding Core' } });
  doc.pipe(fs.createWriteStream(path.join(OUT, 'OPB-Product-Brochure.pdf')));

  // Page 1 – Cover
  doc.addPage({ margins: { top: 0, bottom: 0, left: 0, right: 0 } });
  pdfCover(doc);

  // Page 2 – Executive Summary
  doc.addPage();
  pdfH1(doc, 'Executive Summary');
  pdfBody(doc, 'Onukonu Pet Boarding Core (OPB) is a purpose-built management platform for multi-branch pet boarding businesses. Unlike generic booking systems or adapted hotel management software, OPB was designed from the ground up around the specific operational reality of professional pet boarding: vaccination requirements, per-pet stay tracking, kennel assignment, medication management, and the need for real-time situational awareness across multiple locations.');
  pdfBody(doc, 'OPB replaces fragmented spreadsheets, WhatsApp group messages, and paper registers with a single, role-secured management console. Every operational action — from the first client inquiry to invoice payment — flows through one system, creating an auditable, searchable record of the entire business.');
  pdfH2(doc, 'Platform at a Glance');
  pdfTwoColFeatures(doc, [
    { title: '🐾  Multi-Branch Native', body: 'All data is branch-scoped. Staff see only their branch. Management sees everything.' },
    { title: '🤖  AI Executive Briefings', body: 'Gemini-powered situational awareness delivered to Telegram every morning and evening.' },
    { title: '📄  Intelligent Invoice Engine', body: 'Auto-generated PDF invoices with email and WhatsApp delivery.' },
    { title: '📲  WhatsApp & Telegram', body: 'Native communication channels for client messaging and management alerts.' },
    { title: '🔐  Role-Based Access', body: 'Four roles enforce the right level of access for every team member.' },
    { title: '🌐  Open Deployment', body: 'Standard WordPress + MySQL stack. Runs on any PHP 8.2 shared host.' },
  ]);
  pdfScreenshot(doc, 'Live Operations Dashboard');

  // Section 1 – Multi-Branch Operations
  pdfSectionDivider(doc, 1, 'Multi-Branch\nOperations', 'Unified management across all locations from a single console');
  pdfBody(doc, 'OPB is built for the operational reality of running more than one boarding location. Branch identity is a first-class concept in the platform — not an afterthought. Every booking, task, kennel, expense, and staff member is scoped to a branch.');
  pdfH2(doc, 'How Branch Scoping Works');
  pdfBullets(doc, [
    'Super Admins see all branches aggregated. They can filter to any single branch at any time.',
    'Branch Managers see only their own branch and cannot access data from other locations.',
    'Reception and Staff roles are fully restricted to their assigned branch.',
    'All REST API endpoints enforce branch scoping at the database query level — not the presentation layer.',
    'Reports, SAL briefs, and the occupancy board all respect branch boundaries.',
  ]);
  pdfH2(doc, 'Branch Configuration');
  pdfBullets(doc, [
    'Define unlimited branches with name, location, phone, email, and active status.',
    'Each branch has its own kennel inventory with configurable capacity.',
    'Staff assignments are per-branch. Users can only be assigned to one branch at a time.',
    'Boarding service pricing and add-on catalogues are shared across branches for consistency.',
  ]);
  pdfScreenshot(doc, 'Branch Management Screen');

  // Section 2 – Client & Pet Management
  pdfSectionDivider(doc, 2, 'Client & Pet\nManagement', 'Complete relationship records for every owner and every pet');
  pdfBody(doc, 'OPB maintains a complete record of every client relationship, including all pets, their medical histories, their booking histories, and their invoice records — all accessible from a single client profile.');
  pdfH2(doc, 'Client Profiles');
  pdfBullets(doc, [
    'Full contact record: name, phone, email, home branch, referral source, wallet balance.',
    'All pets linked to the client with direct navigation to each pet profile.',
    'Complete booking and invoice history on the client record.',
    'WhatsApp quick-message button opens a pre-populated message with one click.',
    'Client portal access: staff can generate or view the client\'s My Pets portal link.',
  ]);
  pdfH2(doc, 'Pet Profiles');
  pdfBullets(doc, [
    'Full medical record: breed, size, DOB, microchip, vaccination status.',
    'Long-form medical history, ongoing medication, dietary restrictions, grooming preferences.',
    'Special care flag triggers attention items in SAL executive briefs.',
    'Document uploads: vaccination certificates, vet reports, health clearances.',
    'Vaccination status is tracked and unvaccinated pets currently boarded are flagged automatically.',
  ]);
  pdfScreenshot(doc, 'Pet Profile – Medical Details');

  // Section 3 – Bookings & Kennel
  pdfSectionDivider(doc, 3, 'Smart Booking System &\nKennel Management', 'From first reservation to check-out, with full kennel visibility');
  pdfBody(doc, 'OPB\'s booking module handles the full lifecycle of a boarding stay, from initial reservation through check-in, active boarding, and check-out. Multiple pets can be booked in a single transaction, each with their own stay dates and service selections.');
  pdfH2(doc, 'Booking Lifecycle');
  pdfBullets(doc, [
    'Create bookings for one or more pets in a single transaction.',
    'Select boarding service type (Standard Suite, Premium Suite, etc.) per pet.',
    'Attach add-on services: grooming, medication administration, special meals.',
    'Invoice is auto-generated on booking creation with itemised line items.',
    'Check-in workflow: assigns kennel, records actual arrival time, activates the stay.',
    'Check-out workflow: records actual departure, recalculates billing if stay extended.',
  ]);
  pdfH2(doc, 'Kennel Occupancy Board');
  pdfBullets(doc, [
    'Visual card-based board shows every kennel in the branch with current occupant.',
    'Colour-coded by status: vacant (green), occupied (blue), cleaning (amber).',
    'Linear timeline view shows occupancy across a selected date range — identify gaps and plan capacity.',
    'Kennel assignment happens at check-in. Staff select from available kennels for the pet\'s size.',
  ]);
  pdfScreenshot(doc, 'Kennel Occupancy Board – Visual View');
  pdfScreenshot(doc, 'Kennel Timeline View');

  // Section 4 – Invoice Engine
  pdfSectionDivider(doc, 4, 'Custom Invoice Engine', 'Professional invoicing with PDF, email, and WhatsApp delivery');
  pdfBody(doc, 'OPB\'s invoice engine auto-generates professional invoices at booking creation. Every invoice is fully itemised, adjustable, audited, and deliverable to the client via three channels: PDF download, email attachment, or WhatsApp link.');
  pdfH2(doc, 'Invoice Generation');
  pdfBullets(doc, [
    'Auto-generated on booking creation from the pricing engine calculation.',
    'Itemised line items: one per boarding stay plus one per add-on service.',
    'Adjustments (discounts, corrections) recorded with reason, applied to the invoice.',
    'Every action logged in the invoice audit trail: generated, adjusted, emailed, paid, PDF viewed.',
  ]);
  pdfH2(doc, 'PDF Document (mPDF Engine)');
  pdfBullets(doc, [
    'Professional A4 PDF with facility branding, client details, and itemised charges.',
    'Generated on demand and cached as a token-gated file.',
    'mPDF library with base64 image embedding for reliability on shared hosting.',
  ]);
  pdfH2(doc, 'Multi-Channel Delivery');
  pdfBullets(doc, [
    'Email: invoice PDF sent as attachment to the client\'s registered email.',
    'WhatsApp: one-click link generates a pre-filled WhatsApp message with invoice summary and payment instructions.',
    'Public URL: token-gated invoice summary page where clients can view and download the PDF without logging in.',
  ]);
  pdfScreenshot(doc, 'Invoice Detail – Line Items and Delivery Options');

  // Section 5 – SAL Executive Briefings
  pdfSectionDivider(doc, 5, 'SAL Executive Briefings', 'AI-powered situational awareness delivered to Telegram — automatically');
  pdfBody(doc, 'The Situational Awareness Layer (SAL) is OPB\'s flagship intelligence feature. It automatically generates three types of executive brief — Morning, Evening, and Accounts — and delivers them to a configured Telegram channel on a schedule. Briefs are written by Google Gemini based entirely on live OPB database facts. No hallucination, no speculation.');
  pdfH2(doc, 'Three Brief Types');
  pdfFeatureBox(doc, '🌅  Morning Operations Brief  (default 07:00)', [
    'Active boarders per branch: occupancy, today\'s arrivals and departures, tomorrow\'s schedule.',
    'Attention items: overstays, unvaccinated pets, overdue tasks, overdue invoices.',
    'Medication and special care — every pet currently boarded with medical needs listed by name.',
    'Operational exceptions: potential no-shows, missing records.',
  ]);
  pdfFeatureBox(doc, '🌆  Evening Closure Brief  (default 19:00)', [
    'Actual check-ins and check-outs completed during the day.',
    'Outstanding tasks still open at close of day.',
    'Day summary: final occupancy count and key statistics.',
  ]);
  pdfFeatureBox(doc, '💳  Accounts Snapshot  (default 09:00)', [
    'Payments received today — total and count, per branch.',
    'Unpaid and overdue invoices with outstanding amounts.',
    'Expenses recorded today by branch.',
    'Numbers only. No trend interpretation, no forecasting.',
  ]);
  pdfH2(doc, 'AI Formatting with Gemini');
  pdfBullets(doc, [
    'Google Gemini (model configurable, default gemini-2.5-flash) receives structured OPB data.',
    'Gemini acts as a formatter only — it summarises facts, never invents them.',
    'Temperature set to 0.1 for high consistency and factual accuracy.',
    'Custom prompt override per brief type: admins can edit Gemini instructions from the SAL dashboard.',
    'Deterministic PHP fallback: if Gemini is unavailable, a rule-based brief is delivered instead.',
    'No brief is ever silently skipped — delivery is guaranteed.',
  ]);
  pdfH2(doc, 'SAL Dashboard');
  pdfBullets(doc, [
    'Schedule configuration: enable/disable each brief type, set custom delivery time.',
    'Preview mode: inspect the snapshot JSON, prompt, Gemini output, and Telegram message before sending.',
    'Manual send: trigger any brief type immediately from the dashboard.',
    'Brief history: full log of every delivered brief with Telegram status and timing.',
    'Diagnostics: per-brief-type last run, last failure, next scheduled time.',
  ]);
  pdfScreenshot(doc, 'SAL Dashboard – Schedule and Preview Mode');
  pdfScreenshot(doc, 'Example SAL Morning Brief (Telegram)');

  // Section 6 – OPSMAIL
  pdfSectionDivider(doc, 6, 'OPSMAIL Intelligence\nPipeline', 'Operational notifications via Telegram and AI-powered email processing');
  pdfBody(doc, 'OPSMAIL is OPB\'s event-driven operational messaging pipeline. It captures business events in an internal queue and delivers them to the management Telegram channel in real time. It also polls a configured IMAP inbox and uses Gemini AI to classify and process incoming operational emails.');
  pdfH2(doc, 'Event Notifications (Telegram)');
  pdfBullets(doc, [
    'Five event hooks: booking created, check-in, check-out, payment recorded, invoice sent.',
    'WP Cron flushes the outgoing queue every minute.',
    'Queue viewer shows all pending and delivered items with acknowledgement.',
    'Separate Telegram channel from SAL — different bot or different chat ID.',
  ]);
  pdfH2(doc, 'Email Intelligence (IMAP + Gemini)');
  pdfBullets(doc, [
    'Polls configured IMAP inbox every 5 minutes via WP Cron.',
    'Google Gemini classifies incoming emails by type (expense submission, inquiry, general).',
    'Recognised expense emails are automatically converted into expense records in the OPB database.',
    'Gemini Lab: test interface for trying classification prompts without triggering real records.',
  ]);
  pdfScreenshot(doc, 'OPSMAIL Queue Viewer');

  // Section 7 – Analytics & Reports
  pdfSectionDivider(doc, 7, 'Analytics &\nReports', 'Revenue, occupancy, and operational performance — by branch and period');
  pdfBody(doc, 'OPB\'s reporting module provides business owners and managers with factual, date-range-filtered views of revenue, occupancy, expenses, and payment modes — without requiring a separate BI tool.');
  pdfH2(doc, 'Available Reports');
  pdfBullets(doc, [
    'Revenue by branch, month, and boarding service type. Billed vs paid vs outstanding.',
    'Occupancy rate over time. Average stay length. Peak occupancy identification.',
    'Boarding trends by pet type, breed size, and service category.',
    'Expense analysis by category and branch. Income vs expense comparison.',
    'Payment mode breakdown: cash, card, UPI, bank transfer, wallet.',
    'All reports support custom date range selection.',
  ]);
  pdfScreenshot(doc, 'Reports – Revenue and Occupancy Charts');

  // Section 8 – Role-Based Access
  pdfSectionDivider(doc, 8, 'Role-Based\nAccess Control', 'Four roles enforce the right level of access for every team member');
  pdfBody(doc, 'OPB defines four custom WordPress roles. Every REST API endpoint enforces the appropriate role and capability check — access control happens at the API layer, not just the interface.');
  pdfH2(doc, 'Role Hierarchy');
  pdfFeatureBox(doc, 'Super Admin — Global access', [
    'Full access to all branches, all modules, all data.',
    'User and role management. System configuration. Data archiving.',
    'OPSMAIL, SAL, and all administrative functions.',
  ]);
  pdfFeatureBox(doc, 'Branch Manager — Branch-scoped full access', [
    'Full operational access to their branch: bookings, clients, pets, invoices, tasks, expenses.',
    'View branch reports and analytics.',
    'Cannot access other branches or system administration.',
  ]);
  pdfFeatureBox(doc, 'Reception — Operational branch access', [
    'Create and manage bookings, clients, pets, check-ins, check-outs.',
    'Record payments. Send invoices.',
    'No access to reports, settings, or administrative functions.',
  ]);
  pdfFeatureBox(doc, 'Staff — Task access only', [
    'View and update tasks assigned to their branch.',
    'Read-only access to boarding information needed for care duties.',
  ]);

  // Section 9 – Client Portal
  pdfSectionDivider(doc, 9, 'Client Self-Service\nPortal', 'Clients access their own records — no WordPress account needed');
  pdfBody(doc, 'The My Pets client portal gives pet owners a secure view of their own boarding records — pet profiles, booking history, and invoices — without creating a WordPress user account. Authentication uses a one-time password sent to their registered email address.');
  pdfH2(doc, 'Portal Features');
  pdfBullets(doc, [
    'Email OTP authentication — clients receive a 6-digit code, no passwords to remember.',
    'View all pet profiles and current boarding status.',
    'Booking history with stay details.',
    'Invoice access with PDF download.',
    'Sessions expire automatically. Access log maintained for security audit.',
  ]);

  // Section 10 – Open Architecture
  pdfSectionDivider(doc, 10, 'Open Architecture &\nDeployment Flexibility', 'Standard stack. No lock-in. Runs on any PHP 8.2 shared host.');
  pdfH2(doc, 'Technology Stack');
  pdfBullets(doc, [
    'PHP 8.2 — fully compatible with Hostinger, cPanel, and all major shared hosting providers.',
    'MySQL / MariaDB — 29-table relational schema, no external database dependency.',
    'React 18 SPA — single-page admin console, no additional Node.js server required in production.',
    'WordPress Plugin — standard WP architecture, installs like any other plugin.',
    'mPDF — PDF generation via Composer, no external PDF service required.',
    'Google Gemini API — AI capabilities via a configurable API key. Graceful fallback if unavailable.',
    'Telegram Bot API — notifications via a standard bot token. No third-party messaging platform required.',
    'WordPress REST API — 104 routes in the opb/v1 namespace. Fully documented, extensible.',
  ]);
  pdfH2(doc, 'What You Do Not Need');
  pdfBullets(doc, [
    'No WooCommerce dependency.',
    'No external SaaS subscription for core functionality.',
    'No dedicated server — runs on standard shared hosting.',
    'No mobile app — the admin console is fully responsive.',
  ]);

  pdfPageNumbers(doc);
  doc.end();
  console.log('✅  Brochure PDF written');

  // ── DOCX ─────────────────────────────────────────────────────────────────
  const docxDoc = new Document({
    title: 'OPB Core – Product Feature Brochure',
    styles: { paragraphStyles: [] },
    sections: [{
      properties: {},
      children: [
        new Paragraph({ alignment: AlignmentType.CENTER, spacing: { before: 2000, after: 400 }, children: [new TextRun({ text: 'ONUKONU PET BOARDING CORE', bold: true, size: 48, color: '1A365D', allCaps: true })] }),
        new Paragraph({ alignment: AlignmentType.CENTER, spacing: { after: 200 }, children: [new TextRun({ text: 'Product Feature Brochure', bold: true, size: 36, color: 'D97706' })] }),
        new Paragraph({ alignment: AlignmentType.CENTER, spacing: { after: 200 }, children: [new TextRun({ text: 'Purpose-Built for Multi-Branch Pet Boarding Operations', italics: true, size: 24, color: '6B7280' })] }),
        new Paragraph({ alignment: AlignmentType.CENTER, spacing: { after: 600 }, children: [new TextRun({ text: `Version 3.3.0  ·  ${new Date().toLocaleDateString('en-IN', { year: 'numeric', month: 'long' })}`, size: 20, color: '9CA3AF' })] }),
        new Paragraph({ pageBreakBefore: true, children: [] }),

        docxH1('Executive Summary'),
        docxBody('Onukonu Pet Boarding Core (OPB) is a purpose-built management platform for multi-branch pet boarding businesses. Unlike generic booking systems or adapted hotel management software, OPB was designed from the ground up around the specific operational reality of professional pet boarding: vaccination requirements, per-pet stay tracking, kennel assignment, medication management, and the need for real-time situational awareness across multiple locations.'),
        docxBody('OPB replaces fragmented spreadsheets, WhatsApp group messages, and paper registers with a single, role-secured management console. Every operational action — from the first client inquiry to invoice payment — flows through one system, creating an auditable, searchable record of the entire business.'),
        docxScreenshot('Live Operations Dashboard'),
        docxSpacer(),

        docxH1('Multi-Branch Operations Management'),
        docxBody('OPB is built for the operational reality of running more than one boarding location. Branch identity is a first-class concept in the platform. Every booking, task, kennel, expense, and staff member is scoped to a branch.'),
        docxH2('How Branch Scoping Works'),
        docxBullet('Super Admins see all branches aggregated. They can filter to any single branch at any time.'),
        docxBullet('Branch Managers see only their own branch and cannot access data from other locations.'),
        docxBullet('Reception and Staff roles are fully restricted to their assigned branch.'),
        docxBullet('All REST API endpoints enforce branch scoping at the database query level.'),
        docxBullet('Reports, SAL briefs, and the occupancy board all respect branch boundaries.'),
        docxScreenshot('Branch Management and Staff Configuration'),
        docxSpacer(),

        docxH1('Client & Pet Management'),
        docxH2('Client Profiles'),
        docxBullet('Full contact record: name, phone, email, home branch, referral source, wallet balance.'),
        docxBullet('All pets linked to the client with direct navigation to each pet profile.'),
        docxBullet('Complete booking and invoice history on the client record.'),
        docxBullet('WhatsApp quick-message button opens a pre-populated message with one click.'),
        docxH2('Pet Profiles'),
        docxBullet('Full medical record: breed, size, DOB, microchip, vaccination status.'),
        docxBullet('Long-form medical history, ongoing medication, dietary restrictions, grooming preferences.'),
        docxBullet('Document uploads: vaccination certificates, vet reports, health clearances.'),
        docxBullet('Vaccination status tracked — unvaccinated pets currently boarded are flagged automatically in SAL briefs.'),
        docxScreenshot('Pet Profile – Medical Details View'),
        docxSpacer(),

        docxH1('Smart Booking System & Kennel Management'),
        docxH2('Booking Lifecycle'),
        docxBullet('Create bookings for one or more pets in a single transaction.'),
        docxBullet('Select boarding service type per pet with auto-calculated pricing.'),
        docxBullet('Attach add-on services: grooming, medication administration, special meals.'),
        docxBullet('Invoice is auto-generated on booking creation with itemised line items.'),
        docxBullet('Check-in workflow: assigns kennel, records actual arrival time, activates the stay.'),
        docxBullet('Check-out workflow: records actual departure, recalculates billing if stay extended.'),
        docxH2('Kennel Occupancy Board'),
        docxBullet('Visual card-based board shows every kennel with current occupant and status.'),
        docxBullet('Colour-coded: vacant (green), occupied (blue), cleaning (amber).'),
        docxBullet('Linear timeline view for capacity planning across a date range.'),
        docxScreenshot('Kennel Occupancy Board'),
        docxScreenshot('Kennel Linear Timeline View'),
        docxSpacer(),

        docxH1('Custom Invoice Engine'),
        docxH2('Invoice Generation'),
        docxBullet('Auto-generated on booking creation from the pricing engine.'),
        docxBullet('Itemised line items: boarding stays plus all add-on services.'),
        docxBullet('Adjustments with reason. Full audit trail of every invoice action.'),
        docxH2('PDF Document Engine (mPDF)'),
        docxBullet('Professional A4 PDF with facility branding, client details, and itemised charges.'),
        docxBullet('Generated on demand with base64-embedded images for shared hosting reliability.'),
        docxH2('Multi-Channel Delivery'),
        docxBullet('Email: invoice PDF sent as an attachment to the client\'s registered email.'),
        docxBullet('WhatsApp: one-click pre-filled message with invoice summary and payment instructions.'),
        docxBullet('Public URL: token-gated invoice summary page — clients can view and download without logging in.'),
        docxScreenshot('Invoice Detail – Line Items, Payment Recording, and Delivery Options'),
        docxSpacer(),

        docxH1('SAL Executive Briefings — AI Situational Awareness'),
        docxBody('The Situational Awareness Layer (SAL) automatically generates three types of executive brief and delivers them to Telegram on a schedule. Briefs are written by Google Gemini based entirely on live OPB database facts.'),
        docxH2('Three Brief Types'),
        docxH3('🌅  Morning Operations Brief (default 07:00)'),
        docxBullet('Active boarders per branch: occupancy, arrivals, departures, tomorrow\'s schedule.'),
        docxBullet('Attention items: overstays, unvaccinated pets, overdue tasks, overdue invoices.'),
        docxBullet('Medication and special care pets listed by name with details.'),
        docxH3('🌆  Evening Closure Brief (default 19:00)'),
        docxBullet('Actual check-ins and check-outs completed during the day.'),
        docxBullet('Outstanding tasks still open at close of day. Day summary.'),
        docxH3('💳  Accounts Snapshot (default 09:00)'),
        docxBullet('Payments received today — total and count per branch.'),
        docxBullet('Unpaid and overdue invoices. Expenses recorded today.'),
        docxH2('AI Features'),
        docxBullet('Google Gemini formats briefs from structured OPB data — summarises facts, never invents them.'),
        docxBullet('Custom prompt override per brief type from the SAL dashboard.'),
        docxBullet('Deterministic PHP fallback — delivery is guaranteed even if Gemini is unavailable.'),
        docxBullet('Preview mode: inspect the full pipeline (snapshot → prompt → Gemini → Telegram) before sending.'),
        docxScreenshot('SAL Dashboard – Schedule Configuration and Preview Mode'),
        docxSpacer(),

        docxH1('OPSMAIL Intelligence Pipeline'),
        docxH2('Event Notifications (Telegram)'),
        docxBullet('Five event hooks: booking created, check-in, check-out, payment recorded, invoice sent.'),
        docxBullet('WP Cron flushes the outgoing queue every minute.'),
        docxBullet('Queue viewer with acknowledgement and cron health monitor.'),
        docxH2('Email Intelligence (IMAP + Gemini)'),
        docxBullet('Polls configured IMAP inbox every 5 minutes.'),
        docxBullet('Gemini classifies incoming emails. Expense emails auto-create expense records.'),
        docxBullet('Gemini Lab: test interface for classification without triggering real records.'),
        docxScreenshot('OPSMAIL Queue Viewer and Gemini Lab'),
        docxSpacer(),

        docxH1('Analytics & Reports'),
        docxBullet('Revenue by branch, month, and boarding service type. Billed vs paid vs outstanding.'),
        docxBullet('Occupancy rate over time. Average stay length. Peak occupancy.'),
        docxBullet('Boarding trends by pet type, breed size, and service category.'),
        docxBullet('Expense analysis by category and branch. Income vs expense comparison.'),
        docxBullet('Payment mode breakdown: cash, card, UPI, bank transfer, wallet.'),
        docxBullet('All reports support custom date range selection.'),
        docxScreenshot('Reports – Revenue, Occupancy, and Payment Charts'),
        docxSpacer(),

        docxH1('Role-Based Access Control'),
        docxBody('OPB defines four custom WordPress roles. Every REST API endpoint enforces the appropriate role at the API layer.'),
        docxFeatureTable([
          { feature: 'Super Admin', desc: 'Full global access. All branches, all modules, system configuration, user management, data archiving, OPSMAIL, SAL.', roles: 'Owner / Director' },
          { feature: 'Branch Manager', desc: 'Full operational access to their branch. Bookings, clients, pets, invoices, tasks, expenses, branch reports. Cannot access other branches.', roles: 'Location Manager' },
          { feature: 'Reception', desc: 'Create and manage bookings, clients, pets, check-ins, check-outs. Record payments. Send invoices. No reports or settings.', roles: 'Front Desk Staff' },
          { feature: 'Staff', desc: 'View and update tasks assigned to their branch. Read-only boarding information.', roles: 'Care Staff' },
        ]),
        docxSpacer(),

        docxH1('Open Architecture & Deployment Flexibility'),
        docxH2('Technology Stack'),
        docxBullet('PHP 8.2 — compatible with all major shared hosting providers.'),
        docxBullet('MySQL / MariaDB — 29-table relational schema, no external database dependency.'),
        docxBullet('React 18 SPA — modern single-page admin console.'),
        docxBullet('WordPress Plugin — standard installation, no dedicated server required.'),
        docxBullet('mPDF — PDF generation via Composer, no external PDF service.'),
        docxBullet('Google Gemini API — AI via configurable key. Graceful fallback if unavailable.'),
        docxBullet('Telegram Bot API — notifications via standard bot token.'),
        docxBullet('WordPress REST API — 104 routes, extensible, fully documented.'),
        docxH2('No Lock-In'),
        docxBullet('No WooCommerce dependency.'),
        docxBullet('No external SaaS subscription for core functionality.'),
        docxBullet('No dedicated server — runs on standard shared hosting.'),
        docxBullet('No mobile app required — the admin console is fully responsive.'),
      ],
    }],
  });

  const docxBuf = await Packer.toBuffer(docxDoc);
  fs.writeFileSync(path.join(OUT, 'OPB-Product-Brochure.docx'), docxBuf);
  console.log('✅  Brochure DOCX written');
}

generateBrochure().catch(err => { console.error('❌ Brochure error:', err); process.exit(1); });
