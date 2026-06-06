---
name: mPDF integration
description: How mPDF is integrated into the plugin and key constraints for PDF generation
---

## Installation
- Installed via `composer require mpdf/mpdf` inside `plugin/` directory
- Autoloader: `plugin/vendor/autoload.php`
- Must be required **before all other plugin requires** in `onukonu-pet-boarding-core.php`

## mPDF config used
```php
new \Mpdf\Mpdf([
    'mode'           => 'utf-8',
    'format'         => 'A4',
    'margin_top'     => 6,
    'margin_right'   => 12,
    'margin_bottom'  => 18,
    'margin_left'    => 12,
    'margin_footer'  => 5,
    'tempDir'        => trailingslashit(get_temp_dir()) . 'opb-mpdf',
]);
```

## HTML template constraints (mPDF does NOT support)
- `display: flex` or `display: grid` — use `<table>` elements for all layout
- CSS custom properties (`--var`)
- `position: sticky` / `position: fixed`
- Modern pseudo-selectors

## mPDF DOES support
- Inline `<style>` blocks with most CSS2 + some CSS3
- `background-color`, `border`, `padding`, `margin`, `font-weight`, `text-align`, `width`, `height`
- `border-radius` (limited)
- `float: left` / `float: right`
- `@page` rules and `SetHTMLFooter()`

## Images
- Base64 data URIs are the most reliable approach on shared hosting (avoids HTTP loopback issues)
- Helper: `OPB_Invoice_Document::img_src(string $key)` reads file via `OPB_Customizations::get_media_path()`, encodes to `data:image/...;base64,...`
- Falls back to `get_media_url()` if file is not locally accessible

## PDF output
- Stored at: `wp-content/uploads/opb-invoices/{invoice_id}/invoice.pdf`
- Relative path saved to `opb_invoices.doc_pdf_path`
- Temp dir created per-generation, left at `get_temp_dir()/opb-mpdf` for mPDF internal use

**Why base64 for images:** Hostinger shared hosting may block HTTP loopback requests (wp-cron style). Embedding images as base64 in the HTML avoids any network dependency during PDF generation.
