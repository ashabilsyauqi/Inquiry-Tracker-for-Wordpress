<?php
/**
 * Plugin Name: Inquiry & Button Click Tracker
 * Plugin URI:  https://wordpress.org/plugins
 * Description: Universal real-time Button, WhatsApp, and Floating Click Tracking. Features time period filters, Ads vs Organic labeling, accordion dropdown breakdowns per page, WhatsApp inquiry reports, and CSV export.
 * Version:     1.2.0
 * Author:      Digital Web Growth
 * License:     GPL-2.0-or-later
 * Text Domain: inquiry-tracker
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'INQ_TRACKER_VERSION', '1.2.0' );
define( 'INQ_TRACKER_TABLE', $GLOBALS['wpdb']->prefix . 'inquiry_tracker_clicks' );
define( 'INQ_TRACKER_LOG_TABLE', $GLOBALS['wpdb']->prefix . 'inquiry_tracker_log' );

/* ─────────────────────────────────────────
   HELPER FUNCTIONS: AD PAGES & FILTERS
───────────────────────────────────────── */
function inq_tracker_get_ad_list() {
    $raw = get_option( 'inq_tracker_ad_pages', '' );
    $lines = explode( "\n", str_replace( "\r", "", $raw ) );
    $ad_list = [];
    foreach ( $lines as $line ) {
        $line = trim( $line );
        if ( empty( $line ) ) continue;
        
        $parsed = parse_url( $line );
        $path = $parsed['path'] ?? $line;
        $path = '/' . trim( $path, '/' );
        if ( $path === '' ) $path = '/';
        
        $ad_list[] = [
            'original' => $line,
            'path'     => strtolower( $path ),
        ];
    }
    return $ad_list;
}

function inq_tracker_is_ad_page( $url, $ad_list = null ) {
    if ( $ad_list === null ) {
        $ad_list = inq_tracker_get_ad_list();
    }
    if ( empty( $ad_list ) ) return false;
    
    $parsed = parse_url( $url );
    $url_path = $parsed['path'] ?? $url;
    $url_path = '/' . trim( $url_path, '/' );
    if ( $url_path === '' ) $url_path = '/';
    $url_path = strtolower( $url_path );

    foreach ( $ad_list as $ad ) {
        $ad_path = $ad['path'];
        // 1. Exact path match
        if ( $url_path === $ad_path ) {
            return true;
        }
        // 2. Subpath match (e.g. if site is in subfolder or matching path ending)
        if ( $ad_path !== '/' && ( substr( $url_path, -strlen( $ad_path ) ) === $ad_path ) ) {
            return true;
        }
        // 3. Normalized full URL match without query parameters
        $orig_clean = strtok( $ad['original'], '?#' );
        $url_clean  = strtok( $url, '?#' );
        if ( strtolower( trim( (string)$orig_clean, '/' ) ) === strtolower( trim( (string)$url_clean, '/' ) ) ) {
            return true;
        }
    }
    return false;
}

if ( ! function_exists( 'inq_filter_url' ) ) {
    function inq_filter_url( $p, $t = 'all', $cs = '', $ce = '' ) {
        $url = admin_url( 'admin.php?page=inquiry-analytics&period=' . urlencode( $p ) . '&traffic=' . urlencode( $t ) );
        if ( $p === 'custom' && $cs && $ce ) {
            $url .= '&start_date=' . urlencode( $cs ) . '&end_date=' . urlencode( $ce );
        }
        return $url;
    }
}

