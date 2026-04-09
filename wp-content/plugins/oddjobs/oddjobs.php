<?php
/**
 * Plugin Name:  OddJobs Marketplace
 * Plugin URI:   https://example.com/oddjobs
 * Description:  Connects employers who need odd jobs done with student providers. Employers browse a WooCommerce shop; students apply via a registration form and are manually promoted to Shop Manager by an admin.
 * Version:      1.0.0
 * Author:       OddJobs
 * License:      GPL-2.0+
 * Text Domain:  oddjobs
 */

defined( 'ABSPATH' ) || exit;

define( 'ODDJOBS_VERSION',    '1.0.0' );
define( 'ODDJOBS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ODDJOBS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// ════════════════════════════════════════════════════════════════════════════
// ACTIVATION / DEACTIVATION
// ════════════════════════════════════════════════════════════════════════════

register_activation_hook( __FILE__, 'oddjobs_activate' );
function oddjobs_activate() {
    oddjobs_register_cpt();          // register CPT so rewrite rules exist
    flush_rewrite_rules();
    oddjobs_create_pending_table();  // custom DB table for applicants
    oddjobs_create_pages();          // portal page + registration page
    oddjobs_insert_sample_jobs();    // 5 sample odd-job posts with images
    oddjobs_register_provider_role();
}

register_deactivation_hook( __FILE__, 'oddjobs_deactivate' );
function oddjobs_deactivate() {
    flush_rewrite_rules();
}

// ════════════════════════════════════════════════════════════════════════════
// CUSTOM POST TYPE — odd_job
// ════════════════════════════════════════════════════════════════════════════

add_action( 'init', 'oddjobs_register_cpt' );
function oddjobs_register_cpt() {
    $labels = [
        'name'               => 'Odd Jobs',
        'singular_name'      => 'Odd Job',
        'add_new'            => 'Add New Job',
        'add_new_item'       => 'Add New Odd Job',
        'edit_item'          => 'Edit Odd Job',
        'new_item'           => 'New Odd Job',
        'view_item'          => 'View Odd Job',
        'search_items'       => 'Search Odd Jobs',
        'not_found'          => 'No odd jobs found',
        'not_found_in_trash' => 'No odd jobs found in Trash',
        'menu_name'          => 'Odd Jobs',
    ];

    register_post_type( 'odd_job', [
        'labels'          => $labels,
        'public'          => true,
        'has_archive'     => true,
        'rewrite'         => [ 'slug' => 'odd-jobs' ],
        'supports'        => [ 'title', 'editor', 'thumbnail', 'excerpt', 'custom-fields' ],
        'menu_icon'       => 'dashicons-hammer',
        'show_in_rest'    => true,
        'capability_type' => 'post',
        'map_meta_cap'    => true,
    ] );
}

// ════════════════════════════════════════════════════════════════════════════
// PROVIDER ROLE (fallback when WooCommerce is absent)
// ════════════════════════════════════════════════════════════════════════════

add_action( 'init', 'oddjobs_register_provider_role' );
function oddjobs_register_provider_role() {
    if ( ! get_role( 'odd_job_provider' ) ) {
        add_role( 'odd_job_provider', 'Odd Job Provider', [
            'read'                => true,
            'edit_posts'          => true,
            'publish_posts'       => true,
            'edit_published_posts'=> true,
            'upload_files'        => true,
        ] );
    }
}

/** Returns 'shop_manager' when WooCommerce is active, otherwise our fallback. */
function oddjobs_provider_role() {
    return function_exists( 'WC' ) ? 'shop_manager' : 'odd_job_provider';
}

// ════════════════════════════════════════════════════════════════════════════
// PENDING-REGISTRATIONS TABLE
// ════════════════════════════════════════════════════════════════════════════

function oddjobs_create_pending_table() {
    global $wpdb;
    $table   = $wpdb->prefix . 'oddjobs_pending';
    $charset = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$table} (
  id        bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name      varchar(100)        NOT NULL DEFAULT '',
  email     varchar(200)        NOT NULL DEFAULT '',
  phone     varchar(50)         NOT NULL DEFAULT '',
  summary   text                NOT NULL,
  submitted datetime            NOT NULL DEFAULT '0000-00-00 00:00:00',
  status    varchar(20)         NOT NULL DEFAULT 'pending',
  PRIMARY KEY  (id)
) {$charset};";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
}

// ════════════════════════════════════════════════════════════════════════════
// CREATE PAGES ON ACTIVATION
// ════════════════════════════════════════════════════════════════════════════

