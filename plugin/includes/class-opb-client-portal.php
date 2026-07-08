<?php
/**
 * OPB_Client_Portal
 *
 * Serves the client-facing relationship page at /my-pets/.
 *
 * Registers rewrite rule: /my-pets/ → index.php?opb_my_pets=1
 *
 * Renders a fully self-contained HTML page (no WordPress theme) with:
 *   Screen 1 — Email entry
 *   Screen 2 — OTP verification
 *   Screen 3 — Relationship page (pets, bookings, invoices, support)
 *
 * Auth is handled entirely via REST API calls to OPB_Client_Relationship_API.
 * Sessions persist via HttpOnly cookie opb_client_session.
 */
class OPB_Client_Portal {

    public static function register(): void {
        add_action( 'init',              [ self::class, 'add_rewrite_rules' ] );
        add_filter( 'query_vars',        [ self::class, 'add_query_vars'    ] );
        add_action( 'template_redirect', [ self::class, 'maybe_serve'       ] );
    }

    public static function add_rewrite_rules(): void {
        add_rewrite_rule( '^my-pets/?$', 'index.php?opb_my_pets=1', 'top' );
    }

    public static function add_query_vars( array $vars ): array {
        $vars[] = 'opb_my_pets';
        return $vars;
    }

    public static function maybe_serve(): void {
        if ( get_query_var( 'opb_my_pets' ) ) {
            self::render();
            exit;
        }
    }

    // ── Page renderer ─────────────────────────────────────────────────────────

    public static function render(): void {
        // Prevent the portal shell page from being cached by Hostinger's
        // LiteSpeed/edge cache or any WP caching plugin. The page injects a
        // WP nonce (preview mode) and must never be served stale.
        nocache_headers();

        $facility = esc_html( OPB_Customizations::facility_name() );
        $api_base = esc_js( rest_url( 'opb/v1' ) );

        // ── Preview mode detection ─────────────────────────────────────────────
        $preview_mode      = false;
        $preview_client_id = 0;
        $preview_nonce     = '';

        if ( isset( $_GET['preview_client'] ) ) {
            $candidate_id = (int) $_GET['preview_client'];
            if ( $candidate_id > 0 && is_user_logged_in() && OPB_Roles::has_opb_role() ) {
                $preview_mode      = true;
                $preview_client_id = $candidate_id;
                $preview_nonce     = wp_create_nonce( 'wp_rest' );
            }
        }

        $preview_mode_js      = $preview_mode ? 'true' : 'false';
        $preview_client_id_js = esc_js( (string) $preview_client_id );
        $preview_nonce_js     = esc_js( $preview_nonce );

        // ── OG meta — single authoritative title source ────────────────────────
        // $page_title is the canonical page title string used by both <title>
        // and og:title so the two never drift.
        $page_title = "My Pets \u{2014} {$facility}";
        $og_url     = esc_url( home_url( '/my-pets/' ) );
        $og_image   = esc_url( OPB_PLUGIN_URL . 'assets/icons/icon-512.png' );
        $og_title   = esc_attr( $page_title );

        echo <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>{$page_title}</title>
<meta name="robots" content="noindex,nofollow">
<meta property="og:type"        content="website">
<meta property="og:url"         content="{$og_url}">
<meta property="og:title"       content="{$og_title}">
<meta property="og:description" content="View your pets, bookings and invoices.">
<meta property="og:image"       content="{$og_image}">
<style>
/* ── Reset & Base ──────────────────────────────────────────────────────────── */
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html{-webkit-text-size-adjust:100%}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;
     background:#f0f4f8;min-height:100vh;color:#1e293b;line-height:1.5;
     padding-bottom:env(safe-area-inset-bottom)}

/* ── Header ───────────────────────────────────────────────────────────────── */
.site-header{background:#1e3a8a;color:#fff;padding:16px 20px;
             display:flex;align-items:center;justify-content:space-between;
             position:sticky;top:0;z-index:50;box-shadow:0 2px 8px rgba(0,0,0,.2)}
.site-header h1{font-size:1.05rem;font-weight:700;display:flex;align-items:center;gap:8px}
.site-header h1 span{font-size:1.2rem}
#header-user{font-size:.8rem;color:#bfdbfe;max-width:160px;overflow:hidden;
             text-overflow:ellipsis;white-space:nowrap}
#btn-signout{background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.3);
             color:#fff;padding:5px 10px;border-radius:6px;font-size:.75rem;
             cursor:pointer;transition:background .15s;display:none}
#btn-signout:hover{background:rgba(255,255,255,.25)}

/* ── Page nav (logged-in section links) ───────────────────────────────────── */
#page-nav{display:none;background:#fff;border-bottom:1px solid #e2e8f0;
          overflow-x:auto;white-space:nowrap;padding:0 16px}
