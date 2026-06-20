'use strict';
const PDFDocument = require('pdfkit');
const fs = require('fs');
const path = require('path');
const { Document, Packer, Paragraph, TextRun, Table, TableRow, TableCell, HeadingLevel, AlignmentType, WidthType, BorderStyle, ShadingType, NumberingConfig, LevelFormat } = require('docx');

const OUT = __dirname;
const NAVY  = '#1A365D';
const AMBER = '#D97706';
const TEAL  = '#0F766E';
const GREEN = '#065F46';
const LGRAY = '#F8FAFC';
const DGRAY = '#1F2937';
const MGRAY = '#6B7280';

// ─── PDF HELPERS ─────────────────────────────────────────────────────────────
function addPageNum(doc) {
  const range = doc.bufferedPageRange();
  const W = doc.page.width - 80;
  for (let i = range.start + 1; i < range.start + range.count; i++) {
    doc.switchToPage(i);
    doc.rect(0, doc.page.height - 28, doc.page.width, 28).fill('#F1F5F9');
    doc.fill(MGRAY).font('Helvetica').fontSize(8)
       .text('OPB Core  ·  User Manual', 40, doc.page.height - 18, { lineBreak: false });
    doc.fill(MGRAY).font('Helvetica').fontSize(8)
       .text(`Page ${i - range.start} of ${range.count - 1}`, 40, doc.page.height - 18, { width: W, align: 'right', lineBreak: false });
  }
}

function mH1(doc, num, text) {
  if (doc.y > doc.page.height - 100) doc.addPage();
  doc.moveDown(0.6);
  doc.rect(40, doc.y, doc.page.width - 80, 32).fill(NAVY);
  const ty = doc.y + 9;
  doc.fill(AMBER).font('Helvetica-Bold').fontSize(9).text(num + '.', 48, ty, { continued: true });
  doc.fill('#FFFFFF').font('Helvetica-Bold').fontSize(12).text('  ' + text, { lineBreak: false });
  doc.y = ty + 34;
}

function mH2(doc, text) {
  if (doc.y > doc.page.height - 80) doc.addPage();
  doc.moveDown(0.4);
  doc.rect(40, doc.y, 4, 18).fill(AMBER);
  doc.fill(NAVY).font('Helvetica-Bold').fontSize(11).text(text, 52, doc.y, { lineBreak: false });
  doc.y += 22;
}

function mBody(doc, text) {
  doc.fill(DGRAY).font('Helvetica').fontSize(10)
     .text(text, 40, doc.y, { width: doc.page.width - 80, lineGap: 3 });
  doc.moveDown(0.25);
}

function mStep(doc, num, text) {
  if (doc.y > doc.page.height - 50) doc.addPage();
  const sy = doc.y;
  doc.rect(40, sy, 22, 22).fill(TEAL);
  doc.fill('#FFFFFF').font('Helvetica-Bold').fontSize(10).text(String(num), 40, sy + 6, { width: 22, align: 'center', lineBreak: false });
  doc.fill(DGRAY).font('Helvetica').fontSize(10).text(text, 68, sy + 5, { width: doc.page.width - 116, lineGap: 2 });
  doc.y = Math.max(doc.y, sy + 26);
  doc.moveDown(0.1);
}

function mNote(doc, text) {
  if (doc.y > doc.page.height - 60) doc.addPage();
  const sy = doc.y; const W = doc.page.width - 80;
  doc.rect(40, sy, W, 14).fill('#FEF3C7');
  doc.fill(AMBER).font('Helvetica-Bold').fontSize(8).text('NOTE', 48, sy + 3, { lineBreak: false });
  doc.fill(DGRAY).font('Helvetica').fontSize(9).text(text, 40, sy + 16, { width: W, lineGap: 2 });
  doc.rect(40, sy, W, doc.y - sy + 6).stroke('#FCD34D').strokeOpacity(0.6);
  doc.y += 8;
}

function mTip(doc, text) {
  if (doc.y > doc.page.height - 60) doc.addPage();
  const sy = doc.y; const W = doc.page.width - 80;
  doc.rect(40, sy, W, 14).fill('#D1FAE5');
  doc.fill(GREEN).font('Helvetica-Bold').fontSize(8).text('TIP', 48, sy + 3, { lineBreak: false });
  doc.fill(DGRAY).font('Helvetica').fontSize(9).text(text, 40, sy + 16, { width: W, lineGap: 2 });
  doc.rect(40, sy, W, doc.y - sy + 6).stroke('#6EE7B7').strokeOpacity(0.6);
  doc.y += 8;
}

function mScreenshot(doc, label, sub) {
  const W = doc.page.width - 80; const sh = 130;
  if (doc.y + sh > doc.page.height - 50) doc.addPage();
  const sy = doc.y;
  doc.rect(40, sy, W, sh).fill('#F1F5F9').stroke('#CBD5E0');
  doc.rect(40, sy, W, 22).fill('#E2E8F0');
  // Browser bar dots
  [0,1,2].forEach(i => doc.circle(54 + i*14, sy + 11, 4).fill(['#FC8181','#F6AD55','#68D391'][i]));
  doc.fill(NAVY).font('Helvetica-Bold').fontSize(8).text('OPB Management Console', 86, sy + 7, { lineBreak: false });
  doc.fill(MGRAY).font('Helvetica-BoldOblique').fontSize(10)
     .text(`[ ${label} ]`, 40, sy + 50, { width: W, align: 'center', lineBreak: false });
  if (sub) doc.fill('#94A3B8').font('Helvetica').fontSize(8).text(sub, 52, sy + 70, { width: W - 24, align: 'center' });
  doc.y = sy + sh + 10;
}

function mDataBadge(doc, label, val) {
  const x = doc.x || 40; const sy = doc.y;
  doc.rect(x, sy, 110, 34).fill('#EFF6FF').stroke('#BFDBFE');
  doc.fill(NAVY).font('Helvetica-Bold').fontSize(8).text(label, x + 6, sy + 5, { lineBreak: false });
  doc.fill(TEAL).font('Helvetica-Bold').fontSize(12).text(val, x + 6, sy + 17, { lineBreak: false });
  doc.y = sy + 38;
}

function mFieldRow(doc, label, val, sample) {
  const W = doc.page.width - 80;
  if (doc.y > doc.page.height - 40) doc.addPage();
  const sy = doc.y;
  doc.rect(40, sy, W, 18).fill(doc._fieldAlt ? '#FFFFFF' : '#F9FAFB');
  doc.fill(NAVY).font('Helvetica-Bold').fontSize(8).text(label, 48, sy + 5, { width: 140, lineBreak: false });
  doc.fill(DGRAY).font('Helvetica').fontSize(8).text(val, 192, sy + 5, { width: 120, lineBreak: false });
  doc.fill(MGRAY).font('Helvetica-Oblique').fontSize(8).text(sample, 316, sy + 5, { width: W - 280, lineBreak: false });
  doc.rect(40, sy, W, 18).stroke('#E5E7EB').strokeOpacity(0.5);
  doc.y = sy + 20;
  doc._fieldAlt = !doc._fieldAlt;
}