function oddjobs_create_pages() {
    $pages = [
        'welcome-to-oddjobs'    => [
            'title'   => 'Welcome to OddJobs',
            'content' => '[oddjobs_portal]',
        ],
        'odd-jobs-registration' => [
            'title'   => 'Odd Jobs Registration',
            'content' => '[oddjobs_registration]',
        ],
    ];

    foreach ( $pages as $slug => $data ) {
        if ( ! get_page_by_path( $slug ) ) {
            wp_insert_post( [
                'post_title'   => $data['title'],
                'post_content' => $data['content'],
                'post_status'  => 'publish',
                'post_type'    => 'page',
                'post_name'    => $slug,
            ] );
        }
    }

    oddjobs_set_front_page();
}

/**
 * Points WordPress's "static front page" setting at the Welcome to OddJobs page.
 * Safe to call multiple times — only updates when not already configured.
 */
function oddjobs_set_front_page() {
    $page = get_page_by_path( 'welcome-to-oddjobs' );
    if ( ! $page ) {
        return; // page not created yet; will be called again after insert
    }

    update_option( 'show_on_front', 'page' );
    update_option( 'page_on_front', $page->ID );
}

// Run once for sites where the plugin was already active before this fix.
add_action( 'admin_init', 'oddjobs_maybe_set_front_page' );
function oddjobs_maybe_set_front_page() {
    if ( get_option( 'oddjobs_front_page_set' ) ) {
        return;
    }
    oddjobs_set_front_page();
    // Only mark done once the page actually exists.
    if ( 'page' === get_option( 'show_on_front' ) && get_option( 'page_on_front' ) ) {
        update_option( 'oddjobs_front_page_set', true );
    }
}

// ════════════════════════════════════════════════════════════════════════════
// SAMPLE ODD JOB POSTS WITH SVG MEDIA
// Uses direct $wpdb inserts to bypass WordPress hook overhead and avoid
// memory exhaustion on constrained / Playground environments.
// ════════════════════════════════════════════════════════════════════════════

function oddjobs_sample_data() {
    return [
        [
            'title'   => 'Lawn Mowing & Garden Tidy',
            'slug'    => 'lawn-mowing-garden-tidy',
            'content' => '<p>Keep your outdoor space looking its best! I offer professional lawn mowing, edging, and basic garden tidy-up — including bagging and removing yard waste. Available on weekends and weekday evenings. All necessary tools are provided. Whether you need a one-off cut or a regular schedule, just reach out and we\'ll arrange a convenient time.</p>',
            'excerpt' => 'Professional lawn mowing, edging, and garden tidy-up. Tools provided, yard waste removed.',
            'price'   => '25.00',
            'color1'  => '#4caf50',
            'color2'  => '#1b5e20',
            'emoji'   => "\xF0\x9F\x8C\xBF", // 🌿
            'label'   => 'Lawn &amp; Garden',
        ],
        [
            'title'   => 'Dog Walking & Pet Care',
            'slug'    => 'dog-walking-pet-care',
            'content' => '<p>I am passionate about animals and happy to walk your dog, refresh food and water, or keep your pet company while you\'re at work or on holiday. Experienced handling dogs of all sizes and temperaments. References available on request. Choose from a 30-minute neighbourhood stroll or a full 60-minute park walk.</p>',
            'excerpt' => 'Reliable dog walking (30 or 60 min) and in-home pet care. All breeds welcome.',
            'price'   => '20.00',
            'color1'  => '#8d6e63',
            'color2'  => '#3e2723',
            'emoji'   => "\xF0\x9F\x90\xBE", // 🐾
            'label'   => 'Dog Walking',
        ],
        [
            'title'   => 'Car Washing & Interior Detailing',
            'slug'    => 'car-washing-interior-detailing',
            'content' => '<p>Give your car the sparkle it deserves — right in your own driveway! Services include exterior hand wash, wheel clean, window polish, interior vacuum, and dashboard wipe-down. Eco-friendly, waterless products available on request. Pricing varies by vehicle size; contact me for a quick quote.</p>',
            'excerpt' => 'Mobile car wash and interior detailing at your location. Eco-friendly products available.',
            'price'   => '35.00',
            'color1'  => '#1976d2',
            'color2'  => '#0d47a1',
            'emoji'   => "\xF0\x9F\x9A\x97", // 🚗
            'label'   => 'Car Washing',
        ],
        [
            'title'   => 'House Cleaning & Decluttering',
            'slug'    => 'house-cleaning-decluttering',
            'content' => '<p>A tidy home, stress-free. I provide thorough cleaning including dusting, vacuuming, mopping hard floors, full kitchen scrub-down, and bathroom sanitising. I can also help with decluttering and reorganising cupboards or storage areas. Flexible half-day or full-day bookings to suit your schedule.</p>',
            'excerpt' => 'Thorough home cleaning and decluttering. Flexible half- or full-day bookings.',
            'price'   => '60.00',
            'color1'  => '#fbc02d',
            'color2'  => '#e65100',
            'emoji'   => "\xF0\x9F\xA7\xB9", // 🧹
            'label'   => 'House Cleaning',
        ],
        [
            'title'   => 'Grocery Shopping & General Errands',
            'slug'    => 'grocery-shopping-general-errands',
            'content' => '<p>Too busy to run errands? Let me handle grocery shopping, pharmacy collections, post office drop-offs, and more. I have a reliable vehicle and can handle multiple stops in one trip. Same-day service available for most requests. Itemised receipts provided for every purchase — no hidden fees.</p>',
            'excerpt' => 'Grocery runs, pharmacy pickups, and general errands. Same-day available, receipts provided.',
            'price'   => '15.00',
            'color1'  => '#8e24aa',
            'color2'  => '#4a148c',
            'emoji'   => "\xF0\x9F\x9B\x92", // 🛒
            'label'   => 'Errands',
        ],
    ];
}

