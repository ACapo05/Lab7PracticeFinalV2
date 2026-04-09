<?php
/**
 * OddJobs Child Theme — functions.php
 *
 * Responsibilities:
 *  1. Enqueue parent (wpico) stylesheet + child stylesheet
 *  2. Load Google Fonts (Poppins + Open Sans + Fira Code)
 *  3. Inject Google Analytics 4 snippet (tag ID stored in wp-admin → OddJobs → Settings)
 */

defined( 'ABSPATH' ) || exit;

// ── 1. Enqueue stylesheets & fonts ──────────────────────────────────────────
add_action( 'wp_enqueue_scripts', 'oddjobs_child_enqueue_styles' );
function oddjobs_child_enqueue_styles() {

    // Google Fonts – subset only the weights we actually use
    wp_enqueue_style(
        'oddjobs-google-fonts',
        'https://fonts.googleapis.com/css2?family=Fira+Code:wght@400&family=Open+Sans:wght@400;500;600&family=Poppins:wght@400;600;700&display=swap',
        [],
        null   // no version string – font URL is self-versioned
    );

    // Parent theme
    wp_enqueue_style(
        'wpico-parent-style',
        get_template_directory_uri() . '/style.css',
        [ 'oddjobs-google-fonts' ],
        wp_get_theme( 'wpico' )->get( 'Version' )
    );

    // Child theme (adds :root tokens + brand overrides on top)
    wp_enqueue_style(
        'oddjobs-child-style',
        get_stylesheet_uri(),
        [ 'wpico-parent-style' ],
        wp_get_theme()->get( 'Version' )
    );
}

// ── 2. Google Analytics 4 ───────────────────────────────────────────────────
add_action( 'wp_head', 'oddjobs_child_analytics', 1 );
function oddjobs_child_analytics() {
    $ga_id = get_option( 'oddjobs_ga_id', '' );
    if ( empty( $ga_id ) ) {
        return;
    }
    $ga_id = esc_attr( $ga_id );
    ?>
<!-- Google Analytics 4 (OddJobs) -->
<script async src="https://www.googletagmanager.com/gtag/js?id=<?php echo $ga_id; ?>"></script>
<script>
window.dataLayer = window.dataLayer || [];
function gtag(){ dataLayer.push(arguments); }
gtag('js', new Date());
gtag('config', '<?php echo esc_js( get_option( 'oddjobs_ga_id', '' ) ); ?>');
</script>
    <?php
}
