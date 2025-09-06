=== WC Task Cleaner ===
Contributors: mxtag
Tags: woocommerce, action scheduler, cleanup, performance, database
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.2
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Clean WooCommerce Action Scheduler tasks (complete/failed) to optimize database size and speed up your store.

== Description ==

**WC Task Cleaner** helps you keep WooCommerce's Action Scheduler tables lean by removing completed and failed tasks (and their scheduler logs). This reduces database bloat and can improve query performance.

**Key features**
- ✓ One-click cleanup for **Completed + Failed** tasks
- ✓ Selective cleanup by **hook name**
- ✓ Optional cleanup of **failed** tasks only
- ✓ Built-in, minimal **operation log** (plugin-owned table)
- ✓ Admin-only, secure & i18n-ready; no front-end impact

> This plugin works with WooCommerce when available, but does not require it to be installed.

== Installation ==

1. Upload and activate **WC Task Cleaner**.
2. If using WooCommerce, the plugin will show Action Scheduler cleanup options.
3. Go to **Tools → WC Task Cleaner** to run cleanups.

== Frequently Asked Questions ==

= Does this affect future scheduled tasks? =
No. It only removes already **completed** or **failed** tasks. Pending tasks and future schedules remain intact.

= Is it safe to remove failed tasks? =
Usually yes, but consider reviewing failures if you're troubleshooting. You can choose **failed-only** cleanup.

== Screenshots ==

1. Admin page with stats, completed/failed overviews, and operation logs.

== Changelog ==

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