function oddjobs_insert_sample_jobs() {
    if ( get_option( 'oddjobs_samples_inserted' ) ) {
        return;
    }

    global $wpdb;

    $upload  = wp_upload_dir();
    $now     = current_time( 'mysql' );
    $now_gmt = current_time( 'mysql', 1 );
    $author  = absint( get_current_user_id() ) ?: 1;

    foreach ( oddjobs_sample_data() as $job ) {

        // ── 1. Write the SVG file (pure text, no image library) ──────────
        $filename   = 'oddjobs-' . $job['slug'] . '.svg';
        $image_path = trailingslashit( $upload['path'] ) . $filename;
        $image_url  = trailingslashit( $upload['url'] )  . $filename;
        $rel_path   = ltrim( str_replace( $upload['basedir'], '', $image_path ), '/\\' );

        oddjobs_write_svg( $image_path, $job['color1'], $job['color2'], $job['emoji'], $job['label'] );

        // ── 2. Insert the odd_job post directly (no hooks fired) ─────────
        $wpdb->insert( $wpdb->posts, [
            'post_author'           => $author,
            'post_date'             => $now,
            'post_date_gmt'         => $now_gmt,
            'post_content'          => $job['content'],
            'post_title'            => $job['title'],
            'post_excerpt'          => $job['excerpt'],
            'post_status'           => 'publish',
            'comment_status'        => 'closed',
            'ping_status'           => 'closed',
            'post_name'             => $job['slug'],
            'post_modified'         => $now,
            'post_modified_gmt'     => $now_gmt,
            'post_type'             => 'odd_job',
            'to_ping'               => '',
            'pinged'                => '',
            'post_content_filtered' => '',
            'guid'                  => home_url( '/odd-jobs/' . $job['slug'] . '/' ),
        ] );
        $post_id = (int) $wpdb->insert_id;
        if ( ! $post_id ) {
            continue;
        }

        // ── 3. Insert the SVG as a media attachment (no hooks fired) ─────
        $wpdb->insert( $wpdb->posts, [
            'post_author'           => $author,
            'post_date'             => $now,
            'post_date_gmt'         => $now_gmt,
            'post_content'          => '',
            'post_title'            => $job['title'],
            'post_excerpt'          => '',
            'post_status'           => 'inherit',
            'post_name'             => $job['slug'] . '-img',
            'post_modified'         => $now,
            'post_modified_gmt'     => $now_gmt,
            'post_type'             => 'attachment',
            'post_mime_type'        => 'image/svg+xml',
            'post_parent'           => $post_id,
            'to_ping'               => '',
            'pinged'                => '',
            'post_content_filtered' => '',
            'guid'                  => $image_url,
        ] );
        $att_id = (int) $wpdb->insert_id;
        if ( ! $att_id ) {
            continue;
        }

        // ── 4. Insert post meta (direct rows, no update_post_meta hooks) ─
        $meta_rows = [
            [ $post_id, '_thumbnail_id',          (string) $att_id ],
            [ $post_id, '_oddjobs_price',          $job['price'] ],
            [ $post_id, '_oddjobs_is_sample',      '1' ],
            [ $att_id,  '_wp_attached_file',       $rel_path ],
            [ $att_id,  '_wp_attachment_metadata', serialize( [
                'width'  => 800,
                'height' => 500,
                'file'   => $rel_path,
                'sizes'  => [],
            ] ) ],
        ];
        foreach ( $meta_rows as [ $pid, $key, $val ] ) {
            $wpdb->insert( $wpdb->postmeta, [
                'post_id'    => $pid,
                'meta_key'   => $key,
                'meta_value' => $val,
            ] );
        }
    }

    update_option( 'oddjobs_samples_inserted', true );
}