/* ─────────────────────────────────────────
   1. ACTIVATION & AUTO-MIGRATION
───────────────────────────────────────── */
function inq_tracker_ensure_tables() {
    global $wpdb;
    $agg_table = $wpdb->prefix . 'inquiry_tracker_clicks';
    $log_table = $wpdb->prefix . 'inquiry_tracker_log';
    $charset   = $wpdb->get_charset_collate();

    // 1. Aggregated Summary Table
    $wpdb->query( "CREATE TABLE IF NOT EXISTS `{$agg_table}` (
        `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `btn_type`     VARCHAR(20)     NOT NULL DEFAULT 'page',
        `page_url`     VARCHAR(500)    NOT NULL DEFAULT '',
        `page_title`   VARCHAR(300)    NOT NULL DEFAULT '',
        `btn_text`     VARCHAR(300)    NOT NULL DEFAULT '',
        `btn_href`     VARCHAR(500)    NOT NULL DEFAULT '',
        `btn_selector` VARCHAR(300)    NOT NULL DEFAULT '',
        `click_count`  BIGINT UNSIGNED NOT NULL DEFAULT 1,
        `first_seen`   DATETIME        NOT NULL,
        `last_clicked` DATETIME        NOT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `unique_btn` (`page_url`(190), `btn_selector`(90))
    ) {$charset};" );

    // Check & migrate btn_type if missing in agg table
    $col = $wpdb->get_results( "SHOW COLUMNS FROM `{$agg_table}` LIKE 'btn_type'" );
    if ( empty( $col ) ) {
        $wpdb->query( "ALTER TABLE `{$agg_table}` ADD COLUMN `btn_type` VARCHAR(20) NOT NULL DEFAULT 'page' AFTER `id`" );
    }

    // 2. Individual Click Log Table (for accurate Date/Period filtering)
    $wpdb->query( "CREATE TABLE IF NOT EXISTS `{$log_table}` (
        `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `btn_type`     VARCHAR(20)     NOT NULL DEFAULT 'page',
        `is_wa`        TINYINT(1)      NOT NULL DEFAULT 0,
        `page_url`     VARCHAR(500)    NOT NULL DEFAULT '',
        `page_title`   VARCHAR(300)    NOT NULL DEFAULT '',
        `btn_text`     VARCHAR(300)    NOT NULL DEFAULT '',
        `btn_href`     VARCHAR(500)    NOT NULL DEFAULT '',
        `btn_selector` VARCHAR(300)    NOT NULL DEFAULT '',
        `clicked_at`   DATETIME        NOT NULL,
        PRIMARY KEY (`id`),
        KEY `idx_clicked_at` (`clicked_at`),
        KEY `idx_is_wa` (`is_wa`),
        KEY `idx_btn_type` (`btn_type`),
        KEY `idx_page_url` (`page_url`(190))
    ) {$charset};" );

    // 3. Seed log table from aggregate table if log table is empty
    $log_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$log_table}`" );
    if ( $log_count === 0 ) {
        $existing = $wpdb->get_results( "SELECT * FROM `{$agg_table}`", ARRAY_A );
        if ( ! empty( $existing ) ) {
            foreach ( $existing as $ex ) {
                $is_wa = (
                    stripos( $ex['btn_href'], 'wa.me' ) !== false ||
                    stripos( $ex['btn_href'], 'whatsapp' ) !== false ||
                    stripos( $ex['btn_text'], 'whatsapp' ) !== false ||
                    stripos( $ex['btn_text'], 'wa' ) !== false ||
                    stripos( $ex['btn_selector'], 'wa' ) !== false ||
                    stripos( $ex['btn_selector'], 'ctc' ) !== false
                ) ? 1 : 0;

                $clicks = (int) $ex['click_count'];
                for ( $i = 0; $i < $clicks; $i++ ) {
                    $wpdb->insert( $log_table, [
                        'btn_type'     => $ex['btn_type'] ?? 'page',
                        'is_wa'        => $is_wa,
                        'page_url'     => $ex['page_url'],
                        'page_title'   => $ex['page_title'],
                        'btn_text'     => $ex['btn_text'],
                        'btn_href'     => $ex['btn_href'],
                        'btn_selector' => $ex['btn_selector'],
                        'clicked_at'   => $ex['last_clicked'] ?: current_time('mysql'),
                    ], [ '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' ] );
                }
            }
        }
    }

    update_option( 'inquiry_tracker_db_version', INQ_TRACKER_VERSION );
}
add_action( 'plugins_loaded', 'inq_tracker_ensure_tables' );
register_activation_hook( __FILE__, 'inq_tracker_ensure_tables' );

/* ─────────────────────────────────────────
   2. AJAX ENDPOINT — record a click
───────────────────────────────────────── */
add_action( 'wp_ajax_inq_track_click',        'inq_tracker_record_click' );
add_action( 'wp_ajax_nopriv_inq_track_click', 'inq_tracker_record_click' );

function inq_tracker_record_click() {
    global $wpdb;

    $ua   = strtolower( $_SERVER['HTTP_USER_AGENT'] ?? '' );
    $bots = [ 'bot', 'crawl', 'spider', 'slurp', 'mediapartners', 'facebookexternalhit', 'ia_archiver' ];
    foreach ( $bots as $b ) {
        if ( strpos( $ua, $b ) !== false ) wp_die( 'ok' );
    }

    $now        = current_time( 'mysql' );
    $btn_type   = in_array( $_POST['btn_type'] ?? '', [ 'floating', 'page' ] ) ? $_POST['btn_type'] : 'page';
    $is_wa_post = isset( $_POST['is_wa'] ) && $_POST['is_wa'] == '1' ? 1 : 0;
    $page_url   = sanitize_text_field( wp_unslash( $_POST['page_url']     ?? '' ) );
    $page_ttl   = sanitize_text_field( wp_unslash( $_POST['page_title']   ?? '' ) );
    $btn_text   = sanitize_text_field( wp_unslash( $_POST['btn_text']     ?? '' ) );
    $btn_href   = sanitize_text_field( wp_unslash( $_POST['btn_href']     ?? '' ) );
    $btn_sel    = sanitize_text_field( wp_unslash( $_POST['btn_selector'] ?? '' ) );

    if ( empty( $page_url ) ) wp_die( 'ok' );
    if ( empty( $btn_sel ) ) {
        $btn_sel = $btn_type === 'floating' ? '.floating-button' : '.button';
    }

    // WhatsApp detection
    $is_wa = $is_wa_post;
    if ( ! $is_wa ) {
        if (
            stripos( $btn_href, 'wa.me' ) !== false ||
            stripos( $btn_href, 'api.whatsapp.com' ) !== false ||
            stripos( $btn_href, 'whatsapp' ) !== false ||
            stripos( $btn_text, 'whatsapp' ) !== false ||
            stripos( $btn_text, 'wa' ) !== false ||
            stripos( $btn_sel, 'wa' ) !== false ||
            stripos( $btn_sel, 'whatsapp' ) !== false ||
            stripos( $btn_sel, 'float' ) !== false ||
            stripos( $btn_sel, 'ctc' ) !== false ||
            stripos( $btn_sel, 'joinchat' ) !== false ||
            stripos( $btn_sel, 'chat' ) !== false
        ) {
            $is_wa = 1;
        }
    }

    $agg_table = INQ_TRACKER_TABLE;
    $log_table = INQ_TRACKER_LOG_TABLE;

    // 1. Update Aggregated Table
    $updated = $wpdb->query(
        $wpdb->prepare(
            "UPDATE {$agg_table}
             SET click_count  = click_count + 1,
                 last_clicked = %s,
                 btn_type     = %s,
                 page_title   = %s,
                 btn_text     = %s,
                 btn_href     = %s
             WHERE page_url = %s AND btn_selector = %s",
            $now, $btn_type, $page_ttl, $btn_text, $btn_href, $page_url, $btn_sel
        )
    );

    if ( ! $updated ) {
        $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$agg_table}
                    (btn_type, page_url, page_title, btn_text, btn_href, btn_selector, click_count, first_seen, last_clicked)
                 VALUES (%s, %s, %s, %s, %s, %s, 1, %s, %s)
                 ON DUPLICATE KEY UPDATE
                    click_count  = click_count + 1,
                    last_clicked = VALUES(last_clicked),
                    btn_type     = VALUES(btn_type),
                    page_title   = VALUES(page_title),
                    btn_text     = VALUES(btn_text),
                    btn_href     = VALUES(btn_href)",
                $btn_type, $page_url, $page_ttl, $btn_text, $btn_href, $btn_sel, $now, $now
            )
        );
    }

    // 2. Insert into Click Log Table (with timestamp)
    $wpdb->insert( $log_table, [
        'btn_type'     => $btn_type,
        'is_wa'        => $is_wa,
        'page_url'     => $page_url,
        'page_title'   => $page_ttl,
        'btn_text'     => $btn_text,
        'btn_href'     => $btn_href,
        'btn_selector' => $btn_sel,
        'clicked_at'   => $now,
    ], [ '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' ] );

    wp_die( 'ok' );
}

/* ─────────────────────────────────────────
   3. FRONTEND JS
───────────────────────────────────────── */
add_action( 'wp_footer', 'inq_tracker_inject_js', 99 );
function inq_tracker_inject_js() {
    if ( is_admin() ) return;
    $ajax_url = admin_url( 'admin-ajax.php' );
    ?>
<script id="inq-click-tracker-js">
(function() {
  'use strict';
  var ajaxUrl = <?php echo wp_json_encode( $ajax_url ); ?>;

  var FLOAT_SELECTORS = [
    '.wa-float', '.wa-float a', '.floating-whatsapp', '.floating-wpp',
    '[id*="floating"]', '[class*="floating"]', '[class*="float-"]', '[class*="-float"]',
    '.ht-ctc-chat', '.ht-ctc-cta', '.ht-ctc', '.ctc-button', '#click-to-chat', '#ht-ctc-chat',
    '.joinchat', '.joinchat__button', '.joinchat__open', '.joinchat__chat',
    '.qlwapp', '.qlwapp-toggle', '.qlwapp-button',
    '.chaty-widget', '.chaty-whatsapp', '.chaty-whatsapp-icon', '.i-channel-whatsapp', '.chaty-button',
    '.njt-whatsapp', '.wa__btn_popup', '.wa__popup_chat_box',
    '.buttonizer', '.buttonizer-button',
    '[class*="whatsapp-float"]', '[id*="whatsapp"]', '[class*="wa-button"]', '[class*="wa_button"]',
    '.whatsapp-chat', '#whatsapp-chat', '.wp-whatsapp-chat'
  ].join(',');

  var PAGE_SELECTORS = [
    'a.elementor-button',
    '.elementor-button-wrapper a',
    '.elementor-button-link',
    'a[href*="wa.me"]',
    'a[href*="whatsapp.com"]',
    'a[href*="tel:"]',
    'a[href*="mailto:"]',
    'button[type="submit"]',
    'input[type="submit"]',
    '.wp-block-button__link',
    'a.btn',
    'a[class*="btn-"]',
    'button.btn',
    'button[class*="btn-"]',
    '[class*="elementor-button"]:not(div):not(section)',
    '.wpcf7-submit',
    '.gform_button',
    '.wpforms-submit',
    '.single_add_to_cart_button',
    '.add_to_cart_button'
  ].join(',');

  function getSafeClass(el) {
    if (!el) return '';
    try {
      if (typeof el.className === 'string') return el.className;
      if (el.className && typeof el.className.baseVal === 'string') return el.className.baseVal;
      var attr = el.getAttribute('class');
      return typeof attr === 'string' ? attr : '';
    } catch(e) { return ''; }
  }

  function findFloatingAncestor(el) {
    var node = el;
    while (node && node !== document.body && node !== document.documentElement) {
      if (node.nodeType === 1) {
        try {
          if (node.matches && node.matches(FLOAT_SELECTORS)) {
            return node;
          }
        } catch(e) {}

        try {
          var style = window.getComputedStyle(node);
          if (style && (style.position === 'fixed' || style.position === 'sticky')) {
            var isTopNav = (style.top === '0px' && style.bottom === 'auto' && node.offsetWidth > (window.innerWidth * 0.75));
            if (!isTopNav) {
              return node;
            }
          }
        } catch(e) {}

        var idCls = ((node.id || '') + ' ' + getSafeClass(node)).toLowerCase();
        if (/(float|joinchat|ctc|ht-ctc|chaty|buttonizer|qlwapp|njt-wa|whatsapp|wa-btn)/.test(idCls)) {
          return node;
        }
      }
      node = node.parentElement;
    }
    return null;
  }

  function getSelector(el) {
    try {
      var node = el;
      for (var i = 0; i < 6; i++) {
        if (!node || node === document.body) break;
        var cls = getSafeClass(node);
        if (cls) {
          var m = cls.match(/elementor-element-([\w]+)/);
          if (m) return '.elementor-element-' + m[1] + ' ' + el.tagName.toLowerCase();
        }
        node = node.parentElement;
      }
      var tag = el.tagName.toLowerCase();
      var fc  = getSafeClass(el).trim().split(/\s+/)[0];
      return fc ? tag + '.' + fc : tag;
    } catch(e) { return el.tagName ? el.tagName.toLowerCase() : 'unknown'; }
  }

  function getBtnText(el) {
    var t = (el.innerText || el.textContent || '').trim().replace(/\s+/g, ' ');
    if (!t) t = el.getAttribute('title') || el.getAttribute('aria-label') || '';
    if (!t) { var img = el.querySelector('img'); if (img) t = img.getAttribute('alt') || ''; }
    return (t || '').slice(0, 200);
  }

  var lastSentTime = 0;
  var lastSentKey  = '';

  function sendClick(data) {
    var now = Date.now();
    var key = data.btn_type + ':' + data.btn_selector + ':' + data.page_url;
    if (now - lastSentTime < 350 && lastSentKey === key) {
      return;
    }
    lastSentTime = now;
    lastSentKey  = key;

    var params = new URLSearchParams();
    params.append('action',       'inq_track_click');
    params.append('btn_type',     data.btn_type);
    params.append('is_wa',        data.is_wa ? '1' : '0');
    params.append('page_url',     data.page_url);
    params.append('page_title',   data.page_title);
    params.append('btn_text',     data.btn_text);
    params.append('btn_href',     data.btn_href);
    params.append('btn_selector', data.btn_selector);

    var payload = params.toString();
    var sent = false;

    if (navigator.sendBeacon) {
      try {
        var blob = new Blob([payload], { type: 'application/x-www-form-urlencoded; charset=UTF-8' });
        sent = navigator.sendBeacon(ajaxUrl, blob);
      } catch(e) {
        sent = false;
      }
    }

    if (!sent) {
      try {
        fetch(ajaxUrl, {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
          body: payload,
          keepalive: true
        });
      } catch(e) {
        try {
          var xhr = new XMLHttpRequest();
          xhr.open('POST', ajaxUrl, true);
          xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
          xhr.send(payload);
        } catch(err) {}
      }
    }
  }

  function handleInteraction(e) {
    var rawTarget = e.target;
    if (!rawTarget || rawTarget === document.body) return;

    // 1. FIRST: Check if clicked element is inside a FLOATING widget
    var floatEl = findFloatingAncestor(rawTarget);
    if (floatEl) {
      var aEl = (rawTarget.tagName.toLowerCase() === 'a' ? rawTarget : null) ||
                (rawTarget.closest ? rawTarget.closest('a') : null) ||
                floatEl.querySelector('a') ||
                (floatEl.tagName.toLowerCase() === 'a' ? floatEl : null);

      var href = '';
      if (aEl) {
        href = aEl.getAttribute('href') || '';
      } else {
        href = floatEl.getAttribute('data-href') ||
               floatEl.getAttribute('data-url') ||
               floatEl.getAttribute('data-number') ||
               floatEl.getAttribute('data-phone') || '';
      }

      var text = getBtnText(floatEl) || getBtnText(rawTarget);
      var floatCls = getSafeClass(floatEl).trim();
      var firstCls = floatCls ? '.' + floatCls.split(/\s+/)[0] : '';
      var selector = floatEl.id ? '#' + floatEl.id : (firstCls || '.floating-button');

      var combinedCheck = (href + ' ' + text + ' ' + floatCls + ' ' + (floatEl.id || '')).toLowerCase();
      var isWa = /(wa\.me|whatsapp|api\.whatsapp|joinchat|ctc|ht-ctc|chaty|qlwapp|chat)/.test(combinedCheck);

      if (!text && isWa) {
        text = 'WhatsApp Floating Button';
      }

      sendClick({
        btn_type:     'floating',
        is_wa:        isWa ? 1 : 0,
        page_url:     window.location.href,
        page_title:   document.title,
        btn_text:     text || 'Floating Button',
        btn_href:     href,
        btn_selector: selector
      });
      return;
    }

    // 2. SECOND: Check if clicked element is a regular PAGE button / link
    var pageEl = rawTarget.closest ? rawTarget.closest(PAGE_SELECTORS) : null;
    if (!pageEl) {
      var cur = rawTarget;
      for (var d = 0; d < 5 && cur && cur !== document.body; d++) {
        if (cur.matches && cur.matches(PAGE_SELECTORS)) {
          pageEl = cur;
          break;
        }
        cur = cur.parentElement;
      }
    }

    if (pageEl) {
      var pHref = pageEl.getAttribute('href') || '';
      var pText = getBtnText(pageEl);
      var pCheck = (pHref + ' ' + pText).toLowerCase();
      var pIsWa  = /(wa\.me|whatsapp|api\.whatsapp)/.test(pCheck);

      sendClick({
        btn_type:     'page',
        is_wa:        pIsWa ? 1 : 0,
        page_url:     window.location.href,
        page_title:   document.title,
        btn_text:     pText || '(no text)',
        btn_href:     pHref,
        btn_selector: getSelector(pageEl)
      });
    }
  }

  function attachListeners() {
    document.addEventListener('click', handleInteraction, true);
    document.addEventListener('touchend', function(e) {
      if (e.target && findFloatingAncestor(e.target)) {
        handleInteraction(e);
      }
    }, { capture: true, passive: true });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', attachListeners);
  } else {
    attachListeners();
  }
})();
</script>
    <?php
}

/* ─────────────────────────────────────────
   4. ADMIN MENU
───────────────────────────────────────── */
add_action( 'admin_menu', 'inq_tracker_add_menu' );
function inq_tracker_add_menu() {
    add_menu_page(
        'Inquiry Tracker', 'Inquiry Tracker', 'manage_options',
        'inquiry-analytics', 'inq_tracker_dashboard_page',
        'dashicons-chart-bar', 30
    );
}

/* ─────────────────────────────────────────
   5. ADMIN ACTIONS (Reset & Export CSV)
───────────────────────────────────────── */
add_action( 'admin_init', 'inq_tracker_handle_actions' );
function inq_tracker_handle_actions() {
    if ( ! current_user_can( 'manage_options' ) ) return;

    // 1. Save Ad Pages Configuration
    if ( isset( $_POST['inq_save_ad_pages'] ) && check_admin_referer( 'inq_save_ad_pages_nonce' ) ) {
        $ad_pages_raw = sanitize_textarea_field( wp_unslash( $_POST['inq_ad_pages'] ?? '' ) );
        update_option( 'inq_tracker_ad_pages', $ad_pages_raw );
        wp_redirect( admin_url( 'admin.php?page=inquiry-analytics&ad_saved=1' ) );
        exit;
    }

    // 2. Reset Data
    if ( isset( $_POST['inq_reset_data'] ) && check_admin_referer( 'inq_reset_nonce' ) ) {
        global $wpdb;
        $type = $_POST['inq_reset_type'] ?? 'all';
        $agg  = INQ_TRACKER_TABLE;
        $log  = INQ_TRACKER_LOG_TABLE;

        if ( $type === 'floating' ) {
            $wpdb->query( "DELETE FROM {$agg} WHERE btn_type = 'floating'" );
            $wpdb->query( "DELETE FROM {$log} WHERE btn_type = 'floating'" );
        } elseif ( $type === 'page' ) {
            $wpdb->query( "DELETE FROM {$agg} WHERE btn_type = 'page'" );
            $wpdb->query( "DELETE FROM {$log} WHERE btn_type = 'page'" );
        } else {
            $wpdb->query( "TRUNCATE TABLE {$agg}" );
            $wpdb->query( "TRUNCATE TABLE {$log}" );
        }
        wp_redirect( admin_url( 'admin.php?page=inquiry-analytics&reset=1' ) );
        exit;
    }

    // 3. Export CSV
    if ( isset( $_GET['inq_export'] ) && check_admin_referer( 'inq_export_nonce' ) ) {
        inq_tracker_export_csv( $_GET['inq_export'] );
    }
}

function inq_tracker_export_csv( $type = 'all' ) {
    global $wpdb;
    $log_table = INQ_TRACKER_LOG_TABLE;
    $ad_list   = inq_tracker_get_ad_list();

    // Filters for export
    $period     = sanitize_text_field( $_GET['period'] ?? 'all' );
    $traffic    = sanitize_text_field( $_GET['traffic'] ?? 'all' );
    if ( ! in_array( $traffic, [ 'all', 'ads', 'organic' ], true ) ) {
        $traffic = 'all';
    }

    $today_str  = current_time( 'Y-m-d' );
    $date_where = "";

    if ( $period === 'today' ) {
        $date_where = $wpdb->prepare( " AND clicked_at >= %s AND clicked_at <= %s", $today_str . ' 00:00:00', $today_str . ' 23:59:59' );
    } elseif ( $period === 'yesterday' ) {
        $yest = date( 'Y-m-d', strtotime( '-1 day', strtotime( $today_str ) ) );
        $date_where = $wpdb->prepare( " AND clicked_at >= %s AND clicked_at <= %s", $yest . ' 00:00:00', $yest . ' 23:59:59' );
    } elseif ( $period === '7days' ) {
        $d7 = date( 'Y-m-d', strtotime( '-6 days', strtotime( $today_str ) ) );
        $date_where = $wpdb->prepare( " AND clicked_at >= %s AND clicked_at <= %s", $d7 . ' 00:00:00', $today_str . ' 23:59:59' );
    } elseif ( $period === '30days' ) {
        $d30 = date( 'Y-m-d', strtotime( '-29 days', strtotime( $today_str ) ) );
        $date_where = $wpdb->prepare( " AND clicked_at >= %s AND clicked_at <= %s", $d30 . ' 00:00:00', $today_str . ' 23:59:59' );
    } elseif ( $period === 'this_month' ) {
        $m_start = date( 'Y-m-01', strtotime( $today_str ) );
        $date_where = $wpdb->prepare( " AND clicked_at >= %s AND clicked_at <= %s", $m_start . ' 00:00:00', $today_str . ' 23:59:59' );
    } elseif ( $period === 'custom' ) {
        $cs = sanitize_text_field( $_GET['start_date'] ?? $today_str );
        $ce = sanitize_text_field( $_GET['end_date']   ?? $today_str );
        $date_where = $wpdb->prepare( " AND clicked_at >= %s AND clicked_at <= %s", $cs . ' 00:00:00', $ce . ' 23:59:59' );
    }

    header( 'Content-Type: text/csv; charset=UTF-8' );
    header( 'Content-Disposition: attachment; filename="inquiry-report-' . sanitize_file_name( $type . '-' . $traffic . '-' . $period . '-' . date('Y-m-d') ) . '.csv"' );
    $out = fopen( 'php://output', 'w' );
    fprintf( $out, chr(0xEF).chr(0xBB).chr(0xBF) ); // UTF-8 BOM

    if ( $type === 'wa_report' ) {
        fputcsv( $out, [ 'No', 'Tipe Trafik', 'Halaman URL', 'Judul Halaman', 'Total Klik WA', 'Klik WA Halaman', 'Klik Floating WA', 'Terakhir Diklik' ] );
        $rows = $wpdb->get_results(
            "SELECT page_url, page_title,
                    COUNT(*) AS total_wa_clicks,
                    SUM(CASE WHEN btn_type = 'page' THEN 1 ELSE 0 END) AS page_wa_clicks,
                    SUM(CASE WHEN btn_type = 'floating' THEN 1 ELSE 0 END) AS float_wa_clicks,
                    MAX(clicked_at) AS last_clicked
             FROM {$log_table}
             WHERE is_wa = 1 {$date_where}
             GROUP BY page_url
             ORDER BY total_wa_clicks DESC",
            ARRAY_A
        );
        $counter = 1;
        foreach ( $rows as $r ) {
            $is_ad = inq_tracker_is_ad_page( $r['page_url'], $ad_list );
            if ( $traffic === 'ads' && ! $is_ad ) continue;
            if ( $traffic === 'organic' && $is_ad ) continue;

            fputcsv( $out, [
                $counter++,
                $is_ad ? 'Iklan (Paid)' : 'Organik',
                $r['page_url'],
                $r['page_title'],
                $r['total_wa_clicks'],
                $r['page_wa_clicks'],
                $r['float_wa_clicks'],
                $r['last_clicked'],
            ] );
        }
    } elseif ( $type === 'pages' ) {
        fputcsv( $out, [ 'Tipe Trafik', 'Halaman URL', 'Judul Halaman', 'Button Text', 'Target Link', 'CSS Selector', 'Total Klik', 'Terakhir Diklik' ] );
        $rows = $wpdb->get_results(
            "SELECT page_url, page_title, btn_text, btn_href, btn_selector,
                    COUNT(*) AS click_count,
                    MAX(clicked_at) AS last_clicked
             FROM {$log_table}
             WHERE btn_type = 'page' {$date_where}
             GROUP BY page_url, btn_selector, btn_text, btn_href
             ORDER BY page_url, click_count DESC",
            ARRAY_A
        );
        foreach ( $rows as $r ) {
            $is_ad = inq_tracker_is_ad_page( $r['page_url'], $ad_list );
            if ( $traffic === 'ads' && ! $is_ad ) continue;
            if ( $traffic === 'organic' && $is_ad ) continue;

            fputcsv( $out, [
                $is_ad ? 'Iklan (Paid)' : 'Organik',
                $r['page_url'],
                $r['page_title'],
                $r['btn_text'],
                $r['btn_href'],
                $r['btn_selector'],
                $r['click_count'],
                $r['last_clicked'],
            ] );
        }
    } elseif ( $type === 'floating' ) {
        fputcsv( $out, [ 'Tipe Halaman Asal', 'Button Text', 'Target Link', 'CSS Selector', 'Halaman Terakhir', 'Total Klik', 'Terakhir Diklik' ] );
        $rows = $wpdb->get_results(
            "SELECT btn_text, btn_href, btn_selector, page_url,
                    COUNT(*) AS click_count,
                    MAX(clicked_at) AS last_clicked
             FROM {$log_table}
             WHERE btn_type = 'floating' {$date_where}
             GROUP BY btn_selector, btn_text, btn_href, page_url
             ORDER BY click_count DESC",
            ARRAY_A
        );
        foreach ( $rows as $r ) {
            $is_ad = inq_tracker_is_ad_page( $r['page_url'], $ad_list );
            if ( $traffic === 'ads' && ! $is_ad ) continue;
            if ( $traffic === 'organic' && $is_ad ) continue;

            fputcsv( $out, [
                $is_ad ? 'Iklan (Paid)' : 'Organik',
                $r['btn_text'],
                $r['btn_href'],
                $r['btn_selector'],
                $r['page_url'],
                $r['click_count'],
                $r['last_clicked'],
            ] );
        }
    }
    fclose( $out );
    exit;
}

/* ─────────────────────────────────────────
   6. DASHBOARD PAGE (v3.0)
───────────────────────────────────────── */
function inq_tracker_dashboard_page() {
    global $wpdb;
    $log_table = INQ_TRACKER_LOG_TABLE;
    $agg_table = INQ_TRACKER_TABLE;

    // ── Determine Active Filters (Period & Traffic) ──
    $period    = sanitize_text_field( $_GET['period'] ?? 'all' );
    $traffic   = sanitize_text_field( $_GET['traffic'] ?? 'all' );
    if ( ! in_array( $traffic, [ 'all', 'ads', 'organic' ], true ) ) {
        $traffic = 'all';
    }

    $today_str    = current_time( 'Y-m-d' );
    $start_date   = '';
    $end_date     = '';
    $custom_start = sanitize_text_field( $_GET['start_date'] ?? $today_str );
    $custom_end   = sanitize_text_field( $_GET['end_date']   ?? $today_str );

    if ( $period === 'today' ) {
        $start_date   = $today_str . ' 00:00:00';
        $end_date     = $today_str . ' 23:59:59';
        $label_period = 'Hari Ini (' . date_i18n( 'd M Y', strtotime( $today_str ) ) . ')';
    } elseif ( $period === 'yesterday' ) {
        $yest_str     = date( 'Y-m-d', strtotime( '-1 day', strtotime( $today_str ) ) );
        $start_date   = $yest_str . ' 00:00:00';
        $end_date     = $yest_str . ' 23:59:59';
        $label_period = 'Kemarin (' . date_i18n( 'd M Y', strtotime( $yest_str ) ) . ')';
    } elseif ( $period === '7days' ) {
        $d7_str       = date( 'Y-m-d', strtotime( '-6 days', strtotime( $today_str ) ) );
        $start_date   = $d7_str . ' 00:00:00';
        $end_date     = $today_str . ' 23:59:59';
        $label_period = '7 Hari Terakhir (' . date_i18n( 'd M', strtotime( $d7_str ) ) . ' - ' . date_i18n( 'd M Y', strtotime( $today_str ) ) . ')';
    } elseif ( $period === '30days' ) {
        $d30_str      = date( 'Y-m-d', strtotime( '-29 days', strtotime( $today_str ) ) );
        $start_date   = $d30_str . ' 00:00:00';
        $end_date     = $today_str . ' 23:59:59';
        $label_period = '30 Hari Terakhir (' . date_i18n( 'd M', strtotime( $d30_str ) ) . ' - ' . date_i18n( 'd M Y', strtotime( $today_str ) ) . ')';
    } elseif ( $period === 'this_month' ) {
        $m_start      = date( 'Y-m-01', strtotime( $today_str ) );
        $start_date   = $m_start . ' 00:00:00';
        $end_date     = $today_str . ' 23:59:59';
        $label_period = 'Bulan Ini (' . date_i18n( 'F Y', strtotime( $today_str ) ) . ')';
    } elseif ( $period === 'custom' ) {
        $start_date   = $custom_start . ' 00:00:00';
        $end_date     = $custom_end   . ' 23:59:59';
        $label_period = date_i18n( 'd M Y', strtotime( $custom_start ) ) . ' - ' . date_i18n( 'd M Y', strtotime( $custom_end ) );
    } else {
        $period       = 'all';
        $label_period = 'Semua Waktu';
    }

    $date_where = "";
    if ( $period !== 'all' && ! empty( $start_date ) && ! empty( $end_date ) ) {
        $date_where = $wpdb->prepare( " AND clicked_at >= %s AND clicked_at <= %s", $start_date, $end_date );
    }

    $ad_list = inq_tracker_get_ad_list();

    // ── 1. Query Raw Data for Period ──
    $wa_report_raw = $wpdb->get_results(
        "SELECT page_url, page_title,
                COUNT(*) AS total_wa_clicks,
                SUM(CASE WHEN btn_type = 'page' THEN 1 ELSE 0 END) AS page_wa_clicks,
                SUM(CASE WHEN btn_type = 'floating' THEN 1 ELSE 0 END) AS float_wa_clicks,
                MAX(clicked_at) AS last_clicked
         FROM {$log_table}
         WHERE is_wa = 1 {$date_where}
         GROUP BY page_url
         ORDER BY total_wa_clicks DESC",
        ARRAY_A
    );

    $pages_raw = $wpdb->get_results(
        "SELECT page_url, page_title,
                COUNT(*) AS total_clicks,
                MAX(clicked_at) AS last_clicked
         FROM {$log_table}
         WHERE btn_type = 'page' {$date_where}
         GROUP BY page_url
         ORDER BY total_clicks DESC",
        ARRAY_A
    );

    $btns_raw = $wpdb->get_results(
        "SELECT page_url, btn_text, btn_href, btn_selector,
                COUNT(*) AS click_count,
                MAX(clicked_at) AS last_clicked
         FROM {$log_table}
         WHERE btn_type = 'page' {$date_where}
         GROUP BY page_url, btn_selector, btn_text, btn_href
         ORDER BY click_count DESC",
        ARRAY_A
    );

    $float_raw = $wpdb->get_results(
        "SELECT btn_text, btn_href, btn_selector, page_url,
                COUNT(*) AS click_count,
                MAX(clicked_at) AS last_clicked
         FROM {$log_table}
         WHERE btn_type = 'floating' {$date_where}
         GROUP BY btn_selector, btn_text, btn_href, page_url
         ORDER BY click_count DESC",
        ARRAY_A
    );

    // ── 2. Calculate Breakdown KPIs (Iklan vs Organik) ──
    $wa_ads_clicks = 0; $wa_org_clicks = 0;
    foreach ( $wa_report_raw as $r ) {
        if ( inq_tracker_is_ad_page( $r['page_url'], $ad_list ) ) {
            $wa_ads_clicks += (int) $r['total_wa_clicks'];
        } else {
            $wa_org_clicks += (int) $r['total_wa_clicks'];
        }
    }
    $total_wa_clicks_all = $wa_ads_clicks + $wa_org_clicks;

    $page_ads_clicks = 0; $page_org_clicks = 0;
    foreach ( $pages_raw as $r ) {
        if ( inq_tracker_is_ad_page( $r['page_url'], $ad_list ) ) {
            $page_ads_clicks += (int) $r['total_clicks'];
        } else {
            $page_org_clicks += (int) $r['total_clicks'];
        }
    }
    $total_page_clicks_all = $page_ads_clicks + $page_org_clicks;

    $float_ads_clicks = 0; $float_org_clicks = 0;
    foreach ( $float_raw as $r ) {
        if ( inq_tracker_is_ad_page( $r['page_url'], $ad_list ) ) {
            $float_ads_clicks += (int) $r['click_count'];
        } else {
            $float_org_clicks += (int) $r['click_count'];
        }
    }
    $total_float_clicks_all = $float_ads_clicks + $float_org_clicks;

    // ── 3. Filter Datasets According to Active Traffic Filter ──
    $wa_report_data = [];
    foreach ( $wa_report_raw as $r ) {
        $is_ad = inq_tracker_is_ad_page( $r['page_url'], $ad_list );
        if ( $traffic === 'ads' && ! $is_ad ) continue;
        if ( $traffic === 'organic' && $is_ad ) continue;
        $r['is_ad'] = $is_ad;
        $wa_report_data[] = $r;
    }

    $pages_data = [];
    foreach ( $pages_raw as $r ) {
        $is_ad = inq_tracker_is_ad_page( $r['page_url'], $ad_list );
        if ( $traffic === 'ads' && ! $is_ad ) continue;
        if ( $traffic === 'organic' && $is_ad ) continue;
        $r['is_ad'] = $is_ad;
        $pages_data[] = $r;
    }

    $btns_by_page = [];
    foreach ( $btns_raw as $btn ) {
        $is_ad = inq_tracker_is_ad_page( $btn['page_url'], $ad_list );
        if ( $traffic === 'ads' && ! $is_ad ) continue;
        if ( $traffic === 'organic' && $is_ad ) continue;
        $btn['is_ad'] = $is_ad;
        $btns_by_page[ $btn['page_url'] ][] = $btn;
    }

    $float_data = [];
    foreach ( $float_raw as $r ) {
        $is_ad = inq_tracker_is_ad_page( $r['page_url'], $ad_list );
        if ( $traffic === 'ads' && ! $is_ad ) continue;
        if ( $traffic === 'organic' && $is_ad ) continue;
        $r['is_ad'] = $is_ad;
        $float_data[] = $r;
    }

    // Active numbers for display
    if ( $traffic === 'ads' ) {
        $display_wa_clicks    = $wa_ads_clicks;
        $display_page_clicks  = $page_ads_clicks;
        $display_float_clicks = $float_ads_clicks;
        $label_traffic        = '🎯 Hanya Iklan (Paid Ads)';
    } elseif ( $traffic === 'organic' ) {
        $display_wa_clicks    = $wa_org_clicks;
        $display_page_clicks  = $page_org_clicks;
        $display_float_clicks = $float_org_clicks;
        $label_traffic        = '🌱 Hanya Organik';
    } else {
        $display_wa_clicks    = $total_wa_clicks_all;
        $display_page_clicks  = $total_page_clicks_all;
        $display_float_clicks = $total_float_clicks_all;
        $label_traffic        = 'Semua Trafik (Iklan + Organik)';
    }

    $active_pages = array_unique( array_merge( array_column( $pages_data, 'page_url' ), array_column( $float_data, 'page_url' ) ) );
    $display_pages_count = count( $active_pages );

    // Export URLs with period & traffic parameters
    $custom_params = ( $period === 'custom' ? '&start_date=' . urlencode( $custom_start ) . '&end_date=' . urlencode( $custom_end ) : '' );
    $export_wa_url    = wp_nonce_url( admin_url( 'admin.php?page=inquiry-analytics&inq_export=wa_report&period=' . $period . '&traffic=' . $traffic . $custom_params ), 'inq_export_nonce' );
    $export_page_url  = wp_nonce_url( admin_url( 'admin.php?page=inquiry-analytics&inq_export=pages&period=' . $period . '&traffic=' . $traffic . $custom_params ), 'inq_export_nonce' );
    $export_float_url = wp_nonce_url( admin_url( 'admin.php?page=inquiry-analytics&inq_export=floating&period=' . $period . '&traffic=' . $traffic . $custom_params ), 'inq_export_nonce' );
    ?>
    <div class="wrap" style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Oxygen-Sans,Ubuntu,Cantarell,sans-serif;width:100%;max-width:100%;box-sizing:border-box;margin-top:20px;padding-right:24px;">

        <!-- Header -->
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:20px;border-bottom:2px solid #e2e8f0;padding-bottom:16px;">
            <div style="display:flex;align-items:center;gap:12px;">
                <span class="dashicons dashicons-chart-bar" style="font-size:36px;width:36px;height:36px;color:#0073aa;"></span>
                <div>
                    <h1 style="margin:0;font-size:24px;font-weight:700;color:#1e293b;">Inquiry Tracker — Tracking Klik & WhatsApp</h1>
                    <div style="font-size:12px;color:#64748b;margin-top:2px;">Analisis efektivitas button & inquiry WhatsApp (Halaman Iklan vs Organik)</div>
                </div>
            </div>
            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                <div style="background:#e0f2fe;color:#0369a1;padding:6px 14px;border-radius:20px;font-size:12px;font-weight:600;display:flex;align-items:center;gap:6px;">
                    <span class="dashicons dashicons-calendar-alt" style="font-size:16px;width:16px;height:16px;"></span>
                    Periode: <?php echo esc_html( $label_period ); ?>
                </div>
                <div style="background:<?php echo $traffic === 'ads' ? '#f3e8ff;color:#7c3aed;border:1px solid #d8b4fe;' : ( $traffic === 'organic' ? '#f0fdf4;color:#16a34a;border:1px solid #bbf7d0;' : '#f1f5f9;color:#475569;border:1px solid #e2e8f0;' ); ?>;padding:6px 14px;border-radius:20px;font-size:12px;font-weight:700;display:flex;align-items:center;gap:6px;">
                    <?php echo esc_html( $label_traffic ); ?>
                </div>
            </div>
        </div>

        <?php if ( isset( $_GET['reset'] ) ): ?>
        <div class="notice notice-success is-dismissible" style="margin-bottom:20px;"><p>✅ Data berhasil direset.</p></div>
        <?php endif; ?>

        <?php if ( isset( $_GET['ad_saved'] ) ): ?>
        <div class="notice notice-success is-dismissible" style="margin-bottom:20px;">
            <p>✅ <strong>Daftar halaman iklan berhasil diperbarui!</strong> Sebanyak <?php echo count( $ad_list ); ?> link halaman iklan aktif terdaftar.</p>
        </div>
        <?php endif; ?>

        <!-- ═══════════════════════════════════════════════
             CARD: KONFIGURASI HALAMAN IKLAN (ADS LANDING PAGES)
        ════════════════════════════════════════════════ -->
        <div style="background:#fff;border:1px solid #cbd5e1;border-radius:10px;padding:18px 20px;margin-bottom:24px;box-shadow:0 1px 3px rgba(0,0,0,0.05);">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;flex-wrap:wrap;gap:8px;">
                <div style="display:flex;align-items:center;gap:8px;">
                    <span class="dashicons dashicons-megaphone" style="color:#7c3aed;font-size:22px;width:22px;height:22px;"></span>
                    <strong style="font-size:15px;color:#0f172a;">Konfigurasi Halaman Iklan (Landing Pages Ads)</strong>
                    <span style="background:#f3e8ff;color:#7c3aed;font-size:11px;font-weight:700;padding:2px 10px;border-radius:12px;border:1px solid #e9d5ff;">
                        <?php echo count( $ad_list ); ?> Halaman Terdaftar
                    </span>
                </div>
                <button type="button" onclick="inqToggleSettings()" class="button button-secondary" style="font-size:12px;" id="inq-btn-toggle-settings">
                    ⚙ Buka / Tutup Pengaturan Iklan
                </button>
            </div>

            <div id="inq-settings-body" style="<?php echo ( empty( $ad_list ) || isset( $_GET['ad_saved'] ) ) ? 'display:block;' : 'display:none;'; ?>">
                <p style="font-size:12px;color:#64748b;margin-top:0;margin-bottom:10px;line-height:1.5;">
                    Masukkan link atau path halaman landing page iklan (<strong>1 URL per baris</strong>). 
                    Semua inquiry, klik tombol, dan <em>floating WhatsApp</em> yang terjadi di halaman ini akan otomatis diberi label <strong>🎯 IKLAN</strong>.
                    <br><em>Tips: Sistem otomatis mencocokkan URL meskipun membawa parameter iklan (seperti <code>?utm_source=...</code>, <code>?gclid=...</code>, atau <code>?fbclid=...</code>).</em>
                </p>
                <form method="post" action="">
                    <?php wp_nonce_field( 'inq_save_ad_pages_nonce' ); ?>
                    <input type="hidden" name="inq_save_ad_pages" value="1">
                    <textarea name="inq_ad_pages" rows="4" style="width:100%;font-family:monospace;font-size:12px;padding:8px 10px;border:1px solid #cbd5e1;border-radius:6px;background:#f8fafc;" placeholder="Contoh:&#10;https://website-anda.com/promo-spesial/&#10;/landing-page-produk/&#10;/iklan-google/"><?php echo esc_textarea( get_option( 'inq_tracker_ad_pages', '' ) ); ?></textarea>
                    <div style="margin-top:8px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
                        <span style="font-size:11px;color:#94a3b8;">Format: <code>https://website.com/promo/</code> atau slug <code>/promo/</code></span>
                        <button type="submit" class="button button-primary" style="background:#7c3aed;border-color:#6d28d9;font-weight:600;font-size:12px;">
                            💾 Simpan Daftar Halaman Iklan
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- ═══════════════════════════════════════════════
             FILTER BAR: PERIODE WAKTU & SUMBER TRAFIK
        ════════════════════════════════════════════════ -->
        <div style="background:#fff;border:1px solid #cbd5e1;border-radius:10px;padding:16px 20px;margin-bottom:24px;box-shadow:0 1px 3px rgba(0,0,0,0.05);">
            
            <!-- Row 1: Filter Periode Waktu -->
            <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:14px;padding-bottom:14px;border-bottom:1px solid #f1f5f9;">
                <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                    <span style="font-size:13px;font-weight:700;color:#475569;margin-right:6px;">Pilih Periode:</span>
                    <?php
                    $presets = [
                        'all'        => 'Semua Waktu',
                        'today'      => 'Hari Ini',
                        'yesterday'  => 'Kemarin',
                        '7days'      => '7 Hari Terakhir',
                        '30days'     => '30 Hari Terakhir',
                        'this_month' => 'Bulan Ini',
                    ];
                    foreach ( $presets as $p_key => $p_name ):
                        $is_active = ( $period === $p_key );
                    ?>
                        <a href="<?php echo esc_url( inq_filter_url( $p_key, $traffic, $custom_start, $custom_end ) ); ?>"
                           style="display:inline-block;padding:6px 14px;border-radius:6px;font-size:12px;text-decoration:none;font-weight:<?php echo $is_active ? '700' : '500'; ?>;background:<?php echo $is_active ? '#0073aa' : '#f1f5f9'; ?>;color:<?php echo $is_active ? '#fff' : '#334155'; ?>;border:1px solid <?php echo $is_active ? '#0073aa' : '#e2e8f0'; ?>;transition:all 0.15s;">
                            <?php echo esc_html( $p_name ); ?>
                        </a>
                    <?php endforeach; ?>
                </div>

                <!-- Custom Date Range Form -->
                <form method="get" action="" style="display:flex;align-items:center;gap:8px;margin:0;flex-wrap:wrap;">
                    <input type="hidden" name="page" value="inquiry-analytics">
                    <input type="hidden" name="period" value="custom">
                    <input type="hidden" name="traffic" value="<?php echo esc_attr( $traffic ); ?>">
                    <label style="font-size:12px;font-weight:600;color:#64748b;">Custom:</label>
                    <input type="date" name="start_date" value="<?php echo esc_attr( $custom_start ); ?>" style="padding:4px 8px;font-size:12px;border:1px solid #cbd5e1;border-radius:6px;">
                    <span style="color:#94a3b8;font-size:12px;">s/d</span>
                    <input type="date" name="end_date" value="<?php echo esc_attr( $custom_end ); ?>" style="padding:4px 8px;font-size:12px;border:1px solid #cbd5e1;border-radius:6px;">
                    <button type="submit" class="button button-secondary" style="font-size:12px;padding:2px 10px;height:30px;">Terapkan</button>
                </form>
            </div>

            <!-- Row 2: Filter Sumber Trafik (Marketing: Iklan vs Organik) -->
            <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-top:12px;">
                <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                    <span style="font-size:13px;font-weight:700;color:#475569;margin-right:6px;">Filter Trafik:</span>
                    
                    <!-- Semua -->
                    <a href="<?php echo esc_url( inq_filter_url( $period, 'all', $custom_start, $custom_end ) ); ?>"
                       style="display:inline-flex;align-items:center;gap:5px;padding:6px 14px;border-radius:6px;font-size:12px;text-decoration:none;font-weight:<?php echo $traffic === 'all' ? '700' : '500'; ?>;background:<?php echo $traffic === 'all' ? '#1e293b' : '#f8fafc'; ?>;color:<?php echo $traffic === 'all' ? '#fff' : '#334155'; ?>;border:1px solid <?php echo $traffic === 'all' ? '#1e293b' : '#cbd5e1'; ?>;">
                        Semua Trafik
                        <span style="background:<?php echo $traffic === 'all' ? 'rgba(255,255,255,0.2)' : '#e2e8f0'; ?>;padding:1px 6px;border-radius:10px;font-size:11px;">
                            <?php echo number_format( $total_wa_clicks_all ); ?> WA
                        </span>
                    </a>

                    <!-- Hanya Iklan -->
                    <a href="<?php echo esc_url( inq_filter_url( $period, 'ads', $custom_start, $custom_end ) ); ?>"
                       style="display:inline-flex;align-items:center;gap:5px;padding:6px 14px;border-radius:6px;font-size:12px;text-decoration:none;font-weight:<?php echo $traffic === 'ads' ? '700' : '600'; ?>;background:<?php echo $traffic === 'ads' ? '#7c3aed' : '#faf5ff'; ?>;color:<?php echo $traffic === 'ads' ? '#fff' : '#7c3aed'; ?>;border:1px solid <?php echo $traffic === 'ads' ? '#7c3aed' : '#d8b4fe'; ?>;">
                        🎯 Hanya Halaman Iklan (Paid)
                        <span style="background:<?php echo $traffic === 'ads' ? 'rgba(255,255,255,0.2)' : '#f3e8ff'; ?>;padding:1px 6px;border-radius:10px;font-size:11px;">
                            <?php echo number_format( $wa_ads_clicks ); ?> WA
                        </span>
                    </a>

                    <!-- Hanya Organik -->
                    <a href="<?php echo esc_url( inq_filter_url( $period, 'organic', $custom_start, $custom_end ) ); ?>"
                       style="display:inline-flex;align-items:center;gap:5px;padding:6px 14px;border-radius:6px;font-size:12px;text-decoration:none;font-weight:<?php echo $traffic === 'organic' ? '700' : '600'; ?>;background:<?php echo $traffic === 'organic' ? '#059669' : '#f0fdf4'; ?>;color:<?php echo $traffic === 'organic' ? '#fff' : '#059669'; ?>;border:1px solid <?php echo $traffic === 'organic' ? '#059669' : '#a7f3d0'; ?>;">
                        🌱 Hanya Organik
                        <span style="background:<?php echo $traffic === 'organic' ? 'rgba(255,255,255,0.2)' : '#dcfce7'; ?>;padding:1px 6px;border-radius:10px;font-size:11px;">
                            <?php echo number_format( $wa_org_clicks ); ?> WA
                        </span>
                    </a>
                </div>

                <div style="font-size:12px;color:#64748b;">
                    Menampilkan data: <strong><?php echo esc_html( $label_traffic ); ?></strong>
                </div>
            </div>

        </div>

        <!-- ═══════════════════════════════════════════════
             TOP SUMMARY CARDS (TRAFFIC & PERIOD AWARE)
        ════════════════════════════════════════════════ -->
        <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(240px, 1fr));gap:16px;margin-bottom:28px;">
            
            <!-- WA Inquiries Card (HIGHLIGHT) -->
            <div style="background:linear-gradient(135deg, #10b981 0%, #059669 100%);color:#fff;border-radius:10px;padding:18px 22px;box-shadow:0 4px 6px -1px rgba(16,185,129,0.2);">
                <div style="font-size:13px;font-weight:600;opacity:0.9;display:flex;align-items:center;justify-content:space-between;">
                    <span style="display:flex;align-items:center;gap:6px;">
                        <span class="dashicons dashicons-whatsapp" style="font-size:18px;width:18px;height:18px;"></span>
                        Total Inquiry WhatsApp
                    </span>
                    <?php if ( $traffic !== 'all' ): ?>
                        <span style="background:rgba(255,255,255,0.25);font-size:10px;padding:2px 8px;border-radius:10px;font-weight:700;">
                            <?php echo $traffic === 'ads' ? 'IKLAN' : 'ORGANIK'; ?>
                        </span>
                    <?php endif; ?>
                </div>
                <div style="font-size:36px;font-weight:800;margin-top:6px;line-height:1;"><?php echo number_format( $display_wa_clicks ); ?></div>
                <div style="font-size:11px;opacity:0.9;margin-top:8px;display:flex;gap:10px;border-top:1px solid rgba(255,255,255,0.2);padding-top:6px;">
                    <span>🎯 Iklan: <b><?php echo number_format( $wa_ads_clicks ); ?></b></span>
                    <span>🌱 Organik: <b><?php echo number_format( $wa_org_clicks ); ?></b></span>
                </div>
            </div>

            <!-- Page Buttons Card -->
            <div style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:18px 22px;box-shadow:0 1px 3px rgba(0,0,0,0.05);">
                <div style="font-size:13px;color:#64748b;font-weight:600;display:flex;align-items:center;justify-content:space-between;">
                    <span style="display:flex;align-items:center;gap:6px;">
                        <span class="dashicons dashicons-admin-page" style="color:#0073aa;"></span>
                        Klik Button Halaman
                    </span>
                    <?php if ( $traffic !== 'all' ): ?>
                        <span style="background:#f1f5f9;color:#64748b;font-size:10px;padding:2px 8px;border-radius:10px;font-weight:700;">
                            <?php echo $traffic === 'ads' ? 'IKLAN' : 'ORGANIK'; ?>
                        </span>
                    <?php endif; ?>
                </div>
                <div style="font-size:34px;font-weight:700;color:#0073aa;margin-top:6px;line-height:1;"><?php echo number_format( $display_page_clicks ); ?></div>
                <div style="font-size:11px;color:#64748b;margin-top:8px;display:flex;gap:10px;border-top:1px solid #f1f5f9;padding-top:6px;">
                    <span>🎯 Iklan: <b><?php echo number_format( $page_ads_clicks ); ?></b></span>
                    <span>🌱 Organik: <b><?php echo number_format( $page_org_clicks ); ?></b></span>
                </div>
            </div>

            <!-- Floating Buttons Card -->
            <div style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:18px 22px;box-shadow:0 1px 3px rgba(0,0,0,0.05);">
                <div style="font-size:13px;color:#64748b;font-weight:600;display:flex;align-items:center;justify-content:space-between;">
                    <span style="display:flex;align-items:center;gap:6px;">
                        <span class="dashicons dashicons-format-chat" style="color:#25d366;"></span>
                        Klik Floating Button
                    </span>
                    <?php if ( $traffic !== 'all' ): ?>
                        <span style="background:#f1f5f9;color:#64748b;font-size:10px;padding:2px 8px;border-radius:10px;font-weight:700;">
                            <?php echo $traffic === 'ads' ? 'IKLAN' : 'ORGANIK'; ?>
                        </span>
                    <?php endif; ?>
                </div>
                <div style="font-size:34px;font-weight:700;color:#25d366;margin-top:6px;line-height:1;"><?php echo number_format( $display_float_clicks ); ?></div>
                <div style="font-size:11px;color:#64748b;margin-top:8px;display:flex;gap:10px;border-top:1px solid #f1f5f9;padding-top:6px;">
                    <span>🎯 Iklan: <b><?php echo number_format( $float_ads_clicks ); ?></b></span>
                    <span>🌱 Organik: <b><?php echo number_format( $float_org_clicks ); ?></b></span>
                </div>
            </div>

            <!-- Total Pages Card -->
            <div style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:18px 22px;box-shadow:0 1px 3px rgba(0,0,0,0.05);">
                <div style="font-size:13px;color:#64748b;font-weight:600;display:flex;align-items:center;justify-content:space-between;">
                    <span style="display:flex;align-items:center;gap:6px;">
                        <span class="dashicons dashicons-visibility" style="color:#8b5cf6;"></span>
                        Halaman Interaktif
                    </span>
                    <span style="background:#f3e8ff;color:#7c3aed;font-size:10px;padding:2px 8px;border-radius:10px;font-weight:700;">
                        <?php echo count( $ad_list ); ?> Iklan Terdaftar
                    </span>
                </div>
                <div style="font-size:34px;font-weight:700;color:#8b5cf6;margin-top:6px;line-height:1;"><?php echo number_format( $display_pages_count ); ?></div>
                <div style="font-size:11px;color:#64748b;margin-top:8px;border-top:1px solid #f1f5f9;padding-top:6px;">
                    Halaman aktif sesuai filter trafik
                </div>
            </div>

        </div>

        <!-- ═══════════════════════════════════════════════
             SECTION 1 (SPECIAL): REPORT KLIK BUTTON WA TIAP HALAMAN
             (Sesuai Arahan: "intinya nnti bisa jdi tabel report jumlah klik button wa tiap halaman berdasarkan periode waktu")
        ════════════════════════════════════════════════ -->
        <div style="background:#fff;border:1px solid #cbd5e1;border-radius:10px;padding:20px 24px;margin-bottom:36px;box-shadow:0 1px 3px rgba(0,0,0,0.05);">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:10px;">
                <div>
                    <h2 style="margin:0;font-size:17px;font-weight:700;color:#0f172a;display:flex;align-items:center;gap:8px;">
                        <span class="dashicons dashicons-whatsapp" style="color:#10b981;font-size:24px;width:24px;height:24px;"></span>
                        Report Klik Button WhatsApp Tiap Halaman
                        <span style="background:#10b981;color:#fff;font-size:11px;padding:2px 10px;border-radius:12px;font-weight:600;">
                            <?php echo number_format( $display_wa_clicks ); ?> Total Inquiry
                        </span>
                    </h2>
                    <div style="font-size:12px;color:#64748b;margin-top:4px;">
                        Peringkat halaman yang menghasilkan inquiry WhatsApp pada periode: <strong><?php echo esc_html( $label_period ); ?></strong> (<?php echo esc_html( $label_traffic ); ?>)
                    </div>
                </div>

                <div style="display:flex;align-items:center;gap:8px;">
                    <a href="<?php echo esc_url( $export_wa_url ); ?>" class="button button-secondary" style="font-size:12px;">
                        ⬇ Export Report WA (CSV)
                    </a>
                </div>
            </div>

            <?php if ( empty( $wa_report_data ) ): ?>
            <div style="background:#f8fafc;border:1px dashed #cbd5e1;border-radius:8px;padding:36px;text-align:center;color:#64748b;">
                <span class="dashicons dashicons-info" style="font-size:32px;width:32px;height:32px;color:#94a3b8;display:block;margin:0 auto 8px;"></span>
                Belum ada klik button WhatsApp yang tercatat pada filter aktif (<strong><?php echo esc_html( $label_period ); ?></strong> · <?php echo esc_html( $label_traffic ); ?>).
            </div>
            <?php else: ?>
            <table class="widefat striped" style="border:1px solid #e2e8f0;border-radius:8px;overflow:hidden;">
                <thead>
                    <tr style="background:#065f46;color:#fff;">
                        <th style="color:#fff;width:40px;text-align:center;">#</th>
                        <th style="color:#fff;">Halaman Asal Inquiry</th>
                        <th style="color:#fff;text-align:center;width:140px;">Button WA Page</th>
                        <th style="color:#fff;text-align:center;width:140px;">Floating WA</th>
                        <th style="color:#fff;text-align:center;width:140px;">Total Klik WA</th>
                        <th style="color:#fff;width:180px;">Terakhir Diklik</th>
                        <th style="color:#fff;width:100px;text-align:center;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $max_wa = (int) ( $wa_report_data[0]['total_wa_clicks'] ?? 1 );
                    foreach ( $wa_report_data as $idx => $wa_row ):
                        $path = parse_url( $wa_row['page_url'], PHP_URL_PATH ) ?: $wa_row['page_url'];
                        $pct  = $max_wa > 0 ? round( ( (int)$wa_row['total_wa_clicks'] / $max_wa ) * 100 ) : 0;
                        $ts   = strtotime( $wa_row['last_clicked'] );
                        $ts_str = $ts ? human_time_diff( $ts ) . ' lalu' : $wa_row['last_clicked'];
                    ?>
                    <tr>
                        <td style="text-align:center;font-weight:700;color:#64748b;"><?php echo $idx + 1; ?></td>
                        <td>
                            <div style="font-weight:600;font-size:13px;color:#0f172a;display:flex;align-items:center;flex-wrap:wrap;gap:5px;">
                                <?php echo esc_html( $path ); ?>
                                <?php if ( $path === '/' ): ?>
                                    <span style="background:#dcfce7;color:#15803d;font-size:10px;padding:2px 6px;border-radius:6px;font-weight:700;">HOMEPAGE</span>
                                <?php endif; ?>
                                <?php if ( ! empty( $wa_row['is_ad'] ) ): ?>
                                    <span style="background:#f3e8ff;color:#7c3aed;border:1px solid #d8b4fe;font-size:10px;padding:1px 6px;border-radius:6px;font-weight:700;">🎯 IKLAN</span>
                                <?php else: ?>
                                    <span style="background:#f1f5f9;color:#64748b;border:1px solid #e2e8f0;font-size:10px;padding:1px 6px;border-radius:6px;font-weight:600;">🌱 ORGANIK</span>
                                <?php endif; ?>
                            </div>
                            <?php if ( $wa_row['page_title'] ): ?>
                            <div style="font-size:11px;color:#64748b;margin-top:2px;"><?php echo esc_html( $wa_row['page_title'] ); ?></div>
                            <?php endif; ?>

                            <!-- Visual Proportion Bar -->
                            <div style="background:#e2e8f0;border-radius:4px;height:4px;width:100%;max-width:240px;margin-top:6px;">
                                <div style="background:#10b981;height:4px;border-radius:4px;width:<?php echo $pct; ?>%;"></div>
                            </div>
                        </td>

                        <td style="text-align:center;">
                            <span style="background:#f1f5f9;color:#334155;padding:3px 10px;border-radius:10px;font-size:12px;font-weight:600;">
                                <?php echo number_format( (int)$wa_row['page_wa_clicks'] ); ?>
                            </span>
                        </td>

                        <td style="text-align:center;">
                            <span style="background:#f0fdf4;color:#16a34a;padding:3px 10px;border-radius:10px;font-size:12px;font-weight:600;border:1px solid #bbf7d0;">
                                <?php echo number_format( (int)$wa_row['float_wa_clicks'] ); ?>
                            </span>
                        </td>

                        <td style="text-align:center;">
                            <span style="background:#10b981;color:#fff;padding:4px 14px;border-radius:14px;font-weight:700;font-size:14px;box-shadow:0 1px 2px rgba(0,0,0,0.1);">
                                <?php echo number_format( (int)$wa_row['total_wa_clicks'] ); ?>
                            </span>
                        </td>

                        <td style="font-size:12px;color:#64748b;">
                            <?php echo esc_html( $ts_str ); ?>
                        </td>

                        <td style="text-align:center;">
                            <a href="<?php echo esc_url( $wa_row['page_url'] ); ?>" target="_blank" class="button button-small" style="font-size:11px;">
                                Kunjungi ↗
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr style="background:#f8fafc;font-weight:700;">
                        <td colspan="4" style="text-align:right;padding:10px 16px;">Total Klik WhatsApp (Tampilan Aktif):</td>
                        <td style="text-align:center;font-size:16px;color:#059669;padding:10px 0;"><?php echo number_format( $display_wa_clicks ); ?></td>
                        <td colspan="2"></td>
                    </tr>
                </tfoot>
            </table>
            <?php endif; ?>
        </div>

        <!-- ═══════════════════════════════════════════════
             SECTION 2: PAGES (DENGAN ACCORDION / SUB-DROPDOWN TIAP BUTTON)
        ════════════════════════════════════════════════ -->
        <div style="background:#fff;border:1px solid #cbd5e1;border-radius:10px;padding:20px 24px;margin-bottom:36px;box-shadow:0 1px 3px rgba(0,0,0,0.05);">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:10px;">
                <div>
                    <h2 style="margin:0;font-size:17px;font-weight:700;color:#0f172a;display:flex;align-items:center;gap:8px;">
                        <span class="dashicons dashicons-admin-page" style="color:#0073aa;font-size:24px;width:24px;height:24px;"></span>
                        Pages — Rincian Button Tiap Halaman
                        <span style="background:#0073aa;color:#fff;font-size:11px;padding:2px 10px;border-radius:12px;font-weight:600;">
                            <?php echo number_format( count($pages_data) ); ?> Halaman · <?php echo number_format( $display_page_clicks ); ?> Klik
                        </span>
                    </h2>
                    <div style="font-size:12px;color:#64748b;margin-top:4px;">
                        Klik baris halaman mana saja untuk membuka sub-dropdown dan melihat button apa saja yang diklik.
                    </div>
                </div>

                <div style="display:flex;align-items:center;gap:8px;">
                    <a href="<?php echo esc_url( $export_page_url ); ?>" class="button button-secondary" style="font-size:12px;">
                        ⬇ Export Pages (CSV)
                    </a>
                    <form method="post" style="margin:0;" onsubmit="return confirm('Reset SEMUA data klik halaman?');">
                        <?php wp_nonce_field( 'inq_reset_nonce' ); ?>
                        <input type="hidden" name="inq_reset_data" value="1">
                        <input type="hidden" name="inq_reset_type" value="page">
                        <button type="submit" class="button" style="font-size:12px;color:#d63638;border-color:#d63638;">🗑 Reset Data Page</button>
                    </form>
                </div>
            </div>

            <?php if ( empty( $pages_data ) ): ?>
            <div style="background:#f8fafc;border:1px dashed #cbd5e1;border-radius:8px;padding:36px;text-align:center;color:#64748b;">
                Belum ada klik button halaman pada filter aktif (<strong><?php echo esc_html( $label_period ); ?></strong> · <?php echo esc_html( $label_traffic ); ?>).
            </div>
            <?php else: ?>

            <div style="border:1px solid #e2e8f0;border-radius:8px;overflow:hidden;">
                <!-- Header -->
                <div style="display:grid;grid-template-columns:40px 1fr 130px 160px 40px;background:#0073aa;color:#fff;font-weight:700;font-size:13px;padding:12px 16px;gap:10px;align-items:center;">
                    <div style="text-align:center;">#</div>
                    <div>Halaman (Klik baris untuk lihat detail button)</div>
                    <div style="text-align:center;">Total Klik</div>
                    <div>Terakhir Diklik</div>
                    <div style="text-align:center;"></div>
                </div>

                <!-- Page Rows -->
                <?php foreach ( $pages_data as $i => $page ):
                    $page_key  = md5( $page['page_url'] );
                    $path      = parse_url( $page['page_url'], PHP_URL_PATH ) ?: $page['page_url'];
                    $ts        = strtotime( $page['last_clicked'] );
                    $ts_str    = $ts ? human_time_diff( $ts ) . ' lalu' : $page['last_clicked'];
                    $btn_rows  = $btns_by_page[ $page['page_url'] ] ?? [];
                    $clk       = (int) $page['total_clicks'];
                    $badge_bg  = $clk >= 50 ? '#059669' : ( $clk >= 10 ? '#0073aa' : '#64748b' );
                ?>
                <div class="inq-page-row" style="border-top:1px solid #e2e8f0;">
                    <!-- Parent Page Bar -->
                    <div onclick="inqToggle('<?php echo $page_key; ?>')"
                         style="display:grid;grid-template-columns:40px 1fr 130px 160px 40px;padding:14px 16px;gap:10px;align-items:center;cursor:pointer;background:#fff;transition:background 0.15s;"
                         onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='#fff'">

                        <div style="color:#94a3b8;font-size:12px;text-align:center;font-weight:600;"><?php echo $i + 1; ?></div>

                        <div>
                            <div style="font-weight:700;font-size:14px;color:#0f172a;display:flex;align-items:center;flex-wrap:wrap;gap:5px;">
                                <?php echo esc_html( $path ); ?>
                                <?php if ( $path === '/' ): ?>
                                    <span style="background:#e0f2fe;color:#0369a1;font-size:10px;padding:2px 6px;border-radius:6px;font-weight:700;">HOMEPAGE</span>
                                <?php endif; ?>
                                <?php if ( ! empty( $page['is_ad'] ) ): ?>
                                    <span style="background:#f3e8ff;color:#7c3aed;border:1px solid #d8b4fe;font-size:10px;padding:1px 6px;border-radius:6px;font-weight:700;">🎯 IKLAN</span>
                                <?php else: ?>
                                    <span style="background:#f1f5f9;color:#64748b;border:1px solid #e2e8f0;font-size:10px;padding:1px 6px;border-radius:6px;font-weight:600;">🌱 ORGANIK</span>
                                <?php endif; ?>
                                <span style="font-size:11px;color:#64748b;font-weight:400;margin-left:4px;">
                                    (<?php echo count( $btn_rows ); ?> jenis button)
                                </span>
                            </div>
                            <?php if ( $page['page_title'] ): ?>
                            <div style="font-size:11px;color:#64748b;margin-top:2px;"><?php echo esc_html( $page['page_title'] ); ?></div>
                            <?php endif; ?>
                        </div>

                        <div style="text-align:center;">
                            <span style="background:<?php echo $badge_bg; ?>;color:#fff;padding:4px 12px;border-radius:12px;font-weight:700;font-size:13px;">
                                <?php echo number_format( $clk ); ?>
                            </span>
                        </div>

                        <div style="font-size:12px;color:#64748b;"><?php echo esc_html( $ts_str ); ?></div>

                        <div style="text-align:center;font-size:16px;color:#0073aa;font-weight:700;" id="arrow-<?php echo $page_key; ?>">▾</div>
                    </div>

                    <!-- Child Button Accordion Dropdown -->
                    <div id="detail-<?php echo $page_key; ?>" style="display:none;background:#f8fafc;border-top:1px solid #e2e8f0;padding:12px 20px 16px;">
                        
                        <div style="font-size:11px;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:10px;display:flex;align-items:center;justify-content:space-between;">
                            <span>Rincian Button di Halaman Ini:</span>
                            <a href="<?php echo esc_url( $page['page_url'] ); ?>" target="_blank" style="font-size:11px;color:#0073aa;text-transform:none;">
                                Buka Halaman ↗
                            </a>
                        </div>

                        <?php if ( empty( $btn_rows ) ): ?>
                        <div style="padding:10px;color:#94a3b8;font-size:12px;">Tidak ada data detail button.</div>
                        <?php else: ?>

                        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:6px;overflow:hidden;">
                            <div style="display:grid;grid-template-columns:30px 1fr 180px 100px;padding:8px 14px;background:#f1f5f9;font-size:11px;font-weight:700;color:#475569;border-bottom:1px solid #e2e8f0;">
                                <div>#</div>
                                <div>Button & Selector</div>
                                <div>Tujuan Link</div>
                                <div style="text-align:center;">Total Klik</div>
                            </div>

                            <?php foreach ( $btn_rows as $j => $btn ):
                                $b_clk   = (int) $btn['click_count'];
                                $b_pct   = $clk > 0 ? round( ( $b_clk / $clk ) * 100 ) : 0;
                                $is_wa_btn = ( stripos($btn['btn_href'], 'wa.me') !== false || stripos($btn['btn_text'], 'wa') !== false );
                            ?>
                            <div style="display:grid;grid-template-columns:30px 1fr 180px 100px;padding:10px 14px;gap:8px;align-items:center;border-bottom:1px solid #f1f5f9;background:<?php echo $j % 2 === 0 ? '#fff' : '#fafafa'; ?>;">
                                <div style="color:#94a3b8;font-size:11px;"><?php echo $j + 1; ?></div>

                                <div>
                                    <div style="font-size:13px;font-weight:600;color:#0f172a;">
                                        <?php echo esc_html( $btn['btn_text'] ?: '(tanpa teks)' ); ?>
                                        <?php if ( $is_wa_btn ): ?>
                                            <span style="background:#dcfce7;color:#15803d;font-size:10px;padding:1px 5px;border-radius:4px;margin-left:4px;font-weight:700;">WA</span>
                                        <?php endif; ?>
                                    </div>
                                    <code style="font-size:10px;color:#64748b;background:#f1f5f9;padding:1px 4px;border-radius:3px;margin-top:2px;display:inline-block;">
                                        <?php echo esc_html( substr( $btn['btn_selector'], 0, 70 ) ); ?>
                                    </code>
                                    <div style="background:#e2e8f0;border-radius:3px;height:4px;width:100%;max-width:180px;margin-top:4px;">
                                        <div style="background:<?php echo $is_wa_btn ? '#10b981' : '#0073aa'; ?>;height:4px;border-radius:3px;width:<?php echo $b_pct; ?>%;"></div>
                                    </div>
                                    <span style="font-size:10px;color:#94a3b8;"><?php echo $b_pct; ?>% dari klik halaman ini</span>
                                </div>

                                <div style="font-size:11px;color:#475569;word-break:break-all;">
                                    <?php echo $btn['btn_href'] ? esc_html( substr( $btn['btn_href'], 0, 50 ) . ( strlen( $btn['btn_href'] ) > 50 ? '…' : '' ) ) : '<span style="color:#cbd5e1;">—</span>'; ?>
                                </div>

                                <div style="text-align:center;">
                                    <span style="background:<?php echo $is_wa_btn ? '#10b981' : '#0073aa'; ?>;color:#fff;padding:3px 10px;border-radius:10px;font-weight:700;font-size:12px;">
                                        <?php echo number_format( $b_clk ); ?>
                                    </span>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>

                        <?php endif; ?>

                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <?php endif; ?>
        </div>

        <!-- ═══════════════════════════════════════════════
             SECTION 3: FLOATING BUTTONS
        ════════════════════════════════════════════════ -->
        <div style="background:#fff;border:1px solid #cbd5e1;border-radius:10px;padding:20px 24px;margin-bottom:36px;box-shadow:0 1px 3px rgba(0,0,0,0.05);">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:10px;">
                <div>
                    <h2 style="margin:0;font-size:17px;font-weight:700;color:#0f172a;display:flex;align-items:center;gap:8px;">
                        <span class="dashicons dashicons-format-chat" style="color:#25d366;font-size:24px;width:24px;height:24px;"></span>
                        Floating Buttons
                        <span style="background:#25d366;color:#fff;font-size:11px;padding:2px 10px;border-radius:12px;font-weight:600;">
                            <?php echo number_format( $display_float_clicks ); ?> Klik
                        </span>
                    </h2>
                    <div style="font-size:12px;color:#64748b;margin-top:4px;">
                        Klik pada floating action button (WhatsApp pojok layar, dll) pada periode: <strong><?php echo esc_html( $label_period ); ?></strong> (<?php echo esc_html( $label_traffic ); ?>)
                    </div>
                </div>

                <div style="display:flex;align-items:center;gap:8px;">
                    <a href="<?php echo esc_url( $export_float_url ); ?>" class="button button-secondary" style="font-size:12px;">
                        ⬇ Export Floating (CSV)
                    </a>
                    <form method="post" style="margin:0;" onsubmit="return confirm('Reset SEMUA data floating button?');">
                        <?php wp_nonce_field( 'inq_reset_nonce' ); ?>
                        <input type="hidden" name="inq_reset_data" value="1">
                        <input type="hidden" name="inq_reset_type" value="floating">
                        <button type="submit" class="button" style="font-size:12px;color:#d63638;border-color:#d63638;">🗑 Reset Floating</button>
                    </form>
                </div>
            </div>

            <?php if ( empty( $float_data ) ): ?>
            <div style="background:#f8fafc;border:1px dashed #cbd5e1;border-radius:8px;padding:36px;text-align:center;color:#64748b;">
                Belum ada data klik floating button pada filter aktif (<strong><?php echo esc_html( $label_period ); ?></strong> · <?php echo esc_html( $label_traffic ); ?>).
            </div>
            <?php else: ?>
            <div style="border:1px solid #e2e8f0;border-radius:8px;overflow:hidden;">
                <div style="display:grid;grid-template-columns:40px 1fr 1fr 140px 160px 100px;background:#10b981;color:#fff;font-weight:700;font-size:13px;padding:12px 16px;gap:10px;align-items:center;">
                    <div style="text-align:center;">#</div>
                    <div>Button</div>
                    <div>Halaman Tempat Diklik</div>
                    <div>Tujuan Link</div>
                    <div>Terakhir Diklik</div>
                    <div style="text-align:center;">Total Klik</div>
                </div>

                <?php foreach ( $float_data as $i => $row ):
                    $clk  = (int) $row['click_count'];
                    $ts   = strtotime( $row['last_clicked'] );
                    $path = parse_url( $row['page_url'], PHP_URL_PATH ) ?: $row['page_url'];
                ?>
                <div style="display:grid;grid-template-columns:40px 1fr 1fr 140px 160px 100px;padding:12px 16px;gap:10px;align-items:center;border-top:1px solid #e2e8f0;background:<?php echo $i % 2 === 0 ? '#fff' : '#f0fdf4'; ?>;">
                    <div style="color:#94a3b8;font-size:12px;text-align:center;font-weight:600;"><?php echo $i + 1; ?></div>
                    <div>
                        <div style="font-weight:700;font-size:13px;color:#0f172a;"><?php echo esc_html( $row['btn_text'] ?: 'WhatsApp Floating Badge' ); ?></div>
                        <code style="font-size:10px;color:#64748b;background:#e2e8f0;padding:1px 4px;border-radius:3px;"><?php echo esc_html( substr( $row['btn_selector'], 0, 40 ) ); ?></code>
                    </div>
                    <div>
                        <a href="<?php echo esc_url( $row['page_url'] ); ?>" target="_blank" style="font-size:12px;color:#0073aa;font-weight:600;display:block;">
                            <?php echo esc_html( $path ); ?> ↗
                        </a>
                        <?php if ( ! empty( $row['is_ad'] ) ): ?>
                            <span style="background:#f3e8ff;color:#7c3aed;border:1px solid #d8b4fe;font-size:10px;padding:1px 6px;border-radius:4px;font-weight:700;display:inline-block;margin-top:3px;">🎯 IKLAN</span>
                        <?php else: ?>
                            <span style="background:#f1f5f9;color:#64748b;border:1px solid #e2e8f0;font-size:10px;padding:1px 6px;border-radius:4px;font-weight:600;display:inline-block;margin-top:3px;">🌱 ORGANIK</span>
                        <?php endif; ?>
                    </div>
                    <div style="font-size:11px;color:#475569;word-break:break-all;">
                        <?php echo $row['btn_href'] ? esc_html( substr( $row['btn_href'], 0, 40 ) ) : '<span style="color:#cbd5e1;">—</span>'; ?>
                    </div>
                    <div style="font-size:12px;color:#64748b;">
                        <?php echo $ts ? human_time_diff( $ts ) . ' lalu' : esc_html( $row['last_clicked'] ); ?>
                    </div>
                    <div style="text-align:center;">
                        <span style="background:#10b981;color:#fff;padding:4px 14px;border-radius:14px;font-weight:700;font-size:14px;">
                            <?php echo number_format( $clk ); ?>
                        </span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- Global Reset Button -->
        <div style="text-align:right;margin-top:20px;padding-top:16px;border-top:1px solid #e2e8f0;">
            <form method="post" style="display:inline-block;margin:0;" onsubmit="return confirm('PERINGATAN: Yakin ingin menghapus SEMUA data klik (Pages & Floating)? Tindakan ini permanen.');">
                <?php wp_nonce_field( 'inq_reset_nonce' ); ?>
                <input type="hidden" name="inq_reset_data" value="1">
                <input type="hidden" name="inq_reset_type" value="all">
                <button type="submit" class="button" style="color:#b91c1c;border-color:#fca5a5;background:#fef2f2;font-size:12px;">
                    🗑 Reset Seluruh Database Klik
                </button>
            </form>
        </div>

        <p style="color:#94a3b8;font-size:11px;margin-top:16px;">
            Inquiry Tracker v<?php echo INQ_TRACKER_VERSION; ?> · First-party tracking · Bot-filtered · Ads vs Organic Ready
        </p>

    </div>

    <!-- Dashboard Toggle JS -->
    <script>
    function inqToggle(key) {
        var el    = document.getElementById('detail-' + key);
        var arrow = document.getElementById('arrow-' + key);
        if (!el) return;
        var isOpen = el.style.display !== 'none';
        el.style.display  = isOpen ? 'none' : 'block';
        if (arrow) {
            arrow.textContent = isOpen ? '▾' : '▴';
            arrow.style.color = isOpen ? '#0073aa' : '#10b981';
        }
    }

    function inqToggleSettings() {
        var el = document.getElementById('inq-settings-body');
        if (!el) return;
        var isOpen = el.style.display !== 'none';
        el.style.display = isOpen ? 'none' : 'block';
    }
    </script>
    <?php
}
