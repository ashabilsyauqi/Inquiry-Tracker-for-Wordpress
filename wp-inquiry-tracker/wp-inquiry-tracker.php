<?php
/**
 * Plugin Name: Inquiry & Button Click Tracker
 * Plugin URI:  https://wordpress.org/plugins
 * Description: Universal real-time Button, WhatsApp, and Floating Click Tracking with First-Touch Origin URL & Traffic Source Attribution (Google Search, Google Ads, Meta Ads, Instagram, Direct, Referral).
 * Version:     1.3.0
 * Author:      Digital Web Growth
 * License:     GPL-2.0-or-later
 * Text Domain: inquiry-tracker
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'INQ_TRACKER_VERSION', '1.3.0' );
define( 'INQ_TRACKER_TABLE', $GLOBALS['wpdb']->prefix . 'inquiry_tracker_clicks' );
define( 'INQ_TRACKER_LOG_TABLE', $GLOBALS['wpdb']->prefix . 'inquiry_tracker_log' );

/* ─────────────────────────────────────────
   HELPER FUNCTIONS: ORIGIN & FILTERS
───────────────────────────────────────── */
function inq_tracker_origin_name( $source ) {
    $names = [
        'google_search' => 'Google Search (Organik)',
        'google_ads'    => 'Google Ads',
        'meta_ads'      => 'Meta / Facebook Ads',
        'facebook'      => 'Facebook',
        'instagram'     => 'Instagram',
        'tiktok_ads'    => 'TikTok Ads',
        'tiktok'        => 'TikTok',
        'campaign'      => 'Custom Campaign (UTM)',
        'twitter'       => 'X / Twitter',
        'youtube'       => 'YouTube',
        'referral'      => 'Website Rujukan (Referral)',
        'direct'        => 'Direct / Langsung',
    ];
    return $names[ $source ] ?? ( $source ? ucfirst( str_replace( '_', ' ', $source ) ) : 'Direct / Langsung' );
}

function inq_tracker_origin_badge( $source ) {
    switch ( $source ) {
        case 'google_search':
            return '<span style="background:#ecfdf5;color:#047857;border:1px solid #a7f3d0;font-size:11px;padding:2px 8px;border-radius:6px;font-weight:700;">🔍 Google Search (Organik)</span>';
        case 'google_ads':
            return '<span style="background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;font-size:11px;padding:2px 8px;border-radius:6px;font-weight:700;">🎯 Google Ads</span>';
        case 'meta_ads':
            return '<span style="background:#f0fdf4;color:#15803d;border:1px solid #bbf7d0;font-size:11px;padding:2px 8px;border-radius:6px;font-weight:700;">📢 Meta / FB Ads</span>';
        case 'facebook':
            return '<span style="background:#eff6ff;color:#1e40af;border:1px solid #bfdbfe;font-size:11px;padding:2px 8px;border-radius:6px;font-weight:700;">📘 Facebook</span>';
        case 'instagram':
            return '<span style="background:#fdf2f8;color:#be185d;border:1px solid #fbcfe8;font-size:11px;padding:2px 8px;border-radius:6px;font-weight:700;">📱 Instagram</span>';
        case 'tiktok_ads':
            return '<span style="background:#f8fafc;color:#0f172a;border:1px solid #cbd5e1;font-size:11px;padding:2px 8px;border-radius:6px;font-weight:700;">🎯 TikTok Ads</span>';
        case 'tiktok':
            return '<span style="background:#f8fafc;color:#0f172a;border:1px solid #cbd5e1;font-size:11px;padding:2px 8px;border-radius:6px;font-weight:700;">🎵 TikTok</span>';
        case 'campaign':
            return '<span style="background:#f5f3ff;color:#6d28d9;border:1px solid #ddd6fe;font-size:11px;padding:2px 8px;border-radius:6px;font-weight:700;">🏷️ Custom Campaign</span>';
        case 'twitter':
            return '<span style="background:#f0f9ff;color:#0369a1;border:1px solid #bae6fd;font-size:11px;padding:2px 8px;border-radius:6px;font-weight:700;">🐦 X / Twitter</span>';
        case 'youtube':
            return '<span style="background:#fef2f2;color:#b91c1c;border:1px solid #fecaca;font-size:11px;padding:2px 8px;border-radius:6px;font-weight:700;">▶️ YouTube</span>';
        case 'referral':
            return '<span style="background:#f1f5f9;color:#475569;border:1px solid #cbd5e1;font-size:11px;padding:2px 8px;border-radius:6px;font-weight:700;">🔗 Referral Eksternal</span>';
        case 'direct':
        default:
            return '<span style="background:#f8fafc;color:#64748b;border:1px solid #e2e8f0;font-size:11px;padding:2px 8px;border-radius:6px;font-weight:600;">🌐 Direct / Langsung</span>';
    }
}

