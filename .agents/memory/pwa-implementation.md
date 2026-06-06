---
name: PWA implementation
description: How OPB's Progressive Web App layer is structured and key gotchas
---

## Key architectural decisions

**PNG icons are mandatory for Chrome's beforeinstallprompt**
SVG-only icons prevent Chrome from firing `beforeinstallprompt`. Always include 192×192 and 512×512 PNG icons in the manifest.

**Why:** Chrome's installability criteria requires PNG (or WebP) icons. The existing SVG icons were kept alongside PNGs for other browsers.

**How to apply:** Any time icons are regenerated, produce `icon-192.png`, `icon-512.png`, `icon-maskable.png` (via the Python Pillow script drawing the paw shape). Do not remove the SVG fallbacks.

---

## Manifest is served dynamically by PHP

The route `GET /opb-manifest.json` is handled by `OPB_Portal::maybe_serve_portal()` which calls `OPB_Portal::build_manifest()`. That method reads `facility_name` from `OPB_Customizations::get()` so the manifest `name` field updates automatically when the admin changes the facility name in Settings → Customization.

The static `plugin/assets/manifest.json` file is the fallback only — it is no longer read-file'd; the PHP builder is always used.

---

## Service worker scope

SW is registered with `{ scope: '/portal/' }` — it only controls the staff portal. REST API calls (`/wp-json/`) are in the NEVER_CACHE list and pass through to the network without interception.

---

## Push notification foundation

`plugin/assets/sw.js` contains `push`, `notificationclick`, and `pushsubscriptionchange` event listeners as stubs. They are structurally complete (parse payload, show notification, handle click URL routing) but no VAPID key or subscription flow exists yet.

When push is activated in future: add VAPID public key to OPB global, call `PushManager.subscribe()` in a new `usePushNotifications` hook, and add a server-side endpoint to store endpoints.

---

## Sidebar install button

`usePWAInstall` hook (`plugin/app/src/hooks/usePWAInstall.ts`) manages three states: `unsupported`, `installable`, `installed`. The install button in `Sidebar.tsx` renders only when state is `installable`. After the user accepts the prompt the button disappears automatically.

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