#page-nav a{display:inline-block;padding:10px 14px;font-size:.82rem;font-weight:600;
            color:#64748b;text-decoration:none;border-bottom:2px solid transparent;
            transition:all .15s}
#page-nav a:hover{color:#1e3a8a}
#page-nav a.active{color:#1e3a8a;border-bottom-color:#1e3a8a}

/* ── Container ────────────────────────────────────────────────────────────── */
.container{max-width:680px;margin:0 auto;padding:24px 16px 48px}

/* ── Cards ────────────────────────────────────────────────────────────────── */
.card{background:#fff;border-radius:12px;padding:24px;
      box-shadow:0 1px 4px rgba(0,0,0,.08);margin-bottom:16px}
.card-sm{padding:16px}
.section-title{font-size:1rem;font-weight:700;color:#1e3a8a;margin-bottom:16px;
               display:flex;align-items:center;gap:8px}
.section-title span{font-size:1.2rem}

/* ── Forms ────────────────────────────────────────────────────────────────── */
label{display:block;font-size:.8rem;font-weight:600;color:#475569;margin-bottom:5px}
input[type=email],input[type=tel],input[type=text]{
  width:100%;padding:11px 14px;border:1.5px solid #cbd5e1;border-radius:8px;
  font-size:.95rem;color:#1e293b;transition:border-color .15s,box-shadow .15s;
  -webkit-appearance:none}
input:focus{outline:none;border-color:#3b82f6;box-shadow:0 0 0 3px rgba(59,130,246,.15)}
.field{margin-bottom:16px}

/* ── OTP Input ────────────────────────────────────────────────────────────── */
#otp-input{font-size:2rem;font-weight:700;letter-spacing:10px;text-align:center;
           font-family:monospace;padding:14px;border-radius:10px}
#otp-input:focus{border-color:#3b82f6;box-shadow:0 0 0 3px rgba(59,130,246,.15)}

/* ── Buttons ──────────────────────────────────────────────────────────────── */
.btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;
     padding:11px 20px;border:none;border-radius:8px;font-size:.9rem;font-weight:600;
     cursor:pointer;transition:all .15s;text-decoration:none}
