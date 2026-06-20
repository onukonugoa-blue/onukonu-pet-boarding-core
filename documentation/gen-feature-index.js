'use strict';
const ExcelJS = require('exceljs');
const fs = require('fs');
const path = require('path');
const PDFDocument = require('pdfkit');

const OUT = __dirname;

const FEATURES = [
  // ── DASHBOARD ──────────────────────────────────────────────────────────────
  { module:'Dashboard', feature:'Live Operations Dashboard', description:'Real-time overview cards: active boarders, today\'s arrivals/departures, open tasks, revenue. Refreshes on load.', roles:'All staff', screen:'/  (Dashboard)', endpoint:'GET /dashboard', php:'OPB_Dashboard_API' },
  { module:'Dashboard', feature:'Branch-scoped Filtering', description:'Branch managers and below see only their branch data. Super-admins see all branches aggregated.', roles:'All staff', screen:'/ (Dashboard)', endpoint:'GET /dashboard', php:'OPB_REST_Base::permission_check' },
  { module:'Dashboard', feature:'Occupancy KPI Card', description:'Shows current live kennel occupancy count with capacity. Colour-coded by fill percentage.', roles:'All staff', screen:'/ (Dashboard)', endpoint:'GET /dashboard', php:'OPB_Dashboard_API' },
  { module:'Dashboard', feature:'Revenue at a Glance', description:'Today\'s payments received and monthly total displayed on the dashboard header.', roles:'Super Admin, Branch Manager', screen:'/ (Dashboard)', endpoint:'GET /dashboard', php:'OPB_Dashboard_API' },
  { module:'Dashboard', feature:'Quick-Access Navigation', description:'Sidebar with role-filtered navigation links to all modules. Collapses on mobile.', roles:'All staff', screen:'(Global)', endpoint:'—', php:'OPB_Roles' },

  // ── CLIENTS ────────────────────────────────────────────────────────────────
  { module:'Client Management', feature:'Client List with Search', description:'Paginated client list with name/phone/email search, status filter, and branch filter.', roles:'Reception, Branch Manager, Super Admin', screen:'/clients', endpoint:'GET /clients', php:'OPB_Clients_API' },
  { module:'Client Management', feature:'Client Profile', description:'Full client record: personal details, linked pets, booking history, invoice history, wallet balance, onboarding status.', roles:'Reception, Branch Manager, Super Admin', screen:'/clients/:id', endpoint:'GET /clients/:id', php:'OPB_Clients_API' },
  { module:'Client Management', feature:'Client Create / Edit', description:'Form to create or edit client with name, phone, email, home branch, source, notes.', roles:'Reception, Branch Manager, Super Admin', screen:'/clients/new, /clients/:id/edit', endpoint:'POST /clients, PUT /clients/:id', php:'OPB_Clients_API' },
  { module:'Client Management', feature:'Pet Sub-listing on Client', description:'View all pets linked to a client from the client profile, with direct links to pet profiles.', roles:'All staff', screen:'/clients/:id', endpoint:'GET /clients/:id/pets', php:'OPB_Clients_API' },
  { module:'Client Management', feature:'Booking History on Client', description:'Full booking history for a client filterable by status and date range.', roles:'Reception, Branch Manager, Super Admin', screen:'/clients/:id', endpoint:'GET /clients/:id/bookings', php:'OPB_Clients_API' },
  { module:'Client Management', feature:'WhatsApp Quick-Message', description:'One-click WhatsApp deep-link from client profile opens a pre-populated message in WhatsApp.', roles:'Reception, Branch Manager, Super Admin', screen:'/clients/:id', endpoint:'—', php:'OPB_Notifications' },
  { module:'Client Management', feature:'Client Archiving & Restoration', description:'Super admins can archive/restore clients. Archived clients are excluded from all operational views.', roles:'Super Admin', screen:'/admin/data-management', endpoint:'POST /admin/clients/:id/archive, /restore', php:'OPB_Data_Management_API' },
  { module:'Client Management', feature:'Portal Preview (Staff)', description:'Staff can preview the client\'s My Pets portal access link from the client profile.', roles:'Reception, Branch Manager, Super Admin', screen:'/clients/:id', endpoint:'GET /clients/:id/portal-preview', php:'OPB_Clients_API' },

  // ── PETS ───────────────────────────────────────────────────────────────────
  { module:'Pet Management', feature:'Pet Profile', description:'Full pet record: name, type, breed, size, DOB, vaccination status, microchip, medical history, preferences/allergies, special care flags, medication details.', roles:'All staff', screen:'/pets/:id', endpoint:'GET /pets/:id', php:'OPB_Pets_API' },
  { module:'Pet Management', feature:'Pet Create / Edit', description:'Form to create or update a pet linked to a client. Includes vaccination status and medical fields.', roles:'Reception, Branch Manager, Super Admin', screen:'/clients/:clientId/pets/new, /pets/:id/edit', endpoint:'POST /clients/:id/pets, PUT /pets/:id', php:'OPB_Pets_API, OPB_Clients_API' },
  { module:'Pet Management', feature:'Vaccination Status Tracking', description:'Pets flagged with vaccination status (vaccinated / partial / unvaccinated). SAL briefs highlight unvaccinated pets currently boarded.', roles:'All staff', screen:'/pets/:id', endpoint:'PUT /pets/:id', php:'OPB_Pets_API, OPB_SAL_Snapshot' },
  { module:'Pet Management', feature:'Medical History & Allergies', description:'Long-form fields for major illness history, ongoing medication, dietary restrictions, and grooming preferences.', roles:'All staff', screen:'/pets/:id', endpoint:'GET/PUT /pets/:id', php:'OPB_Pets_API' },
  { module:'Pet Management', feature:'Document Uploads', description:'Upload and manage documents per pet (vaccination records, health certificates, vet reports). File storage on server.', roles:'Reception, Branch Manager, Super Admin', screen:'/pets/:id', endpoint:'GET/POST/DELETE /pets/:id/documents', php:'OPB_Pets_API' },
  { module:'Pet Management', feature:'Medication / Special Care Flag', description:'Pets on active medication or special care are flagged in the SAL brief and highlighted during boarding.', roles:'All staff', screen:'/pets/:id', endpoint:'GET /pets/:id', php:'OPB_SAL_Snapshot' },
  { module:'Pet Management', feature:'Pet Archiving & Restoration', description:'Archived pets are removed from booking selection and operational views. Restorable by super admin.', roles:'Super Admin', screen:'/admin/data-management', endpoint:'POST /admin/pets/:id/archive, /restore', php:'OPB_Data_Management_API' },

  // ── BOOKINGS ───────────────────────────────────────────────────────────────
  { module:'Bookings', feature:'Booking Creation (Multi-pet)', description:'Create a booking for one or more pets with individual stay dates, boarding service type, and add-on services.', roles:'Reception, Branch Manager, Super Admin', screen:'/bookings/new', endpoint:'POST /bookings', php:'OPB_Bookings_API, OPB_Invoice_Generator' },
  { module:'Bookings', feature:'Booking List with Filters', description:'Filterable booking list by status, branch, date range, and client. Sortable by date and status.', roles:'All staff', screen:'/bookings', endpoint:'GET /bookings', php:'OPB_Bookings_API' },
  { module:'Bookings', feature:'Booking Detail View', description:'Full booking record: client, pets, stays, add-ons, pricing breakdown, invoice link, status history.', roles:'All staff', screen:'/bookings/:id', endpoint:'GET /bookings/:id', php:'OPB_Bookings_API' },
  { module:'Bookings', feature:'Boarding Service Selection', description:'Select from configured boarding services (e.g. Standard Suite, Premium Suite) with pricing auto-calculated by the pricing engine.', roles:'Reception, Branch Manager, Super Admin', screen:'/bookings/new', endpoint:'GET /settings/boarding', php:'OPB_Pricing_Engine, OPB_Settings_API' },
  { module:'Bookings', feature:'Add-on Services', description:'Attach add-on services (grooming, medication, special meals) to any stay. Add or remove add-ons post-booking.', roles:'Reception, Branch Manager, Super Admin', screen:'/bookings/:id', endpoint:'POST/DELETE /bookings/:id/addons', php:'OPB_Bookings_API' },
  { module:'Bookings', feature:'Automatic Invoice Generation', description:'Invoice is auto-generated on booking creation with line items for boarding + add-ons. Recalculates on stay changes.', roles:'Reception, Branch Manager, Super Admin', screen:'/bookings/new', endpoint:'POST /bookings', php:'OPB_Invoice_Generator' },
  { module:'Bookings', feature:'Check-In Workflow', description:'Guided check-in screen. Assigns kennel, records actual check-in date/time, updates booking status to active.', roles:'Reception, Branch Manager, Super Admin', screen:'/bookings/:id/checkin', endpoint:'POST /bookings/:id/checkin', php:'OPB_Bookings_API' },
  { module:'Bookings', feature:'Check-Out Workflow', description:'Guided check-out screen. Records actual departure, updates billing if stay extended, closes booking.', roles:'Reception, Branch Manager, Super Admin', screen:'/bookings/:id/checkout', endpoint:'POST /bookings/:id/checkout', php:'OPB_Bookings_API' },
  { module:'Bookings', feature:'Booking Cancellation & Restoration', description:'Cancel bookings with reason. Super admin can restore cancelled bookings from data management.', roles:'Branch Manager, Super Admin', screen:'/bookings/:id, /admin/data-management', endpoint:'POST /admin/bookings/:id/cancel, /restore', php:'OPB_Data_Management_API' },
  { module:'Bookings', feature:'Overstay Detection', description:'SAL automatically flags pets whose check-out date has passed but who are still actively boarded.', roles:'All (via SAL brief)', screen:'N/A (SAL delivery)', endpoint:'SAL pipeline', php:'OPB_SAL_Snapshot' },

  // ── KENNEL MANAGEMENT ──────────────────────────────────────────────────────
  { module:'Kennel Management', feature:'Visual Kennel Occupancy Board', description:'Card-based view of all kennels per branch showing current occupant, pet name, stay dates, and status. Colour-coded by state (vacant/occupied/cleaning).', roles:'All staff', screen:'/kennel', endpoint:'GET /kennel-board', php:'OPB_Bookings_API' },
  { module:'Kennel Management', feature:'Linear Timeline View', description:'Horizontal timeline showing kennel occupancy across a date range. Identify gaps and plan capacity.', roles:'All staff', screen:'/kennel/linear', endpoint:'GET /kennel-board', php:'OPB_Bookings_API' },
  { module:'Kennel Management', feature:'Kennel Assignment at Check-In', description:'Staff select or confirm kennel assignment during the check-in workflow.', roles:'Reception, Branch Manager, Super Admin', screen:'/bookings/:id/checkin', endpoint:'POST /stays/:id/assign-kennel', php:'OPB_Bookings_API' },
  { module:'Kennel Management', feature:'Kennel Configuration', description:'Define kennels per branch: code, name, capacity notes, active/inactive status.', roles:'Super Admin', screen:'/settings/kennels', endpoint:'GET/POST /kennels, /kennels/:id', php:'OPB_Kennels_API' },
  { module:'Kennel Management', feature:'Kennel Staff Assignment', description:'Assign staff members to specific kennels for responsibility tracking.', roles:'Super Admin, Branch Manager', screen:'/settings/kennels', endpoint:'—', php:'OPB_Kennels_API' },

  // ── INVOICES ───────────────────────────────────────────────────────────────
  { module:'Invoicing', feature:'Invoice List', description:'List all invoices with status filters (pending, partial, paid, overdue). Search by client or invoice ID.', roles:'Reception, Branch Manager, Super Admin', screen:'/invoices', endpoint:'GET /invoices', php:'OPB_Invoices_API' },
  { module:'Invoicing', feature:'Invoice Detail View', description:'Full invoice with itemised line items, subtotals, payment records, and adjustment history.', roles:'Reception, Branch Manager, Super Admin', screen:'/invoices/:id', endpoint:'GET /invoices/:id', php:'OPB_Invoices_API' },
  { module:'Invoicing', feature:'PDF Document Generation', description:'Generate a professional PDF invoice using mPDF. Includes facility branding, line items, payment terms, and QR code.', roles:'Reception, Branch Manager, Super Admin', screen:'/invoices/:id', endpoint:'POST /invoices/:id/document/generate', php:'OPB_Invoice_Document, OPB_Invoice_Generator' },
  { module:'Invoicing', feature:'PDF Email Delivery', description:'Send the invoice PDF as an email attachment to the client\'s registered email address.', roles:'Reception, Branch Manager, Super Admin', screen:'/invoices/:id', endpoint:'POST /invoices/:id/send-email', php:'OPB_Invoice_Delivery_API' },
  { module:'Invoicing', feature:'WhatsApp Payment Link', description:'Generate a WhatsApp deep-link with the invoice summary and payment instructions. Opens a pre-filled WhatsApp message.', roles:'Reception, Branch Manager, Super Admin', screen:'/invoices/:id', endpoint:'GET /invoices/:id/whatsapp-link', php:'OPB_Invoice_Delivery_API' },
  { module:'Invoicing', feature:'Public Invoice Summary Page', description:'Token-gated public URL (no WP login required) where clients can view their invoice summary and download the PDF.', roles:'Public (client, via token)', screen:'WordPress frontend page', endpoint:'GET /public/invoice/:token', php:'OPB_Public_Portal' },
  { module:'Invoicing', feature:'Invoice Adjustment', description:'Apply manual adjustments (discounts, corrections) to an invoice with a reason. Logged in audit trail.', roles:'Branch Manager, Super Admin', screen:'/invoices/:id', endpoint:'POST /invoices/:id/adjust', php:'OPB_Invoices_API' },
  { module:'Invoicing', feature:'Invoice Audit Trail', description:'Every invoice action (generated, adjusted, emailed, payment recorded, PDF viewed) is logged with timestamp, actor, and change details.', roles:'Super Admin, Branch Manager', screen:'/invoices/:id', endpoint:'GET /invoices/:id/audit', php:'OPB_Invoices_API' },
  { module:'Invoicing', feature:'Payment Recording', description:'Record full or partial payments against an invoice. Supports multiple payment modes (cash, card, bank transfer, UPI, wallet).', roles:'Reception, Branch Manager, Super Admin', screen:'/invoices/:id', endpoint:'POST /payments, GET /invoices/:id/payments', php:'OPB_Payments_API' },
  { module:'Invoicing', feature:'Overdue Invoice Tracking', description:'Invoices unpaid beyond 7 days flagged as overdue. Highlighted in the SAL Accounts Snapshot and reports.', roles:'All (via SAL)', screen:'/invoices, SAL brief', endpoint:'SAL pipeline', php:'OPB_SAL_Snapshot, OPB_Invoices_API' },

  // ── TASKS ──────────────────────────────────────────────────────────────────
  { module:'Task Management', feature:'Task List with Filters', description:'View tasks by branch, status, priority, and due date. Supports overdue filter.', roles:'All staff', screen:'/tasks', endpoint:'GET /tasks', php:'OPB_Tasks_API' },
  { module:'Task Management', feature:'Task Creation', description:'Create tasks with title, description, branch, assignee, priority (low/medium/high/urgent), and due date.', roles:'Reception, Branch Manager, Super Admin', screen:'/tasks', endpoint:'POST /tasks', php:'OPB_Tasks_API' },
  { module:'Task Management', feature:'Task Status Workflow', description:'Move tasks through open → in progress → done. Status changes logged with timestamp.', roles:'All staff', screen:'/tasks', endpoint:'PUT /tasks/:id', php:'OPB_Tasks_API' },
  { module:'Task Management', feature:'Assignee Management', description:'Assign tasks to specific staff members. Unassigned tasks are flagged in SAL briefs.', roles:'Branch Manager, Super Admin', screen:'/tasks', endpoint:'PUT /tasks/:id', php:'OPB_Tasks_API' },
  { module:'Task Management', feature:'Overdue Task Alerts (SAL)', description:'SAL morning and evening briefs list all overdue tasks by branch with title and due date.', roles:'All (via SAL)', screen:'N/A (SAL delivery)', endpoint:'SAL pipeline', php:'OPB_SAL_Snapshot' },
  { module:'Task Management', feature:'Priority Management', description:'Four priority levels: low, medium, high, urgent. High/urgent tasks highlighted in the task list.', roles:'All staff', screen:'/tasks', endpoint:'GET/PUT /tasks', php:'OPB_Tasks_API' },

  // ── EXPENSES ───────────────────────────────────────────────────────────────
  { module:'Expense Tracking', feature:'Expense Recording', description:'Record branch expenses with description, amount, category, date, and optional notes.', roles:'Reception, Branch Manager, Super Admin', screen:'/expenses', endpoint:'POST /expenses', php:'OPB_Expenses_API' },
  { module:'Expense Tracking', feature:'Expense List with Filters', description:'Filter expenses by branch, category, date range. Displays running totals.', roles:'Branch Manager, Super Admin', screen:'/expenses', endpoint:'GET /expenses', php:'OPB_Expenses_API' },
  { module:'Expense Tracking', feature:'Expense Category Management', description:'Create and manage expense categories (e.g. Feed, Veterinary, Utilities, Maintenance).', roles:'Super Admin', screen:'/settings/expense-categories', endpoint:'GET/POST/PUT /expense-categories', php:'OPB_Expense_Categories_API' },
  { module:'Expense Tracking', feature:'Expense Reporting in SAL', description:'Daily expenses are included in the SAL Accounts Snapshot: count and total per branch.', roles:'All (via SAL)', screen:'N/A (SAL delivery)', endpoint:'SAL pipeline', php:'OPB_SAL_Snapshot' },
  { module:'Expense Tracking', feature:'OPSMAIL Expense Inbox', description:'Staff can email expenses to a configured IMAP inbox. Gemini AI classifies and creates the expense record automatically.', roles:'All staff (via email)', screen:'/admin/opsmail', endpoint:'POST /opsmail/process-mailbox', php:'OPB_Mailbox_Processor' },

  // ── INQUIRIES & ONBOARDING ────────────────────────────────────────────────
  { module:'Inquiries & Onboarding', feature:'Public Inquiry Form', description:'Public-facing inquiry form (no login required) on the WordPress frontend. Captures owner name, phone, email, pet details, preferred dates.', roles:'Public', screen:'WordPress page', endpoint:'POST /public/inquiries', php:'OPB_Public_API' },
  { module:'Inquiries & Onboarding', feature:'Inquiry List & Management', description:'Internal inquiry list with status workflow: new → reviewing → onboarding → converted / rejected.', roles:'Reception, Branch Manager, Super Admin', screen:'/inquiries', endpoint:'GET /inquiries', php:'OPB_Inquiries_API' },
  { module:'Inquiries & Onboarding', feature:'Inquiry Detail & Notes', description:'View full inquiry details, add internal notes, track status history.', roles:'Reception, Branch Manager, Super Admin', screen:'/inquiries/:id', endpoint:'GET/POST /inquiries/:id/notes', php:'OPB_Inquiries_API' },
  { module:'Inquiries & Onboarding', feature:'Duplicate Detection', description:'System checks for existing clients/inquiries with matching phone/email before processing a new inquiry.', roles:'Reception, Branch Manager, Super Admin', screen:'/inquiries/:id', endpoint:'GET /inquiries/:id/duplicate-check', php:'OPB_Inquiries_API' },
  { module:'Inquiries & Onboarding', feature:'Onboarding Link Generation', description:'Generate a unique 64-char token URL for the client to complete their own onboarding form online.', roles:'Reception, Branch Manager, Super Admin', screen:'/inquiries/:id', endpoint:'POST /inquiries/:id/send-onboarding', php:'OPB_Inquiries_API, OPB_Onboarding_Handler' },
  { module:'Inquiries & Onboarding', feature:'Multi-step Online Onboarding Form', description:'Client-facing form: personal details, pet profiles, document uploads, T&C acceptance. No WP account needed.', roles:'Public (client, via token)', screen:'WordPress page (public)', endpoint:'GET/POST /public/onboarding/:token', php:'OPB_Public_API, OPB_Onboarding_Handler' },
  { module:'Inquiries & Onboarding', feature:'Automatic Acknowledgement Email', description:'Automated email sent to the inquiry owner immediately on form submission.', roles:'System', screen:'N/A', endpoint:'POST /public/inquiries', php:'OPB_Notifications, OPB_Onboarding_Handler' },
  { module:'Inquiries & Onboarding', feature:'WhatsApp Onboarding Message', description:'Staff can send a configurable WhatsApp message with the onboarding link directly from the inquiry detail screen.', roles:'Reception, Branch Manager, Super Admin', screen:'/inquiries/:id', endpoint:'—', php:'OPB_Notifications' },
  { module:'Inquiries & Onboarding', feature:'Inquiry-to-Client Conversion', description:'One-click convert a completed inquiry to a full client + pet record. Linked to home branch.', roles:'Reception, Branch Manager, Super Admin', screen:'/inquiries/:id', endpoint:'POST /inquiries/:id/convert', php:'OPB_Inquiries_API, OPB_Onboarding_Handler' },

  // ── REPORTS ────────────────────────────────────────────────────────────────
  { module:'Reports & Analytics', feature:'Revenue Report', description:'Revenue by branch, month, and service type. Includes total billed, paid, and outstanding amounts.', roles:'Super Admin, Branch Manager', screen:'/reports', endpoint:'GET /reports', php:'OPB_Reports_API' },
  { module:'Reports & Analytics', feature:'Occupancy Analytics', description:'Occupancy rate over time per branch. Average stay length and peak occupancy periods.', roles:'Super Admin, Branch Manager', screen:'/reports', endpoint:'GET /reports', php:'OPB_Reports_API' },
  { module:'Reports & Analytics', feature:'Boarding Trends', description:'Trend charts for bookings by pet type, breed size, and boarding service category.', roles:'Super Admin, Branch Manager', screen:'/reports', endpoint:'GET /reports', php:'OPB_Reports_API' },
  { module:'Reports & Analytics', feature:'Expense Analysis', description:'Expense totals by category and branch. Compare income vs expenses.', roles:'Super Admin, Branch Manager', screen:'/reports', endpoint:'GET /reports', php:'OPB_Reports_API' },
  { module:'Reports & Analytics', feature:'Payment Mode Breakdown', description:'Breakdown of payments received by mode: cash, card, UPI, bank transfer, wallet.', roles:'Super Admin, Branch Manager', screen:'/reports', endpoint:'GET /reports', php:'OPB_Reports_API' },
  { module:'Reports & Analytics', feature:'Date Range Filtering', description:'All report views support custom date range selection. Defaults to current month.', roles:'Super Admin, Branch Manager', screen:'/reports', endpoint:'GET /reports?from=&to=', php:'OPB_Reports_API' },

  // ── OPSMAIL ────────────────────────────────────────────────────────────────
  { module:'OPSMAIL Intelligence', feature:'Event-Driven Notification Queue', description:'Internal queue (opb_opsmail_queue) captures all operational events. 5 hook points: booking creation, check-in, check-out, payment recorded, invoice sent.', roles:'System', screen:'/admin/opsmail', endpoint:'GET /opsmail/queue', php:'OPB_Opsmail' },
  { module:'OPSMAIL Intelligence', feature:'Telegram Notification Delivery', description:'WP Cron flushes the queue every minute, delivering pending messages to the configured Telegram bot.', roles:'System (delivery) / Super Admin (view)', screen:'/admin/opsmail', endpoint:'POST /opsmail/process-telegram', php:'OPB_Telegram_Consumer' },
  { module:'OPSMAIL Intelligence', feature:'IMAP Email Inbox Polling', description:'Polls a configured IMAP mailbox every 5 minutes for unstructured operational emails (e.g. expense submissions).', roles:'System', screen:'/admin/opsmail', endpoint:'POST /opsmail/process-mailbox', php:'OPB_Mailbox_Processor' },
  { module:'OPSMAIL Intelligence', feature:'Gemini AI Email Classification', description:'Incoming emails are classified by Google Gemini. Recognised expense emails are auto-created as expense records.', roles:'System', screen:'/admin/opsmail/gemini-lab', endpoint:'POST /opsmail/gemini-run', php:'OPB_Mailbox_Processor' },
  { module:'OPSMAIL Intelligence', feature:'Queue Viewer with Acknowledge', description:'Staff can view the full event queue, see message status, and acknowledge processed items.', roles:'Super Admin', screen:'/admin/opsmail', endpoint:'GET /opsmail/queue, POST /opsmail/queue/:id/acknowledge', php:'OPB_Opsmail_API' },
  { module:'OPSMAIL Intelligence', feature:'Gemini Lab (Test Interface)', description:'Admin UI to test Gemini prompts and OPSMAIL pipeline without triggering real events.', roles:'Super Admin', screen:'/admin/opsmail/gemini-lab', endpoint:'POST /opsmail/test-gemini', php:'OPB_Opsmail_API' },
  { module:'OPSMAIL Intelligence', feature:'Cron Health Monitor', description:'Displays status of all OPB cron jobs: last run, last success, next scheduled.', roles:'Super Admin', screen:'/admin/opsmail', endpoint:'GET /opsmail/cron-health', php:'OPB_Cron_Health' },
  { module:'OPSMAIL Intelligence', feature:'Test Telegram & Mailbox', description:'One-click test endpoints to verify Telegram bot connectivity and IMAP mailbox access.', roles:'Super Admin', screen:'/admin/opsmail', endpoint:'POST /opsmail/test-telegram, /opsmail/test-mailbox', php:'OPB_Opsmail_API' },

  // ── SAL ────────────────────────────────────────────────────────────────────
  { module:'SAL Executive Briefings', feature:'Morning Operations Brief', description:'Automated 07:00 Telegram brief: active boarders, today\'s arrivals/departures, overdue tasks, medication pets, exceptions.', roles:'System (delivery) / Super Admin (config)', screen:'/admin/sal', endpoint:'POST /sal/send', php:'OPB_SAL_Formatter, OPB_SAL_Snapshot' },
  { module:'SAL Executive Briefings', feature:'Evening Closure Brief', description:'Automated 19:00 Telegram brief: actual check-ins/check-outs completed, open tasks, day summary.', roles:'System (delivery) / Super Admin (config)', screen:'/admin/sal', endpoint:'POST /sal/send', php:'OPB_SAL_Formatter, OPB_SAL_Snapshot' },
  { module:'SAL Executive Briefings', feature:'Accounts Snapshot', description:'Automated financial brief: payments received today, unpaid/overdue invoices, expenses by branch. Numbers only, no interpretation.', roles:'System (delivery) / Super Admin (config)', screen:'/admin/sal', endpoint:'POST /sal/send', php:'OPB_SAL_Formatter, OPB_SAL_Snapshot' },
  { module:'SAL Executive Briefings', feature:'Gemini AI Formatting', description:'Google Gemini formats the brief from structured OPB data. Produces Telegram HTML with sections, bullets, and attention items.', roles:'System', screen:'N/A', endpoint:'—', php:'OPB_SAL_Formatter::call_gemini' },
  { module:'SAL Executive Briefings', feature:'Deterministic Fallback', description:'If Gemini is unavailable, a PHP-built fallback brief is delivered instead. No brief is ever silently skipped.', roles:'System', screen:'N/A', endpoint:'—', php:'OPB_SAL_Formatter::deterministic_fallback' },
  { module:'SAL Executive Briefings', feature:'Custom Prompt Override', description:'Super admins can override the Gemini prompt per brief type from the SAL dashboard. Custom prompt stored in WordPress options.', roles:'Super Admin', screen:'/admin/sal → E. Prompt Customization', endpoint:'POST /sal/config', php:'OPB_SAL_Formatter::build_prompt' },
  { module:'SAL Executive Briefings', feature:'Preview Mode (Pipeline Inspector)', description:'Generate a brief preview without sending. Inspect the snapshot JSON, prompt, Gemini output, and final Telegram message at each pipeline step.', roles:'Super Admin', screen:'/admin/sal → Preview Mode', endpoint:'POST /sal/generate', php:'OPB_SAL_Formatter::format' },
  { module:'SAL Executive Briefings', feature:'Brief History', description:'Searchable log of all delivered briefs: type, trigger (scheduled/manual), Telegram status, timing, fallback indicator, and full message text.', roles:'Super Admin', screen:'/admin/sal → D. Brief History', endpoint:'GET /sal/history', php:'OPB_SAL_API' },
  { module:'SAL Executive Briefings', feature:'SAL Diagnostics', description:'Per-brief-type diagnostics: last run, last success, last failure with error message, next scheduled run. Cron active indicator.', roles:'Super Admin', screen:'/admin/sal → F. Diagnostics', endpoint:'GET /sal/diagnostics', php:'OPB_SAL_API' },
  { module:'SAL Executive Briefings', feature:'Schedule Configuration', description:'Enable/disable each brief type independently. Set custom delivery time (24h HH:MM). Separate SAL Telegram chat ID supported.', roles:'Super Admin', screen:'/admin/sal → A. Schedule', endpoint:'POST /sal/config', php:'OPB_SAL_Scheduler' },

  // ── CLIENT PORTAL ─────────────────────────────────────────────────────────
  { module:'Client Self-Service Portal', feature:'Email OTP Authentication', description:'Clients access the portal via a one-time password sent to their registered email. No WordPress account required.', roles:'Client (public)', screen:'WordPress /my-pets/', endpoint:'POST /client/auth/request-otp, /verify-otp', php:'OPB_Client_Auth' },
  { module:'Client Self-Service Portal', feature:'My Pets Overview', description:'Client-facing view of all their pets\' current and upcoming boarding status.', roles:'Client (authenticated)', screen:'WordPress /my-pets/', endpoint:'GET /client/me', php:'OPB_Client_Portal, OPB_Client_Relationship_API' },
  { module:'Client Self-Service Portal', feature:'Booking History (Client View)', description:'Client can view their own booking history with stay details and status.', roles:'Client (authenticated)', screen:'WordPress /my-pets/', endpoint:'GET /client/me', php:'OPB_Client_Relationship_API' },
  { module:'Client Self-Service Portal', feature:'Invoice Access (Client View)', description:'Client can view their invoices and download PDFs from the portal.', roles:'Client (authenticated)', screen:'WordPress /my-pets/', endpoint:'GET /client/me', php:'OPB_Client_Relationship_API' },
  { module:'Client Self-Service Portal', feature:'Session Management', description:'Sessions stored in opb_client_sessions with expiry. Access log maintained in opb_client_access_log.', roles:'System', screen:'N/A', endpoint:'POST /client/auth/logout', php:'OPB_Client_Auth' },

  // ── SETTINGS ──────────────────────────────────────────────────────────────
  { module:'Settings & Configuration', feature:'Branch Management', description:'Create and manage branches: name, code, location, phone, email, active status. Branches scope all operational data.', roles:'Super Admin', screen:'/settings/branches', endpoint:'GET/POST/PUT /branches', php:'OPB_Branches_API' },
  { module:'Settings & Configuration', feature:'Staff Management', description:'Create WordPress users with OPB roles, assign to branches. Role determines module access and data scope.', roles:'Super Admin', screen:'/settings/staff', endpoint:'WordPress Users API + OPB_User_Admin', php:'OPB_User_Admin' },
  { module:'Settings & Configuration', feature:'Boarding Service Catalogue', description:'Define boarding service types with name, description, pricing (per night / flat), and size applicability.', roles:'Super Admin', screen:'/settings/boarding', endpoint:'GET/POST /settings/boarding', php:'OPB_Settings_API' },
  { module:'Settings & Configuration', feature:'Add-on Service Catalogue', description:'Define add-on services (grooming, medication admin, special meals) with pricing.', roles:'Super Admin', screen:'/settings/addons', endpoint:'GET/POST /settings/addons', php:'OPB_Settings_API' },
  { module:'Settings & Configuration', feature:'Kennel Configuration', description:'Configure kennels per branch: code, name, size, status. Assign staff to kennels.', roles:'Super Admin', screen:'/settings/kennels', endpoint:'GET/POST/PUT/DELETE /kennels', php:'OPB_Kennels_API' },
  { module:'Settings & Configuration', feature:'Business Customization Centre', description:'Central settings hub: facility name, support contact, legal T&C, privacy policy, messaging templates (email, WhatsApp), OPSMAIL credentials, SAL configuration.', roles:'Super Admin', screen:'/settings/customization', endpoint:'GET/POST /customizations', php:'OPB_Customizations, OPB_Customizations_API' },
  { module:'Settings & Configuration', feature:'Expense Category Management', description:'Define expense categories used across all branches for consistent expense tagging.', roles:'Super Admin', screen:'/settings/expense-categories', endpoint:'GET/POST/PUT/DELETE /expense-categories', php:'OPB_Expense_Categories_API' },
  { module:'Settings & Configuration', feature:'Role-Based Access Control', description:'Four custom roles: Super Admin (global), Branch Manager (branch-scoped), Reception (operational), Staff (tasks only). Enforced at every REST endpoint.', roles:'Super Admin (management)', screen:'/settings/staff', endpoint:'All endpoints', php:'OPB_Roles, OPB_REST_Base' },
  { module:'Settings & Configuration', feature:'Messaging Template Customization', description:'All automated messages (onboarding, invoice, acknowledgement) are fully editable in the customization centre. Support for {{PLACEHOLDER}} variables.', roles:'Super Admin', screen:'/settings/customization', endpoint:'POST /customizations', php:'OPB_Customizations' },
  { module:'Settings & Configuration', feature:'Login Branding', description:'Custom facility name and logo displayed on the WordPress admin login page.', roles:'System / Super Admin (config)', screen:'wp-login.php', endpoint:'—', php:'OPB_Login_Branding' },

  // ── DATA MANAGEMENT ───────────────────────────────────────────────────────
  { module:'Data Management', feature:'Archive / Restore Clients', description:'Super-admin-only bulk archive and selective restore of client records.', roles:'Super Admin', screen:'/admin/data-management', endpoint:'POST /admin/clients/:id/archive, /restore', php:'OPB_Data_Management_API' },
  { module:'Data Management', feature:'Archive / Restore Pets', description:'Archive individual pet records. Archived pets excluded from booking selection.', roles:'Super Admin', screen:'/admin/data-management', endpoint:'POST /admin/pets/:id/archive, /restore', php:'OPB_Data_Management_API' },
  { module:'Data Management', feature:'Archive / Restore Bookings', description:'Cancel and archive bookings. Full booking history preserved for reporting.', roles:'Super Admin', screen:'/admin/data-management', endpoint:'POST /admin/bookings/:id/cancel, /restore', php:'OPB_Data_Management_API' },
  { module:'Data Management', feature:'Archive / Restore Inquiries', description:'Archive resolved or spam inquiries. Restorable at any time.', roles:'Super Admin', screen:'/admin/data-management', endpoint:'POST /admin/inquiries/:id/archive, /restore', php:'OPB_Data_Management_API' },

  // ── IMPORT ────────────────────────────────────────────────────────────────
  { module:'Data Import', feature:'CSV / XLSX Bulk Import', description:'Import clients, pets, and historical booking data from legacy CSV or XLSX files.', roles:'Super Admin', screen:'/import', endpoint:'POST /import/run', php:'OPB_Import_API' },
  { module:'Data Import', feature:'Dry-Run Mode', description:'Validate the import file and report errors/warnings without writing to the database.', roles:'Super Admin', screen:'/import', endpoint:'POST /import/dry-run', php:'OPB_Import_API' },
  { module:'Data Import', feature:'Import History', description:'Log of all past imports with record counts, errors, and timestamps.', roles:'Super Admin', screen:'/import', endpoint:'GET /import/history', php:'OPB_Import_API' },

  // ── INTEGRATION ───────────────────────────────────────────────────────────
  { module:'Integration & Platform', feature:'REST API (opb/v1)', description:'Full REST API with 104 route registrations across 21 resource types. All endpoints use WP nonce authentication.', roles:'All (role-scoped)', screen:'N/A', endpoint:'All opb/v1/* endpoints', php:'OPB_REST_Base' },
  { module:'Integration & Platform', feature:'WordPress Plugin Architecture', description:'Standard WP plugin structure. Hooks into WP admin, REST API, cron, and user management. Zero WooCommerce dependency.', roles:'N/A', screen:'N/A', endpoint:'N/A', php:'onukonu-pet-boarding-core.php' },
  { module:'Integration & Platform', feature:'React 18 SPA Frontend', description:'Single-page React application served in the WordPress admin. Client-side routing, React Router v6, no full-page reloads.', roles:'All staff', screen:'All /wp-admin/... screens', endpoint:'N/A', php:'N/A (React)' },
  { module:'Integration & Platform', feature:'WP Cron Jobs (3)', description:'opb_cron_process_mailbox (5 min), opb_cron_process_telegram (1 min), opb_sal_cron (scheduled per brief). All hooks registered via OPB_Loader.', roles:'System', screen:'N/A', endpoint:'N/A', php:'OPB_Loader, OPB_SAL_Scheduler' },
  { module:'Integration & Platform', feature:'mPDF PDF Engine', description:'mPDF library installed via Composer generates print-quality PDF invoices. Images embedded as base64 for shared hosting compatibility.', roles:'System', screen:'N/A', endpoint:'POST /invoices/:id/document/generate', php:'OPB_Invoice_Document' },
  { module:'Integration & Platform', feature:'Google Gemini AI Integration', description:'Gemini API used in OPSMAIL (email classification) and SAL (brief formatting). Model and API key configurable. Falls back gracefully on failure.', roles:'System / Super Admin (config)', screen:'/settings/customization, /admin/opsmail, /admin/sal', endpoint:'—', php:'OPB_Mailbox_Processor, OPB_SAL_Formatter' },
  { module:'Integration & Platform', feature:'Telegram Bot Integration', description:'Telegram Bot API used for OPSMAIL notifications and SAL executive briefings. Separate chat IDs configurable per channel.', roles:'System / Super Admin (config)', screen:'/settings/customization, /admin/sal', endpoint:'—', php:'OPB_Telegram_Consumer' },
];

