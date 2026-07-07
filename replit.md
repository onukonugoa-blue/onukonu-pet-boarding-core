# Onukonu Pet Boarding Core

Replacement platform for the discontinued boarding SaaS — implemented as a WordPress plugin targeting PHP 8.2, MySQL, and Hostinger shared hosting.

## How to run

The Node.js dev server (`server.js`) serves the project documentation site:

```
node server.js
```

This is configured as the default workflow ("Start application"). Visit the preview to browse:
- **Overview** — project summary and version badges
- **Architecture** — system design and module breakdown
- **Analysis** — financial forensics and data queries
- **Plugin** — plugin file structure
- **Changelog** — version history
- **RC1 Audit**, **Permission Audit**, **Financial Forensics** — audit reports

The actual WordPress plugin source lives in the `plugin/` directory and is deployed to Hostinger separately.

## Stack

- **Documentation server**: Node.js (no framework), port 5000
- **Plugin**: PHP 8.2, WordPress hooks/REST API, MySQL via `$wpdb`
- **PDF generation**: mPDF (Composer, inside `plugin/vendor/`)
- **Build tooling**: adm-zip (npm) for producing the plugin ZIP

## User preferences
