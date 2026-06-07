---
name: Client Relationship Page
description: v2.1.0 feature — /my-pets/ public page with Email OTP auth, no WP login
---

## Route
- `/my-pets/` → query var `opb_my_pets=1` → `OPB_Client_Portal::render()`

## Auth flow
1. Client enters email → `POST /opb/v1/client/auth/request-otp`
2. 6-digit OTP emailed (bcrypt-hashed in DB, 10 min TTL, 5-attempt limit)
3. Client verifies OTP → `POST /opb/v1/client/auth/verify-otp`
4. Session token (64-char hex, SHA-256 in DB) returned + HttpOnly cookie `opb_client_session`
5. `GET /opb/v1/client/me` returns full data using cookie or Bearer header

## New DB tables (v2.1.0)
- `opb_client_otps` — OTP hashes, expiry, attempt count
- `opb_client_sessions` — session token hashes, TTL, invalidation
- `opb_client_access_log` — audit log for OTP sent/verified, login, logout, page access

## Key classes
- `OPB_Client_Auth` — OTP + session logic, email sending
- `OPB_Client_Portal` — public page renderer (standalone HTML, vanilla JS)
- `OPB_Client_Relationship_API` — REST endpoints

## Staff UI
- `ClientProfile.tsx` — "My Pets Page ↗" button shown when client has an email
- `ClientList.tsx` — 🐾 icon button on mobile cards for clients with email

**Why:** Clients are not WordPress users; they identify via email OTP. Session token stored as SHA-256 hash (never plain) for defense-in-depth.
