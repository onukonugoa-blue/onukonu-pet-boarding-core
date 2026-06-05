---
name: Invoice document engine
description: Architecture decisions for the v1.9.0 invoice document/delivery feature
---

## Feature summary
- **Class**: `OPB_Invoice_Document` in `plugin/includes/class-opb-invoice-document.php`
- **REST API**: `OPB_Invoice_Delivery_API` in `plugin/includes/api/class-opb-invoice-delivery-api.php`
- **React API**: `plugin/app/src/api/invoice-delivery.ts`
- **UI panel**: `InvoiceDetail.tsx` — delivery card between summary and line items

## DB changes (idempotent ALTERs in activator)
- `opb_invoices.doc_token VARCHAR(64) NULL` — 64-char hex token for public URL
- `opb_invoices.doc_generated_at DATETIME NULL`
- Unique index `uq_invoice_doc_token`

## Public URL pattern
- Rewrite rule: `^opb-invoice/([a-f0-9]{64})/?$` → query var `opb_invoice`
- Served by `OPB_Invoice_Document::serve()` after token→invoice_id DB lookup
- No auth required; `?print=1` triggers auto-print dialog for PDF export
- HTML stored at `wp-content/uploads/opb-invoices/{id}/invoice.html`

## REST routes
- `POST /opb/v1/invoices/{id}/document/generate` — generate/regen
- `GET  /opb/v1/invoices/{id}/document` — get stored info
- `POST /opb/v1/invoices/{id}/send-email` — send via wp_mail (body: `{to}`)
- `GET  /opb/v1/invoices/{id}/whatsapp-link` — returns `{url, message, phone}`

## Customizations added (category: invoice)
- `invoice_email_subject`, `invoice_email_intro`, `invoice_footer_note`
- `invoice_payment_note`, `invoice_whatsapp_message`
- New VALID_PLACEHOLDERS: `INVOICE_NUMBER`, `INVOICE_LINK`, `INVOICE_TOTAL`, `INVOICE_PAID`, `INVOICE_DUE`
- New tab in Customization.tsx: "Invoice & Delivery" (id: invoice)

**Why:** No PDF library dependency — browser print-to-PDF keeps the plugin self-contained on shared hosting (Hostinger). Token prevents public listing; no auth for the public invoice view URL so clients can open it without a WP account.