async function generateFeatureIndex() {
  const outDir = OUT;

  // ── 1. XLSX ──────────────────────────────────────────────────────────────
  const workbook = new ExcelJS.Workbook();
  workbook.creator = 'Onukonu Pet Boarding Core';
  workbook.created = new Date();

  // All Features tab
  const ws = workbook.addWorksheet('All Features', { views: [{ state: 'frozen', ySplit: 1 }] });
  ws.columns = [
    { header: 'Module',        key: 'module',      width: 28 },
    { header: 'Feature',       key: 'feature',     width: 38 },
    { header: 'Description',   key: 'description', width: 72 },
    { header: 'User Roles',    key: 'roles',       width: 38 },
    { header: 'React Screen',  key: 'screen',      width: 32 },
    { header: 'REST Endpoint', key: 'endpoint',    width: 38 },
    { header: 'PHP Class',     key: 'php',         width: 38 },
  ];

  const hdStyle = { font: { bold: true, color: { argb: 'FFFFFFFF' }, size: 11 }, fill: { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FF1A365D' } }, alignment: { vertical: 'middle', wrapText: true } };
  ws.getRow(1).eachCell(cell => Object.assign(cell, hdStyle));
  ws.getRow(1).height = 22;

  const moduleColors = {};
  const palette = ['FFEEF6FF','FFFFFBEB','FFF0FDF4','FFFFF7ED','FFF0F9FF','FFFDF2F8','FFECFDF5','FFF5F3FF','FEFCE8FF'];
  let pi = 0;
  FEATURES.forEach((f, i) => {
    if (!moduleColors[f.module]) { moduleColors[f.module] = palette[pi++ % palette.length]; }
    const row = ws.addRow(f);
    row.height = 36;
    const bg = moduleColors[f.module];
    row.eachCell(cell => {
      cell.fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: bg } };
      cell.alignment = { vertical: 'middle', wrapText: true };
      cell.border = { bottom: { style: 'thin', color: { argb: 'FFE5E7EB' } } };
    });
  });
  ws.autoFilter = { from: 'A1', to: 'G1' };

  // Per-module tabs
  const modules = [...new Set(FEATURES.map(f => f.module))];
  modules.forEach(mod => {
    const mws = workbook.addWorksheet(mod.substring(0, 28), { views: [{ state: 'frozen', ySplit: 1 }] });
    mws.columns = ws.columns.map(c => ({ ...c }));
    mws.getRow(1).eachCell(cell => Object.assign(cell, hdStyle));
    mws.getRow(1).height = 22;
    FEATURES.filter(f => f.module === mod).forEach(f => {
      const row = mws.addRow(f);
      row.height = 36;
      row.eachCell(cell => { cell.alignment = { vertical: 'middle', wrapText: true }; cell.border = { bottom: { style: 'thin', color: { argb: 'FFE5E7EB' } } }; });
    });
    mws.autoFilter = { from: 'A1', to: 'G1' };
  });

  // Summary tab
  const sum = workbook.addWorksheet('Summary');
  sum.columns = [
    { header: 'Module', key: 'module', width: 32 },
    { header: 'Feature Count', key: 'count', width: 16 },
  ];
  sum.getRow(1).eachCell(cell => Object.assign(cell, hdStyle));
  modules.forEach(mod => {
    const cnt = FEATURES.filter(f => f.module === mod).length;
    const row = sum.addRow({ module: mod, count: cnt });
    row.getCell('count').alignment = { horizontal: 'center' };
  });
  sum.addRow({});
  const totRow = sum.addRow({ module: 'TOTAL', count: FEATURES.length });
  totRow.font = { bold: true };

  await workbook.xlsx.writeFile(path.join(outDir, 'OPB-Feature-Index.xlsx'));
  console.log('✅  Feature Index XLSX written');

  // ── 2. CSV ───────────────────────────────────────────────────────────────
  const csvHeader = 'Module,Feature,Description,User Roles,React Screen,REST Endpoint,PHP Class\n';
  const csvRows = FEATURES.map(f =>
    [f.module, f.feature, f.description, f.roles, f.screen, f.endpoint, f.php]
      .map(v => `"${(v||'').replace(/"/g, '""')}"`)
      .join(',')
  ).join('\n');
  fs.writeFileSync(path.join(outDir, 'OPB-Feature-Index.csv'), csvHeader + csvRows, 'utf8');
  console.log('✅  Feature Index CSV written');

  // ── 3. PDF ───────────────────────────────────────────────────────────────
  const doc = new PDFDocument({ size: 'A4', margins: { top: 50, bottom: 50, left: 40, right: 40 }, info: { Title: 'OPB Feature Index', Author: 'Onukonu Pet Boarding Core' } });
  const pdfPath = path.join(outDir, 'OPB-Feature-Index.pdf');
  doc.pipe(fs.createWriteStream(pdfPath));

  const W = doc.page.width - 80;
  const NAVY = '#1A365D';
  const AMBER = '#D97706';
  const LGRAY = '#F3F4F6';
  const DGRAY = '#374151';
  const MGRAY = '#6B7280';

  // Cover
  doc.rect(0, 0, doc.page.width, doc.page.height).fill(NAVY);
  doc.fill('#FFFFFF').font('Helvetica-Bold').fontSize(28).text('Onukonu Pet Boarding Core', 40, 180, { width: W, align: 'center' });
  doc.fill(AMBER).font('Helvetica-Bold').fontSize(20).text('Feature Index', 40, 230, { width: W, align: 'center' });
  doc.fill('#CBD5E0').font('Helvetica').fontSize(13).text('Complete Module & Capability Catalogue', 40, 265, { width: W, align: 'center' });
  doc.fill('#718096').font('Helvetica').fontSize(11).text(`Version 3.3.0  ·  ${FEATURES.length} Features  ·  ${modules.length} Modules`, 40, 300, { width: W, align: 'center' });
  doc.fill('#4A5568').font('Helvetica').fontSize(10).text(`Generated ${new Date().toLocaleDateString('en-IN', { year:'numeric', month:'long', day:'numeric' })}`, 40, 325, { width: W, align: 'center' });

  // Stats boxes
  const stats = [
    { label: 'Total Features', val: String(FEATURES.length) },
    { label: 'Modules', val: String(modules.length) },
    { label: 'REST Endpoints', val: '104' },
    { label: 'DB Tables', val: '29' },
    { label: 'React Screens', val: '31' },
    { label: 'User Roles', val: '4' },
  ];
  const bw = (W - 20) / 3; const bh = 60;
  stats.forEach((s, i) => {
    const bx = 40 + (i % 3) * (bw + 10);
    const by = 400 + Math.floor(i / 3) * (bh + 10);
    doc.rect(bx, by, bw, bh).fill('#2D3748');
    doc.fill(AMBER).font('Helvetica-Bold').fontSize(20).text(s.val, bx, by + 8, { width: bw, align: 'center' });
    doc.fill('#A0AEC0').font('Helvetica').fontSize(9).text(s.label, bx, by + 35, { width: bw, align: 'center' });
  });

  doc.addPage();

  // Per-module sections
  modules.forEach(mod => {
    const features = FEATURES.filter(f => f.module === mod);
    const sectionH = 28 + features.length * 52 + 20;
    if (doc.y + sectionH > doc.page.height - 60) doc.addPage();

    // Section header
    doc.rect(40, doc.y, W, 26).fill(NAVY);
    doc.fill('#FFFFFF').font('Helvetica-Bold').fontSize(11).text(mod.toUpperCase(), 48, doc.y - 20, { width: W - 16, lineBreak: false });
    doc.moveDown(0.2);

    const cols = [{ x: 40, w: 180 }, { x: 226, w: 200 }, { x: 432, w: 168 }];
    const colHd = ['Feature', 'Description', 'Roles / Endpoint'];

    // Column headers
    const hdy = doc.y;
    doc.rect(40, hdy, W, 16).fill('#E5E7EB');
    cols.forEach((c, ci) => {
      doc.fill(NAVY).font('Helvetica-Bold').fontSize(8).text(colHd[ci], c.x + 4, hdy + 4, { width: c.w - 8, lineBreak: false });
    });
    doc.y = hdy + 18;

    features.forEach((f, fi) => {
      const ry = doc.y;
      if (ry + 48 > doc.page.height - 50) { doc.addPage(); }
      const ry2 = doc.y;
      const bg = fi % 2 === 0 ? '#FFFFFF' : LGRAY;
      doc.rect(40, ry2, W, 48).fill(bg);

      doc.fill(NAVY).font('Helvetica-Bold').fontSize(8).text(f.feature, cols[0].x + 4, ry2 + 4, { width: cols[0].w - 8 });
      doc.fill(DGRAY).font('Helvetica').fontSize(7.5).text(f.description, cols[1].x + 4, ry2 + 4, { width: cols[1].w - 8 });
      doc.fill(MGRAY).font('Helvetica').fontSize(7).text(f.roles, cols[2].x + 4, ry2 + 4, { width: cols[2].w - 8 });
      doc.fill('#374151').font('Helvetica').fontSize(7).text(f.endpoint, cols[2].x + 4, ry2 + 18, { width: cols[2].w - 8 });

      doc.rect(40, ry2, W, 48).stroke('#E5E7EB').strokeOpacity(0.5);
      doc.y = ry2 + 50;
    });
    doc.moveDown(0.5);
  });

  // Page numbers
  const range = doc.bufferedPageRange();
  for (let i = range.start; i < range.start + range.count; i++) {
    doc.switchToPage(i);
    if (i === range.start) continue;
    doc.fill('#9CA3AF').font('Helvetica').fontSize(8)
      .text(`OPB Feature Index  ·  Page ${i - range.start + 1} of ${range.count - 1}`, 40, doc.page.height - 35, { width: W, align: 'center' });
  }

  doc.end();
  console.log('✅  Feature Index PDF written');
}

generateFeatureIndex().catch(err => { console.error('❌ Feature Index error:', err); process.exit(1); });
