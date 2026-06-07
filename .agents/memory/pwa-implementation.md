---
name: PWA implementation
description: How OPB's Progressive Web App layer is structured and key gotchas
---

## CRITICAL — beforeinstallprompt race condition (root cause of missing install prompt)

Chrome fires `beforeinstallprompt` before `DOMContentLoaded`, and before React mounts and `useEffect` runs. Listeners registered inside `useEffect` always miss the event.

**Fix:** An inline synchronous `<script>` in `render_portal()` captures the event at parse time and stores it on `window.__opbDeferredInstall`. The `usePWAInstall` hook reads that stored event on mount, then also listens for future events.

**Why:** Without this, `installState` stays `'unsupported'` forever on every page load and the install button never appears regardless of manifest/SW validity.

**How to apply:** The inline script must remain the first `<script>` in `<head>`, before `<?php wp_head(); ?>`. Do not move it below the module script enqueue.

---

## CRITICAL — Single install handler (no duplicate listeners)

`usePWAInstall` is the single source of truth for install state. Do not add any other `beforeinstallprompt` listener anywhere. Both `TopBar` and `Sidebar` use this hook — they share state automatically.

**Why:** Duplicate listeners racing on a one-time event caused inconsistent state; the button sometimes appeared in one component but not the other.

---

## PNG icons are mandatory for Chrome installability

Always include `icon-192.png`, `icon-512.png`, `icon-maskable.png` (PNG) in the manifest. SVG icons kept alongside as fallbacks for other browsers. `apple-touch-icon` must be PNG — iOS Safari ignores SVG for that link.

---

## Manifest id field is required (Chrome 112+)

Manifest must include `"id": "/portal/"`. Both the static `plugin/assets/manifest.json` and the dynamic PHP `build_manifest()` method must include this field. Without it Chrome may not recognise an already-installed app across reinstalls.

---

## Service-Worker-Allowed header

When serving `/opb-sw.js` via PHP, include `header('Service-Worker-Allowed: /')`. The SW at root path claiming the narrower `/portal/` scope is technically valid without this header, but some hosting configurations require it explicitly.

---

## Manifest is served dynamically by PHP

`GET /opb-manifest.json` → `OPB_Portal::build_manifest()` reads `facility_name` from `OPB_Customizations::get()`. The static `plugin/assets/manifest.json` is a reference copy only — the PHP builder is always used at runtime.

---

## SW cache key must match release version

`CACHE_VERSION` in `plugin/assets/sw.js` must be bumped on every release (format: `opb-{VERSION}`). Old caches are purged in the `activate` handler by filtering keys that start with `opb-` but don't match the current version. Forgetting to bump means users keep stale assets after an update.

---

## Service worker scope

SW registered with `{ scope: '/portal/' }`. REST API calls (`/wp-json/`) are in the NEVER_CACHE list — always network-only. No REST responses are ever cached.

---

## Push notification foundation

`plugin/assets/sw.js` contains `push`, `notificationclick`, and `pushsubscriptionchange` event listeners as stubs. Structurally complete but no VAPID key or subscription flow exists yet. When push is activated: add VAPID public key to OPB global, call `PushManager.subscribe()` in a new `usePushNotifications` hook, add server-side endpoint to store subscriptions.

---

## Icon generation script (inline, not a file)

```python
from PIL import Image, ImageDraw
import math

def draw_paw(draw, size):
    f = size / 192.0
    draw.rounded_rectangle([0, 0, size-1, size-1], radius=int(size*0.146), fill=(30,58,95))
    for (cx, cy, rx, ry, angle) in [(62,76,17,21,-15),(130,76,17,21,15),(85,62,15,19,-5),(107,62,15,19,5)]:
        pts = []
        for i in range(60):
            t = 2*math.pi*i/60; x=rx*f*math.cos(t); y=ry*f*math.sin(t)
            rad=math.radians(angle)
            pts.append((x*math.cos(rad)-y*math.sin(rad)+cx*f, x*math.sin(rad)+y*math.cos(rad)+cy*f))
        draw.polygon(pts, fill=(255,255,255))
    pts = [(96*f+36*f*math.cos(2*math.pi*i/60), 122*f+29*f*math.sin(2*math.pi*i/60)) for i in range(60)]
    draw.polygon(pts, fill=(255,255,255))
```