if ( ! function_exists( 'inq_filter_url' ) ) {
    function inq_filter_url( $p, $s = 'all', $cs = '', $ce = '' ) {
        $url = admin_url( 'admin.php?page=inquiry-analytics&period=' . urlencode( $p ) . '&source=' . urlencode( $s ) );
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

    // 2. Individual Click Log Table (for accurate Date/Period filtering & Origin Attribution)
    $wpdb->query( "CREATE TABLE IF NOT EXISTS `{$log_table}` (
        `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `btn_type`        VARCHAR(20)     NOT NULL DEFAULT 'page',
        `is_wa`           TINYINT(1)      NOT NULL DEFAULT 0,
        `page_url`        VARCHAR(500)    NOT NULL DEFAULT '',
        `page_title`      VARCHAR(300)    NOT NULL DEFAULT '',
        `btn_text`        VARCHAR(300)    NOT NULL DEFAULT '',
        `btn_href`        VARCHAR(500)    NOT NULL DEFAULT '',
        `btn_selector`    VARCHAR(300)    NOT NULL DEFAULT '',
        `origin_source`   VARCHAR(50)     NOT NULL DEFAULT 'direct',
        `origin_referrer` VARCHAR(500)    NOT NULL DEFAULT '',
        `origin_landing`  VARCHAR(500)    NOT NULL DEFAULT '',
        `clicked_at`      DATETIME        NOT NULL,
        PRIMARY KEY (`id`),
        KEY `idx_clicked_at` (`clicked_at`),
        KEY `idx_is_wa` (`is_wa`),
        KEY `idx_btn_type` (`btn_type`),
        KEY `idx_origin_source` (`origin_source`),
        KEY `idx_page_url` (`page_url`(190))
    ) {$charset};" );

    // Check & migrate origin columns in existing log table if missing
    $col_orig = $wpdb->get_results( "SHOW COLUMNS FROM `{$log_table}` LIKE 'origin_source'" );
    if ( empty( $col_orig ) ) {
        $wpdb->query( "ALTER TABLE `{$log_table}` 
            ADD COLUMN `origin_source` VARCHAR(50) NOT NULL DEFAULT 'direct' AFTER `btn_selector`,
            ADD COLUMN `origin_referrer` VARCHAR(500) NOT NULL DEFAULT '' AFTER `origin_source`,
            ADD COLUMN `origin_landing` VARCHAR(500) NOT NULL DEFAULT '' AFTER `origin_referrer`,
            ADD KEY `idx_origin_source` (`origin_source`)" );
    }

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
                        'btn_type'        => $ex['btn_type'] ?? 'page',
                        'is_wa'           => $is_wa,
                        'page_url'        => $ex['page_url'],
                        'page_title'      => $ex['page_title'],
                        'btn_text'        => $ex['btn_text'],
                        'btn_href'        => $ex['btn_href'],
                        'btn_selector'    => $ex['btn_selector'],
                        'origin_source'   => 'direct',
                        'origin_referrer' => '',
                        'origin_landing'  => $ex['page_url'],
                        'clicked_at'      => $ex['last_clicked'] ?: current_time('mysql'),
                    ], [ '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ] );
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

    $now             = current_time( 'mysql' );
    $btn_type        = in_array( $_POST['btn_type'] ?? '', [ 'floating', 'page' ] ) ? $_POST['btn_type'] : 'page';
    $is_wa_post      = isset( $_POST['is_wa'] ) && $_POST['is_wa'] == '1' ? 1 : 0;
    $page_url        = sanitize_text_field( wp_unslash( $_POST['page_url']        ?? '' ) );
    $page_ttl        = sanitize_text_field( wp_unslash( $_POST['page_title']      ?? '' ) );
    $btn_text        = sanitize_text_field( wp_unslash( $_POST['btn_text']        ?? '' ) );
    $btn_href        = sanitize_text_field( wp_unslash( $_POST['btn_href']        ?? '' ) );
    $btn_sel         = sanitize_text_field( wp_unslash( $_POST['btn_selector']    ?? '' ) );
    $origin_source   = sanitize_text_field( wp_unslash( $_POST['origin_source']   ?? 'direct' ) );
    $origin_referrer = sanitize_text_field( wp_unslash( $_POST['origin_referrer'] ?? '' ) );
    $origin_landing  = sanitize_text_field( wp_unslash( $_POST['origin_landing']  ?? '' ) );

    if ( empty( $origin_source ) ) {
        $origin_source = 'direct';
    }
    $origin_source   = substr( $origin_source, 0, 50 );
    $origin_referrer = substr( $origin_referrer, 0, 500 );
    $origin_landing  = substr( $origin_landing, 0, 500 );

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

    // 2. Insert into Click Log Table (with timestamp & origin attribution)
    $wpdb->insert( $log_table, [
        'btn_type'        => $btn_type,
        'is_wa'           => $is_wa,
        'page_url'        => $page_url,
        'page_title'      => $page_ttl,
        'btn_text'        => $btn_text,
        'btn_href'        => $btn_href,
        'btn_selector'    => $btn_sel,
        'origin_source'   => $origin_source,
        'origin_referrer' => $origin_referrer,
        'origin_landing'  => $origin_landing,
        'clicked_at'      => $now,
    ], [ '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ] );

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

  // ── 1. First-Touch Origin & Referrer Tracking ──
  function initOriginTracking() {
    try {
      if (sessionStorage.getItem('inq_origin_source')) {
        return; // Session already initialized with first-touch
      }

      var source = 'direct';
      var ref = document.referrer ? document.referrer.trim() : '';
      var landing = window.location.href;
      var curHost = window.location.hostname.toLowerCase();
      var searchStr = window.location.search || '';

      // A. Check Paid Ads & Campaign URL parameters
      var urlParams = new URLSearchParams(searchStr);
      var gclid = urlParams.get('gclid') || urlParams.get('gbraid') || urlParams.get('wbraid');
      var fbclid = urlParams.get('fbclid');
      var ttclid = urlParams.get('ttclid');
      var utmSource = (urlParams.get('utm_source') || '').toLowerCase();
      var utmMedium = (urlParams.get('utm_medium') || '').toLowerCase();

      if (gclid) {
        source = 'google_ads';
      } else if (fbclid) {
        source = 'meta_ads';
      } else if (ttclid) {
        source = 'tiktok_ads';
      } else if (utmSource || utmMedium) {
        if (utmSource.indexOf('google') !== -1 && /cpc|ppc|paid|ads/.test(utmMedium)) {
          source = 'google_ads';
        } else if (/facebook|meta|fb|instagram|ig/.test(utmSource) && /cpc|ppc|paid|ads/.test(utmMedium)) {
          source = 'meta_ads';
        } else if (utmSource.indexOf('tiktok') !== -1 && /cpc|ppc|paid|ads/.test(utmMedium)) {
          source = 'tiktok_ads';
        } else if (utmSource.indexOf('google') !== -1) {
          source = 'google_search';
        } else if (/instagram|ig/.test(utmSource)) {
          source = 'instagram';
        } else if (/facebook|fb/.test(utmSource)) {
          source = 'facebook';
        } else {
          source = 'campaign';
        }
      } else if (ref) {
        // B. Check Referrer Hostname
        try {
          var refUrl = new URL(ref);
          var refHost = refUrl.hostname.toLowerCase();

          if (refHost === curHost || refHost.indexOf('.' + curHost) !== -1 || curHost.indexOf('.' + refHost) !== -1) {
            source = 'direct';
          } else if (/google\.[a-z.]+/.test(refHost)) {
            source = 'google_search';
          } else if (/instagram\.com|l\.instagram\.com/.test(refHost)) {
            source = 'instagram';
          } else if (/facebook\.com|l\.facebook\.com|lm\.facebook\.com|fb\.com/.test(refHost)) {
            source = 'facebook';
          } else if (/tiktok\.com/.test(refHost)) {
            source = 'tiktok';
          } else if (/twitter\.com|t\.co|x\.com/.test(refHost)) {
            source = 'twitter';
          } else if (/youtube\.com|youtu\.be/.test(refHost)) {
            source = 'youtube';
          } else if (/bing\.com/.test(refHost)) {
            source = 'bing_search';
          } else if (/yahoo\.[a-z.]+/.test(refHost)) {
            source = 'yahoo_search';
          } else {
            source = 'referral';
          }
        } catch(e) {
          source = 'referral';
        }
      } else {
        source = 'direct';
      }

      sessionStorage.setItem('inq_origin_source', source);
      sessionStorage.setItem('inq_origin_referrer', ref);
      sessionStorage.setItem('inq_origin_landing', landing);
    } catch(err) {}
  }

  initOriginTracking();

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

    var origSource   = 'direct';
    var origReferrer = '';
    var origLanding  = window.location.href;
    try {
      origSource   = sessionStorage.getItem('inq_origin_source') || 'direct';
      origReferrer = sessionStorage.getItem('inq_origin_referrer') || '';
      origLanding  = sessionStorage.getItem('inq_origin_landing') || window.location.href;
    } catch(e) {}

    var params = new URLSearchParams();
    params.append('action',          'inq_track_click');
    params.append('btn_type',        data.btn_type);
    params.append('is_wa',           data.is_wa ? '1' : '0');
    params.append('page_url',        data.page_url);
    params.append('page_title',      data.page_title);
    params.append('btn_text',        data.btn_text);
    params.append('btn_href',        data.btn_href);
    params.append('btn_selector',    data.btn_selector);
    params.append('origin_source',   origSource);
    params.append('origin_referrer', origReferrer);
    params.append('origin_landing',  origLanding);

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

    // 1. Reset Data
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

    // 2. Export CSV
    if ( isset( $_GET['inq_export'] ) && check_admin_referer( 'inq_export_nonce' ) ) {
        inq_tracker_export_csv( sanitize_text_field( $_GET['inq_export'] ) );
    }
}

function inq_tracker_export_csv( $type = 'all' ) {
    global $wpdb;
    $log_table = INQ_TRACKER_LOG_TABLE;

    // Filters for export
    $period = sanitize_text_field( $_GET['period'] ?? 'all' );
    $source = sanitize_text_field( $_GET['source'] ?? 'all' );

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

    $source_where = "";
    if ( $source !== 'all' && ! empty( $source ) ) {
        $source_where = $wpdb->prepare( " AND origin_source = %s", $source );
    }

    header( 'Content-Type: text/csv; charset=UTF-8' );
    header( 'Content-Disposition: attachment; filename="inquiry-report-' . sanitize_file_name( $type . '-' . $source . '-' . $period . '-' . date('Y-m-d') ) . '.csv"' );
    $out = fopen( 'php://output', 'w' );
    fprintf( $out, chr(0xEF).chr(0xBB).chr(0xBF) ); // UTF-8 BOM

    if ( $type === 'leads' ) {
        fputcsv( $out, [ 'No', 'Waktu Masuk', 'Sumber Trafik (Origin)', 'URL Referrer Asal', 'Landing Page Pertama', 'Halaman Konversi (Klik WA)', 'Tipe Button', 'Teks Button', 'Target Link WA' ] );
        $rows = $wpdb->get_results(
            "SELECT * FROM {$log_table}
             WHERE is_wa = 1 {$date_where} {$source_where}
             ORDER BY clicked_at DESC LIMIT 5000",
            ARRAY_A
        );
        $counter = 1;
        foreach ( $rows as $r ) {
            fputcsv( $out, [
                $counter++,
                $r['clicked_at'],
                inq_tracker_origin_name( $r['origin_source'] ),
                $r['origin_referrer'] ?: '(Direct / Ketik URL)',
                $r['origin_landing'] ?: '-',
                $r['page_url'],
                $r['btn_type'] === 'floating' ? 'Floating WA' : 'Page Button',
                $r['btn_text'],
                $r['btn_href'],
            ] );
        }
    } elseif ( $type === 'origin' ) {
        fputcsv( $out, [ 'No', 'Sumber Trafik', 'URL Referrer Asal', 'Landing Page Pertama', 'Halaman Konversi (CTA)', 'Total Klik Button', 'Total Inquiry WA', 'Terakhir Diklik' ] );
        $rows = $wpdb->get_results(
            "SELECT origin_source, origin_referrer, origin_landing, page_url,
                    COUNT(*) AS total_clicks,
                    SUM(CASE WHEN is_wa = 1 THEN 1 ELSE 0 END) AS total_wa,
                    MAX(clicked_at) AS last_clicked
             FROM {$log_table}
             WHERE 1=1 {$date_where} {$source_where}
             GROUP BY origin_source, origin_referrer, origin_landing, page_url
             ORDER BY total_wa DESC, total_clicks DESC",
            ARRAY_A
        );
        $counter = 1;
        foreach ( $rows as $r ) {
            fputcsv( $out, [
                $counter++,
                inq_tracker_origin_name( $r['origin_source'] ),
                $r['origin_referrer'] ?: '(Direct / Tidak Ada Referrer)',
                $r['origin_landing'] ?: '-',
                $r['page_url'],
                $r['total_clicks'],
                $r['total_wa'],
                $r['last_clicked'],
            ] );
        }
    } elseif ( $type === 'wa_report' ) {
        fputcsv( $out, [ 'No', 'Halaman URL', 'Judul Halaman', 'Total Klik WA', 'Klik WA Halaman', 'Klik Floating WA', 'Terakhir Diklik' ] );
        $rows = $wpdb->get_results(
            "SELECT page_url, page_title,
                    COUNT(*) AS total_wa_clicks,
                    SUM(CASE WHEN btn_type = 'page' THEN 1 ELSE 0 END) AS page_wa_clicks,
                    SUM(CASE WHEN btn_type = 'floating' THEN 1 ELSE 0 END) AS float_wa_clicks,
                    MAX(clicked_at) AS last_clicked
             FROM {$log_table}
             WHERE is_wa = 1 {$date_where} {$source_where}
             GROUP BY page_url
             ORDER BY total_wa_clicks DESC",
            ARRAY_A
        );
        $counter = 1;
        foreach ( $rows as $r ) {
            fputcsv( $out, [
                $counter++,
                $r['page_url'],
                $r['page_title'],
                $r['total_wa_clicks'],
                $r['page_wa_clicks'],
                $r['float_wa_clicks'],
                $r['last_clicked'],
            ] );
        }
    } elseif ( $type === 'pages' ) {
        fputcsv( $out, [ 'No', 'Halaman URL', 'Judul Halaman', 'Button Text', 'Target Link', 'CSS Selector', 'Total Klik', 'Terakhir Diklik' ] );
        $rows = $wpdb->get_results(
            "SELECT page_url, page_title, btn_text, btn_href, btn_selector,
                    COUNT(*) AS click_count,
                    MAX(clicked_at) AS last_clicked
             FROM {$log_table}
             WHERE btn_type = 'page' {$date_where} {$source_where}
             GROUP BY page_url, btn_selector, btn_text, btn_href
             ORDER BY page_url, click_count DESC",
            ARRAY_A
        );
        $counter = 1;
        foreach ( $rows as $r ) {
            fputcsv( $out, [
                $counter++,
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
        fputcsv( $out, [ 'No', 'Button Text', 'Target Link', 'CSS Selector', 'Halaman Terakhir', 'Total Klik', 'Terakhir Diklik' ] );
        $rows = $wpdb->get_results(
            "SELECT btn_text, btn_href, btn_selector, page_url,
                    COUNT(*) AS click_count,
                    MAX(clicked_at) AS last_clicked
             FROM {$log_table}
             WHERE btn_type = 'floating' {$date_where} {$source_where}
             GROUP BY btn_selector, btn_text, btn_href, page_url
             ORDER BY click_count DESC",
            ARRAY_A
        );
        $counter = 1;
        foreach ( $rows as $r ) {
            fputcsv( $out, [
                $counter++,
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
   6. DASHBOARD PAGE (v1.3.0 with Origin Attribution)
───────────────────────────────────────── */
function inq_tracker_dashboard_page() {
    global $wpdb;
    $log_table = INQ_TRACKER_LOG_TABLE;
    $agg_table = INQ_TRACKER_TABLE;

    // ── Determine Active Filters (Period & Source) ──
    $period = sanitize_text_field( $_GET['period'] ?? 'all' );
    $source = sanitize_text_field( $_GET['source'] ?? 'all' );

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

    // ── 1. Query Overall Stats by Source for Filter Pills & Highlights ──
    $source_stats_raw = $wpdb->get_results(
        "SELECT origin_source,
                COUNT(*) AS total_clicks,
                SUM(CASE WHEN is_wa = 1 THEN 1 ELSE 0 END) AS total_wa
         FROM {$log_table}
         WHERE 1=1 {$date_where}
         GROUP BY origin_source
         ORDER BY total_wa DESC, total_clicks DESC",
        ARRAY_A
    );

    $source_wa_map     = [];
    $source_clicks_map = [];
    $total_wa_all      = 0;
    $total_clicks_all  = 0;

    foreach ( $source_stats_raw as $s_row ) {
        $s_key = $s_row['origin_source'] ?: 'direct';
        $source_wa_map[ $s_key ]     = (int) $s_row['total_wa'];
        $source_clicks_map[ $s_key ] = (int) $s_row['total_clicks'];
        $total_wa_all               += (int) $s_row['total_wa'];
        $total_clicks_all           += (int) $s_row['total_clicks'];
    }

    // ── 2. Source Where Clause ──
    $source_where = "";
    if ( $source !== 'all' && ! empty( $source ) ) {
        $source_where = $wpdb->prepare( " AND origin_source = %s", $source );
        $label_source = inq_tracker_origin_name( $source );
    } else {
        $source       = 'all';
        $label_source = 'Semua Sumber Trafik';
    }

    // ── 3. Query Leads Data (PER LEADS - Real-time Inquiry Log) ──
    $leads_data = $wpdb->get_results(
        "SELECT id, btn_type, is_wa, page_url, page_title, btn_text, btn_href, btn_selector,
                origin_source, origin_referrer, origin_landing, clicked_at
         FROM {$log_table}
         WHERE is_wa = 1 {$date_where} {$source_where}
         ORDER BY clicked_at DESC
         LIMIT 100",
        ARRAY_A
    );

    // ── 4. Query Origin Aggregated Summary Data ──
    $origin_report_data = $wpdb->get_results(
        "SELECT origin_source, origin_referrer, origin_landing, page_url,
                COUNT(*) AS total_clicks,
                SUM(CASE WHEN is_wa = 1 THEN 1 ELSE 0 END) AS total_wa,
                SUM(CASE WHEN btn_type = 'page' THEN 1 ELSE 0 END) AS page_clicks,
                SUM(CASE WHEN btn_type = 'floating' THEN 1 ELSE 0 END) AS float_clicks,
                MAX(clicked_at) AS last_clicked
         FROM {$log_table}
         WHERE 1=1 {$date_where} {$source_where}
         GROUP BY origin_source, origin_referrer, origin_landing, page_url
         ORDER BY total_wa DESC, total_clicks DESC",
        ARRAY_A
    );

    // ── 5. Query Section Data ──
    $wa_report_data = $wpdb->get_results(
        "SELECT page_url, page_title,
                COUNT(*) AS total_wa_clicks,
                SUM(CASE WHEN btn_type = 'page' THEN 1 ELSE 0 END) AS page_wa_clicks,
                SUM(CASE WHEN btn_type = 'floating' THEN 1 ELSE 0 END) AS float_wa_clicks,
                MAX(clicked_at) AS last_clicked
         FROM {$log_table}
         WHERE is_wa = 1 {$date_where} {$source_where}
         GROUP BY page_url
         ORDER BY total_wa_clicks DESC",
        ARRAY_A
    );

    $pages_raw = $wpdb->get_results(
        "SELECT page_url, page_title,
                COUNT(*) AS total_clicks,
                MAX(clicked_at) AS last_clicked
         FROM {$log_table}
         WHERE btn_type = 'page' {$date_where} {$source_where}
         GROUP BY page_url
         ORDER BY total_clicks DESC",
        ARRAY_A
    );

    $btns_raw = $wpdb->get_results(
        "SELECT page_url, btn_text, btn_href, btn_selector,
                COUNT(*) AS click_count,
                MAX(clicked_at) AS last_clicked
         FROM {$log_table}
         WHERE btn_type = 'page' {$date_where} {$source_where}
         GROUP BY page_url, btn_selector, btn_text, btn_href
         ORDER BY click_count DESC",
        ARRAY_A
    );

    $float_raw = $wpdb->get_results(
        "SELECT btn_text, btn_href, btn_selector, page_url,
                COUNT(*) AS click_count,
                MAX(clicked_at) AS last_clicked
         FROM {$log_table}
         WHERE btn_type = 'floating' {$date_where} {$source_where}
         GROUP BY btn_selector, btn_text, btn_href, page_url
         ORDER BY click_count DESC",
        ARRAY_A
    );

    $btns_by_page = [];
    foreach ( $btns_raw as $btn ) {
        $btns_by_page[ $btn['page_url'] ][] = $btn;
    }

    // ── 6. KPI Displays ──
    $display_wa_clicks    = (int) array_sum( array_column( $wa_report_data, 'total_wa_clicks' ) );
    $display_page_clicks  = (int) array_sum( array_column( $pages_raw, 'total_clicks' ) );
    $display_float_clicks = (int) array_sum( array_column( $float_raw, 'click_count' ) );

    $active_pages = array_unique( array_merge( array_column( $pages_raw, 'page_url' ), array_column( $float_raw, 'page_url' ) ) );
    $display_pages_count = count( $active_pages );

    // Export URLs
    $custom_params     = ( $period === 'custom' ? '&start_date=' . urlencode( $custom_start ) . '&end_date=' . urlencode( $custom_end ) : '' );
    $export_leads_url  = wp_nonce_url( admin_url( 'admin.php?page=inquiry-analytics&inq_export=leads&period=' . $period . '&source=' . $source . $custom_params ), 'inq_export_nonce' );
    $export_origin_url = wp_nonce_url( admin_url( 'admin.php?page=inquiry-analytics&inq_export=origin&period=' . $period . '&source=' . $source . $custom_params ), 'inq_export_nonce' );
    $export_wa_url     = wp_nonce_url( admin_url( 'admin.php?page=inquiry-analytics&inq_export=wa_report&period=' . $period . '&source=' . $source . $custom_params ), 'inq_export_nonce' );
    $export_page_url   = wp_nonce_url( admin_url( 'admin.php?page=inquiry-analytics&inq_export=pages&period=' . $period . '&source=' . $source . $custom_params ), 'inq_export_nonce' );
    $export_float_url  = wp_nonce_url( admin_url( 'admin.php?page=inquiry-analytics&inq_export=floating&period=' . $period . '&source=' . $source . $custom_params ), 'inq_export_nonce' );

    // Known standard source presets for filter pills
    $known_sources = [
        'all'           => 'Semua Sumber',
        'google_search' => '🔍 Google Search (Organik)',
        'google_ads'    => '🎯 Google Ads',
        'meta_ads'      => '📢 Meta / FB Ads',
        'instagram'     => '📱 Instagram',
        'facebook'      => '📘 Facebook',
        'direct'        => '🌐 Direct / Langsung',
        'referral'      => '🔗 Referral Eksternal',
        'campaign'      => '🏷️ Custom Campaign',
    ];
    foreach ( $source_stats_raw as $s_row ) {
        $sk = $s_row['origin_source'];
        if ( $sk && ! isset( $known_sources[ $sk ] ) ) {
            $known_sources[ $sk ] = inq_tracker_origin_name( $sk );
        }
    }
    ?>
    <div class="wrap" style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Oxygen-Sans,Ubuntu,Cantarell,sans-serif;width:100%;max-width:100%;box-sizing:border-box;margin-top:20px;padding-right:24px;">

        <!-- Header -->
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:20px;border-bottom:2px solid #e2e8f0;padding-bottom:16px;">
            <div style="display:flex;align-items:center;gap:12px;">
                <span class="dashicons dashicons-chart-bar" style="font-size:36px;width:36px;height:36px;color:#0073aa;"></span>
                <div>
                    <h1 style="margin:0;font-size:24px;font-weight:700;color:#1e293b;">Inquiry Tracker — Tracking Klik & WhatsApp</h1>
                    <div style="font-size:12px;color:#64748b;margin-top:2px;">Analisis efektivitas button & inquiry WhatsApp dengan Atribusi Objektif Sumber Trafik (Origin URL)</div>
                </div>
            </div>
            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                <div style="background:#e0f2fe;color:#0369a1;padding:6px 14px;border-radius:20px;font-size:12px;font-weight:600;display:flex;align-items:center;gap:6px;">
                    <span class="dashicons dashicons-calendar-alt" style="font-size:16px;width:16px;height:16px;"></span>
                    Periode: <?php echo esc_html( $label_period ); ?>
                </div>
                <div style="background:#f1f5f9;color:#334155;border:1px solid #cbd5e1;padding:6px 14px;border-radius:20px;font-size:12px;font-weight:700;display:flex;align-items:center;gap:6px;">
                    <?php echo esc_html( $label_source ); ?>
                </div>
            </div>
        </div>

        <?php if ( isset( $_GET['reset'] ) ): ?>
        <div class="notice notice-success is-dismissible" style="margin-bottom:20px;"><p>✅ Data berhasil direset.</p></div>
        <?php endif; ?>

        <!-- ═══════════════════════════════════════════════
             FILTER BAR: PERIODE WAKTU & SUMBER TRAFIK (ORIGIN URL)
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
                        <a href="<?php echo esc_url( inq_filter_url( $p_key, $source, $custom_start, $custom_end ) ); ?>"
                           style="display:inline-block;padding:6px 14px;border-radius:6px;font-size:12px;text-decoration:none;font-weight:<?php echo $is_active ? '700' : '500'; ?>;background:<?php echo $is_active ? '#0073aa' : '#f1f5f9'; ?>;color:<?php echo $is_active ? '#fff' : '#334155'; ?>;border:1px solid <?php echo $is_active ? '#0073aa' : '#e2e8f0'; ?>;transition:all 0.15s;">
                            <?php echo esc_html( $p_name ); ?>
                        </a>
                    <?php endforeach; ?>
                </div>

                <!-- Custom Date Range Form -->
                <form method="get" action="" style="display:flex;align-items:center;gap:8px;margin:0;flex-wrap:wrap;">
                    <input type="hidden" name="page" value="inquiry-analytics">
                    <input type="hidden" name="period" value="custom">
                    <input type="hidden" name="source" value="<?php echo esc_attr( $source ); ?>">
                    <label style="font-size:12px;font-weight:600;color:#64748b;">Custom:</label>
                    <input type="date" name="start_date" value="<?php echo esc_attr( $custom_start ); ?>" style="padding:4px 8px;font-size:12px;border:1px solid #cbd5e1;border-radius:6px;">
                    <span style="color:#94a3b8;font-size:12px;">s/d</span>
                    <input type="date" name="end_date" value="<?php echo esc_attr( $custom_end ); ?>" style="padding:4px 8px;font-size:12px;border:1px solid #cbd5e1;border-radius:6px;">
                    <button type="submit" class="button button-secondary" style="font-size:12px;padding:2px 10px;height:30px;">Terapkan</button>
                </form>
            </div>

            <!-- Row 2: Filter Sumber Trafik (Origin URL Pills) -->
            <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-top:12px;">
                <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                    <span style="font-size:13px;font-weight:700;color:#475569;margin-right:6px;">Sumber Trafik:</span>
                    
                    <?php foreach ( $known_sources as $s_key => $s_title ):
                        $is_s_active = ( $source === $s_key );
                        $wa_num = ( $s_key === 'all' ) ? $total_wa_all : ( $source_wa_map[ $s_key ] ?? 0 );
                        // Display pill if active, or 'all', or has recorded clicks/wa
                        if ( $s_key !== 'all' && ! $is_s_active && empty( $source_clicks_map[ $s_key ] ) && empty( $source_wa_map[ $s_key ] ) ) {
                            continue;
                        }
                    ?>
                        <a href="<?php echo esc_url( inq_filter_url( $period, $s_key, $custom_start, $custom_end ) ); ?>"
                           style="display:inline-flex;align-items:center;gap:6px;padding:6px 14px;border-radius:6px;font-size:12px;text-decoration:none;font-weight:<?php echo $is_s_active ? '700' : '500'; ?>;background:<?php echo $is_s_active ? '#0073aa' : '#f8fafc'; ?>;color:<?php echo $is_s_active ? '#fff' : '#334155'; ?>;border:1px solid <?php echo $is_s_active ? '#0073aa' : '#cbd5e1'; ?>;transition:all 0.15s;">
                            <?php echo esc_html( $s_title ); ?>
                            <span style="background:<?php echo $is_s_active ? 'rgba(255,255,255,0.25)' : '#e2e8f0'; ?>;color:<?php echo $is_s_active ? '#fff' : '#475569'; ?>;padding:1px 6px;border-radius:10px;font-size:10px;font-weight:700;">
                                <?php echo number_format( $wa_num ); ?> WA
                            </span>
                        </a>
                    <?php endforeach; ?>
                </div>

                <div style="font-size:12px;color:#64748b;">
                    Filter Aktif: <strong><?php echo esc_html( $label_source ); ?></strong>
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
                        Total Leads WhatsApp
                    </span>
                    <?php if ( $source !== 'all' ): ?>
                        <span style="background:rgba(255,255,255,0.25);font-size:10px;padding:2px 8px;border-radius:10px;font-weight:700;">
                            <?php echo esc_html( strtoupper( str_replace('_', ' ', $source) ) ); ?>
                        </span>
                    <?php endif; ?>
                </div>
                <div style="font-size:36px;font-weight:800;margin-top:6px;line-height:1;"><?php echo number_format( $display_wa_clicks ); ?></div>
                
                <!-- Dynamic Breakdown by Origin Source -->
                <div style="font-size:11px;opacity:0.95;margin-top:8px;display:flex;flex-wrap:wrap;gap:8px;border-top:1px solid rgba(255,255,255,0.25);padding-top:6px;">
                    <?php
                    $wa_source_badges = [];
                    foreach ( $source_stats_raw as $s_item ) {
                        if ( (int) $s_item['total_wa'] > 0 ) {
                            $wa_source_badges[] = inq_tracker_origin_name( $s_item['origin_source'] ) . ': <b>' . number_format( (int)$s_item['total_wa'] ) . '</b>';
                        }
                    }
                    if ( empty( $wa_source_badges ) ) {
                        echo '<span>Belum ada inquiry WA tercatat</span>';
                    } else {
                        echo implode( ' <span style="opacity:0.6;">•</span> ', array_slice( $wa_source_badges, 0, 3 ) );
                    }
                    ?>
                </div>
            </div>

            <!-- Page Buttons Card -->
            <div style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:18px 22px;box-shadow:0 1px 3px rgba(0,0,0,0.05);">
                <div style="font-size:13px;color:#64748b;font-weight:600;display:flex;align-items:center;justify-content:space-between;">
                    <span style="display:flex;align-items:center;gap:6px;">
                        <span class="dashicons dashicons-admin-page" style="color:#0073aa;"></span>
                        Klik Button Halaman
                    </span>
                    <span style="background:#f1f5f9;color:#64748b;font-size:10px;padding:2px 8px;border-radius:10px;font-weight:700;">
                        PAGE CTA
                    </span>
                </div>
                <div style="font-size:34px;font-weight:700;color:#0073aa;margin-top:6px;line-height:1;"><?php echo number_format( $display_page_clicks ); ?></div>
                <div style="font-size:11px;color:#64748b;margin-top:8px;border-top:1px solid #f1f5f9;padding-top:6px;">
                    Klik elemen button atau link CTA di body halaman
                </div>
            </div>

            <!-- Floating Buttons Card -->
            <div style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:18px 22px;box-shadow:0 1px 3px rgba(0,0,0,0.05);">
                <div style="font-size:13px;color:#64748b;font-weight:600;display:flex;align-items:center;justify-content:space-between;">
                    <span style="display:flex;align-items:center;gap:6px;">
                        <span class="dashicons dashicons-format-chat" style="color:#25d366;"></span>
                        Klik Floating Button
                    </span>
                    <span style="background:#f0fdf4;color:#16a34a;border:1px solid #bbf7d0;font-size:10px;padding:2px 8px;border-radius:10px;font-weight:700;">
                        FLOATING
                    </span>
                </div>
                <div style="font-size:34px;font-weight:700;color:#25d366;margin-top:6px;line-height:1;"><?php echo number_format( $display_float_clicks ); ?></div>
                <div style="font-size:11px;color:#64748b;margin-top:8px;border-top:1px solid #f1f5f9;padding-top:6px;">
                    Klik widget floating WhatsApp / chat melayang
                </div>
            </div>

            <!-- Total Pages & Sources Card -->
            <div style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:18px 22px;box-shadow:0 1px 3px rgba(0,0,0,0.05);">
                <div style="font-size:13px;color:#64748b;font-weight:600;display:flex;align-items:center;justify-content:space-between;">
                    <span style="display:flex;align-items:center;gap:6px;">
                        <span class="dashicons dashicons-visibility" style="color:#8b5cf6;"></span>
                        Halaman Konversi Aktif
                    </span>
                    <span style="background:#f3e8ff;color:#7c3aed;font-size:10px;padding:2px 8px;border-radius:10px;font-weight:700;">
                        INTERAKTIF
                    </span>
                </div>
                <div style="font-size:34px;font-weight:700;color:#8b5cf6;margin-top:6px;line-height:1;"><?php echo number_format( $display_pages_count ); ?></div>
                <div style="font-size:11px;color:#64748b;margin-top:8px;border-top:1px solid #f1f5f9;padding-top:6px;">
                    <?php echo count($origin_report_data); ?> variasi jalur origin ke konversi
                </div>
            </div>

        </div>

        <!-- ═══════════════════════════════════════════════
             SECTION 1 (HIGHLIGHT): DAFTAR LEADS & INQUIRY WHATSAPP (PER LEAD)
        ════════════════════════════════════════════════ -->
        <div style="background:#fff;border:1px solid #cbd5e1;border-radius:10px;padding:20px 24px;margin-bottom:36px;box-shadow:0 1px 3px rgba(0,0,0,0.05);">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:10px;">
                <div>
                    <h2 style="margin:0;font-size:17px;font-weight:700;color:#0f172a;display:flex;align-items:center;gap:8px;">
                        <span class="dashicons dashicons-whatsapp" style="color:#10b981;font-size:24px;width:24px;height:24px;"></span>
                        Riwayat Leads & Inquiry WhatsApp (Real-Time Per Lead)
                        <span style="background:#10b981;color:#fff;font-size:11px;padding:2px 10px;border-radius:12px;font-weight:600;">
                            <?php echo count( $leads_data ); ?> Leads Tercatat
                        </span>
                    </h2>
                    <div style="font-size:12px;color:#64748b;margin-top:4px;">
                        Setiap baris mencatat 1 pengunjung (per lead) yang melakukan klik WhatsApp, lengkap dengan <strong>Origin URL (dari mana mereka dapat link)</strong>, landing page awal, dan halaman konversi.
                    </div>
                </div>

                <div style="display:flex;align-items:center;gap:8px;">
                    <a href="<?php echo esc_url( $export_leads_url ); ?>" class="button button-secondary" style="font-size:12px;">
                        ⬇ Export Data Leads (CSV)
                    </a>
                </div>
            </div>

            <?php if ( empty( $leads_data ) ): ?>
            <div style="background:#f8fafc;border:1px dashed #cbd5e1;border-radius:8px;padding:36px;text-align:center;color:#64748b;">
                <span class="dashicons dashicons-info" style="font-size:32px;width:32px;height:32px;color:#94a3b8;display:block;margin:0 auto 8px;"></span>
                Belum ada leads inquiry WhatsApp yang tercatat pada filter aktif (<strong><?php echo esc_html( $label_period ); ?></strong> · <?php echo esc_html( $label_source ); ?>).
            </div>
            <?php else: ?>
            <div style="border:1px solid #e2e8f0;border-radius:8px;overflow-x:auto;">
                <table class="widefat striped" style="border:none;margin:0;">
                    <thead>
                        <tr style="background:#065f46;color:#fff;">
                            <th style="color:#fff;width:35px;text-align:center;">#</th>
                            <th style="color:#fff;width:145px;">Waktu Lead Masuk</th>
                            <th style="color:#fff;width:170px;">Sumber Trafik (Origin)</th>
                            <th style="color:#fff;width:200px;">URL Referrer Asal</th>
                            <th style="color:#fff;">Landing Page Pertama</th>
                            <th style="color:#fff;">Halaman Konversi (Klik WA)</th>
                            <th style="color:#fff;width:150px;">Tombol yang Diklik</th>
                            <th style="color:#fff;width:80px;text-align:center;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $leads_data as $idx => $lead ):
                            $land_url  = $lead['origin_landing'] ?: $lead['page_url'];
                            $land_path = parse_url( $land_url, PHP_URL_PATH ) ?: $land_url;
                            $conv_path = parse_url( $lead['page_url'], PHP_URL_PATH ) ?: $lead['page_url'];
                            $ts        = strtotime( $lead['clicked_at'] );
                            $ts_date   = $ts ? date_i18n( 'd M Y, H:i', $ts ) : $lead['clicked_at'];
                            $ts_rel    = $ts ? human_time_diff( $ts ) . ' lalu' : '';
                        ?>
                        <tr>
                            <td style="text-align:center;font-weight:700;color:#64748b;"><?php echo $idx + 1; ?></td>
                            <td>
                                <div style="font-weight:600;font-size:12px;color:#0f172a;"><?php echo esc_html( $ts_date ); ?></div>
                                <div style="font-size:10px;color:#94a3b8;"><?php echo esc_html( $ts_rel ); ?></div>
                            </td>
                            <td>
                                <?php echo inq_tracker_origin_badge( $lead['origin_source'] ); ?>
                            </td>
                            <td>
                                <?php if ( empty( $lead['origin_referrer'] ) ): ?>
                                    <span style="font-size:11px;color:#64748b;">🌐 Direct (Ketik URL)</span>
                                <?php else: ?>
                                    <a href="<?php echo esc_url( $lead['origin_referrer'] ); ?>" target="_blank" style="font-size:11px;color:#0284c7;text-decoration:none;word-break:break-all;" title="<?php echo esc_attr( $lead['origin_referrer'] ); ?>">
                                        <?php echo esc_html( substr( $lead['origin_referrer'], 0, 38 ) . ( strlen( $lead['origin_referrer'] ) > 38 ? '…' : '' ) ); ?> ↗
                                    </a>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div style="font-size:12px;font-weight:600;color:#0f172a;word-break:break-all;">
                                    <?php echo esc_html( $land_path ); ?>
                                    <?php if ( $land_path === '/' ): ?>
                                        <span style="background:#dcfce7;color:#15803d;font-size:9px;padding:1px 5px;border-radius:4px;font-weight:700;">HOME</span>
                                    <?php endif; ?>
                                </div>
                                <?php if ( $land_url !== $land_path ): ?>
                                <a href="<?php echo esc_url( $land_url ); ?>" target="_blank" style="font-size:10px;color:#94a3b8;text-decoration:none;">Buka Landing ↗</a>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div style="font-size:12px;font-weight:600;color:#0f172a;word-break:break-all;">
                                    <?php echo esc_html( $conv_path ); ?>
                                </div>
                                <?php if ( $lead['page_title'] ): ?>
                                <div style="font-size:10px;color:#64748b;"><?php echo esc_html( substr( $lead['page_title'], 0, 40 ) ); ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div style="font-size:12px;font-weight:600;color:#0f172a;">
                                    <?php echo esc_html( $lead['btn_text'] ?: 'WhatsApp Button' ); ?>
                                </div>
                                <div style="margin-top:2px;">
                                    <?php if ( $lead['btn_type'] === 'floating' ): ?>
                                        <span style="background:#f0fdf4;color:#16a34a;border:1px solid #bbf7d0;font-size:9px;padding:1px 6px;border-radius:4px;font-weight:700;">FLOATING WA</span>
                                    <?php else: ?>
                                        <span style="background:#f1f5f9;color:#334155;border:1px solid #e2e8f0;font-size:9px;padding:1px 6px;border-radius:4px;font-weight:600;">PAGE BUTTON</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td style="text-align:center;">
                                <a href="<?php echo esc_url( $lead['page_url'] ); ?>" target="_blank" class="button button-small" style="font-size:11px;">
                                    Lihat ↗
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <!-- ═══════════════════════════════════════════════
             SECTION 2: RINGKASAN DISTRIBUSI SUMBER TRAFIK & JALUR (AGGREGATED SUMMARY)
        ════════════════════════════════════════════════ -->
        <div style="background:#fff;border:1px solid #cbd5e1;border-radius:10px;padding:20px 24px;margin-bottom:36px;box-shadow:0 1px 3px rgba(0,0,0,0.05);">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:10px;">
                <div>
                    <h2 style="margin:0;font-size:17px;font-weight:700;color:#0f172a;display:flex;align-items:center;gap:8px;">
                        <span class="dashicons dashicons-admin-site-alt3" style="color:#0284c7;font-size:24px;width:24px;height:24px;"></span>
                        Ringkasan Distribusi Sumber Trafik (Aggregated Summary)
                        <span style="background:#0284c7;color:#fff;font-size:11px;padding:2px 10px;border-radius:12px;font-weight:600;">
                            <?php echo count( $origin_report_data ); ?> Jalur Trafik
                        </span>
                    </h2>
                    <div style="font-size:12px;color:#64748b;margin-top:4px;">
                        Agregat total klik button dan konversi WhatsApp yang dikelompokkan berdasarkan asal kedatangan, URL referrer, dan halaman tujuan.
                    </div>
                </div>

                <div style="display:flex;align-items:center;gap:8px;">
                    <a href="<?php echo esc_url( $export_origin_url ); ?>" class="button button-secondary" style="font-size:12px;">
                        ⬇ Export Ringkasan Sumber Trafik (CSV)
                    </a>
                </div>
            </div>

            <?php if ( empty( $origin_report_data ) ): ?>
            <div style="background:#f8fafc;border:1px dashed #cbd5e1;border-radius:8px;padding:36px;text-align:center;color:#64748b;">
                Belum ada data ringkasan sumber trafik yang tercatat.
            </div>
            <?php else: ?>
            <div style="border:1px solid #e2e8f0;border-radius:8px;overflow-x:auto;">
                <table class="widefat striped" style="border:none;margin:0;">
                    <thead>
                        <tr style="background:#0f172a;color:#fff;">
                            <th style="color:#fff;width:35px;text-align:center;">#</th>
                            <th style="color:#fff;width:170px;">Sumber Trafik</th>
                            <th style="color:#fff;width:200px;">URL Referrer Asal</th>
                            <th style="color:#fff;">Landing Page Pertama</th>
                            <th style="color:#fff;">Halaman Konversi (CTA)</th>
                            <th style="color:#fff;text-align:center;width:100px;">Total Klik</th>
                            <th style="color:#fff;text-align:center;width:110px;">Inquiry WA</th>
                            <th style="color:#fff;width:130px;">Terakhir Terjadi</th>
                            <th style="color:#fff;width:80px;text-align:center;">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $origin_report_data as $idx => $orow ):
                            $land_url    = $orow['origin_landing'] ?: $orow['page_url'];
                            $land_path   = parse_url( $land_url, PHP_URL_PATH ) ?: $land_url;
                            $conv_path   = parse_url( $orow['page_url'], PHP_URL_PATH ) ?: $orow['page_url'];
                            $ts          = strtotime( $orow['last_clicked'] );
                            $ts_str      = $ts ? human_time_diff( $ts ) . ' lalu' : $orow['last_clicked'];
                        ?>
                        <tr>
                            <td style="text-align:center;font-weight:700;color:#64748b;"><?php echo $idx + 1; ?></td>
                            <td>
                                <?php echo inq_tracker_origin_badge( $orow['origin_source'] ); ?>
                            </td>
                            <td>
                                <?php if ( empty( $orow['origin_referrer'] ) ): ?>
                                    <span style="font-size:11px;color:#64748b;">🌐 Direct / Langsung</span>
                                <?php else: ?>
                                    <a href="<?php echo esc_url( $orow['origin_referrer'] ); ?>" target="_blank" style="font-size:11px;color:#0284c7;text-decoration:none;word-break:break-all;" title="<?php echo esc_attr( $orow['origin_referrer'] ); ?>">
                                        <?php echo esc_html( substr( $orow['origin_referrer'], 0, 40 ) . ( strlen( $orow['origin_referrer'] ) > 40 ? '…' : '' ) ); ?> ↗
                                    </a>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div style="font-size:12px;font-weight:600;color:#0f172a;word-break:break-all;">
                                    <?php echo esc_html( $land_path ); ?>
                                    <?php if ( $land_path === '/' ): ?>
                                        <span style="background:#dcfce7;color:#15803d;font-size:9px;padding:1px 5px;border-radius:4px;font-weight:700;">HOME</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <div style="font-size:12px;font-weight:600;color:#0f172a;word-break:break-all;">
                                    <?php echo esc_html( $conv_path ); ?>
                                </div>
                                <span style="font-size:10px;color:#64748b;">
                                    <?php echo ( (int)$orow['page_clicks'] > 0 ? (int)$orow['page_clicks'] . ' Button' : '' ) . ( (int)$orow['page_clicks'] > 0 && (int)$orow['float_clicks'] > 0 ? ' • ' : '' ) . ( (int)$orow['float_clicks'] > 0 ? (int)$orow['float_clicks'] . ' Floating' : '' ); ?>
                                </span>
                            </td>
                            <td style="text-align:center;">
                                <span style="background:#f1f5f9;color:#334155;padding:3px 10px;border-radius:10px;font-size:12px;font-weight:600;">
                                    <?php echo number_format( (int)$orow['total_clicks'] ); ?>
                                </span>
                            </td>
                            <td style="text-align:center;">
                                <?php if ( (int)$orow['total_wa'] > 0 ): ?>
                                    <span style="background:#10b981;color:#fff;padding:3px 10px;border-radius:10px;font-weight:700;font-size:12px;box-shadow:0 1px 2px rgba(0,0,0,0.1);">
                                        <?php echo number_format( (int)$orow['total_wa'] ); ?> WA
                                    </span>
                                <?php else: ?>
                                    <span style="color:#cbd5e1;font-size:12px;">0</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-size:11px;color:#64748b;">
                                <?php echo esc_html( $ts_str ); ?>
                            </td>
                            <td style="text-align:center;">
                                <a href="<?php echo esc_url( $orow['page_url'] ); ?>" target="_blank" class="button button-small" style="font-size:11px;">
                                    Lihat ↗
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr style="background:#f8fafc;font-weight:700;">
                            <td colspan="5" style="text-align:right;padding:10px 16px;">Total Tampilan:</td>
                            <td style="text-align:center;font-size:14px;color:#0f172a;padding:10px 0;"><?php echo number_format( array_sum( array_column( $origin_report_data, 'total_clicks' ) ) ); ?></td>
                            <td style="text-align:center;font-size:14px;color:#059669;padding:10px 0;"><?php echo number_format( array_sum( array_column( $origin_report_data, 'total_wa' ) ) ); ?> WA</td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <!-- ═══════════════════════════════════════════════
             SECTION 3: REPORT KLIK BUTTON WA TIAP HALAMAN
        ════════════════════════════════════════════════ -->
        <div style="background:#fff;border:1px solid #cbd5e1;border-radius:10px;padding:20px 24px;margin-bottom:36px;box-shadow:0 1px 3px rgba(0,0,0,0.05);">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:10px;">
                <div>
                    <h2 style="margin:0;font-size:17px;font-weight:700;color:#0f172a;display:flex;align-items:center;gap:8px;">
                        <span class="dashicons dashicons-media-spreadsheet" style="color:#0073aa;font-size:24px;width:24px;height:24px;"></span>
                        Performa WhatsApp Tiap Halaman Konversi
                        <span style="background:#0073aa;color:#fff;font-size:11px;padding:2px 10px;border-radius:12px;font-weight:600;">
                            <?php echo number_format( $display_wa_clicks ); ?> Total Inquiry
                        </span>
                    </h2>
                    <div style="font-size:12px;color:#64748b;margin-top:4px;">
                        Peringkat halaman konversi yang menghasilkan inquiry WhatsApp pada periode: <strong><?php echo esc_html( $label_period ); ?></strong> (<?php echo esc_html( $label_source ); ?>)
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
                Belum ada klik button WhatsApp yang tercatat pada filter aktif.
            </div>
            <?php else: ?>
            <div style="border:1px solid #e2e8f0;border-radius:8px;overflow-x:auto;">
                <table class="widefat striped" style="border:none;margin:0;">
                    <thead>
                        <tr style="background:#0073aa;color:#fff;">
                            <th style="color:#fff;width:40px;text-align:center;">#</th>
                            <th style="color:#fff;">Halaman Konversi</th>
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
                                </div>
                                <?php if ( $wa_row['page_title'] ): ?>
                                <div style="font-size:11px;color:#64748b;margin-top:2px;"><?php echo esc_html( $wa_row['page_title'] ); ?></div>
                                <?php endif; ?>

                                <!-- Visual Proportion Bar -->
                                <div style="background:#e2e8f0;border-radius:4px;height:4px;width:100%;max-width:240px;margin-top:6px;">
                                    <div style="background:#0073aa;height:4px;border-radius:4px;width:<?php echo $pct; ?>%;"></div>
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
            </div>
            <?php endif; ?>
        </div>

        <!-- ═══════════════════════════════════════════════
             SECTION 4: PAGES (DENGAN ACCORDION SUB-DROPDOWN TIAP BUTTON)
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
                        Klik baris halaman mana saja untuk membuka sub-dropdown dan melihat rincian button apa saja yang diklik.
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
                Belum ada klik button halaman pada filter aktif.
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
                            <div style="font-weight:700;font-size:14px;color:#0f172a;display:flex;align-items:center;flex-wrap:gap:5px;">
                                <?php echo esc_html( $path ); ?>
                                <?php if ( $path === '/' ): ?>
                                    <span style="background:#e0f2fe;color:#0369a1;font-size:10px;padding:2px 6px;border-radius:6px;font-weight:700;">HOMEPAGE</span>
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
             SECTION 5: FLOATING BUTTONS
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
                        Klik pada floating action button (WhatsApp melayang pojok layar, dll) pada periode: <strong><?php echo esc_html( $label_period ); ?></strong> (<?php echo esc_html( $label_source ); ?>)
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

            <?php if ( empty( $float_raw ) ): ?>
            <div style="background:#f8fafc;border:1px dashed #cbd5e1;border-radius:8px;padding:36px;text-align:center;color:#64748b;">
                Belum ada data klik floating button pada filter aktif.
            </div>
            <?php else: ?>
            <div style="border:1px solid #e2e8f0;border-radius:8px;overflow-x:auto;">
                <div style="display:grid;grid-template-columns:40px 1fr 1fr 140px 160px 100px;background:#10b981;color:#fff;font-weight:700;font-size:13px;padding:12px 16px;gap:10px;align-items:center;min-width:760px;">
                    <div style="text-align:center;">#</div>
                    <div>Button</div>
                    <div>Halaman Tempat Diklik</div>
                    <div>Tujuan Link</div>
                    <div>Terakhir Diklik</div>
                    <div style="text-align:center;">Total Klik</div>
                </div>

                <?php foreach ( $float_raw as $i => $row ):
                    $clk  = (int) $row['click_count'];
                    $ts   = strtotime( $row['last_clicked'] );
                    $path = parse_url( $row['page_url'], PHP_URL_PATH ) ?: $row['page_url'];
                ?>
                <div style="display:grid;grid-template-columns:40px 1fr 1fr 140px 160px 100px;padding:12px 16px;gap:10px;align-items:center;border-top:1px solid #e2e8f0;background:<?php echo $i % 2 === 0 ? '#fff' : '#f0fdf4'; ?>;min-width:760px;">
                    <div style="color:#94a3b8;font-size:12px;text-align:center;font-weight:600;"><?php echo $i + 1; ?></div>
                    <div>
                        <div style="font-weight:700;font-size:13px;color:#0f172a;"><?php echo esc_html( $row['btn_text'] ?: 'WhatsApp Floating Badge' ); ?></div>
                        <code style="font-size:10px;color:#64748b;background:#e2e8f0;padding:1px 4px;border-radius:3px;"><?php echo esc_html( substr( $row['btn_selector'], 0, 40 ) ); ?></code>
                    </div>
                    <div>
                        <a href="<?php echo esc_url( $row['page_url'] ); ?>" target="_blank" style="font-size:12px;color:#0073aa;font-weight:600;display:block;">
                            <?php echo esc_html( $path ); ?> ↗
                        </a>
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
            Inquiry Tracker v<?php echo INQ_TRACKER_VERSION; ?> · First-party tracking · Bot-filtered · First-Touch Origin URL Attribution
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
    </script>
    <?php
}