// ─── DOCX HELPERS ─────────────────────────────────────────────────────────────
const dH1 = (num, text) => new Paragraph({ heading: HeadingLevel.HEADING_1, spacing: { before: 400, after: 120 }, children: [new TextRun({ text: `${num}. ${text}`, bold: true, size: 32, color: '1A365D' })] });
const dH2 = text => new Paragraph({ heading: HeadingLevel.HEADING_2, spacing: { before: 240, after: 80 }, children: [new TextRun({ text, bold: true, size: 26, color: '0F766E' })] });
const dH3 = text => new Paragraph({ heading: HeadingLevel.HEADING_3, spacing: { before: 160, after: 60 }, children: [new TextRun({ text, bold: true, size: 22, color: '374151' })] });
const dBody = text => new Paragraph({ spacing: { after: 120 }, children: [new TextRun({ text, size: 20, color: '1F2937' })] });
const dStep = (n, text) => new Paragraph({ spacing: { after: 80 }, children: [new TextRun({ text: `Step ${n}: `, bold: true, size: 20, color: '0F766E' }), new TextRun({ text, size: 20 })] });
const dBullet = text => new Paragraph({ bullet: { level: 0 }, spacing: { after: 60 }, children: [new TextRun({ text, size: 20, color: '374151' })] });
const dNote = text => new Paragraph({ spacing: { before: 120, after: 120 }, shading: { type: ShadingType.SOLID, color: 'FEF3C7' }, border: { left: { style: BorderStyle.SINGLE, size: 12, color: 'F59E0B' } }, children: [new TextRun({ text: '📌  NOTE: ', bold: true, size: 20 }), new TextRun({ text, size: 20, color: '374151' })] });
const dTip = text => new Paragraph({ spacing: { before: 120, after: 120 }, shading: { type: ShadingType.SOLID, color: 'D1FAE5' }, border: { left: { style: BorderStyle.SINGLE, size: 12, color: '10B981' } }, children: [new TextRun({ text: '✅  TIP: ', bold: true, size: 20 }), new TextRun({ text, size: 20, color: '374151' })] });
const dScreenshot = label => new Paragraph({ spacing: { before: 160, after: 160 }, shading: { type: ShadingType.SOLID, color: 'EEF2FF' }, border: { top: { style: BorderStyle.SINGLE, size: 8, color: 'C7D2FE' }, bottom: { style: BorderStyle.SINGLE, size: 8, color: 'C7D2FE' }, left: { style: BorderStyle.SINGLE, size: 8, color: 'C7D2FE' }, right: { style: BorderStyle.SINGLE, size: 8, color: 'C7D2FE' } }, children: [new TextRun({ text: `[ Screenshot: ${label} ]`, italics: true, color: '4338CA', size: 20 })] });
const dSpacer = () => new Paragraph({ children: [new TextRun('')] });

// ─── DEMO DATA ────────────────────────────────────────────────────────────────
const DEMO = {
  clients: [
    { name: 'Priya Nair', phone: '+91 98765 43210', email: 'priya.nair@email.com', branch: 'Kozhikode Main', pets: ['Bruno (Labrador)', 'Mia (Beagle)'], status: 'Active' },
    { name: 'Arjun Menon', phone: '+91 94471 12345', email: 'arjun.m@email.com', branch: 'Calicut City', pets: ['Rocky (German Shepherd)'], status: 'Active' },
    { name: 'Lakshmi Iyer', phone: '+91 99876 54321', email: 'lakshmi.i@email.com', branch: 'Kozhikode Main', pets: ['Luna (Golden Retriever)', 'Shadow (Persian Cat)'], status: 'Active' },
  ],
  bookings: [
    { id: 'BK-1042', client: 'Priya Nair', pets: ['Bruno'], service: 'Premium Suite', checkin: '2026-06-15', checkout: '2026-06-22', amount: '₹8,400', status: 'Active (Checked In)' },
    { id: 'BK-1043', client: 'Arjun Menon', pets: ['Rocky'], service: 'Standard Suite', checkin: '2026-06-18', checkout: '2026-06-25', amount: '₹5,600', status: 'Active (Checked In)' },
    { id: 'BK-1044', client: 'Lakshmi Iyer', pets: ['Luna', 'Shadow'], service: 'Premium Suite + Standard Suite', checkin: '2026-06-20', checkout: '2026-06-27', amount: '₹11,200', status: 'Confirmed' },
  ],
  invoice: { id: 'INV-1042', client: 'Priya Nair', date: '2026-06-15', items: [{ desc: 'Premium Suite – Bruno (7 nights)', amount: '₹7,000' }, { desc: 'Daily Medication Admin – Bruno', amount: '₹700' }, { desc: 'Grooming Session (Full)', amount: '₹700' }], total: '₹8,400', paid: '₹5,000', due: '₹3,400' },
};

