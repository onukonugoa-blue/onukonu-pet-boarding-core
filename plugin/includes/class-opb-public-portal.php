<?php
/**
 * OPB_Public_Portal — Serves public-facing inquiry and onboarding pages.
 *
 * Routes:
 *   /opb-inquiry/           → Public inquiry submission form
 *   /opb-onboard/{token}/   → Public onboarding form (token-gated)
 */
class OPB_Public_Portal {

    public static function register(): void {
        add_action( 'init',              [ self::class, 'add_rewrite_rules' ] );
        add_filter( 'query_vars',        [ self::class, 'add_query_vars'    ] );
        add_action( 'template_redirect', [ self::class, 'maybe_serve'       ] );
    }

    public static function add_rewrite_rules(): void {
        add_rewrite_rule( '^opb-inquiry/?$',                     'index.php?opb_inquiry=1',             'top' );
        add_rewrite_rule( '^opb-onboard/([a-f0-9]{64})/?$',     'index.php?opb_onboard=$matches[1]',   'top' );
    }

    public static function add_query_vars( array $vars ): array {
        $vars[] = 'opb_inquiry';
        $vars[] = 'opb_onboard';
        return $vars;
    }

    public static function maybe_serve(): void {
        if ( get_query_var( 'opb_inquiry' ) ) {
            self::render_inquiry_form();
            exit;
        }
        $token = get_query_var( 'opb_onboard' );
        if ( $token && preg_match( '/^[a-f0-9]{64}$/', $token ) ) {
            self::render_onboarding_form( $token );
            exit;
        }
    }

    // ── Inquiry Form ───────────────────────────────────────────────────────────