.btn-primary{background:#1e3a8a;color:#fff;width:100%}
.btn-primary:hover{background:#1e40af}
.btn-primary:disabled{background:#94a3b8;cursor:not-allowed}
.btn-secondary{background:#f1f5f9;color:#475569;border:1px solid #e2e8f0}
.btn-secondary:hover{background:#e2e8f0}
.btn-outline{background:transparent;border:1.5px solid #cbd5e1;color:#475569}
.btn-sm{padding:7px 14px;font-size:.8rem}
.btn-icon{gap:5px}
.btn-whatsapp{background:#22c55e;color:#fff}
.btn-whatsapp:hover{background:#16a34a}
.btn-email{background:#3b82f6;color:#fff}
.btn-email:hover{background:#2563eb}

/* ── Alerts ───────────────────────────────────────────────────────────────── */
.alert{padding:10px 14px;border-radius:8px;font-size:.85rem;margin-bottom:14px;display:none}
.alert-error{background:#fef2f2;border:1px solid #fca5a5;color:#b91c1c}
.alert-success{background:#f0fdf4;border:1px solid #86efac;color:#166534}
.alert-info{background:#eff6ff;border:1px solid #93c5fd;color:#1d4ed8}

/* ── Tabs ─────────────────────────────────────────────────────────────────── */
.tabs{display:flex;gap:4px;background:#f1f5f9;border-radius:8px;padding:4px;
      margin-bottom:16px}
.tab{flex:1;padding:7px 12px;border:none;border-radius:6px;font-size:.82rem;
     font-weight:600;cursor:pointer;background:transparent;color:#64748b;
     transition:all .15s}
.tab.active{background:#fff;color:#1e3a8a;box-shadow:0 1px 3px rgba(0,0,0,.1)}

/* ── Pet card ─────────────────────────────────────────────────────────────── */
.pet-card{border:1px solid #e2e8f0;border-radius:10px;overflow:hidden;
          margin-bottom:14px;background:#fafbff}
.pet-card-header{display:flex;gap:12px;padding:14px;align-items:flex-start}
.pet-photo{width:60px;height:60px;border-radius:8px;object-fit:cover;
           flex-shrink:0;background:#e2e8f0}
.pet-photo-placeholder{width:60px;height:60px;border-radius:8px;
                        background:#e0e7ff;display:flex;align-items:center;
                        justify-content:center;font-size:1.8rem;flex-shrink:0}
.pet-info{flex:1;min-width:0}
.pet-name{font-size:1rem;font-weight:700;color:#1e293b}
.pet-meta{font-size:.8rem;color:#64748b;margin-top:3px}
.pet-badges{display:flex;flex-wrap:wrap;gap:5px;margin-top:7px}
.badge{display:inline-block;padding:2px 8px;border-radius:20px;
       font-size:.72rem;font-weight:600}
.badge-green{background:#dcfce7;color:#166534}
.badge-orange{background:#fff7ed;color:#c2410c}
.badge-blue{background:#dbeafe;color:#1d4ed8}
.badge-gray{background:#f1f5f9;color:#64748b}
.badge-red{background:#fef2f2;color:#b91c1c}
.pet-docs{padding:0 14px 14px;border-top:1px solid #e2e8f0;padding-top:12px}
.pet-docs-title{font-size:.75rem;font-weight:600;color:#94a3b8;margin-bottom:8px;
                text-transform:uppercase;letter-spacing:.4px}
.doc-pill{display:inline-flex;align-items:center;gap:4px;background:#f8fafc;
          border:1px solid #e2e8f0;border-radius:6px;padding:4px 8px;
          font-size:.75rem;color:#475569;margin:3px 4px 3px 0}

/* ── Booking rows ─────────────────────────────────────────────────────────── */
.booking-row{display:flex;gap:12px;align-items:flex-start;padding:12px 0;
             border-bottom:1px solid #f1f5f9}
.booking-row:last-child{border-bottom:none;padding-bottom:0}
.booking-dot{width:8px;height:8px;border-radius:50%;background:#94a3b8;
             flex-shrink:0;margin-top:6px}
.booking-dot.upcoming{background:#22c55e}
.booking-dot.past{background:#94a3b8}
.booking-body{flex:1;min-width:0}
.booking-date{font-size:.85rem;font-weight:600;color:#1e293b}
.booking-detail{font-size:.78rem;color:#64748b;margin-top:2px}

/* ── Invoice table ────────────────────────────────────────────────────────── */
.inv-row{padding:12px 0;border-bottom:1px solid #f1f5f9;
         display:flex;justify-content:space-between;align-items:flex-start;gap:8px}
.inv-row:last-child{border-bottom:none;padding-bottom:0}
.inv-date{font-size:.85rem;font-weight:600;color:#1e293b}
.inv-amounts{font-size:.78rem;color:#64748b;margin-top:2px}
.inv-right{text-align:right;flex-shrink:0}
.inv-status{margin-bottom:4px}
.inv-pdf-link{font-size:.75rem;color:#3b82f6;text-decoration:none;
              display:inline-flex;align-items:center;gap:3px}
.inv-pdf-link:hover{text-decoration:underline}

/* ── Support buttons ──────────────────────────────────────────────────────── */
.support-buttons{display:flex;gap:12px;flex-wrap:wrap;margin-top:16px}
.support-buttons .btn{flex:1;min-width:140px}

/* ── Loading / empty states ───────────────────────────────────────────────── */
.loading-dots{text-align:center;padding:32px;color:#94a3b8;font-size:.9rem}
.empty-state{text-align:center;padding:32px 16px;color:#94a3b8;font-size:.88rem}
.empty-icon{font-size:2rem;display:block;margin-bottom:8px}

/* ── Preview banner ───────────────────────────────────────────────────────── */
#preview-banner{display:none;background:#f59e0b;color:#1c1917;
               padding:10px 20px;text-align:center;font-size:.84rem;
               font-weight:700;border-bottom:2px solid #d97706;
               position:sticky;top:57px;z-index:49}

/* ── Auth screen specific ─────────────────────────────────────────────────── */
.auth-intro{text-align:center;margin-bottom:24px}
.auth-intro .hero-icon{font-size:3rem;display:block;margin-bottom:12px}
.auth-intro h2{font-size:1.2rem;font-weight:700;color:#1e3a8a;margin-bottom:6px}
.auth-intro p{font-size:.88rem;color:#64748b;line-height:1.6}
.otp-context{background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;
             padding:12px 16px;margin-bottom:16px;font-size:.85rem;color:#1e40af}
.otp-actions{display:flex;gap:8px;justify-content:center;margin-top:12px}
.otp-actions button{background:none;border:none;color:#3b82f6;font-size:.82rem;
                     cursor:pointer;padding:4px 8px;border-radius:4px}
.otp-actions button:hover{background:#eff6ff}
.otp-timer{text-align:center;font-size:.78rem;color:#94a3b8;margin-top:8px}
.otp-timer.expired{color:#dc2626}

/* ── Spinner ──────────────────────────────────────────────────────────────── */
.spinner{display:inline-block;width:14px;height:14px;border:2px solid rgba(255,255,255,.4);
         border-top-color:#fff;border-radius:50%;animation:spin .7s linear infinite;
         vertical-align:middle}
.spinner-dark{border:2px solid #e2e8f0;border-top-color:#3b82f6}
@keyframes spin{to{transform:rotate(360deg)}}

/* ── Screen visibility ────────────────────────────────────────────────────── */
#screen-loading{display:flex;flex-direction:column;align-items:center;
                justify-content:center;min-height:40vh;color:#94a3b8;gap:12px}
#screen-auth{display:none}
#screen-page{display:none}

/* ── Utilities ────────────────────────────────────────────────────────────── */
.mt-2{margin-top:8px}.mt-3{margin-top:12px}.mt-4{margin-top:16px}
.text-sm{font-size:.85rem}.text-xs{font-size:.78rem}
.text-gray{color:#64748b}.text-center{text-align:center}
.note{font-size:.75rem;color:#94a3b8;margin-top:6px;text-align:center}
@media(max-width:400px){
  .otp-actions{flex-direction:column;align-items:center}
  #otp-input{font-size:1.6rem;letter-spacing:6px}
}
</style>
</head>
<body>

<!-- ── Header ──────────────────────────────────────────────────────────────── -->
<header class="site-header">
  <h1><span>🐾</span> {$facility}</h1>
  <div style="display:flex;align-items:center;gap:10px">
    <span id="header-user"></span>
    <button id="btn-signout">Sign Out</button>
  </div>
</header>

<!-- ── Staff preview banner ───────────────────────────────────────────────── -->
<div id="preview-banner">👁 Staff Preview Mode — Viewing as client. OTP authentication bypassed.</div>

<!-- ── Page nav (visible when logged in) ──────────────────────────────────── -->
<nav id="page-nav">
  <a href="#section-pets">My Pets</a>
  <a href="#section-bookings">Bookings</a>
  <a href="#section-invoices">Invoices</a>
  <a href="#section-support">Support</a>
</nav>

<!-- ── Loading ────────────────────────────────────────────────────────────── -->
<div id="screen-loading" class="container">
  <div class="spinner spinner-dark" style="width:24px;height:24px"></div>
  <span>Loading…</span>
</div>

<!-- ── Auth screen ─────────────────────────────────────────────────────────── -->
<div id="screen-auth">
  <div class="container">

    <!-- Step 1: Email -->
    <div id="step-email">
      <div class="auth-intro">
        <span class="hero-icon">🐾</span>
        <h2>View Your Pet Profile</h2>
        <p>Enter your registered email address.<br>We'll send you a secure 6-digit code to sign in.</p>
      </div>
      <div class="card">
        <div id="email-alert" class="alert alert-error"></div>
        <div class="field">
          <label for="email-input">Email Address</label>
          <input type="email" id="email-input" placeholder="you@example.com" autocomplete="email" autofocus>
        </div>
        <button class="btn btn-primary" id="btn-send-otp">Send Verification Code</button>
        <p class="note mt-2">A 6-digit code will be sent to your email. No password needed.</p>
      </div>
    </div>

    <!-- Step 2: OTP -->
    <div id="step-otp" style="display:none">
      <div class="auth-intro">
        <span class="hero-icon">✉️</span>
        <h2>Check Your Email</h2>
      </div>
      <div class="card">
        <div class="otp-context">
          If a matching account exists for <strong id="otp-email-display"></strong>, a verification code has been sent. Please check your inbox and spam folder.
        </div>
        <div id="otp-alert" class="alert alert-error"></div>
        <div class="field">
          <label for="otp-input">Verification Code</label>
          <input type="tel" id="otp-input" placeholder="000000"
                 maxlength="6" inputmode="numeric" pattern="\d{6}" autocomplete="one-time-code">
        </div>
        <button class="btn btn-primary" id="btn-verify-otp">Verify Code</button>
        <div class="otp-actions">
          <button id="btn-resend">Resend Code</button>
          <button id="btn-change-email">Change Email</button>
        </div>
        <p class="otp-timer" id="otp-timer">Code expires in <span id="otp-countdown">10:00</span></p>
      </div>
    </div>

  </div>
</div>

<!-- ── Relationship page ───────────────────────────────────────────────────── -->
<div id="screen-page">
  <div class="container">

    <!-- Welcome card -->
    <div class="card card-sm" id="welcome-card">
      <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px">
        <div>
          <div style="font-size:1.05rem;font-weight:700;color:#1e293b" id="page-client-name"></div>
          <div class="text-sm text-gray mt-2" id="page-client-contact"></div>
          <div class="text-xs text-gray mt-2" id="page-client-branch"></div>
        </div>
        <div style="font-size:2rem;flex-shrink:0">👤</div>
      </div>
    </div>

    <!-- ── My Pets ──────────────────────────────────────────────────────────── -->
    <div id="section-pets">
      <div class="section-title"><span>🐾</span> My Pets</div>
      <div id="pets-container">
        <div class="loading-dots">Loading pets…</div>
      </div>
    </div>

    <!-- ── Bookings ─────────────────────────────────────────────────────────── -->
    <div id="section-bookings" style="margin-top:8px">
      <div class="section-title"><span>📅</span> Bookings</div>
      <div class="card">
        <div class="tabs">
          <button class="tab active" id="tab-upcoming" onclick="showBookingTab('upcoming')">
            Upcoming <span id="upcoming-count"></span>
          </button>
          <button class="tab" id="tab-past" onclick="showBookingTab('past')">
            Past <span id="past-count"></span>
          </button>
        </div>
        <div id="upcoming-bookings"></div>
        <div id="past-bookings" style="display:none"></div>
      </div>
    </div>

    <!-- ── Invoices ─────────────────────────────────────────────────────────── -->
    <div id="section-invoices" style="margin-top:8px">
      <div class="section-title"><span>🧾</span> Invoices</div>
      <div class="card">
        <div id="invoices-container">
          <div class="loading-dots">Loading invoices…</div>
        </div>
      </div>
    </div>

    <!-- ── Support ──────────────────────────────────────────────────────────── -->
    <div id="section-support" style="margin-top:8px">
      <div class="section-title"><span>💬</span> Contact Support</div>
      <div class="card">
        <p class="text-sm text-gray" style="margin-bottom:4px">
          Need help? Reach out to our team — we're happy to assist.
        </p>
        <div class="support-buttons" id="support-buttons"></div>
      </div>
    </div>

  </div>
</div>

<script>
(function(){
'use strict';

var API = '{$api_base}';
var PREVIEW_MODE = {$preview_mode_js};
var PREVIEW_CLIENT_ID = {$preview_client_id_js};
var PREVIEW_NONCE = '{$preview_nonce_js}';
var currentEmail = '';
var otpTimer = null;
var otpSeconds = 600;
var pageData = null;

/* ── Helpers ───────────────────────────────────────────────────────────────── */
function show(id,d){ var el=ge(id); if(el) el.style.display=d||'block'; }
function hide(id){ var el=ge(id); if(el) el.style.display='none'; }
function ge(id){ return document.getElementById(id); }

function showAlert(id, msg, type){
  var el=ge(id);
  if(!el) return;
  el.textContent = msg;
  el.className = 'alert alert-'+(type||'error');
  el.style.display = 'block';
}
function hideAlert(id){ var el=ge(id); if(el) el.style.display='none'; }

function fmtDate(d){
  if(!d) return '—';
  try{
    var dt = new Date(d.replace(' ','T'));
    return dt.toLocaleDateString('en-IN',{day:'numeric',month:'short',year:'numeric'});
  }catch(e){ return d; }
}

function fmtINR(n){
  var v = parseFloat(n||0);
  return '₹'+v.toLocaleString('en-IN',{minimumFractionDigits:2,maximumFractionDigits:2});
}

function maskEmail(e){
  var parts = (e||'').split('@');
  if(parts.length!==2) return e;
  var name = parts[0];
  if(name.length<=2) return '**@'+parts[1];
  return name[0]+'***'+name[name.length-1]+'@'+parts[1];
}

function setBtn(id, loading, text){
  var el = ge(id);
  if(!el) return;
  el.disabled = loading;
  el.innerHTML = loading
    ? '<span class="spinner"></span> '+text+'…'
    : text;
}

/* ── Fetch wrapper ─────────────────────────────────────────────────────────── */
async function api(method, path, body){
  var opts = {
    method: method,
    credentials: 'include',
    headers: { 'Content-Type':'application/json', 'Accept':'application/json' }
  };
  if(PREVIEW_MODE && PREVIEW_NONCE) opts.headers['X-WP-Nonce'] = PREVIEW_NONCE;
  if(body) opts.body = JSON.stringify(body);
  var res = await fetch(API+path, opts);
  var data = await res.json().catch(function(){ return {}; });
  return { ok: res.ok, status: res.status, data: data };
}

/* ── Screens ───────────────────────────────────────────────────────────────── */
function showLoading(){ show('screen-loading','flex'); hide('screen-auth'); hide('screen-page'); }
function showAuth(step){
  hide('screen-loading'); hide('screen-page');
  show('screen-auth');
  hide('btn-signout');
  hide('page-nav');
  ge('header-user').textContent = '';
  if(step==='otp'){
    hide('step-email'); show('step-otp');
    setTimeout(function(){ var el=ge('otp-input'); if(el) el.focus(); }, 100);
  } else {
    show('step-email'); hide('step-otp');
    setTimeout(function(){ var el=ge('email-input'); if(el) el.focus(); }, 100);
  }
}
function showPage(){
  hide('screen-loading'); hide('screen-auth');
  show('screen-page');
  show('btn-signout');
  show('page-nav');
}

/* ── OTP Countdown ─────────────────────────────────────────────────────────── */
function startOtpTimer(){
  otpSeconds = 600;
  if(otpTimer) clearInterval(otpTimer);
  var el = ge('otp-countdown');
  var timerEl = ge('otp-timer');
  otpTimer = setInterval(function(){
    otpSeconds--;
    if(otpSeconds <= 0){
      clearInterval(otpTimer);
      if(el) el.textContent = '0:00';
      if(timerEl){ timerEl.className='otp-timer expired'; timerEl.textContent='Code expired. Request a new one.'; }
      return;
    }
    var m = Math.floor(otpSeconds/60);
    var s = otpSeconds%60;
    if(el) el.textContent = m+':'+(s<10?'0':'')+s;
  }, 1000);
}

/* ── Step 1: Request OTP ───────────────────────────────────────────────────── */
ge('btn-send-otp').addEventListener('click', async function(){
  var email = (ge('email-input').value||'').trim();
  if(!email){ showAlert('email-alert','Please enter your email address.','error'); return; }
  hideAlert('email-alert');
  setBtn('btn-send-otp', true, 'Sending');

  var r = await api('POST','/client/auth/request-otp',{email:email});
  setBtn('btn-send-otp', false, 'Send Verification Code');

  if(!r.ok){
    showAlert('email-alert', r.data.message||'Something went wrong. Please try again.', 'error');
    return;
  }

  currentEmail = email;
  ge('otp-email-display').textContent = maskEmail(email);
  ge('otp-timer').className = 'otp-timer';
  ge('otp-timer').innerHTML = 'Code expires in <span id="otp-countdown">10:00</span>';
  ge('otp-input').value = '';
  startOtpTimer();
  showAuth('otp');
});

ge('email-input').addEventListener('keypress', function(e){
  if(e.key==='Enter') ge('btn-send-otp').click();
});

/* ── Step 2: Verify OTP ────────────────────────────────────────────────────── */
ge('btn-verify-otp').addEventListener('click', async function(){
  var otp = (ge('otp-input').value||'').replace(/\D/g,'').trim();
  if(otp.length!==6){ showAlert('otp-alert','Please enter the 6-digit code from your email.','error'); return; }
  hideAlert('otp-alert');
  setBtn('btn-verify-otp', true, 'Verifying');

  var r = await api('POST','/client/auth/verify-otp',{email:currentEmail, otp:otp});
  setBtn('btn-verify-otp', false, 'Verify Code');

  if(!r.ok){
    showAlert('otp-alert', r.data.message||'Incorrect code. Please try again.', 'error');
    ge('otp-input').value='';
    ge('otp-input').focus();
    return;
  }

  if(otpTimer) clearInterval(otpTimer);
  loadPage();
});

ge('otp-input').addEventListener('keypress', function(e){
  if(e.key==='Enter') ge('btn-verify-otp').click();
});
ge('otp-input').addEventListener('input', function(){
  this.value = this.value.replace(/\D/g,'').slice(0,6);
});

/* ── Resend / Change email ─────────────────────────────────────────────────── */
ge('btn-resend').addEventListener('click', async function(){
  if(otpSeconds > 540){ // must wait at least 60s
    showAlert('otp-alert','Please wait a moment before requesting another code.','info');
    return;
  }
  hideAlert('otp-alert');
  this.disabled = true;
  this.textContent = 'Sending…';

  var r = await api('POST','/client/auth/request-otp',{email:currentEmail});
  this.disabled = false;
  this.textContent = 'Resend Code';

  if(r.ok){
    showAlert('otp-alert','A new code has been sent to your email.','success');
    ge('otp-input').value='';
    ge('otp-timer').className = 'otp-timer';
    ge('otp-timer').innerHTML = 'Code expires in <span id="otp-countdown">10:00</span>';
    startOtpTimer();
  } else {
    showAlert('otp-alert', r.data.message||'Could not resend. Please try again.', 'error');
  }
});

ge('btn-change-email').addEventListener('click', function(){
  if(otpTimer) clearInterval(otpTimer);
  showAuth('email');
});

/* ── Sign out ──────────────────────────────────────────────────────────────── */
ge('btn-signout').addEventListener('click', async function(){
  await api('POST','/client/auth/logout',{});
  pageData = null;
  showAuth('email');
});

/* ── Load relationship page ────────────────────────────────────────────────── */
async function loadPage(){
  showLoading();
  var r = await api('GET','/client/me');
  if(!r.ok){
    if(r.status===401){ showAuth('email'); return; }
    showAuth('email');
    return;
  }
  pageData = r.data;
  renderPage(pageData);
  showPage();
}

/* ── Load preview page (staff mode) ────────────────────────────────────────── */
async function loadPreview(){
  showLoading();
  var r = await api('GET','/clients/'+PREVIEW_CLIENT_ID+'/portal-preview');
  if(!r.ok){
    hide('screen-loading');
    var el = ge('screen-loading');
    if(el){
      el.style.display='flex';
      el.innerHTML='<p style="color:#dc2626;text-align:center;padding:32px;font-size:.9rem">⚠ Preview failed. Make sure you are logged in as staff.</p>';
    }
    return;
  }
  pageData = r.data;
  renderPage(pageData);
  showPage();
  hide('btn-signout');
  show('preview-banner');
}

/* ── Render ────────────────────────────────────────────────────────────────── */
function renderPage(d){
  var c = d.client||{};
  ge('header-user').textContent = c.name||'';

  // Welcome card
  ge('page-client-name').textContent = c.name||'';
  var contact = [];
  if(c.email) contact.push(c.email);
  if(c.phone) contact.push(c.phone);
  ge('page-client-contact').textContent = contact.join(' · ');
  ge('page-client-branch').textContent = c.branch_name ? '📍 '+c.branch_name : '';

  renderPets(d.pets||[]);
  renderBookings(d.bookings||{upcoming:[],past:[]});
  renderInvoices(d.invoices||[]);
  renderSupport(d.support||{});
}

/* ── Pets ──────────────────────────────────────────────────────────────────── */
function renderPets(pets){
  var el = ge('pets-container');
  if(!pets||!pets.length){
    el.innerHTML = '<div class="empty-state"><span class="empty-icon">🐾</span>No pets on record yet.</div>';
    return;
  }
  el.innerHTML = pets.map(function(p){
    var photoHtml = p.photo_url
      ? '<img class="pet-photo" src="'+esc(p.photo_url)+'" alt="'+esc(p.name)+'" loading="lazy">'
      : '<div class="pet-photo-placeholder">'+(p.pet_type==='Cat'?'🐱':p.pet_type==='Dog'?'🐶':'🐾')+'</div>';

    var vacc = p.vaccination_status||'Unknown';
    var vaccBadge = vacc==='Vaccinated'
      ? '<span class="badge badge-green">💉 Vaccinated</span>'
      : vacc==='Not vaccinated'
        ? '<span class="badge badge-red">Not vaccinated</span>'
        : '<span class="badge badge-gray">Vaccination unknown</span>';

    var metaParts = [];
    if(p.breed) metaParts.push(p.breed);
    if(p.gender && p.gender!=='Unknown') metaParts.push(p.gender);
    if(p.breed_size) metaParts.push(p.breed_size);
    if(p.age) metaParts.push(p.age);

    var docsHtml = '';
    if(p.documents && p.documents.length){
      var pills = p.documents.map(function(d){
        return '<span class="doc-pill">📎 '+esc(d.label||formatDocType(d.doc_type))+'</span>';
      }).join('');
      docsHtml = '<div class="pet-docs"><div class="pet-docs-title">Documents</div>'+pills+'</div>';
    }

    return '<div class="pet-card">'
      +'<div class="pet-card-header">'
      +photoHtml
      +'<div class="pet-info">'
      +'<div class="pet-name">'+esc(p.name)+'</div>'
      +(metaParts.length ? '<div class="pet-meta">'+esc(metaParts.join(' · '))+'</div>' : '')
      +'<div class="pet-badges">'+vaccBadge+'</div>'
      +'</div></div>'
      +docsHtml
      +'</div>';
  }).join('');
}

function formatDocType(t){
  var map={vaccination:'Vaccination','vaccination_card':'Vaccination Card',
    'rabies_cert':'Rabies Cert','kennel_cough_cert':'Kennel Cough',
    'medical_report':'Medical Report','pet_photo':'Photo','owner_id':'Owner ID',other:'Document'};
  return map[t]||t||'Document';
}

/* ── Bookings ──────────────────────────────────────────────────────────────── */
function showBookingTab(which){
  ge('tab-upcoming').className = 'tab'+(which==='upcoming'?' active':'');
  ge('tab-past').className = 'tab'+(which==='past'?' active':'');
  if(which==='upcoming'){ show('upcoming-bookings'); hide('past-bookings'); }
  else { hide('upcoming-bookings'); show('past-bookings'); }
}

function renderBookings(bk){
  var upcoming = bk.upcoming||[];
  var past = bk.past||[];

  var uc = ge('upcoming-count');
  var pc = ge('past-count');
  if(uc) uc.textContent = upcoming.length ? '('+upcoming.length+')' : '';
  if(pc) pc.textContent = past.length ? '('+past.length+')' : '';

  ge('upcoming-bookings').innerHTML = renderBookingList(upcoming, true);
  ge('past-bookings').innerHTML = renderBookingList(past, false);
}

function renderBookingList(list, isUpcoming){
  if(!list||!list.length){
    return '<div class="empty-state"><span class="empty-icon">'+(isUpcoming?'📭':'📁')+'</span>'
      +(isUpcoming?'No upcoming bookings.':'No past bookings on record.')+'</div>';
  }
  return list.map(function(b){
    var dateStr = b.check_in_date
      ? fmtDate(b.check_in_date)+(b.check_out_date && b.check_out_date!==b.check_in_date?' → '+fmtDate(b.check_out_date):'')
      : fmtDate(b.booking_date);
    var details = [];
    if(b.pet_names) details.push('🐾 '+b.pet_names);
    if(b.branch_name) details.push('📍 '+b.branch_name);
    if(b.payment_status) details.push(payStatusBadge(b.payment_status));

    return '<div class="booking-row">'
      +'<div class="booking-dot '+(isUpcoming?'upcoming':'past')+'"></div>'
      +'<div class="booking-body">'
      +'<div class="booking-date">'+esc(dateStr)+'</div>'
      +(details.length?'<div class="booking-detail">'+details.join(' &nbsp;·&nbsp; ')+'</div>':'')
      +'</div></div>';
  }).join('');
}

function payStatusBadge(s){
  var cls={Paid:'badge-green','Partially paid':'badge-orange',Unpaid:'badge-red',
    Overpaid:'badge-blue','No bill':'badge-gray'};
  return '<span class="badge '+(cls[s]||'badge-gray')+'">'+esc(s)+'</span>';
}

/* ── Invoices ──────────────────────────────────────────────────────────────── */
function renderInvoices(invs){
  var el = ge('invoices-container');
  if(!invs||!invs.length){
    el.innerHTML = '<div class="empty-state"><span class="empty-icon">🧾</span>No invoices on record yet.</div>';
    return;
  }
  el.innerHTML = invs.map(function(inv){
    var pdfLink = inv.pdf_url
      ? '<a href="'+esc(inv.pdf_url)+'" target="_blank" rel="noopener" class="inv-pdf-link">⬇ Download PDF</a>'
      : '';
    return '<div class="inv-row">'
      +'<div>'
      +'<div class="inv-date">'+fmtDate(inv.invoice_date)+'</div>'
      +'<div class="inv-amounts">'
      +'Total: <strong>'+fmtINR(inv.revenue)+'</strong>'
      +(parseFloat(inv.paid||0)>0?' &nbsp;·&nbsp; Paid: '+fmtINR(inv.paid):'')
      +(parseFloat(inv.due||0)>0?' &nbsp;·&nbsp; Due: <span style="color:#dc2626">'+fmtINR(inv.due)+'</span>':'')
      +'</div>'
      +'</div>'
      +'<div class="inv-right">'
      +'<div class="inv-status">'+payStatusBadge(inv.payment_status)+'</div>'
      +pdfLink
      +'</div></div>';
  }).join('');
}

/* ── Support ───────────────────────────────────────────────────────────────── */
function renderSupport(s){
  var btns = ge('support-buttons');
  var html = '';
  if(s.phone){
    var wa = s.phone.replace(/\D/g,'');
    if(wa && !wa.startsWith('91') && wa.length===10) wa='91'+wa;
    var waHref = 'https://wa.me/'+wa;
    if(s.whatsapp_message) waHref += '?text='+encodeURIComponent(s.whatsapp_message);
    html += '<a href="'+waHref+'" target="_blank" rel="noopener" class="btn btn-whatsapp btn-icon">📱 WhatsApp</a>';
  }
  if(s.email){
    var mailHref = 'mailto:'+esc(s.email);
    var mailSep = '?';
    if(s.email_subject){ mailHref += mailSep+'subject='+encodeURIComponent(s.email_subject); mailSep='&'; }
    if(s.email_body){ mailHref += mailSep+'body='+encodeURIComponent(s.email_body); }
    html += '<a href="'+mailHref+'" class="btn btn-email btn-icon">✉️ Email Us</a>';
  }
  if(!html){
    html = '<p class="text-sm text-gray">Please contact your branch directly.</p>';
  }
  btns.innerHTML = html;
}

/* ── XSS helper ────────────────────────────────────────────────────────────── */
function esc(s){
  return String(s||'')
    .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
    .replace(/"/g,'&quot;').replace(/'/g,'&#x27;');
}

/* ── Page nav scroll highlight ─────────────────────────────────────────────── */
document.querySelectorAll('#page-nav a').forEach(function(a){
  a.addEventListener('click', function(){
    document.querySelectorAll('#page-nav a').forEach(function(x){ x.classList.remove('active'); });
    a.classList.add('active');
  });
});

/* ── Boot ──────────────────────────────────────────────────────────────────── */
showLoading();
if(PREVIEW_MODE){
  loadPreview().catch(function(){
    var el = ge('screen-loading');
    if(el){
      el.style.display='flex';
      el.innerHTML='<p style="color:#dc2626;text-align:center;padding:32px;font-size:.9rem">⚠ Preview failed. Make sure you are logged in as staff.</p>';
    }
  });
} else {
  loadPage().catch(function(){ showAuth('email'); });
}

})();
</script>
</body>
</html>
HTML;
    }
}
