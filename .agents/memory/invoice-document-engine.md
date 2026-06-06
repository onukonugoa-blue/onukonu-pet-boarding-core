---
name: Invoice document engine
description: Architecture decisions for OPB_Invoice_Document across v1.9 and v2.0
---

## v2.0.0 (current)

**Primary document:** PDF generated with mPDF, stored at `uploads/opb-invoices/{id}/invoice.pdf`
**Legacy fallback:** HTML file at `uploads/opb-invoices/{id}/invoice.html` (still written for backward compat)
**Public token URL:** `/opb-invoice/{64-char-hex}/` — shows a clean summary page with "Download PDF" button (not the full invoice HTML)

**Email:** PDF attached via `wp_mail()` `$attachments` parameter. Throws RuntimeException if PDF file does not exist yet — caller must generate first.

**WhatsApp:** `wa.me/` link with rendered message template. `{{INVOICE_LINK}}` resolves to the public summary page URL.

**Audit trail:** `opb_invoice_audit` table. Events: `generated`, `regenerated`, `email_sent`, `whatsapp_shared`.

**v2.0.0 DB additions on opb_invoices:**
- `doc_pdf_path VARCHAR(500)` — relative path under uploads/
- `doc_generated_by BIGINT UNSIGNED` — WP user ID who triggered generation

**Key methods:**
- `generate(int $invoice_id)` — writes HTML + PDF, updates row, logs audit event
- `generate_pdf()` — private; mPDF instance with A4 config, calls `build_pdf_html()`, outputs to file
- `img_src(string $key)` — resolves media setting key to base64 data URI (falls back to URL for mPDF reliability)
- `get_audit(int $invoice_id)` — returns audit rows newest-first
- `log_audit_event()` — private; inserts row into opb_invoice_audit

**Why PDF over HTML:** mPDF produces a stable, printable, non-editable client-facing document. Chosen over TCPDF for better UTF-8 and CSS support.

---

## v1.9.0 (prior)

- **Class**: `OPB_Invoice_Document`
- DB changes: `opb_invoices.doc_token VARCHAR(64)`, `doc_generated_at DATETIME`, unique index `uq_invoice_doc_token`
- Public URL served HTML invoice; `?print=1` triggered browser print dialog
- No PDF — kept plugin self-contained (no Composer dependency)
- REST routes: generate, get info, send-email, whatsapp-link
