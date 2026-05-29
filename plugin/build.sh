#!/usr/bin/env bash
# Build the React frontend for the OPB WordPress plugin.
# Run this from the plugin/ directory or repo root:
#   bash plugin/build.sh

set -e
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR/app"

echo "▶ Installing dependencies…"
npm install

echo "▶ Building React app…"
npm run build

echo "✔ Build complete. Assets are in plugin/assets/dist/"