    private static function render_inquiry_form(): void {
        $facility   = esc_html( get_bloginfo( 'name' ) ?: 'Onukonu Pet Boarding' );
        $api_base   = esc_js( rest_url( 'opb/v1' ) );
        $nonce      = esc_js( wp_create_nonce( 'wp_rest' ) );
        $home_url   = esc_url( home_url( '/' ) );

        global $wpdb;
        $branches = $wpdb->get_results(
            "SELECT id, name FROM {$wpdb->prefix}opb_branches WHERE is_active=1 ORDER BY name ASC",
            ARRAY_A
        );
        $branch_options = '';
        foreach ( $branches as $b ) {
            $branch_options .= '<option value="' . (int)$b['id'] . '">' . esc_html( $b['name'] ) . '</option>';
        }

        echo <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Boarding Inquiry — {$facility}</title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#f0f4f8;min-height:100vh;color:#2d3748}
.header{background:#1e3a5f;color:#fff;padding:20px 24px;text-align:center}
.header h1{font-size:1.4rem;font-weight:700;margin-bottom:4px}
.header p{font-size:.85rem;color:#90cdf4}
.container{max-width:620px;margin:32px auto;padding:0 16px 48px}
.card{background:#fff;border-radius:10px;padding:28px;box-shadow:0 2px 8px rgba(0,0,0,.08)}
.card h2{font-size:1.1rem;color:#1e3a5f;margin-bottom:20px;border-bottom:2px solid #e2e8f0;padding-bottom:10px}
label{display:block;font-size:.8rem;font-weight:600;color:#4a5568;margin-bottom:5px}
input,select,textarea{width:100%;padding:9px 12px;border:1px solid #cbd5e0;border-radius:6px;font-size:.9rem;color:#2d3748;transition:border-color .15s}
input:focus,select:focus,textarea:focus{outline:none;border-color:#3b82f6;box-shadow:0 0 0 3px rgba(59,130,246,.15)}
.field{margin-bottom:16px}
.row{display:grid;grid-template-columns:1fr 1fr;gap:16px}
@media(max-width:480px){.row{grid-template-columns:1fr}}
textarea{resize:vertical;min-height:90px}
.btn{width:100%;padding:12px;background:#1e3a5f;color:#fff;border:none;border-radius:8px;font-size:.95rem;font-weight:600;cursor:pointer;transition:background .15s;margin-top:8px}
.btn:hover{background:#2d5a8e}
.btn:disabled{background:#94a3b8;cursor:not-allowed}
.alert{padding:12px 16px;border-radius:8px;font-size:.875rem;margin-bottom:16px;display:none}
.alert-success{background:#f0fff4;border:1px solid #9ae6b4;color:#276749}
.alert-error{background:#fff5f5;border:1px solid #fc8181;color:#c53030}
.required{color:#e53e3e}
.note{font-size:.78rem;color:#718096;margin-top:4px}
</style>
</head>
<body>
<div class="header">
  <h1>🐾 {$facility}</h1>
  <p>Submit a boarding inquiry and our team will be in touch</p>
</div>
<div class="container">
  <div class="card">
    <h2>Boarding Inquiry</h2>
    <div id="alert" class="alert"></div>
    <form id="inquiry-form" novalidate>
      <div class="row">
        <div class="field">
          <label>Your Name <span class="required">*</span></label>
          <input type="text" name="owner_name" required placeholder="Full name">
        </div>
        <div class="field">
          <label>Phone Number <span class="required">*</span></label>
          <input type="tel" name="phone" required placeholder="+91 98765 43210">
        </div>
      </div>
      <div class="field">
        <label>Email Address</label>
        <input type="email" name="email" placeholder="you@example.com">
      </div>
      <div class="field">
        <label>Preferred Branch</label>
        <select name="branch_id">
          <option value="">— Select branch (optional) —</option>
          {$branch_options}
        </select>
      </div>
      <div class="row">
        <div class="field">
          <label>Pet's Name</label>
          <input type="text" name="pet_name" placeholder="Buddy">
        </div>
        <div class="field">
          <label>Pet Type</label>
          <select name="pet_type">
            <option value="">— Select —</option>
            <option value="Dog">Dog</option>
            <option value="Cat">Cat</option>
            <option value="Other">Other</option>
          </select>
        </div>
      </div>
      <div class="row">
        <div class="field">
          <label>Desired Check-In</label>
          <input type="date" name="desired_check_in">
        </div>
        <div class="field">
          <label>Desired Check-Out</label>
          <input type="date" name="desired_check_out">
        </div>
      </div>
      <div class="field">
        <label>Message</label>
        <textarea name="message" placeholder="Tell us about your pet or any special requirements…"></textarea>
      </div>
      <button type="submit" class="btn" id="submit-btn">Submit Inquiry</button>
      <p class="note" style="text-align:center;margin-top:12px">We'll review your inquiry and send you a personalised onboarding link.</p>
    </form>
    <div id="success-message" style="display:none;text-align:center;padding:24px 0">
      <div style="font-size:3rem;margin-bottom:12px">🎉</div>
      <h3 style="color:#1e3a5f;margin-bottom:8px">Inquiry Received!</h3>
      <p style="color:#4a5568;font-size:.9rem">Thank you for reaching out. Our team will review your inquiry and contact you shortly.</p>
    </div>
  </div>
</div>
<script>
(function(){
  const API = '{$api_base}';
  const form = document.getElementById('inquiry-form');
  const alertEl = document.getElementById('alert');
  const btn = document.getElementById('submit-btn');

  function showAlert(msg, type){
    alertEl.className = 'alert alert-' + type;
    alertEl.textContent = msg;
    alertEl.style.display = 'block';
  }

  form.addEventListener('submit', async function(e){
    e.preventDefault();
    btn.disabled = true;
    btn.textContent = 'Submitting…';
    alertEl.style.display = 'none';

    const data = {};
    new FormData(form).forEach(function(v,k){ data[k] = v; });
    if (!data.owner_name || !data.phone) {
      showAlert('Please fill in your name and phone number.', 'error');
      btn.disabled = false; btn.textContent = 'Submit Inquiry'; return;
    }

    try {
      const res = await fetch(API + '/public/inquiries', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify(data)
      });
      const json = await res.json();
      if (res.ok) {
        form.style.display = 'none';
        document.getElementById('success-message').style.display = 'block';
      } else {
        showAlert(json.message || 'Something went wrong. Please try again.', 'error');
        btn.disabled = false; btn.textContent = 'Submit Inquiry';
      }
    } catch(err) {
      showAlert('Network error. Please check your connection and try again.', 'error');
      btn.disabled = false; btn.textContent = 'Submit Inquiry';
    }
  });
})();
</script>
</body>
</html>
HTML;
    }

    // ── Onboarding Form ────────────────────────────────────────────────────────

    private static function render_onboarding_form( string $token ): void {
        $facility  = esc_html( get_bloginfo( 'name' ) ?: 'Onukonu Pet Boarding' );
        $api_base  = esc_js( rest_url( 'opb/v1' ) );
        $token_js  = esc_js( $token );

        echo <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Complete Onboarding — {$facility}</title>
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#f0f4f8;min-height:100vh;color:#2d3748}
.header{background:#1e3a5f;color:#fff;padding:18px 24px}
.header h1{font-size:1.25rem;font-weight:700}
.header p{font-size:.82rem;color:#90cdf4;margin-top:3px}
.container{max-width:720px;margin:0 auto;padding:24px 16px 60px}
.card{background:#fff;border-radius:10px;padding:24px;margin-bottom:20px;box-shadow:0 2px 8px rgba(0,0,0,.07)}
h2{font-size:1rem;font-weight:700;color:#1e3a5f;margin-bottom:16px;display:flex;align-items:center;gap:8px}
h3{font-size:.9rem;font-weight:600;color:#2d3748;margin:16px 0 10px}
label{display:block;font-size:.78rem;font-weight:600;color:#4a5568;margin-bottom:4px}
input,select,textarea{width:100%;padding:8px 11px;border:1px solid #cbd5e0;border-radius:6px;font-size:.875rem;color:#2d3748;transition:border-color .15s}
input:focus,select:focus,textarea:focus{outline:none;border-color:#3b82f6;box-shadow:0 0 0 3px rgba(59,130,246,.12)}
.field{margin-bottom:14px}
.row{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.row3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px}
@media(max-width:560px){.row,.row3{grid-template-columns:1fr}}
textarea{resize:vertical;min-height:70px}
.btn{padding:10px 20px;border:none;border-radius:7px;font-size:.875rem;font-weight:600;cursor:pointer;transition:background .15s}
.btn-primary{background:#1e3a5f;color:#fff}
.btn-primary:hover{background:#2d5a8e}
.btn-primary:disabled{background:#94a3b8;cursor:not-allowed}
.btn-secondary{background:#e2e8f0;color:#4a5568}
.btn-secondary:hover{background:#cbd5e0}
.btn-danger{background:#fff0f0;color:#c53030;border:1px solid #fc8181}
.btn-danger:hover{background:#fff5f5}
.btn-sm{padding:6px 12px;font-size:.78rem}
.step-indicator{display:flex;gap:8px;margin-bottom:24px;flex-wrap:wrap}
.step{flex:1;min-width:60px;text-align:center;padding:8px 4px;border-radius:8px;font-size:.75rem;font-weight:600;border:2px solid #e2e8f0;color:#a0aec0;transition:all .2s;cursor:default}
.step.active{border-color:#1e3a5f;background:#ebf4ff;color:#1e3a5f}
.step.done{border-color:#48bb78;background:#f0fff4;color:#276749}
.alert{padding:12px 16px;border-radius:8px;font-size:.85rem;margin-bottom:16px}
.alert-success{background:#f0fff4;border:1px solid #9ae6b4;color:#276749}
.alert-error{background:#fff5f5;border:1px solid #fc8181;color:#c53030}
.alert-warning{background:#fffaf0;border:1px solid #f6ad55;color:#c05621}
.alert-info{background:#ebf8ff;border:1px solid #90cdf4;color:#2b6cb0}
.pet-block{border:1px solid #e2e8f0;border-radius:8px;padding:16px;margin-bottom:16px;background:#fafbfc}
.pet-block-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:12px}
.doc-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:12px;margin-top:12px}
.doc-item{border:1px solid #e2e8f0;border-radius:8px;padding:10px;background:#fafbfc;text-align:center;font-size:.75rem}
.doc-item img{width:100%;height:80px;object-fit:cover;border-radius:5px;margin-bottom:6px}
.doc-item .doc-label{color:#4a5568;font-weight:500;word-break:break-word}
.upload-area{border:2px dashed #cbd5e0;border-radius:8px;padding:20px;text-align:center;cursor:pointer;transition:border-color .15s;margin-top:8px}
.upload-area:hover{border-color:#3b82f6}
.upload-area input{display:none}
.tc-block{background:#f7fafc;border:1px solid #e2e8f0;border-radius:8px;padding:16px;max-height:240px;overflow-y:auto;font-size:.8rem;line-height:1.6;color:#4a5568;margin-bottom:16px}
.tc-block h4{font-size:.85rem;font-weight:700;color:#2d3748;margin-bottom:8px}
.tc-block p{margin-bottom:8px}
.check-row{display:flex;align-items:flex-start;gap:10px;margin-bottom:16px}
.check-row input[type=checkbox]{margin-top:2px;width:16px;height:16px;flex-shrink:0}
.check-row label{font-size:.85rem;color:#2d3748;font-weight:500;margin:0}
.nav-row{display:flex;justify-content:space-between;align-items:center;margin-top:20px;gap:12px}
.spinner{display:inline-block;width:14px;height:14px;border:2px solid rgba(255,255,255,.3);border-top-color:#fff;border-radius:50%;animation:spin .7s linear infinite;vertical-align:middle;margin-right:6px}
@keyframes spin{to{transform:rotate(360deg)}}
#loading-screen{text-align:center;padding:60px 0;color:#718096}
#error-screen{display:none}
#app{display:none}
.badge{display:inline-block;padding:2px 8px;border-radius:12px;font-size:.72rem;font-weight:600}
.badge-blue{background:#ebf4ff;color:#2b6cb0}
.required{color:#e53e3e}
</style>
</head>
<body>
<div class="header">
  <h1>🐾 {$facility}</h1>
  <p id="header-sub">Loading your onboarding form…</p>
</div>
<div class="container">
  <div id="loading-screen"><div style="font-size:2rem;margin-bottom:12px">⏳</div>Loading your onboarding form…</div>
  <div id="error-screen" class="card"><div class="alert alert-error" id="error-msg"></div></div>
  <div id="app">
    <!-- Step indicator -->
    <div class="step-indicator">
      <div class="step active" id="step-ind-1">1 · Your Info</div>
      <div class="step" id="step-ind-2">2 · Your Pet(s)</div>
      <div class="step" id="step-ind-3">3 · Documents</div>
      <div class="step" id="step-ind-4">4 · Terms</div>
    </div>

    <!-- Step 1: Client Info -->
    <div id="step-1">
      <div class="card">
        <h2>👤 Your Information</h2>
        <div id="s1-alert"></div>
        <div class="row">
          <div class="field"><label>Full Name <span class="required">*</span></label><input id="c-name" placeholder="Full name"></div>
          <div class="field"><label>Phone <span class="required">*</span></label><input id="c-phone" type="tel" placeholder="+91 98765 43210"></div>
        </div>
        <div class="field"><label>Email</label><input id="c-email" type="email" placeholder="you@example.com"></div>
        <div class="field"><label>Home Address</label><textarea id="c-address" placeholder="Street, City, State, PIN"></textarea></div>
        <h3>Local Guardian (if applicable)</h3>
        <div class="row">
          <div class="field"><label>Guardian Name</label><input id="c-lg-name" placeholder="Name"></div>
          <div class="field"><label>Guardian Contact</label><input id="c-lg-contact" type="tel" placeholder="Phone"></div>
        </div>
        <h3>Emergency Contact</h3>
        <div class="row">
          <div class="field"><label>Contact Name</label><input id="c-ec-name" placeholder="Name"></div>
          <div class="field"><label>Contact Phone</label><input id="c-ec-phone" type="tel" placeholder="Phone"></div>
        </div>
        <div class="field"><label>Additional Notes</label><textarea id="c-notes" placeholder="Any other information for our team…"></textarea></div>
        <div class="nav-row"><span></span><button class="btn btn-primary" id="s1-next">Next: Your Pet(s) →</button></div>
      </div>
    </div>

    <!-- Step 2: Pets -->
    <div id="step-2" style="display:none">
      <div class="card">
        <h2>🐾 Pet Information</h2>
        <div id="s2-alert"></div>
        <div id="pets-container"></div>
        <button class="btn btn-secondary" id="add-pet-btn" style="width:100%;margin-top:4px">+ Add Another Pet</button>
        <div class="nav-row">
          <button class="btn btn-secondary" id="s2-back">← Back</button>
          <button class="btn btn-primary" id="s2-next">Next: Documents →</button>
        </div>
      </div>
    </div>

    <!-- Step 3: Documents -->
    <div id="step-3" style="display:none">
      <div class="card">
        <h2>📎 Document Uploads</h2>
        <p style="font-size:.85rem;color:#4a5568;margin-bottom:16px">Upload any relevant documents. All are optional but help speed up your onboarding.</p>
        <div id="s3-alert"></div>
        <div id="doc-pet-sections"></div>
        <div class="nav-row">
          <button class="btn btn-secondary" id="s3-back">← Back</button>
          <button class="btn btn-primary" id="s3-next">Next: Review Terms →</button>
        </div>
      </div>
    </div>

    <!-- Step 4: T&C -->
    <div id="step-4" style="display:none">
      <div class="card">
        <h2>📜 Boarding Agreement</h2>
        <div id="s4-alert"></div>
        <div class="tc-block">
          <h4>Boarding Terms &amp; Conditions</h4>
          <p><strong>1. Health &amp; Vaccination:</strong> All pets must be up-to-date on core vaccinations (Anti-Rabies, DHPPiL, Kennel Cough) before boarding. {$facility} reserves the right to refuse boarding to pets that do not meet vaccination requirements.</p>
          <p><strong>2. Health Disclosure:</strong> The owner agrees to disclose all known medical conditions, ongoing medications, dietary restrictions, and behavioural issues. {$facility} will not be held liable for undisclosed conditions.</p>
          <p><strong>3. Emergency Medical Treatment:</strong> In the event of a medical emergency, {$facility} will attempt to contact the owner and/or emergency contact immediately. If the owner cannot be reached, {$facility} may authorise necessary veterinary treatment at the owner's expense.</p>
          <p><strong>4. Liability:</strong> {$facility} exercises all reasonable care for boarded pets but is not liable for injury, illness, or death resulting from pre-existing conditions, acts of other animals, or circumstances beyond our control.</p>
          <p><strong>5. Behaviour:</strong> Pets that display aggressive behaviour posing a risk to staff or other animals may be isolated or returned to the owner. No refund will be issued in such cases.</p>
          <p><strong>6. Pick-Up:</strong> Pets not collected within 24 hours after the agreed check-out date without prior notice may incur additional charges. {$facility} reserves the right to seek animal control assistance after 72 hours of no contact.</p>
          <p><strong>7. Photography &amp; Media:</strong> With consent, {$facility} may photograph or video your pet for social media and marketing purposes. Consent can be withdrawn at any time.</p>
          <p><strong>8. Payment:</strong> Full payment is due at or before pick-up unless a prior arrangement has been made. Overdue balances may attract late fees.</p>
          <p><strong>9. Changes:</strong> {$facility} reserves the right to update these terms. Continued use of boarding services constitutes acceptance of current terms.</p>
        </div>
        <div class="check-row">
          <input type="checkbox" id="tc-checkbox">
          <label for="tc-checkbox">I have read and agree to the boarding terms and conditions above. I confirm that all information provided is accurate and complete.</label>
        </div>
        <div id="tc-accepted-notice" style="display:none" class="alert alert-success">✓ Terms accepted. Thank you!</div>
        <div class="nav-row">
          <button class="btn btn-secondary" id="s4-back">← Back</button>
          <button class="btn btn-primary" id="s4-submit" disabled>Submit Onboarding</button>
        </div>
      </div>
    </div>

    <!-- Complete -->
    <div id="complete-screen" style="display:none">
      <div class="card" style="text-align:center;padding:40px 24px">
        <div style="font-size:3.5rem;margin-bottom:16px">🎉</div>
        <h2 style="justify-content:center;color:#276749;font-size:1.2rem;margin-bottom:8px">Onboarding Complete!</h2>
        <p style="color:#4a5568;line-height:1.7">Thank you for completing your onboarding with <strong>{$facility}</strong>.<br>Our team will review your information and contact you to confirm your booking details.</p>
      </div>
    </div>
  </div>
</div>

<script>
(function(){
  const API   = '{$api_base}';
  const TOKEN = '{$token_js}';
  let inquiry = {}, existingPets = [], existingDocs = [], tcAccepted = false;
  let petCounter = 0;

  async function loadData(){
    const apiUrl = API + '/public/onboarding/' + TOKEN;
    console.log('[OPB] STEP 1 — token (last 8):', TOKEN.slice(-8));
    console.log('[OPB] STEP 2 — API URL:', apiUrl);

    // NOTE: AbortController must stay live through body reading, not just headers.
    // Bug fix: do NOT clearTimeout until after res.json() resolves.
    const ctrl    = new AbortController();
    const timeout = setTimeout(function(){
      console.warn('[OPB] STEP 3 — TIMEOUT after 20 s, aborting');
      ctrl.abort();
    }, 20000);

    try {
      let res;
      console.log('[OPB] STEP 3 — fetch starting…');
      try {
        res = await fetch(apiUrl, { signal: ctrl.signal });
      } catch(fetchErr) {
        clearTimeout(timeout);
        console.error('[OPB] STEP 3 FAIL — fetch threw:', fetchErr.name, fetchErr.message);
        if (fetchErr && fetchErr.name === 'AbortError') {
          showErrorScreen('The request timed out. Please check your connection and refresh the page.');
        } else {
          showErrorScreen('Network error. Please check your connection and refresh the page.');
        }
        return;
      }

      console.log('[OPB] STEP 4 — headers received. HTTP status:', res.status, res.statusText);
      console.log('[OPB] STEP 4 — X-OPB-Step header:', res.headers.get('X-OPB-Step'));
      console.log('[OPB] STEP 4 — Content-Type:', res.headers.get('Content-Type'));

      let rawText;
      try {
        console.log('[OPB] STEP 5 — reading body (res.text())…');
        rawText = await res.text();
        clearTimeout(timeout);
      } catch(bodyErr) {
        clearTimeout(timeout);
        console.error('[OPB] STEP 5 FAIL — body read threw:', bodyErr.name, bodyErr.message);
        showErrorScreen('Failed to read server response. Please refresh the page.');
        return;
      }

      console.log('[OPB] STEP 5 OK — body length:', rawText.length, 'chars');
      console.log('[OPB] STEP 5 — raw body (first 500 chars):', rawText.slice(0, 500));

      let json;
      try {
        json = JSON.parse(rawText);
      } catch(parseErr) {
        console.error('[OPB] STEP 6 FAIL — JSON.parse threw:', parseErr.message);
        console.error('[OPB] STEP 6 — full raw body:', rawText);
        showErrorScreen('Server returned an unexpected response. Check the browser console for details.');
        return;
      }

      console.log('[OPB] STEP 6 OK — parsed JSON keys:', Object.keys(json));

      if (!res.ok) {
        console.error('[OPB] STEP 7 FAIL — HTTP', res.status, '— error code:', json.code, '— message:', json.message);
        showErrorScreen(json.message || 'Link not found or expired.');
        return;
      }

      console.log('[OPB] STEP 7 OK — success response. inquiry.owner_name:', json.inquiry && json.inquiry.owner_name);

      inquiry      = json.inquiry   || {};
      existingPets = json.pets      || [];
      existingDocs = json.documents || [];
      tcAccepted   = !!(json.client && json.client.tc_accepted);

      console.log('[OPB] STEP 8 — hiding loading, showing app');
      document.getElementById('header-sub').textContent = 'Welcome, ' + (inquiry.owner_name || '') + '!';
      document.getElementById('loading-screen').style.display = 'none';
      document.getElementById('app').style.display = 'block';

      console.log('[OPB] STEP 9 — prefilling client, building pets/docs');
      prefillClient(json.client);
      buildPets(existingPets);
      buildDocSections();

      if (tcAccepted) {
        document.getElementById('tc-checkbox').checked = true;
        document.getElementById('tc-accepted-notice').style.display = 'block';
        document.getElementById('s4-submit').disabled = false;
        updateStepIndicator(4);
      }
      console.log('[OPB] STEP 9 DONE — page fully loaded');
    } catch(e) {
      clearTimeout(timeout);
      console.error('[OPB] OUTER CATCH — unexpected error:', e.name, e.message, e.stack);
      showErrorScreen('Network error. Please refresh the page.');
    }
  }

  function showErrorScreen(msg){
    document.getElementById('loading-screen').style.display = 'none';
    document.getElementById('error-screen').style.display = 'block';
    document.getElementById('error-msg').textContent = msg;
  }

  function prefillClient(c){
    if (!c && !inquiry) return;
    const src = c || {};
    setVal('c-name',       src.name        || inquiry.owner_name || '');
    setVal('c-phone',      src.phone       || inquiry.phone      || '');
    setVal('c-email',      src.email       || inquiry.email      || '');
    setVal('c-address',    src.address     || '');
    setVal('c-lg-name',    src.local_guardian_name    || '');
    setVal('c-lg-contact', src.local_guardian_contact || '');
    setVal('c-ec-name',    src.emergency_contact_name  || '');
    setVal('c-ec-phone',   src.emergency_contact_phone || '');
    setVal('c-notes',      src.notes || '');
  }

  function setVal(id, v){ const el = document.getElementById(id); if(el) el.value = v || ''; }
  function getVal(id){ const el = document.getElementById(id); return el ? el.value.trim() : ''; }

  // ── Pets ──────────────────────────────────────────────────────────────────
  function buildPets(pets){
    const container = document.getElementById('pets-container');
    container.innerHTML = '';
    if (pets && pets.length > 0) {
      pets.forEach(function(p){ addPetBlock(container, p); });
    } else {
      addPetBlock(container, { name: inquiry.pet_name || '', pet_type: inquiry.pet_type || '' });
    }
  }

  function addPetBlock(container, data){
    petCounter++;
    const idx = petCounter;
    const div = document.createElement('div');
    div.className = 'pet-block';
    div.dataset.petId = data.id || '';
    div.dataset.idx = idx;
    div.innerHTML = petBlockHTML(idx, data);
    container.appendChild(div);
    // Toggle neutered field visibility
    div.querySelector('[data-field="neutered_or_spayed"]').addEventListener('change', function(){
      const heatRow = div.querySelector('.heat-row');
      if (heatRow) heatRow.style.display = (this.value === '0') ? 'grid' : 'none';
    });
    div.querySelector('.remove-pet').addEventListener('click', function(){
      if (container.querySelectorAll('.pet-block').length > 1) div.remove();
    });
  }

  function petBlockHTML(idx, p){
    p = p || {};
    const sel = function(val, options){ return options.map(function(o){ return '<option value="'+o+'"'+(o===val?' selected':'')+'>'+o+'</option>'; }).join(''); };
    const chk = function(v){ return v == 1 ? ' checked' : ''; };
    const petTypes = ['Dog','Cat','Other'];
    const genders  = ['Male','Female','Unknown'];
    const breedSizes = ['Toy','Small','Medium','Large','X-Large'];
    const vacStatuses = ['Vaccinated','Not vaccinated','Unknown'];
    const dietOpts = ['Veg','Non-Veg','Home Food','Commercial Dry','Commercial Wet','Raw','Other'];
    return \`
      <div class="pet-block-header">
        <strong>Pet #\${idx}</strong>
        <button type="button" class="btn btn-danger btn-sm remove-pet">Remove</button>
      </div>
      <div class="row">
        <div class="field"><label>Pet Name <span class="required">*</span></label><input data-field="name" value="\${esc(p.name||'')}"></div>
        <div class="field"><label>Type</label><select data-field="pet_type"><option value="">— Select —</option>\${sel(p.pet_type,petTypes)}</select></div>
      </div>
      <div class="row3">
        <div class="field"><label>Breed</label><input data-field="breed" value="\${esc(p.breed||'')}"></div>
        <div class="field"><label>Breed Size</label><select data-field="breed_size"><option value="">— Select —</option>\${sel(p.breed_size,breedSizes)}</select></div>
        <div class="field"><label>Gender</label><select data-field="gender"><option value="">— Select —</option>\${sel(p.gender,genders)}</select></div>
      </div>
      <div class="row3">
        <div class="field"><label>Weight (kg)</label><input data-field="weight_kg" type="number" step="0.1" value="\${esc(p.weight_kg||'')}"></div>
        <div class="field"><label>Birthday</label><input data-field="birthday" type="date" value="\${esc(p.birthday||'')}"></div>
        <div class="field"><label>Coat</label><input data-field="coat" value="\${esc(p.coat||'')}"></div>
      </div>
      <div class="row">
        <div class="field"><label>Neutered / Spayed</label>
          <select data-field="neutered_or_spayed">
            <option value="">— Unknown —</option>
            <option value="1"\${p.neutered_or_spayed==1?' selected':''}>Yes</option>
            <option value="0"\${p.neutered_or_spayed==0&&p.neutered_or_spayed!==null?' selected':''}>No</option>
          </select>
        </div>
        <div class="field"><label>Microchip Number</label><input data-field="microchip_number" value="\${esc(p.microchip_number||'')}"></div>
      </div>
      <div class="row heat-row" style="display:\${p.neutered_or_spayed==0?'grid':'none'}">
        <div class="field"><label>Last Heat Month</label><input data-field="last_heat_month" type="number" min="1" max="12" value="\${esc(p.last_heat_month||'')}"></div>
        <div class="field"><label>Last Heat Year</label><input data-field="last_heat_year" type="number" min="2000" value="\${esc(p.last_heat_year||'')}"></div>
      </div>
      <h3>Vaccination &amp; Medical</h3>
      <div class="field"><label>Vaccination Status</label>
        <select data-field="vaccination_status"><option value="">— Unknown —</option>\${sel(p.vaccination_status,vacStatuses)}</select>
      </div>
      <div class="row">
        <div class="field"><label>Anti-Rabies Date</label><input data-field="anti_rabies_date" type="date" value="\${esc(p.anti_rabies_date||'')}"></div>
        <div class="field"><label>DHPPiL Date</label><input data-field="dhppil_date" type="date" value="\${esc(p.dhppil_date||'')}"></div>
      </div>
      <div class="row">
        <div class="field"><label>Kennel Cough Date</label><input data-field="kennel_cough_date" type="date" value="\${esc(p.kennel_cough_date||'')}"></div>
        <div class="field"><label>Deworming Date</label><input data-field="deworming_date" type="date" value="\${esc(p.deworming_date||'')}"></div>
      </div>
      <div class="row">
        <div class="field"><label>Vet Name</label><input data-field="vet_name" value="\${esc(p.vet_name||'')}"></div>
        <div class="field"><label>Vet Contact</label><input data-field="vet_contact" type="tel" value="\${esc(p.vet_contact||'')}"></div>
      </div>
      <div class="field"><label>Ongoing Medication?</label>
        <select data-field="ongoing_medication">
          <option value="0"\${!p.ongoing_medication?' selected':''}>No</option>
          <option value="1"\${p.ongoing_medication?' selected':''}>Yes</option>
        </select>
      </div>
      <div class="field"><label>Medication Details</label><textarea data-field="medication_detail">\${esc(p.medication_detail||'')}</textarea></div>
      <div class="field"><label>Major Illness History</label><textarea data-field="major_illness_history">\${esc(p.major_illness_history||'')}</textarea></div>
      <h3>Diet &amp; Care</h3>
      <div class="field"><label>Dietary Preference</label>
        <select data-field="dietary_preference"><option value="">— Select —</option>\${sel(p.dietary_preference,dietOpts)}</select>
      </div>
      <div class="field"><label>Allergies / Preferences</label><textarea data-field="preferences_or_allergies">\${esc(p.preferences_or_allergies||'')}</textarea></div>
      <div class="row3">
        <div class="field"><label>1st Walk Schedule</label><input data-field="first_walk_schedule" placeholder="e.g. 7:00 AM" value="\${esc(p.first_walk_schedule||'')}"></div>
        <div class="field"><label>2nd Walk Schedule</label><input data-field="second_walk_schedule" placeholder="e.g. 1:00 PM" value="\${esc(p.second_walk_schedule||'')}"></div>
        <div class="field"><label>3rd Walk Schedule</label><input data-field="third_walk_schedule" placeholder="e.g. 7:00 PM" value="\${esc(p.third_walk_schedule||'')}"></div>
      </div>
      <div class="field"><label>Additional Notes</label><textarea data-field="additional_notes">\${esc(p.additional_notes||'')}</textarea></div>
      <div class="check-row"><input type="checkbox" data-field="consent_photos"\${chk(p.consent_photos)}><label>I consent to photos/videos of this pet being shared on social media</label></div>
    \`;
  }

  function esc(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

  function collectPets(){
    const pets = [];
    document.querySelectorAll('.pet-block').forEach(function(block){
      const p = { id: block.dataset.petId || null };
      block.querySelectorAll('[data-field]').forEach(function(el){
        const f = el.dataset.field;
        if (el.type === 'checkbox') p[f] = el.checked ? 1 : 0;
        else p[f] = el.value;
      });
      pets.push(p);
    });
    return pets;
  }

  // ── Doc sections ──────────────────────────────────────────────────────────
  function buildDocSections(){
    const container = document.getElementById('doc-pet-sections');
    container.innerHTML = '';
    const pets = document.querySelectorAll('.pet-block');
    pets.forEach(function(block, i){
      const nameEl = block.querySelector('[data-field="name"]');
      const petName = nameEl ? (nameEl.value || ('Pet ' + (i+1))) : ('Pet ' + (i+1));
      const petId   = block.dataset.petId || '';
      const section = document.createElement('div');
      section.style.marginBottom = '20px';
      section.innerHTML = buildDocSection(petName, petId, i);
      container.appendChild(section);
      section.querySelector('.upload-input').addEventListener('change', function(e){
        handleUpload(e.target.files[0], block.dataset.petId, section.querySelector('.doc-grid'), section.querySelector('[data-doc-type]').value);
        this.value = '';
      });
    });

    // General (no pet)
    const gen = document.createElement('div');
    gen.style.marginBottom = '20px';
    gen.innerHTML = buildDocSection('General Documents', '', -1);
    container.appendChild(gen);
    gen.querySelector('.upload-input').addEventListener('change', function(e){
      handleUpload(e.target.files[0], '', gen.querySelector('.doc-grid'), gen.querySelector('[data-doc-type]').value);
      this.value = '';
    });

    // Show existing docs
    existingDocs.forEach(function(doc){
      const targetGrid = doc.onboarding_pet_id
        ? container.querySelector('[data-pet-id="' + doc.onboarding_pet_id + '"] .doc-grid')
        : container.querySelectorAll('.doc-grid')[container.querySelectorAll('.doc-grid').length - 1];
      if (targetGrid) appendDocItem(targetGrid, doc);
    });
  }

  function buildDocSection(petName, petId, idx){
    const types = [
      {v:'owner_id',l:'Owner ID'},
      {v:'vaccination_card',l:'Vaccination Card'},
      {v:'rabies_cert',l:'Anti-Rabies Certificate'},
      {v:'kennel_cough_cert',l:'Kennel Cough Certificate'},
      {v:'medical_report',l:'Medical Report'},
      {v:'pet_photo',l:'Pet Photo'},
      {v:'other',l:'Other'},
    ];
    const opts = types.map(function(t){ return '<option value="'+t.v+'">'+t.l+'</option>'; }).join('');
    return \`<h3>\${esc(petName)}</h3>
      <div data-pet-id="\${esc(petId)}">
        <div class="row" style="margin-bottom:8px">
          <div class="field"><label>Document Type</label><select data-doc-type>\${opts}</select></div>
          <div class="field"><label>Label (optional)</label><input class="doc-label-input" placeholder="e.g. Rabies 2024"></div>
        </div>
        <div class="upload-area" onclick="this.querySelector('.upload-input').click()">
          <input type="file" class="upload-input" accept="image/*,application/pdf">
          <div>📎 Click to upload (JPG, PNG, PDF · max 10MB)</div>
        </div>
        <div class="doc-grid"></div>
      </div>\`;
  }

  function appendDocItem(grid, doc){
    const div = document.createElement('div');
    div.className = 'doc-item';
    const isImg = doc.file_mime && doc.file_mime.startsWith('image/');
    div.innerHTML = (isImg ? '<img src="'+doc.file_url+'" alt="doc">' : '<div style="font-size:2rem;padding:16px">📄</div>')
      + '<div class="doc-label">' + esc(doc.label || doc.doc_type) + '</div>';
    grid.appendChild(div);
  }

  async function handleUpload(file, petId, grid, docType){
    if (!file) return;
    const section = grid.closest('[data-pet-id]');
    const labelEl = section ? section.querySelector('.doc-label-input') : null;
    const label   = labelEl ? labelEl.value.trim() : '';
    const fd = new FormData();
    fd.append('file', file);
    fd.append('doc_type', docType || 'other');
    fd.append('label', label);
    if (petId) fd.append('onboarding_pet_id', petId);

    const notice = document.createElement('div');
    notice.className = 'alert alert-info'; notice.textContent = 'Uploading…';
    grid.before(notice);

    const res = await fetch(API + '/public/onboarding/' + TOKEN + '/documents', { method:'POST', body:fd });
    const json = await res.json();
    notice.remove();
    if (res.ok) {
      appendDocItem(grid, json);
      existingDocs.push(json);
      if (labelEl) labelEl.value = '';
    } else {
      showAlert(section || grid.parentNode, json.message || 'Upload failed.', 'error');
    }
  }

  // ── Navigation ─────────────────────────────────────────────────────────────
  function showAlert(container, msg, type){
    let el = container.querySelector('.step-alert');
    if (!el){ el = document.createElement('div'); el.className = 'step-alert alert'; container.prepend(el); }
    el.className = 'step-alert alert alert-' + type;
    el.textContent = msg;
  }

  function updateStepIndicator(active){
    [1,2,3,4].forEach(function(n){
      const el = document.getElementById('step-ind-' + n);
      el.className = 'step' + (n === active ? ' active' : (n < active ? ' done' : ''));
    });
  }

  function goStep(from, to){
    document.getElementById('step-' + from).style.display = 'none';
    document.getElementById('step-' + to).style.display = 'block';
    updateStepIndicator(to);
    window.scrollTo(0,0);
    if (to === 3) buildDocSections();
  }

  document.getElementById('s1-next').addEventListener('click', function(){
    if (!getVal('c-name') || !getVal('c-phone')) {
      showAlert(document.getElementById('step-1'), 'Please enter your name and phone number.', 'error');
      return;
    }
    goStep(1,2);
  });

  document.getElementById('s2-back').addEventListener('click', function(){ goStep(2,1); });
  document.getElementById('s2-next').addEventListener('click', function(){
    const pets = document.querySelectorAll('.pet-block');
    let valid = true;
    pets.forEach(function(b){ if (!b.querySelector('[data-field="name"]').value.trim()) valid = false; });
    if (!valid){ showAlert(document.getElementById('step-2'), 'Please enter a name for each pet.', 'error'); return; }
    goStep(2,3);
  });

  document.getElementById('s3-back').addEventListener('click', function(){ goStep(3,2); });
  document.getElementById('s3-next').addEventListener('click', function(){ goStep(3,4); });
  document.getElementById('s4-back').addEventListener('click', function(){ goStep(4,3); });

  document.getElementById('add-pet-btn').addEventListener('click', function(){
    addPetBlock(document.getElementById('pets-container'), {});
  });

  document.getElementById('tc-checkbox').addEventListener('change', function(){
    document.getElementById('s4-submit').disabled = !this.checked;
  });

  document.getElementById('s4-submit').addEventListener('click', async function(){
    const btn = this;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span>Submitting…';

    const clientData = {
      name:                   getVal('c-name'),
      phone:                  getVal('c-phone'),
      email:                  getVal('c-email'),
      address:                getVal('c-address'),
      local_guardian_name:    getVal('c-lg-name'),
      local_guardian_contact: getVal('c-lg-contact'),
      emergency_contact_name: getVal('c-ec-name'),
      emergency_contact_phone:getVal('c-ec-phone'),
      notes:                  getVal('c-notes'),
      pets:                   collectPets(),
    };

    try {
      const res  = await fetch(API + '/public/onboarding/' + TOKEN + '/submit', {
        method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(clientData)
      });
      const json = await res.json();
      if (!res.ok){ showAlert(document.getElementById('step-4'), json.message || 'Error saving. Please try again.', 'error'); btn.disabled=false; btn.textContent='Submit Onboarding'; return; }

      // Accept T&C
      const tcRes = await fetch(API + '/public/onboarding/' + TOKEN + '/accept-terms', {
        method:'POST', headers:{'Content-Type':'application/json'}, body:'{}'
      });
      if (!tcRes.ok){ showAlert(document.getElementById('step-4'), 'Error recording terms. Please try again.', 'error'); btn.disabled=false; btn.textContent='Submit Onboarding'; return; }

      document.getElementById('step-4').style.display = 'none';
      document.getElementById('complete-screen').style.display = 'block';
      document.getElementById('header-sub').textContent = 'Onboarding complete!';
      window.scrollTo(0,0);
    } catch(e){
      showAlert(document.getElementById('step-4'), 'Network error. Please check your connection.', 'error');
      btn.disabled = false; btn.textContent = 'Submit Onboarding';
    }
  });

  loadData();
})();
</script>
</body>
</html>
HTML;
    }
}