/**
 * Writes a branded SVG card to $path.
 * Pure text — no image library, no pixel buffers, no memory pressure.
 */
function oddjobs_write_svg( $path, $color1, $color2, $emoji, $label ) {
    if ( file_exists( $path ) ) {
        return;
    }
    $svg = '<?xml version="1.0" encoding="UTF-8"?>'
         . '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 800 500" width="800" height="500">'
         . '<defs><linearGradient id="bg" x1="0" y1="0" x2="0" y2="1">'
         . '<stop offset="0%"   stop-color="' . $color1 . '"/>'
         . '<stop offset="100%" stop-color="' . $color2 . '"/>'
         . '</linearGradient></defs>'
         . '<rect width="800" height="500" fill="url(#bg)"/>'
         . '<circle cx="400" cy="190" r="95" fill="rgba(255,255,255,0.15)"/>'
         . '<text x="400" y="205" font-family="Arial,sans-serif" font-size="96"'
         . ' text-anchor="middle" dominant-baseline="middle">' . $emoji . '</text>'
         . '<text x="400" y="338" font-family="Arial,sans-serif" font-size="46"'
         . ' font-weight="bold" text-anchor="middle" fill="#ffffff">' . $label . '</text>'
         . '<text x="400" y="392" font-family="Arial,sans-serif" font-size="22"'
         . ' text-anchor="middle" fill="rgba(255,255,255,0.75)">OddJobs Service</text>'
         . '</svg>';
    file_put_contents( $path, $svg );
}

// ════════════════════════════════════════════════════════════════════════════
// SHORTCODE — [oddjobs_portal]
// Main-page element: employer → shop, provider → registration
// ════════════════════════════════════════════════════════════════════════════

add_shortcode( 'oddjobs_portal', 'oddjobs_portal_shortcode' );
function oddjobs_portal_shortcode() {

    // WooCommerce shop URL with graceful fallback
    if ( function_exists( 'wc_get_page_id' ) ) {
        $shop_url = get_permalink( wc_get_page_id( 'shop' ) );
    } else {
        $shop_url = home_url( '/shop/' );
    }

    $reg_url = home_url( '/odd-jobs-registration/' );

    ob_start();
    ?>
    <section class="oddjobs-portal" aria-label="OddJobs portal">

        <div class="oddjobs-portal__hero">
            <h2 class="oddjobs-portal__title">Welcome to OddJobs</h2>
            <p class="oddjobs-portal__subtitle">
                Connecting busy people with reliable student providers in your community.
            </p>
        </div>

        <div class="oddjobs-portal__cards">

            <!-- EMPLOYER card -->
            <article class="oddjobs-portal__card oddjobs-portal__card--employer">
                <div class="oddjobs-portal__icon" aria-hidden="true">&#128722;</div>
                <h3>I Need Odd Jobs Done</h3>
                <p>
                    Browse our growing catalogue of student-offered services — from lawn mowing
                    to grocery runs — and book exactly the help you need.
                </p>
                <a href="<?php echo esc_url( $shop_url ); ?>"
                   class="oddjobs-btn oddjobs-btn--primary"
                   aria-label="Browse the OddJobs shop">
                    Browse the Shop
                </a>
            </article>

            <!-- PROVIDER card -->
            <article class="oddjobs-portal__card oddjobs-portal__card--provider">
                <div class="oddjobs-portal__icon" aria-hidden="true">&#127891;</div>
                <h3>I Want to Do Odd Jobs</h3>
                <p>
                    Are you a student looking to earn money on your own schedule?
                    Register now and tell us why you'd be a great odd-job provider.
                </p>
                <a href="<?php echo esc_url( $reg_url ); ?>"
                   class="oddjobs-btn oddjobs-btn--secondary"
                   aria-label="Register as an OddJobs provider">
                    Register as Provider
                </a>
            </article>

        </div><!-- .oddjobs-portal__cards -->

    </section><!-- .oddjobs-portal -->
    <?php
    return ob_get_clean();
}

// ════════════════════════════════════════════════════════════════════════════
// SHORTCODE — [oddjobs_registration]
// Registration page: 100-word summary, admin-gated approval
// ════════════════════════════════════════════════════════════════════════════

