=== Inquiry & Button Click Tracker ===
Contributors: digitalwebgrowth
Tags: click tracking, whatsapp tracking, button analytics, conversion tracking, inquiry tracking
Requires at least: 5.0
Tested up to: 6.7
Stable tag: 1.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Universal real-time Button, WhatsApp, and Floating Click Tracking with First-Touch Origin URL & Traffic Source Attribution.

== Changelog ==

= 1.3.0 =
* Added First-Touch Session Origin URL & Traffic Source Tracking (SessionStorage-based attribution).
* Objectively detects visitor origin: Google Search (Organic SEO), Google Ads (gclid/gbraid), Meta/Facebook Ads (fbclid), Instagram, TikTok Ads (ttclid), Custom Campaigns (UTM), Direct, and External Referrals.
* Solved the "Lost Referrer" problem when visitors navigate internal pages before clicking WhatsApp or CTA buttons.
* Added new "Distribusi Sumber Trafik (Origin URL & Referrer)" table with export CSV support.
* Added dynamic Traffic Source filter pills with live inquiry counts per source.
* Preserved safe backup of previous version (v1.2.0) in wp-inquiry-tracker-v1.2.0-backup.php.
* Added Marketing Ad Pages configuration: define paid ads landing pages via dashboard settings.
* Added smart URL/path matching that preserves ad identification even when URLs contain UTM/gclid/fbclid campaign query parameters.
* Added Traffic Source filter pills (All Traffic, Paid Ads Only, Organic Only).
* Added visual badges ([IKLAN] vs [ORGANIK]) across WhatsApp report tables, Pages accordion, and Floating Buttons history.
* Enhanced CSV export with "Tipe Trafik" column and synchronized traffic filter exports.

= 1.1.0 =
* Major upgrade: Bulletproof Floating Button detection (Click to Chat, Joinchat, Chaty, QuadLayers, Buttonizer, NinjaTeam, and custom CSS fixed/sticky elements).
* Fixed WebKit/Safari sendBeacon boundary issue by using URL-encoded Blob payload.
* Added fallback mechanisms (fetch with keepalive & XHR) and touchend support for mobile devices.
* Added atomic ON DUPLICATE KEY UPDATE database insertion.

= 1.0.0 =
* Initial release with real-time button tracking, period filters, accordion dropdowns, and CSV export.
