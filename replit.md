# Onukonu Pet Boarding Core

A documentation and project management viewer for the Onukonu Pet Boarding Core WordPress plugin — a custom-built management platform for a pet boarding business with three branches.

## Project Overview

This Replit project serves as the development environment and documentation hub for the WordPress plugin. The plugin itself (in `plugin/`) is a PHP 8.2 + React 18 + MySQL system deployed on Hostinger.

The Replit environment runs a Node.js documentation server that displays:
- Project overview and README
- Architecture documentation
- Analysis documents
- Plugin changelog and REST API reference

## Stack

- **Runtime**: Node.js 20
- **Dev server**: `server.js` — plain Node.js HTTP server, no framework
- **Frontend viewer**: Server-side rendered HTML with inline CSS
- **Port**: 5000 (mapped to external port 80)

## Running the project

```bash
node server.js
```

The app starts on port 5000 and is available in the Replit preview pane.

## Project structure

- `server.js` — documentation viewer server
- `plugin/` — WordPress plugin source (PHP + React SPA)
- `docs/` — Architecture and analysis documentation
- `legacy-system/` — Legacy data files (CSV/XLSX) for migration
- `README.md` — Project overview

## User preferences

- Keep the documentation viewer simple and server-side rendered