// ─── MANUAL CONTENT ───────────────────────────────────────────────────────────
async function generateManual() {
  const W_PDF = 595 - 80; // A4 width minus margins

  const doc = new PDFDocument({ size: 'A4', margins: { top: 50, bottom: 50, left: 40, right: 40 }, autoFirstPage: false, info: { Title: 'OPB Core – User Manual', Author: 'Onukonu Pet Boarding Core' } });
  doc.pipe(fs.createWriteStream(path.join(OUT, 'OPB-User-Manual.pdf')));

  // ── Cover ─────────────────────────────────────────────────────────────
  doc.addPage({ margins: { top: 0, bottom: 0, left: 0, right: 0 } });
  const PW = doc.page.width; const PH = doc.page.height;
  doc.rect(0, 0, PW, PH).fill(NAVY);
  doc.rect(0, PH * 0.72, PW, 3).fill(AMBER);
  doc.fill('#FFFFFF').font('Helvetica-Bold').fontSize(10).text('ONUKONU PET BOARDING CORE', 0, 130, { width: PW, align: 'center', characterSpacing: 3 });
  doc.fill(AMBER).font('Helvetica-Bold').fontSize(34).text('User Manual', 0, 152, { width: PW, align: 'center' });
  doc.fill('#94A3B8').font('Helvetica').fontSize(12).text('Complete Step-by-Step Operational Guide', 0, 200, { width: PW, align: 'center' });
  doc.fill('#CBD5E0').font('Helvetica').fontSize(10).text('Version 3.3.0  ·  All Staff Roles', 0, 228, { width: PW, align: 'center' });
  const toc = ['1. System Overview & Login', '2. Dashboard', '3. Inquiries & Client Onboarding', '4. Client Management', '5. Pet Management', '6. Creating Bookings', '7. Check-In & Check-Out', '8. Kennel Management', '9. Invoicing & Payments', '10. Task Management', '11. Expense Tracking', '12. Reports & Analytics', '13. OPSMAIL Setup', '14. SAL Executive Briefings', '15. Settings & Configuration'];
  toc.forEach((t, i) => {
    doc.fill(i % 2 === 0 ? '#CBD5E0' : '#94A3B8').font('Helvetica').fontSize(9).text(t, 80, PH * 0.44 + i * 18, { lineBreak: false });
  });

  // ── 1. System Overview ────────────────────────────────────────────────
  doc.addPage();
  mH1(doc, 1, 'System Overview & Login');
  mBody(doc, 'OPB Core is a WordPress-based management system for pet boarding operations. It runs inside the WordPress admin panel and provides a full suite of tools for managing clients, pets, bookings, invoices, tasks, expenses, and operational intelligence.');
  mH2(doc, 'Accessing the System');
  mStep(doc, 1, 'Open your web browser and navigate to your facility\'s WordPress admin URL (e.g. https://yourdomain.com/wp-admin).');
  mStep(doc, 2, 'Enter your WordPress username and password. Your administrator will have provided these credentials.');
  mStep(doc, 3, 'After login, click "OPB Core" in the WordPress left sidebar to open the management console.');
  mStep(doc, 4, 'The system loads the Operations Dashboard as your starting screen.');
  mScreenshot(doc, 'WordPress Admin Login Screen', 'Custom facility branding appears on the login page');
  mNote(doc, 'Your account must have an OPB role assigned (Super Admin, Branch Manager, Reception, or Staff). Contact your system administrator if you cannot access OPB Core after logging in.');
  mH2(doc, 'User Roles at a Glance');
  doc._fieldAlt = false;
  [['Role', 'Module Access', 'Data Scope'], ['Super Admin', 'All modules including SAL, OPSMAIL, Data Mgmt', 'All branches'], ['Branch Manager', 'Bookings, clients, pets, invoices, tasks, expenses, reports', 'Own branch only'], ['Reception', 'Bookings, clients, pets, invoices, check-in/out', 'Own branch only'], ['Staff', 'Tasks only (view and update)', 'Own branch only']].forEach((r, i) => {
    if (i === 0) { doc.rect(40, doc.y, W_PDF, 18).fill(NAVY); doc.fill('#FFFFFF').font('Helvetica-Bold').fontSize(8).text(r[0], 48, doc.y + 5, { width: 120, lineBreak: false }); doc.fill('#FFFFFF').font('Helvetica-Bold').fontSize(8).text(r[1], 172, doc.y + 5, { width: 220, lineBreak: false }); doc.fill('#FFFFFF').font('Helvetica-Bold').fontSize(8).text(r[2], 396, doc.y + 5, { lineBreak: false }); doc.y += 20; }
    else { mFieldRow(doc, r[0], r[1], r[2]); }
  });

  // ── 2. Dashboard ──────────────────────────────────────────────────────
  doc.addPage();
  mH1(doc, 2, 'Dashboard');
  mBody(doc, 'The Dashboard is the first screen after login. It shows a real-time summary of your facility\'s current operational state. Branch Managers and below see only their own branch; Super Admins see all branches aggregated.');
  mH2(doc, 'Dashboard Cards (Demonstration Data)');
  mScreenshot(doc, 'Operations Dashboard – Live KPI Cards', 'Cards update on each page load from live database data');
  mBody(doc, 'The demonstration below shows a typical dashboard state for a facility with three branches:');
  const kpis = [['Active Boarders', '23'], ['Arrivals Today', '4'], ['Departures Today', '3'], ['Open Tasks', '7'], ['Overdue Invoices', '2'], ['Payments Today', '₹12,400']];
  kpis.forEach(([l, v]) => {
    const sx = doc.x || 40; const sy = doc.y;
    doc.rect(40 + (kpis.indexOf([l,v]) % 3) * 175, sy, 165, 44).fill('#EFF6FF').stroke('#BFDBFE');
  });
  let kpiY = doc.y;
  kpis.forEach(([l, v], i) => {
    const kx = 40 + (i % 3) * 175; const ky = kpiY + Math.floor(i / 3) * 52;
    doc.rect(kx, ky, 165, 44).fill(i % 2 === 0 ? '#EFF6FF' : '#F0FDF4').stroke('#E5E7EB');
    doc.fill(NAVY).font('Helvetica-Bold').fontSize(18).text(v, kx, ky + 4, { width: 165, align: 'center', lineBreak: false });
    doc.fill(MGRAY).font('Helvetica').fontSize(8).text(l, kx, ky + 30, { width: 165, align: 'center', lineBreak: false });
  });
  doc.y = kpiY + 110;
  mNote(doc, 'The dashboard does not auto-refresh. Reload the page to get the latest figures.');

  // ── 3. Inquiries & Onboarding ─────────────────────────────────────────
  doc.addPage();
  mH1(doc, 3, 'Inquiries & Client Onboarding');
  mBody(doc, 'New clients begin their journey as an Inquiry. The inquiry is submitted via the public-facing form on your website, or can be created manually by reception staff. The inquiry workflow progresses through assessment, onboarding, and conversion to a full client record.');
  mH2(doc, 'Inquiry Workflow Overview');
  const stages = ['NEW', 'REVIEWING', 'ONBOARDING SENT', 'ONBOARDING COMPLETE', 'CONVERTED'];
  stages.forEach((s, i) => {
    const sx = 40 + i * 103;
    doc.rect(sx, doc.y, 98, 26).fill(i < 4 ? '#DBEAFE' : '#D1FAE5').stroke(i < 4 ? '#93C5FD' : '#6EE7B7');
    doc.fill(i < 4 ? NAVY : GREEN).font('Helvetica-Bold').fontSize(7).text(s, sx, doc.y + 9, { width: 98, align: 'center', lineBreak: false });
    if (i < 4) { doc.fill(AMBER).font('Helvetica-Bold').fontSize(12).text('→', sx + 98, doc.y - 16, { lineBreak: false }); }
  });
  doc.y += 34;
  mH2(doc, 'Processing an Inquiry');
  mStep(doc, 1, 'Navigate to Inquiries in the sidebar. New inquiries are highlighted in blue.');
  mStep(doc, 2, 'Click an inquiry to open the detail view. Review the owner name, phone, email, preferred dates, and pet details.');
  mStep(doc, 3, 'Run the duplicate check to verify the client is not already in the system.');
  mStep(doc, 4, 'Add internal notes as needed (e.g. "Called and confirmed — medium dog, no known conditions").');
  mStep(doc, 5, 'Click "Send Onboarding Link" to generate a unique onboarding URL and send it via WhatsApp or email.');
  mStep(doc, 6, 'Once the client completes the online form, the status changes to "Onboarding Complete".');
  mStep(doc, 7, 'Click "Convert to Client" to create the full client and pet records. The inquiry is linked to the new client.');
  mScreenshot(doc, 'Inquiry Detail View – with Duplicate Check and Onboarding Actions', 'Demonstration: Inquiry from Priya Nair for Bruno (Labrador, 3 years)');
  mTip(doc, 'Use the WhatsApp button to send the onboarding link. Pre-filled messages use the template from Settings > Customization.');
  mH2(doc, 'Online Onboarding Form (Client-Facing)');
  mBody(doc, 'The client receives a link to a multi-step form on your website. They complete this without creating a WordPress account:');
  mStep(doc, 1, 'Personal details: name, phone, email, address.');
  mStep(doc, 2, 'Pet details: name, type, breed, size, DOB, vaccination status, medical history.');
  mStep(doc, 3, 'Document uploads: vaccination certificates, vet records (optional).');
  mStep(doc, 4, 'Terms & conditions acceptance (version-tracked).');
  mNote(doc, 'The onboarding link expires after 30 days. Use "Resend Onboarding" on the inquiry detail to generate a fresh link.');

  // ── 4. Client Management ──────────────────────────────────────────────
  doc.addPage();
  mH1(doc, 4, 'Client Management');
  mH2(doc, 'Finding a Client');
  mStep(doc, 1, 'Click "Clients" in the sidebar to open the client list.');
  mStep(doc, 2, 'Use the search box to find a client by name, phone number, or email address.');
  mStep(doc, 3, 'Click the client name to open their full profile.');
  mScreenshot(doc, 'Client List – Search Results', 'Demonstration data: Priya Nair, Arjun Menon, Lakshmi Iyer');
  mH2(doc, 'Client Profile Sections');
  ['Contact Details — name, phone, email, home branch, referral source.',
   'Wallet Balance — current credit balance. Deducted automatically when recording payments.',
   'Pets — all linked pet profiles with quick-view links.',
   'Booking History — all past and active bookings, filterable by status.',
   'Invoice History — all invoices with status and outstanding amounts.',
   'Portal — generate or view the client\'s My Pets self-service portal link.'].forEach(b => mBody(doc, '• ' + b));
  mH2(doc, 'Creating or Editing a Client');
  mStep(doc, 1, 'Click "New Client" (from the client list) or "Edit" on the client profile.');
  mStep(doc, 2, 'Fill in the required fields: Full Name, Phone Number, Home Branch.');
  mStep(doc, 3, 'Add email, address, and referral source if available.');
  mStep(doc, 4, 'Click Save. The client record is created immediately.');
  mH2(doc, 'Sending a WhatsApp Message');
  mStep(doc, 1, 'Open the client profile.');
  mStep(doc, 2, 'Click the WhatsApp button next to the client\'s phone number.');
  mStep(doc, 3, 'WhatsApp opens on your device with a pre-filled message. Edit if needed and send.');
  mTip(doc, 'The WhatsApp message template is customisable in Settings > Customization > Messaging Templates.');

  // ── 5. Pet Management ─────────────────────────────────────────────────
  doc.addPage();
  mH1(doc, 5, 'Pet Management');
  mH2(doc, 'Viewing a Pet Profile');
  mBody(doc, 'From the client profile, click any pet name to open the pet profile. You can also search for pets by name using the global search.');
  mScreenshot(doc, 'Pet Profile – Medical Details and Document Uploads', 'Demonstration: Bruno – Labrador, Male, 3 years, Fully Vaccinated');
  mH2(doc, 'Pet Profile Fields');
  doc._fieldAlt = false;
  [['Field', 'Description', 'Example Value'], ['Name', 'Pet\'s name', 'Bruno'], ['Pet Type', 'Dog, Cat, Bird, etc.', 'Dog'], ['Breed', 'Breed name', 'Labrador Retriever'], ['Breed Size', 'Small / Medium / Large / Giant', 'Large'], ['Date of Birth', 'Pet\'s DOB', '12 March 2023'], ['Microchip Number', 'ISO microchip ID if any', '900215000123456'], ['Vaccination Status', 'Vaccinated / Partial / Unvaccinated', 'Vaccinated'], ['Ongoing Medication', 'Current medications and dosage', 'Apoquel 16mg – once daily with food'], ['Dietary Restrictions', 'Food allergies or dietary needs', 'Grain-free diet only'], ['Major Illness History', 'Past significant health events', 'Hip dysplasia diagnosis – June 2025'], ['Grooming Preferences', 'Preferred grooming style or instructions', 'No blow-drying — air dry only'], ['Special Care', 'Flag for attention in SAL brief', 'Yes – requires morning medication']].forEach((r, i) => {
    if (i === 0) { doc.rect(40, doc.y, W_PDF, 18).fill(NAVY); doc.fill('#FFFFFF').font('Helvetica-Bold').fontSize(8).text(r[0], 48, doc.y + 5, { width: 120, lineBreak: false }); doc.fill('#FFFFFF').font('Helvetica-Bold').fontSize(8).text(r[1], 172, doc.y + 5, { width: 180, lineBreak: false }); doc.fill('#FFFFFF').font('Helvetica-Bold').fontSize(8).text(r[2], 360, doc.y + 5, { lineBreak: false }); doc.y += 20; }
    else mFieldRow(doc, r[0], r[1], r[2]);
  });
  mH2(doc, 'Uploading Pet Documents');
  mStep(doc, 1, 'Open the pet profile and scroll to the Documents section.');
  mStep(doc, 2, 'Click "Upload Document" and select the file (PDF, JPG, PNG).');
  mStep(doc, 3, 'The document is stored and displayed with the upload date.');
  mNote(doc, 'Accepted file types: PDF, JPG, PNG, WEBP. Maximum file size is determined by your server\'s PHP upload limit (typically 10–32MB).');

  // ── 6. Creating Bookings ──────────────────────────────────────────────
  doc.addPage();
  mH1(doc, 6, 'Creating a Booking');
  mBody(doc, 'A booking records a planned boarding stay for one or more pets. The system generates an invoice automatically on booking creation.');
  mH2(doc, 'Step-by-Step: New Booking');
  mStep(doc, 1, 'Click "Bookings" in the sidebar, then click the "New Booking" button.');
  mStep(doc, 2, 'Select the client from the dropdown (type to search by name or phone).');
  mStep(doc, 3, 'Select the branch for this booking.');
  mStep(doc, 4, 'Add pets: click "Add Pet" and select each pet from the client\'s pet list. You can book multiple pets in one booking.');
  mStep(doc, 5, 'For each pet, set the check-in date, check-out date, and boarding service type.');
  mStep(doc, 6, 'Optionally add add-on services to each pet\'s stay (grooming, medication admin, special meals).');
  mStep(doc, 7, 'Review the pricing summary. The total is calculated by the pricing engine.');
  mStep(doc, 8, 'Click "Create Booking". The booking and invoice are created simultaneously.');
  mScreenshot(doc, 'New Booking Form – Multi-Pet with Add-ons', 'Demonstration: Priya Nair – Bruno (Premium Suite, 7 nights) + Medication Admin + Grooming');
  mH2(doc, 'Pricing Breakdown (Demonstration)');
  const blines = [['Boarding Service', 'Pet', 'Nights', 'Rate/Night', 'Subtotal'], ['Premium Suite', 'Bruno', '7', '₹1,000', '₹7,000'], ['Daily Medication Admin', 'Bruno', '7', '₹100', '₹700'], ['Full Grooming Session', 'Bruno', '1 (flat)', '₹700', '₹700']];
  const blw = [180, 80, 50, 90, 90];
  blines.forEach((r, ri) => {
    const sy = doc.y;
    doc.rect(40, sy, W_PDF, 18).fill(ri === 0 ? NAVY : ri % 2 === 0 ? '#F9FAFB' : '#FFFFFF');
    let bx = 48;
    r.forEach((v, ci) => {
      doc.fill(ri === 0 ? '#FFFFFF' : DGRAY).font(ri === 0 ? 'Helvetica-Bold' : 'Helvetica').fontSize(8)
         .text(v, bx, sy + 5, { width: blw[ci], lineBreak: false });
      bx += blw[ci];
    });
    doc.y = sy + 20;
  });
  doc.rect(40, doc.y, W_PDF, 18).fill('#DBEAFE');
  doc.fill(NAVY).font('Helvetica-Bold').fontSize(9).text('TOTAL', 48, doc.y + 5, { lineBreak: false });
  doc.fill(NAVY).font('Helvetica-Bold').fontSize(9).text('₹8,400', 40, doc.y + 5, { width: W_PDF - 8, align: 'right', lineBreak: false });
  doc.y += 24;
  mNote(doc, 'Add-on services can be added or removed after booking creation from the Booking Detail screen.');

  // ── 7. Check-In & Check-Out ───────────────────────────────────────────
  doc.addPage();
  mH1(doc, 7, 'Check-In & Check-Out');
  mH2(doc, 'Check-In Workflow');
  mBody(doc, 'Perform check-in when the client arrives with their pet. This records the actual arrival and assigns a kennel.');
  mStep(doc, 1, 'Open the booking from the Booking list or from the client profile.');
  mStep(doc, 2, 'Click the "Check In" button.');
  mStep(doc, 3, 'Verify the pet details, vaccination status, and any medication or special care notes.');
  mStep(doc, 4, 'Select an available kennel from the dropdown (filtered by pet size).');
  mStep(doc, 5, 'Confirm the actual check-in date and time.');
  mStep(doc, 6, 'Click "Confirm Check-In". The kennel is marked occupied and an OPSMAIL notification is queued.');
  mScreenshot(doc, 'Check-In Screen – Kennel Selection and Confirmation', 'Demonstration: Bruno checked in to Kennel L-04 at 10:30 AM');
  mNote(doc, 'If a pet has unvaccinated status, a red warning banner appears on the check-in screen. Check-in can still proceed but is logged.');
  mH2(doc, 'Check-Out Workflow');
  mStep(doc, 1, 'Open the booking and click "Check Out".');
  mStep(doc, 2, 'Verify the actual departure date. If the pet stayed longer than booked, the system flags the extension.');
  mStep(doc, 3, 'If stay was extended, the pricing engine recalculates the invoice and adds a line item for the extra nights.');
  mStep(doc, 4, 'Click "Confirm Check-Out". The kennel is released and the booking is marked complete.');
  mStep(doc, 5, 'The invoice is ready for payment recording or sending to the client.');
  mTip(doc, 'After check-out, use the WhatsApp invoice link on the invoice detail screen to send the final bill to the client instantly.');

  // ── 8. Kennel Management ──────────────────────────────────────────────
  doc.addPage();
  mH1(doc, 8, 'Kennel Management');
  mH2(doc, 'Occupancy Board');
  mBody(doc, 'The Kennel Occupancy Board gives a live view of every kennel in the branch. Access it via Kennel > Board in the sidebar.');
  mScreenshot(doc, 'Kennel Occupancy Board – Visual Card View', 'Demonstration: 18 of 24 kennels occupied across 3 size categories');
  mBody(doc, 'Each kennel card shows: kennel code and name, current occupant (pet name + owner), stay dates, and status indicator.');
  mH2(doc, 'Kennel Status Colours');
  [['Green', 'Vacant — kennel is available for booking'], ['Blue', 'Occupied — pet is currently boarded'], ['Amber', 'Cleaning / Maintenance — temporarily unavailable']].forEach(([c, d]) => {
    const sy = doc.y;
    doc.rect(40, sy, 80, 20).fill(c === 'Green' ? '#D1FAE5' : c === 'Blue' ? '#DBEAFE' : '#FEF3C7').stroke('#E5E7EB');
    doc.fill(c === 'Green' ? GREEN : c === 'Blue' ? NAVY : AMBER).font('Helvetica-Bold').fontSize(8).text(c, 40, sy + 6, { width: 80, align: 'center', lineBreak: false });
    doc.fill(DGRAY).font('Helvetica').fontSize(9).text(d, 130, sy + 6, { lineBreak: false });
    doc.y = sy + 24;
  });
  mH2(doc, 'Linear Timeline View');
  mBody(doc, 'The linear timeline shows kennel occupancy across a date range. Useful for capacity planning and identifying gaps.');
  mStep(doc, 1, 'Click "Kennel" > "Timeline" in the sidebar.');
  mStep(doc, 2, 'Select a date range (default: current week + 2 weeks ahead).');
  mStep(doc, 3, 'Each row is a kennel. Filled segments show occupied periods with pet names.');
  mScreenshot(doc, 'Kennel Linear Timeline View', 'Demonstration: 14-day view showing 6 kennels and their occupancy segments');

  // ── 9. Invoicing & Payments ───────────────────────────────────────────
  doc.addPage();
  mH1(doc, 9, 'Invoicing & Payments');
  mH2(doc, 'Invoice Overview');
  mBody(doc, 'Invoices are created automatically when a booking is made. They contain itemised line items for each boarding stay and all add-on services.');
  mScreenshot(doc, 'Invoice Detail Screen – Line Items and Payment Status', `Demonstration: ${DEMO.invoice.id} – ${DEMO.invoice.client}`);
  const invLines = [['#', 'Description', 'Amount'], ['1', DEMO.invoice.items[0].desc, DEMO.invoice.items[0].amount], ['2', DEMO.invoice.items[1].desc, DEMO.invoice.items[1].amount], ['3', DEMO.invoice.items[2].desc, DEMO.invoice.items[2].amount]];
  invLines.forEach((r, ri) => {
    const sy = doc.y;
    doc.rect(40, sy, W_PDF, 18).fill(ri === 0 ? NAVY : ri % 2 === 0 ? '#F9FAFB' : '#FFFFFF');
    doc.fill(ri === 0 ? '#FFFFFF' : DGRAY).font(ri === 0 ? 'Helvetica-Bold' : 'Helvetica').fontSize(8).text(r[0], 48, sy + 5, { width: 20, lineBreak: false });
    doc.fill(ri === 0 ? '#FFFFFF' : DGRAY).font(ri === 0 ? 'Helvetica-Bold' : 'Helvetica').fontSize(8).text(r[1], 72, sy + 5, { width: 350, lineBreak: false });
    doc.fill(ri === 0 ? '#FFFFFF' : DGRAY).font(ri === 0 ? 'Helvetica-Bold' : 'Helvetica').fontSize(8).text(r[2], 40, sy + 5, { width: W_PDF - 8, align: 'right', lineBreak: false });
    doc.y = sy + 20;
  });
  doc.rect(40, doc.y, W_PDF, 18).fill('#EFF6FF');
  doc.fill(NAVY).font('Helvetica-Bold').fontSize(9).text('TOTAL', 48, doc.y + 5, { lineBreak: false });
  doc.fill(NAVY).font('Helvetica-Bold').fontSize(9).text(DEMO.invoice.total, 40, doc.y + 5, { width: W_PDF - 8, align: 'right', lineBreak: false });
  doc.y += 22;
  doc.fill(GREEN).font('Helvetica').fontSize(8).text(`Paid: ${DEMO.invoice.paid}`, 40, doc.y, { lineBreak: false });
  doc.fill('#DC2626').font('Helvetica-Bold').fontSize(8).text(`Outstanding: ${DEMO.invoice.due}`, 160, doc.y, { lineBreak: false });
  doc.y += 18;
  mH2(doc, 'Recording a Payment');
  mStep(doc, 1, 'Open the invoice from the Invoice list or the booking detail.');
  mStep(doc, 2, 'Click "Record Payment".');
  mStep(doc, 3, 'Enter the amount received, select the payment mode (Cash / Card / UPI / Bank Transfer / Wallet), and add a transaction reference if available.');
  mStep(doc, 4, 'Click Save. The invoice status updates automatically (partial → paid).');
  mNote(doc, 'Multiple partial payments can be recorded against the same invoice. The audit trail logs every payment with timestamp and staff member.');
  mH2(doc, 'Sending the Invoice to the Client');
  mBody(doc, 'Three delivery options are available from the Invoice Detail screen:');
  mStep(doc, 1, 'Email PDF: Click "Send by Email" to email the PDF invoice as an attachment to the client\'s registered email address.');
  mStep(doc, 2, 'WhatsApp Link: Click "WhatsApp" to open a pre-filled WhatsApp message with the invoice summary and payment details.');
  mStep(doc, 3, 'Public URL: Click "Copy Public Link" to get the token-gated invoice page URL. Share this link — the client can view and download without logging in.');
  mH2(doc, 'Invoice Adjustments');
  mStep(doc, 1, 'Open the invoice and click "Adjust Invoice".');
  mStep(doc, 2, 'Enter the adjustment amount (negative for a discount, positive for additional charges).');
  mStep(doc, 3, 'Enter the reason for the adjustment (required). Click Save.');
  mStep(doc, 4, 'The adjustment is added as a line item and logged in the audit trail.');

  // ── 10. Task Management ───────────────────────────────────────────────
  doc.addPage();
  mH1(doc, 10, 'Task Management');
  mBody(doc, 'Tasks are used to track operational responsibilities: kennel cleaning schedules, veterinary appointments, equipment maintenance, and ad-hoc duties. All staff can view tasks; Reception and above can create them.');
  mH2(doc, 'Creating a Task');
  mStep(doc, 1, 'Click "Tasks" in the sidebar.');
  mStep(doc, 2, 'Click "New Task".');
  mStep(doc, 3, 'Fill in: Title (required), Description, Branch, Assignee, Priority (Low / Medium / High / Urgent), Due Date.');
  mStep(doc, 4, 'Click Save. The task appears in the task list and is visible to the assignee.');
  mH2(doc, 'Updating Task Status');
  mStep(doc, 1, 'Find the task in the task list (filter by assignee or status if needed).');
  mStep(doc, 2, 'Click the status dropdown on the task card.');
  mStep(doc, 3, 'Select: Open → In Progress → Done.');
  mNote(doc, 'Overdue tasks (past due date, not done) are highlighted in red in the task list and appear in SAL Morning and Evening Briefs.');
  mH2(doc, 'Demonstration — Current Task Board');
  mScreenshot(doc, 'Task List – Current Board with Priority and Status Filters', 'Demonstration: 7 open tasks including 2 overdue (Kennel L-03 Deep Clean, Vet Appointment – Rocky)');

  // ── 11. Expense Tracking ──────────────────────────────────────────────
  doc.addPage();
  mH1(doc, 11, 'Expense Tracking');
  mH2(doc, 'Recording an Expense');
  mStep(doc, 1, 'Click "Expenses" in the sidebar.');
  mStep(doc, 2, 'Click "Add Expense".');
  mStep(doc, 3, 'Enter: Description, Amount, Category, Branch, Date.');
  mStep(doc, 4, 'Click Save. The expense is recorded and included in today\'s SAL Accounts Snapshot.');
  mH2(doc, 'Demonstration — Daily Expense Log');
  mScreenshot(doc, 'Expense Log – Today\'s Entries by Branch', 'Demonstration: ₹4,200 in expenses across 3 categories');
  const expRows = [['Category', 'Description', 'Branch', 'Amount'], ['Feed & Supplies', 'Royal Canin dog food — 25kg bag × 3', 'Kozhikode Main', '₹2,400'], ['Veterinary', 'Dr. Anil consultation – Rocky (Arjun Menon)', 'Calicut City', '₹800'], ['Utilities', 'Electricity bill – July advance', 'Kozhikode Main', '₹1,000']];
  expRows.forEach((r, ri) => {
    const sy = doc.y;
    doc.rect(40, sy, W_PDF, 18).fill(ri === 0 ? NAVY : ri % 2 === 0 ? '#F9FAFB' : '#FFFFFF');
    const rw = [120, 220, 100, 80];
    let ex = 48;
    r.forEach((v, ci) => {
      doc.fill(ri === 0 ? '#FFFFFF' : DGRAY).font(ri === 0 ? 'Helvetica-Bold' : 'Helvetica').fontSize(8).text(v, ex, sy + 5, { width: rw[ci], lineBreak: false });
      ex += rw[ci];
    });
    doc.y = sy + 20;
  });
  mBody(doc, '');
  mTip(doc, 'Expenses can also be submitted by emailing your configured OPSMAIL inbox. Gemini AI reads and classifies the email and creates the expense record automatically.');

  // ── 12. Reports & Analytics ───────────────────────────────────────────
  doc.addPage();
  mH1(doc, 12, 'Reports & Analytics');
  mBody(doc, 'The Reports module provides date-range filtered views of revenue, occupancy, expenses, and payment modes. Access via "Reports" in the sidebar. Required role: Branch Manager or Super Admin.');
  mH2(doc, 'Available Report Views');
  [['Revenue Summary', 'Total billed, paid, and outstanding amounts by branch and month.'], ['Occupancy Analytics', 'Occupancy rate over time. Average stay length. Peak occupancy days.'], ['Boarding Trends', 'Bookings by pet type, breed size, and boarding service category.'], ['Expense Analysis', 'Total expenses by category and branch. Income vs expense comparison.'], ['Payment Modes', 'Breakdown of payments by mode: cash, card, UPI, bank transfer, wallet.']].forEach(([t, d]) => {
    const sy = doc.y;
    doc.rect(40, sy, 4, 34).fill(AMBER);
    doc.fill(NAVY).font('Helvetica-Bold').fontSize(9).text(t, 52, sy + 4, { lineBreak: false });
    doc.fill(MGRAY).font('Helvetica').fontSize(8.5).text(d, 52, sy + 17, { width: W_PDF - 20, lineBreak: false });
    doc.y = sy + 38;
  });
  mH2(doc, 'Using Reports');
  mStep(doc, 1, 'Select the date range using the From and To date pickers at the top of the Reports screen.');
  mStep(doc, 2, 'Select a branch (Super Admin only — Branch Managers see their branch automatically).');
  mStep(doc, 3, 'The report updates automatically. All figures reflect the selected period.');
  mScreenshot(doc, 'Reports Screen – Revenue and Occupancy Charts', 'Demonstration: June 2026 – ₹1,24,000 revenue, 82% average occupancy across 3 branches');

  // ── 13. OPSMAIL ───────────────────────────────────────────────────────
  doc.addPage();
  mH1(doc, 13, 'OPSMAIL Setup & Management');
  mBody(doc, 'OPSMAIL is the operational messaging pipeline. It sends event notifications to Telegram and processes incoming operational emails via AI. OPSMAIL is configured in Settings > Customization. Access to the OPSMAIL queue requires Super Admin.');
  mH2(doc, 'Required Configuration');
  ['Telegram Bot Token — create a bot via @BotFather on Telegram. Copy the token.',
   'Telegram Chat ID — the target channel or group chat ID where notifications are delivered.',
   'Gemini API Key — required for email classification. Free tier sufficient for most facilities.',
   'IMAP Host, Port, Username, Password — your facility\'s email inbox for OPSMAIL to monitor (optional).'].forEach(b => mBody(doc, '• ' + b));
  mH2(doc, 'Setting Up Telegram Notifications');
  mStep(doc, 1, 'Go to Settings > Customization > OPSMAIL section.');
  mStep(doc, 2, 'Enter your Telegram Bot Token and Chat ID.');
  mStep(doc, 3, 'Click Save, then navigate to Admin > OPSMAIL and click "Test Telegram" to verify delivery.');
  mStep(doc, 4, 'Once verified, all operational events (bookings, check-ins, payments) will deliver notifications to your Telegram channel.');
  mH2(doc, 'OPSMAIL Queue Viewer');
  mBody(doc, 'The queue viewer at Admin > OPSMAIL shows all pending and delivered events. Each item shows the event type, message content, delivery status, and timestamp. Click "Acknowledge" to mark processed items.');
  mScreenshot(doc, 'OPSMAIL Queue Viewer and Gemini Lab', 'Super Admin view showing queued events and AI test interface');

  // ── 14. SAL Executive Briefings ───────────────────────────────────────
  doc.addPage();
  mH1(doc, 14, 'SAL Executive Briefings');
  mBody(doc, 'SAL (Situational Awareness Layer) automatically generates and delivers operational briefs to Telegram. Set up at Admin > SAL. Required role: Super Admin.');
  mH2(doc, 'Configuration Steps');
  mStep(doc, 1, 'Navigate to Admin > SAL in the sidebar.');
  mStep(doc, 2, 'In Section A (Schedule Configuration), enable each brief type and set the delivery time.');
  mStep(doc, 3, 'In Section B (Telegram), enter the SAL Chat ID (or leave blank to use the main OPSMAIL Chat ID).');
  mStep(doc, 4, 'Click "Save Configuration".');
  mStep(doc, 5, 'Click "Send Test Brief" to verify Telegram connectivity and see a sample brief.');
  mH2(doc, 'Preview Mode');
  mBody(doc, 'Before relying on SAL in production, use Preview Mode to inspect the full pipeline:');
  mStep(doc, 1, 'Select the brief type (Morning / Evening / Accounts) from the dropdown in Section: Preview Mode.');
  mStep(doc, 2, 'Click "Generate Preview". The system queries live OPB data but does NOT send to Telegram.');
  mStep(doc, 3, 'Review each tab: Snapshot JSON (raw data), Prompt (sent to Gemini), Gemini Output, Telegram Message.');
  mTip(doc, 'Use the "Prompt" tab to verify your custom prompt is being applied correctly before making it live.');
  mH2(doc, 'Customising the Gemini Prompt');
  mStep(doc, 1, 'In Section E (Prompt Customization), click the tab for the brief type you want to customise.');
  mStep(doc, 2, 'Enter your custom instructions in the textarea. Use {brief_label} and {date} as placeholders.');
  mStep(doc, 3, 'Click "View default" to see the built-in prompt for reference.');
  mStep(doc, 4, 'Click "Save Prompts". The custom prompt is used for the next brief delivery.');
  mStep(doc, 5, 'To revert: click "Reset to default" and confirm. The built-in prompt is restored immediately.');
  mNote(doc, 'The operational data block (live OPB facts) is always appended automatically — you cannot accidentally remove it from the prompt.');
  mH2(doc, 'Example — Morning Brief Output');
  mScreenshot(doc, 'SAL Morning Brief – Example Telegram Delivery', 'Demonstration: 20 June 2026, 07:00 – 3 branches, 23 boarders, 2 overstays, 1 unvaccinated pet');
  const briefSample = `🐾 OPB Morning Operations Brief\n20 June 2026\n\nSUMMARY\nOnukonu Pet Boarding is operating at 82% capacity across all three branches with 23 pets currently boarded. Two overstays and one unvaccinated pet require immediate attention.\n\nATTENTION REQUIRED\n• Overstay: Bruno (Priya Nair, KZK Main) – checkout was 18 June\n• Overstay: Max (Rajan Pillai, Calicut City) – checkout was 19 June\n• Unvaccinated: Shadow (Lakshmi Iyer) – currently boarded at KZK Main\n\nBOARDING\nKozhikode Main: 12/16 kennels active · 2 in · 1 out today\nCalicut City: 7/10 kennels active · 1 in · 2 out today\n...`;
  doc.rect(40, doc.y, W_PDF, 120).fill('#1E293B').stroke('#334155');
  doc.fill('#E2E8F0').font('Helvetica').fontSize(7.5).text(briefSample, 48, doc.y + 8, { width: W_PDF - 16, lineGap: 2 });
  doc.y += 130;

  // ── 15. Settings & Configuration ──────────────────────────────────────
  doc.addPage();
  mH1(doc, 15, 'Settings & Configuration');
  mBody(doc, 'All system settings are accessed via the Settings section in the sidebar. Most settings require Super Admin access.');
  mH2(doc, 'Settings Sections');
  [['Branches', 'Create and manage facility branches. Each branch has a name, code, location, contact details, and active status.'],
   ['Staff', 'Create WordPress user accounts with OPB roles. Assign each user to a branch. Roles determine module access.'],
   ['Boarding Services', 'Define the boarding service types available at your facility (e.g. Standard Suite, Premium Suite). Set per-night pricing.'],
   ['Add-on Services', 'Define add-on services (grooming, medication admin, special meals) with pricing.'],
   ['Kennels', 'Configure individual kennels per branch: code, name, size category, and active status.'],
   ['Customization', 'Central settings hub: facility name, contact details, messaging templates (email and WhatsApp), legal T&C, privacy policy, OPSMAIL credentials, SAL prompt overrides.'],
   ['Expense Categories', 'Manage expense categories (Feed, Veterinary, Utilities, etc.) used across all branches.']].forEach(([t, d]) => {
    mH2(doc, t);
    mBody(doc, d);
  });
  mScreenshot(doc, 'Settings > Customization – Messaging Templates and OPSMAIL Configuration', 'Super Admin view: all 30+ customizable settings organized by category');
  mTip(doc, 'All messaging templates support {{CLIENT_NAME}}, {{FACILITY_NAME}}, {{BOOKING_ID}}, and other placeholders. Refer to the template help text for the full list of supported variables.');

  addPageNum(doc);
  doc.end();
  console.log('✅  User Manual PDF written');

  // ── DOCX ─────────────────────────────────────────────────────────────
  const docxChildren = [
    new Paragraph({ alignment: AlignmentType.CENTER, spacing: { before: 2000, after: 400 }, children: [new TextRun({ text: 'ONUKONU PET BOARDING CORE', bold: true, size: 48, color: '1A365D', allCaps: true })] }),
    new Paragraph({ alignment: AlignmentType.CENTER, spacing: { after: 200 }, children: [new TextRun({ text: 'User Manual', bold: true, size: 40, color: 'D97706' })] }),
    new Paragraph({ alignment: AlignmentType.CENTER, spacing: { after: 400 }, children: [new TextRun({ text: 'Complete Step-by-Step Operational Guide', italics: true, size: 24, color: '6B7280' })] }),
    new Paragraph({ alignment: AlignmentType.CENTER, spacing: { after: 800 }, children: [new TextRun({ text: `Version 3.3.0  ·  ${new Date().toLocaleDateString('en-IN', { year: 'numeric', month: 'long' })}`, size: 20, color: '9CA3AF' })] }),
    new Paragraph({ pageBreakBefore: true, children: [] }),

    dH1(1, 'System Overview & Login'),
    dBody('OPB Core is a WordPress-based management system for pet boarding operations. It runs inside the WordPress admin panel and provides a full suite of tools for managing clients, pets, bookings, invoices, tasks, expenses, and operational intelligence.'),
    dH2('Accessing the System'),
    dStep(1, 'Navigate to your facility\'s WordPress admin URL (e.g. https://yourdomain.com/wp-admin).'),
    dStep(2, 'Enter your WordPress username and password.'),
    dStep(3, 'Click "OPB Core" in the WordPress left sidebar.'),
    dStep(4, 'The system loads the Operations Dashboard.'),
    dScreenshot('WordPress Admin Login and OPB Dashboard'),
    dNote('Your account must have an OPB role. Contact your administrator if access is unavailable.'),
    dSpacer(),

    dH1(2, 'Dashboard'),
    dBody('The Dashboard shows a real-time summary of your facility\'s current operational state. Branch Managers see their branch only; Super Admins see all branches.'),
    dBody('Dashboard KPIs shown with demonstration data: Active Boarders: 23 · Arrivals Today: 4 · Departures Today: 3 · Open Tasks: 7 · Overdue Invoices: 2 · Payments Today: ₹12,400'),
    dScreenshot('Operations Dashboard – Live KPI Cards'),
    dNote('The dashboard does not auto-refresh. Reload the page to get the latest figures.'),
    dSpacer(),

    dH1(3, 'Inquiries & Client Onboarding'),
    dBody('New clients begin as an Inquiry. Process: NEW → REVIEWING → ONBOARDING SENT → ONBOARDING COMPLETE → CONVERTED.'),
    dH2('Processing an Inquiry'),
    dStep(1, 'Navigate to Inquiries. New inquiries are highlighted in blue.'),
    dStep(2, 'Click the inquiry to open the detail view. Review all submitted information.'),
    dStep(3, 'Run the Duplicate Check to verify the client is not already in the system.'),
    dStep(4, 'Add internal notes as needed.'),
    dStep(5, 'Click "Send Onboarding Link" to generate a unique URL and send via WhatsApp or email.'),
    dStep(6, 'Once the client completes the online form, the status becomes "Onboarding Complete".'),
    dStep(7, 'Click "Convert to Client" to create the full client and pet records.'),
    dScreenshot('Inquiry Detail View with Onboarding Actions'),
    dTip('Use the WhatsApp button to send the onboarding link with a pre-filled message from your messaging template.'),
    dSpacer(),

    dH1(4, 'Client Management'),
    dH2('Finding a Client'),
    dStep(1, 'Click "Clients" in the sidebar.'),
    dStep(2, 'Use the search box to find a client by name, phone, or email.'),
    dStep(3, 'Click the client name to open their profile.'),
    dH2('Client Profile Sections'),
    dBullet('Contact Details — name, phone, email, home branch, referral source.'),
    dBullet('Wallet Balance — current credit balance.'),
    dBullet('Pets — all linked pet profiles.'),
    dBullet('Booking History — all bookings filterable by status.'),
    dBullet('Invoice History — all invoices with outstanding amounts.'),
    dBullet('Portal — client\'s My Pets self-service portal link.'),
    dScreenshot('Client Profile – Full View with Pets and Booking History'),
    dTip('The WhatsApp quick-message button on the client profile opens a pre-filled message. The template is customisable in Settings > Customization.'),
    dSpacer(),

    dH1(5, 'Pet Management'),
    dH2('Pet Profile Fields'),
    dBullet('Name, Pet Type, Breed, Breed Size (Small/Medium/Large/Giant), Date of Birth, Microchip Number.'),
    dBullet('Vaccination Status (Vaccinated / Partial / Unvaccinated) — triggers SAL alert if unvaccinated and currently boarded.'),
    dBullet('Ongoing Medication — name and dosage. Appears in SAL Morning Brief.'),
    dBullet('Dietary Restrictions, Major Illness History, Grooming Preferences.'),
    dBullet('Special Care flag — marks the pet for attention in operational briefs.'),
    dH2('Document Uploads'),
    dStep(1, 'Open the pet profile and scroll to the Documents section.'),
    dStep(2, 'Click "Upload Document" and select the file (PDF, JPG, PNG).'),
    dStep(3, 'The document is stored with the upload date.'),
    dScreenshot('Pet Profile – Medical Details and Document Uploads'),
    dSpacer(),

    dH1(6, 'Creating a Booking'),
    dStep(1, 'Click "Bookings" > "New Booking".'),
    dStep(2, 'Select the client (search by name or phone).'),
    dStep(3, 'Select the branch for this booking.'),
    dStep(4, 'Add pets: click "Add Pet" and select from the client\'s pet list. Multiple pets supported.'),
    dStep(5, 'For each pet: set check-in date, check-out date, and boarding service type.'),
    dStep(6, 'Add add-on services per pet (grooming, medication admin, special meals).'),
    dStep(7, 'Review the pricing summary calculated by the pricing engine.'),
    dStep(8, 'Click "Create Booking". Booking and invoice are created simultaneously.'),
    dScreenshot('New Booking Form – Multi-Pet with Add-ons'),
    dNote('Add-on services can be added or removed after booking creation from the Booking Detail screen.'),
    dSpacer(),

    dH1(7, 'Check-In & Check-Out'),
    dH2('Check-In Workflow'),
    dStep(1, 'Open the booking and click "Check In".'),
    dStep(2, 'Verify pet details, vaccination status, and any medication or special care notes.'),
    dStep(3, 'Select an available kennel from the dropdown (filtered by pet size).'),
    dStep(4, 'Confirm actual check-in date and time.'),
    dStep(5, 'Click "Confirm Check-In". The kennel is marked occupied.'),
    dNote('If a pet has Unvaccinated status, a red warning banner appears. Check-in can proceed but is logged.'),
    dH2('Check-Out Workflow'),
    dStep(1, 'Open the booking and click "Check Out".'),
    dStep(2, 'Verify the actual departure date. Extended stays are flagged and rebilled automatically.'),
    dStep(3, 'Click "Confirm Check-Out". The kennel is released and the booking is marked complete.'),
    dScreenshot('Check-In Screen – Kennel Selection and Confirmation'),
    dSpacer(),

    dH1(8, 'Kennel Management'),
    dH2('Occupancy Board'),
    dBullet('Green: Vacant — available for booking.'),
    dBullet('Blue: Occupied — pet currently boarded.'),
    dBullet('Amber: Cleaning / Maintenance — temporarily unavailable.'),
    dH2('Linear Timeline View'),
    dStep(1, 'Click "Kennel" > "Timeline" in the sidebar.'),
    dStep(2, 'Select a date range.'),
    dStep(3, 'Each row is a kennel. Filled segments show occupied periods with pet names.'),
    dScreenshot('Kennel Occupancy Board and Linear Timeline View'),
    dSpacer(),

    dH1(9, 'Invoicing & Payments'),
    dH2('Recording a Payment'),
    dStep(1, 'Open the invoice from the Invoice list or the booking detail.'),
    dStep(2, 'Click "Record Payment".'),
    dStep(3, 'Enter amount, select payment mode (Cash/Card/UPI/Bank Transfer/Wallet), add transaction reference.'),
    dStep(4, 'Click Save. Invoice status updates automatically.'),
    dH2('Sending the Invoice'),
    dBullet('Email PDF: Click "Send by Email" to send the PDF as an email attachment.'),
    dBullet('WhatsApp Link: Click "WhatsApp" to open a pre-filled WhatsApp message with payment details.'),
    dBullet('Public URL: Click "Copy Public Link" — clients can view and download without logging in.'),
    dH2('Invoice Adjustments'),
    dStep(1, 'Open the invoice and click "Adjust Invoice".'),
    dStep(2, 'Enter adjustment amount and reason. Click Save.'),
    dStep(3, 'Adjustment added as a line item and logged in the audit trail.'),
    dScreenshot('Invoice Detail – Line Items, Payment Recording, and Delivery Options'),
    dSpacer(),

    dH1(10, 'Task Management'),
    dH2('Creating a Task'),
    dStep(1, 'Click "Tasks" > "New Task".'),
    dStep(2, 'Enter Title, Description, Branch, Assignee, Priority, Due Date. Click Save.'),
    dH2('Updating Task Status'),
    dStep(1, 'Find the task in the task list.'),
    dStep(2, 'Click the status dropdown: Open → In Progress → Done.'),
    dNote('Overdue tasks (past due date, not done) appear in SAL Morning and Evening Briefs.'),
    dScreenshot('Task List – Priority and Status Filters with Overdue Highlighting'),
    dSpacer(),

    dH1(11, 'Expense Tracking'),
    dStep(1, 'Click "Expenses" > "Add Expense".'),
    dStep(2, 'Enter Description, Amount, Category, Branch, Date. Click Save.'),
    dTip('Expenses can also be submitted by emailing your OPSMAIL inbox. Gemini AI reads and classifies the email automatically.'),
    dScreenshot('Expense Log – Daily Entries by Branch and Category'),
    dSpacer(),

    dH1(12, 'Reports & Analytics'),
    dBullet('Revenue Summary — billed, paid, outstanding by branch and month.'),
    dBullet('Occupancy Analytics — rate over time, average stay length.'),
    dBullet('Boarding Trends — by pet type, breed size, service category.'),
    dBullet('Expense Analysis — by category and branch.'),
    dBullet('Payment Modes — cash, card, UPI, bank transfer, wallet.'),
    dStep(1, 'Select the date range using the From/To date pickers.'),
    dStep(2, 'Select a branch (Super Admin only).'),
    dStep(3, 'The report updates automatically.'),
    dScreenshot('Reports Screen – Revenue and Occupancy Charts'),
    dSpacer(),

    dH1(13, 'OPSMAIL Setup & Management'),
    dH2('Required Configuration (Settings > Customization > OPSMAIL)'),
    dBullet('Telegram Bot Token — create via @BotFather on Telegram.'),
    dBullet('Telegram Chat ID — target channel or group chat ID.'),
    dBullet('Gemini API Key — for email classification.'),
    dBullet('IMAP Host, Port, Username, Password — mailbox for OPSMAIL to monitor (optional).'),
    dH2('Verifying Telegram Delivery'),
    dStep(1, 'Navigate to Admin > OPSMAIL.'),
    dStep(2, 'Click "Test Telegram" to send a test message to the configured channel.'),
    dStep(3, 'Confirm delivery in Telegram.'),
    dScreenshot('OPSMAIL Queue Viewer and Test Interface'),
    dSpacer(),

    dH1(14, 'SAL Executive Briefings'),
    dH2('Setup'),
    dStep(1, 'Navigate to Admin > SAL.'),
    dStep(2, 'Enable each brief type and set delivery times in Section A (Schedule).'),
    dStep(3, 'Enter the SAL Chat ID in Section B (Telegram).'),
    dStep(4, 'Click "Save Configuration" and verify with "Send Test Brief".'),
    dH2('Using Preview Mode'),
    dStep(1, 'Select brief type from the Preview Mode dropdown.'),
    dStep(2, 'Click "Generate Preview". Live data queried — Telegram NOT triggered.'),
    dStep(3, 'Review tabs: Snapshot → Prompt → Gemini Output → Telegram Message.'),
    dH2('Customising the Gemini Prompt'),
    dStep(1, 'Open Section E (Prompt Customization) and select a brief tab.'),
    dStep(2, 'Enter custom instructions. Use {brief_label} and {date} as placeholders.'),
    dStep(3, 'Click "Save Prompts". To revert, click "Reset to default".'),
    dNote('The operational data block is always appended automatically — you cannot remove it from the prompt.'),
    dScreenshot('SAL Dashboard – Schedule, Preview Mode, and Prompt Customization'),
    dSpacer(),

    dH1(15, 'Settings & Configuration'),
    dH2('Settings Sections'),
    dBullet('Branches — create and manage facility locations.'),
    dBullet('Staff — create users with OPB roles and branch assignments.'),
    dBullet('Boarding Services — define service types and per-night pricing.'),
    dBullet('Add-on Services — define add-ons with pricing.'),
    dBullet('Kennels — configure individual kennels per branch.'),
    dBullet('Customization — facility name, messaging templates, legal content, OPSMAIL credentials, SAL settings.'),
    dBullet('Expense Categories — define categories for consistent expense tagging.'),
    dTip('All messaging templates support {{CLIENT_NAME}}, {{FACILITY_NAME}}, and other placeholders. Refer to template help text for the full list.'),
    dScreenshot('Settings > Customization – Full Configuration Hub'),
  ];

  const manualDoc = new Document({ title: 'OPB Core – User Manual', sections: [{ properties: {}, children: docxChildren }] });
  const manualBuf = await Packer.toBuffer(manualDoc);
  fs.writeFileSync(path.join(OUT, 'OPB-User-Manual.docx'), manualBuf);
  console.log('✅  User Manual DOCX written');
}

generateManual().catch(err => { console.error('❌ Manual error:', err); process.exit(1); });