add_shortcode( 'oddjobs_registration', 'oddjobs_registration_shortcode' );
function oddjobs_registration_shortcode() {
    global $wpdb;

    $message      = '';
    $message_type = '';
    $posted       = [];

    // ── Process submission ───────────────────────────────────────────────
    if (
        isset( $_POST['oddjobs_register_nonce'] ) &&
        wp_verify_nonce( $_POST['oddjobs_register_nonce'], 'oddjobs_register' )
    ) {
        $posted = [
            'name'    => sanitize_text_field( $_POST['oddjobs_name']    ?? '' ),
            'email'   => sanitize_email(      $_POST['oddjobs_email']   ?? '' ),
            'phone'   => sanitize_text_field( $_POST['oddjobs_phone']   ?? '' ),
            'summary' => sanitize_textarea_field( $_POST['oddjobs_summary'] ?? '' ),
        ];

        $errors = [];

        if ( empty( $posted['name'] ) ) {
            $errors[] = 'Full name is required.';
        }
        if ( empty( $posted['email'] ) || ! is_email( $posted['email'] ) ) {
            $errors[] = 'A valid email address is required.';
        }
        if ( empty( $posted['phone'] ) ) {
            $errors[] = 'Phone number is required.';
        }

        $word_count = str_word_count( strip_tags( $posted['summary'] ) );
        if ( $word_count < 50 ) {
            $errors[] = "Your summary is too short ({$word_count} words). Please write at least 50 words.";
        } elseif ( $word_count > 100 ) {
            $errors[] = "Your summary is too long ({$word_count} words). Please trim it to 100 words or fewer.";
        }

        // Check for duplicate applications
        $table = $wpdb->prefix . 'oddjobs_pending';
        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE email = %s",
            $posted['email']
        ) );
        if ( $exists ) {
            $errors[] = 'An application with this email address already exists.';
        }
        if ( email_exists( $posted['email'] ) ) {
            $errors[] = 'This email address already has an account.';
        }

        if ( empty( $errors ) ) {
            $wpdb->insert( $table, [
                'name'      => $posted['name'],
                'email'     => $posted['email'],
                'phone'     => $posted['phone'],
                'summary'   => $posted['summary'],
                'submitted' => current_time( 'mysql' ),
                'status'    => 'pending',
            ] );

            // Notify admin
            wp_mail(
                get_option( 'admin_email' ),
                '[OddJobs] New Provider Application — ' . $posted['name'],
                sprintf(
                    "A new odd-job provider has applied.\n\nName:  %s\nEmail: %s\nPhone: %s\n\nSummary:\n%s\n\nReview applications: %s",
                    $posted['name'],
                    $posted['email'],
                    $posted['phone'],
                    $posted['summary'],
                    admin_url( 'admin.php?page=oddjobs-pending' )
                )
            );

            $message      = 'Thanks for applying! Your application is under review — we\'ll be in touch by email once an administrator has looked it over.';
            $message_type = 'success';
            $posted       = []; // clear form
        } else {
            $message      = '<strong>Please fix the following:</strong><br>' . implode( '<br>', $errors );
            $message_type = 'error';
        }
    }

    ob_start();
    ?>
    <section class="oddjobs-registration">

        <div class="oddjobs-registration__intro">
            <h2>Register as an Odd-Jobs Provider</h2>
            <p>
                Fill in the form below. An administrator will review your application and, if
                accepted, will grant you access to create your own odd-job listings on the
                marketplace.
            </p>
        </div>

        <?php if ( $message ) : ?>
            <div class="oddjobs-notice oddjobs-notice--<?php echo esc_attr( $message_type ); ?>"
                 role="alert">
                <?php echo wp_kses_post( $message ); ?>
            </div>
        <?php endif; ?>

        <?php if ( $message_type !== 'success' ) : ?>
        <form method="post"
              class="oddjobs-form"
              id="oddjobs-reg-form"
              novalidate>
            <?php wp_nonce_field( 'oddjobs_register', 'oddjobs_register_nonce' ); ?>

            <div class="oddjobs-form__group">
                <label for="oddjobs_name">
                    Full Name <span class="oddjobs-required" aria-hidden="true">*</span>
                </label>
                <input type="text"
                       id="oddjobs_name"
                       name="oddjobs_name"
                       value="<?php echo esc_attr( $posted['name'] ?? '' ); ?>"
                       placeholder="Jane Smith"
                       required
                       autocomplete="name">
            </div>

            <div class="oddjobs-form__group">
                <label for="oddjobs_email">
                    Email Address <span class="oddjobs-required" aria-hidden="true">*</span>
                </label>
                <input type="email"
                       id="oddjobs_email"
                       name="oddjobs_email"
                       value="<?php echo esc_attr( $posted['email'] ?? '' ); ?>"
                       placeholder="jane@example.com"
                       required
                       autocomplete="email">
            </div>

            <div class="oddjobs-form__group">
                <label for="oddjobs_phone">
                    Phone Number <span class="oddjobs-required" aria-hidden="true">*</span>
                </label>
                <input type="tel"
                       id="oddjobs_phone"
                       name="oddjobs_phone"
                       value="<?php echo esc_attr( $posted['phone'] ?? '' ); ?>"
                       placeholder="(555) 555-5555"
                       required
                       autocomplete="tel">
            </div>

            <div class="oddjobs-form__group">
                <label for="oddjobs_summary">
                    Why would you be a great odd-job provider?
                    <span class="oddjobs-form__hint">&nbsp;(50–100 words)</span>
                    <span class="oddjobs-required" aria-hidden="true">*</span>
                </label>
                <textarea id="oddjobs_summary"
                          name="oddjobs_summary"
                          rows="7"
                          required
                          placeholder="Tell us about your skills, reliability, and why employers should choose you…"><?php echo esc_textarea( $posted['summary'] ?? '' ); ?></textarea>
                <div class="oddjobs-form__word-count" aria-live="polite">
                    Word count: <strong id="oddjobs-wc">0</strong> / 100
                </div>
            </div>

            <button type="submit" class="oddjobs-btn oddjobs-btn--primary oddjobs-btn--submit">
                Submit Application
            </button>
        </form>

        <script>
        (function () {
            'use strict';
            var ta  = document.getElementById('oddjobs_summary');
            var wc  = document.getElementById('oddjobs-wc');
            if (!ta || !wc) return;

            function count(s) {
                var t = s.trim();
                return t === '' ? 0 : t.split(/\s+/).length;
            }

            function update() {
                var n = count(ta.value);
                wc.textContent = n;
                wc.style.color = (n >= 50 && n <= 100) ? '#28a745' : '#dc3545';
            }

            ta.addEventListener('input', update);
            update(); // run once on page load (for browser-autofill)
        }());
        </script>
        <?php endif; ?>

    </section><!-- .oddjobs-registration -->
    <?php
    return ob_get_clean();
}

