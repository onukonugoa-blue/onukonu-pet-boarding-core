<?php
/**
 * OPB_Login_Branding
 *
 * Applies premium visual polish to the WordPress login page.
 *
 * Principle: WordPress owns layout, dimensions, positioning and responsiveness.
 * OPB owns colour, shadow, typography and focus states only.
 *
 * Hooks used:
 *   login_enqueue_scripts  — enqueue our inline CSS
 *   login_headerurl        — link logo back to home
 *   login_headertext       — accessible logo alt text
 *
 * Prohibited CSS (enforced by comment in css() method):
 *   width, height, min-* / max-*, position, top/right/bottom/left,
 *   transform, flex, grid, display overrides, viewport units, centering logic.
 */
class OPB_Login_Branding {

    public static function register(): void {
        add_action( 'login_enqueue_scripts', [ self::class, 'enqueue' ] );
        add_filter( 'login_headerurl',       [ self::class, 'header_url'  ] );
        add_filter( 'login_headertext',      [ self::class, 'header_text' ] );
    }

    // ── Hooks ─────────────────────────────────────────────────────────────────

    public static function enqueue(): void {
        $logo_url = OPB_PLUGIN_URL . 'assets/branding/login-logo.png';
        $css      = self::css( $logo_url );
        wp_add_inline_style( 'login', $css );
    }

    public static function header_url(): string {
        return home_url( '/' );
    }

    public static function header_text(): string {
        return get_bloginfo( 'name' );
    }

    // ── CSS ───────────────────────────────────────────────────────────────────

    /**
     * Returns the complete branding CSS string.
     *
     * Every rule here touches ONLY:
     *   background / background-image / color / border-color / border-radius /
     *   box-shadow / font-family / font-size / font-weight / text-decoration /
     *   opacity / transition / outline / cursor
     *
     * Nothing that affects geometry is touched.
     */
    private static function css( string $logo_url ): string {
        $logo = esc_url( $logo_url );

        return "
/* ── OPB Login Branding ─────────────────────────────────────────────────── */
/* Scope: colour, shadow, typography, focus only. No geometry.               */

/* Page background — soft navy tint, no scrolling or sizing impact */
body.login {
    background-color: #f0f3f7;
    background-image: radial-gradient(ellipse at 60% 0%, #dce6f0 0%, #f0f3f7 60%);
}

/* Logo — swap in branded asset; let WordPress control all sizing */
#login h1 a,
.login h1 a {
    background-image:    url('{$logo}');
    background-size:     contain;
    background-repeat:   no-repeat;
    background-position: center center;
    opacity:             1;
}

/* Login card — subtle elevation, softer border */
#loginform,
#lostpasswordform,
#registerform,
#setupform,
.login form {
    background-color: #ffffff;
    border-color:     #dce3ec;
    border-radius:    6px;
    box-shadow:       0 1px 3px rgba(0, 0, 0, 0.08),
                      0 4px 16px rgba(26, 54, 93, 0.06);
}

/* Labels — slightly refined */
.login label {
    color:       #374151;
    font-weight: 500;
}

/* Input fields — visual polish only */
.login input[type='text'],
.login input[type='password'],
.login input[type='email'],
.login input[type='number'],
.login input[type='tel'] {
    background-color: #f8fafc;
    border-color:     #c8d4e0;
    border-radius:    4px;
    color:            #1e293b;
    box-shadow:       inset 0 1px 2px rgba(0, 0, 0, 0.04);
    transition:       border-color 0.15s ease, box-shadow 0.15s ease;
}

/* Input focus — navy ring, no outline flash */
.login input[type='text']:focus,
.login input[type='password']:focus,
.login input[type='email']:focus,
.login input[type='number']:focus,
.login input[type='tel']:focus {
    border-color: #1a365d;
    box-shadow:   inset 0 1px 2px rgba(0, 0, 0, 0.04),
                  0 0 0 3px rgba(26, 54, 93, 0.12);
    outline:      none;
}

/* Primary button — brand colour */
.login .button-primary,
#wp-submit {
    background-color: #1a365d;
    border-color:     #152d4f;
    color:            #ffffff;
    box-shadow:       0 1px 2px rgba(0, 0, 0, 0.12);
    transition:       background-color 0.15s ease, box-shadow 0.15s ease;
    text-decoration:  none;
}

.login .button-primary:hover,
#wp-submit:hover {
    background-color: #1e3f6e;
    border-color:     #1a365d;
    color:            #ffffff;
    box-shadow:       0 2px 6px rgba(26, 54, 93, 0.22);
}

.login .button-primary:focus,
#wp-submit:focus {
    background-color: #1a365d;
    border-color:     #152d4f;
    color:            #ffffff;
    box-shadow:       0 0 0 3px rgba(26, 54, 93, 0.25);
    outline:          none;
}

/* Secondary button */
.login .button-secondary {
    border-color:  #c8d4e0;
    color:         #374151;
    box-shadow:    0 1px 2px rgba(0, 0, 0, 0.06);
    transition:    border-color 0.15s ease;
}

.login .button-secondary:hover {
    border-color: #1a365d;
    color:        #1a365d;
}

/* Links — muted by default, brand on hover */
#nav a,
#backtoblog a,
.login #nav a,
.login #backtoblog a {
    color:           #6b7280;
    text-decoration: none;
    transition:      color 0.15s ease;
}

#nav a:hover,
#backtoblog a:hover,
.login #nav a:hover,
.login #backtoblog a:hover {
    color:           #1a365d;
    text-decoration: underline;
}

/* Error / notice messages — colour and shadow only */
.login .message,
.login #login_error,
.login .success {
    border-radius: 4px;
    box-shadow:    0 1px 3px rgba(0, 0, 0, 0.06);
}

.login #login_error {
    border-left-color: #dc2626;
    background-color:  #fef2f2;
    color:             #991b1b;
}

.login .message {
    border-left-color: #1a365d;
    background-color:  #eff6ff;
    color:             #1e3a5f;
}

.login .success {
    border-left-color: #16a34a;
    background-color:  #f0fdf4;
    color:             #15803d;
}

/* Privacy policy link */
.login .privacy-policy-page-link a {
    color:      #6b7280;
    transition: color 0.15s ease;
}

.login .privacy-policy-page-link a:hover {
    color: #1a365d;
}

/* Checkbox — accent colour for remember-me */
.login input[type='checkbox']:focus {
    box-shadow: 0 0 0 3px rgba(26, 54, 93, 0.18);
    outline:    none;
}
/* ── End OPB Login Branding ──────────────────────────────────────────────── */
";
    }
}