// ════════════════════════════════════════════════════════════════════════════
// ADMIN MENU
// ════════════════════════════════════════════════════════════════════════════

add_action( 'admin_menu', 'oddjobs_admin_menu' );
function oddjobs_admin_menu() {
    add_menu_page(
        'OddJobs',
        'OddJobs',
        'manage_options',
        'oddjobs-pending',
        'oddjobs_pending_page',
        'dashicons-hammer',
        25
    );

    add_submenu_page(
        'oddjobs-pending',
        'Provider Applications',
        'Applications',
        'manage_options',
        'oddjobs-pending',
        'oddjobs_pending_page'
    );

    add_submenu_page(
        'oddjobs-pending',
        'OddJobs Settings',
        'Settings',
        'manage_options',
        'oddjobs-settings',
        'oddjobs_settings_page'
    );
}

// ════════════════════════════════════════════════════════════════════════════
// ADMIN PAGE — Pending Applications
// ════════════════════════════════════════════════════════════════════════════

function oddjobs_pending_page() {
    global $wpdb;
    $table = $wpdb->prefix . 'oddjobs_pending';

    // ── Handle approve ───────────────────────────────────────────────────
    if (
        isset( $_POST['oddjobs_approve_nonce'] ) &&
        wp_verify_nonce( $_POST['oddjobs_approve_nonce'], 'oddjobs_approve' )
    ) {
        $id  = absint( $_POST['pending_id'] ?? 0 );
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );

        if ( $row && 'pending' === $row->status ) {
            // Build a unique username from the email local-part
            $base = sanitize_user( strstr( $row->email, '@', true ), true );
            $base = $base ?: 'oddjobs';
            $username = $base;
            $suffix   = 1;
            while ( username_exists( $username ) ) {
                $username = $base . $suffix++;
            }

            $password = wp_generate_password( 12, false );
            $user_id  = wp_create_user( $username, $password, $row->email );

            if ( is_wp_error( $user_id ) ) {
                echo '<div class="notice notice-error is-dismissible"><p>'
                     . esc_html( $user_id->get_error_message() ) . '</p></div>';
            } else {
                wp_update_user( [
                    'ID'           => $user_id,
                    'display_name' => $row->name,
                    'first_name'   => $row->name,
                ] );

                $user = new WP_User( $user_id );
                $user->set_role( oddjobs_provider_role() );

                // Send credentials to the new provider
                $login_url = wp_login_url();
                wp_mail(
                    $row->email,
                    '[OddJobs] Your Application Has Been Approved!',
                    "Hi {$row->name},\n\n"
                    . "Great news — your OddJobs provider application has been approved!\n\n"
                    . "You can now log in and create odd-job listings:\n\n"
                    . "  Login URL:  {$login_url}\n"
                    . "  Username:   {$username}\n"
                    . "  Password:   {$password}\n\n"
                    . "Please change your password after your first login.\n\n"
                    . "Welcome to OddJobs!"
                );

                $wpdb->update( $table, [ 'status' => 'approved' ], [ 'id' => $id ] );
                echo '<div class="notice notice-success is-dismissible"><p>'
                     . esc_html( "Approved! Account created for {$row->name} ({$username}). Welcome email sent." )
                     . '</p></div>';
            }
        }
    }

    // ── Handle reject ────────────────────────────────────────────────────
    if (
        isset( $_POST['oddjobs_reject_nonce'] ) &&
        wp_verify_nonce( $_POST['oddjobs_reject_nonce'], 'oddjobs_reject' )
    ) {
        $id = absint( $_POST['pending_id'] ?? 0 );
        $wpdb->update( $table, [ 'status' => 'rejected' ], [ 'id' => $id ] );
        echo '<div class="notice notice-info is-dismissible"><p>Application marked as rejected.</p></div>';
    }

    // ── Fetch rows ───────────────────────────────────────────────────────
    $filter  = sanitize_text_field( $_GET['status_filter'] ?? 'all' );
    $where   = ( 'all' !== $filter ) ? $wpdb->prepare( 'WHERE status = %s', $filter ) : '';
    $rows    = $wpdb->get_results( "SELECT * FROM {$table} {$where} ORDER BY submitted DESC" );
    $counts  = $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM {$table} GROUP BY status", OBJECT_K );
    ?>
    <div class="wrap">
        <h1 class="wp-heading-inline">OddJobs — Provider Applications</h1>
        <hr class="wp-header-end">

        <ul class="subsubsub">
            <?php
            $statuses = [ 'all' => 'All', 'pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected' ];
            $links = [];
            foreach ( $statuses as $s => $label ) {
                $count_val = ( 'all' === $s )
                    ? array_sum( array_column( (array) $counts, 'n' ) )
                    : ( $counts[ $s ]->n ?? 0 );
                $url    = admin_url( 'admin.php?page=oddjobs-pending&status_filter=' . $s );
                $active = ( $filter === $s ) ? ' class="current"' : '';
                $links[] = "<li><a href=\"" . esc_url( $url ) . "\"{$active}>{$label} <span class='count'>({$count_val})</span></a>";
            }
            echo implode( ' | ', $links );
            ?>
        </ul>

        <?php if ( empty( $rows ) ) : ?>
            <p style="margin-top:1.5rem;">No applications found.</p>
        <?php else : ?>
        <table class="wp-list-table widefat fixed striped" style="margin-top:1rem;">
            <thead>
                <tr>
                    <th style="width:14%">Name</th>
                    <th style="width:18%">Email</th>
                    <th style="width:12%">Phone</th>
                    <th>Summary</th>
                    <th style="width:12%">Submitted</th>
                    <th style="width:9%">Status</th>
                    <th style="width:14%">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $rows as $row ) : ?>
                <tr>
                    <td><?php echo esc_html( $row->name ); ?></td>
                    <td><?php echo esc_html( $row->email ); ?></td>
                    <td><?php echo esc_html( $row->phone ); ?></td>
                    <td>
                        <details>
                            <summary style="cursor:pointer;color:#2271b1;">Read summary</summary>
                            <blockquote style="margin:.5rem 0 0;font-size:.9rem;border-left:3px solid #ddd;padding-left:.75rem;">
                                <?php echo esc_html( $row->summary ); ?>
                            </blockquote>
                        </details>
                    </td>
                    <td><?php echo esc_html( mysql2date( 'M j, Y', $row->submitted ) ); ?></td>
                    <td>
                        <span class="oddjobs-badge oddjobs-badge--<?php echo esc_attr( $row->status ); ?>">
                            <?php echo esc_html( ucfirst( $row->status ) ); ?>
                        </span>
                    </td>
                    <td>
                        <?php if ( 'pending' === $row->status ) : ?>
                        <form method="post" style="display:inline-block;margin-right:4px;">
                            <?php wp_nonce_field( 'oddjobs_approve', 'oddjobs_approve_nonce' ); ?>
                            <input type="hidden" name="pending_id" value="<?php echo esc_attr( $row->id ); ?>">
                            <button type="submit" class="button button-primary button-small">
                                Approve
                            </button>
                        </form>
                        <form method="post" style="display:inline-block;">
                            <?php wp_nonce_field( 'oddjobs_reject', 'oddjobs_reject_nonce' ); ?>
                            <input type="hidden" name="pending_id" value="<?php echo esc_attr( $row->id ); ?>">
                            <button type="submit" class="button button-small">Reject</button>
                        </form>
                        <?php else : ?>
                            <em style="color:#888;"><?php echo esc_html( ucfirst( $row->status ) ); ?></em>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div><!-- .wrap -->
    <?php
}

// ════════════════════════════════════════════════════════════════════════════
// ADMIN PAGE — Settings
// ════════════════════════════════════════════════════════════════════════════

function oddjobs_settings_page() {

    // ── Save GA settings ─────────────────────────────────────────────────
    if (
        isset( $_POST['oddjobs_settings_nonce'] ) &&
        wp_verify_nonce( $_POST['oddjobs_settings_nonce'], 'oddjobs_settings' )
    ) {
        update_option( 'oddjobs_ga_id', sanitize_text_field( $_POST['oddjobs_ga_id'] ?? '' ) );
        echo '<div class="notice notice-success is-dismissible"><p>Settings saved.</p></div>';
    }

    // ── Reinstall sample jobs ────────────────────────────────────────────
    if (
        isset( $_POST['oddjobs_reinstall_nonce'] ) &&
        wp_verify_nonce( $_POST['oddjobs_reinstall_nonce'], 'oddjobs_reinstall' )
    ) {
        global $wpdb;
        // Delete only auto-generated sample posts (marked with _oddjobs_is_sample)
        $sample_ids = $wpdb->get_col(
            "SELECT post_id FROM {$wpdb->postmeta}
             WHERE meta_key = '_oddjobs_is_sample' AND meta_value = '1'"
        );
        foreach ( $sample_ids as $sid ) {
            // Delete attachments too
            $att_ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = 'attachment'",
                $sid
            ) );
            foreach ( $att_ids as $aid ) {
                $wpdb->delete( $wpdb->postmeta, [ 'post_id' => $aid ] );
                $wpdb->delete( $wpdb->posts,    [ 'ID'      => $aid ] );
            }
            $wpdb->delete( $wpdb->postmeta, [ 'post_id' => $sid ] );
            $wpdb->delete( $wpdb->posts,    [ 'ID'      => $sid ] );
        }
        delete_option( 'oddjobs_samples_inserted' );
        oddjobs_insert_sample_jobs();
        echo '<div class="notice notice-success is-dismissible"><p>Sample odd jobs have been reinstalled.</p></div>';
    }

    ?>
    <div class="wrap">
        <h1>OddJobs Settings</h1>

        <form method="post">
            <?php wp_nonce_field( 'oddjobs_settings', 'oddjobs_settings_nonce' ); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">
                        <label for="oddjobs_ga_id">Google Analytics 4 ID</label>
                    </th>
                    <td>
                        <input type="text"
                               id="oddjobs_ga_id"
                               name="oddjobs_ga_id"
                               value="<?php echo esc_attr( get_option( 'oddjobs_ga_id', '' ) ); ?>"
                               class="regular-text"
                               placeholder="G-XXXXXXXXXX">
                        <p class="description">
                            The Measurement ID for your GA4 property. The snippet is injected
                            by the child theme's <code>functions.php</code> and only fires when
                            this field is non-empty.
                        </p>
                    </td>
                </tr>
            </table>
            <?php submit_button( 'Save Settings' ); ?>
        </form>

        <hr>

        <h2>Sample Data</h2>
        <p>
            Use this if the five sample odd-job posts are missing or their images did not attach correctly.
            Existing sample posts will be supplemented, not duplicated.
        </p>
        <form method="post">
            <?php wp_nonce_field( 'oddjobs_reinstall', 'oddjobs_reinstall_nonce' ); ?>
            <?php submit_button( 'Reinstall Sample Jobs', 'secondary' ); ?>
        </form>

    </div>
    <?php
}

// ════════════════════════════════════════════════════════════════════════════
// ENQUEUE PLUGIN ASSETS
// ════════════════════════════════════════════════════════════════════════════

add_action( 'wp_enqueue_scripts', 'oddjobs_enqueue_frontend_assets' );
function oddjobs_enqueue_frontend_assets() {
    wp_enqueue_style(
        'oddjobs-plugin',
        ODDJOBS_PLUGIN_URL . 'assets/css/plugin.css',
        [],
        ODDJOBS_VERSION
    );
}

add_action( 'admin_enqueue_scripts', 'oddjobs_enqueue_admin_assets' );
function oddjobs_enqueue_admin_assets( $hook ) {
    if ( false !== strpos( $hook, 'oddjobs' ) ) {
        wp_enqueue_style(
            'oddjobs-admin',
            ODDJOBS_PLUGIN_URL . 'assets/css/admin.css',
            [],
            ODDJOBS_VERSION
        );
    }
}
