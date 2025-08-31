=== WC Task Cleaner ===
Contributors: sophon
Donate link: https://www.mxtag.com/
Tags: Tags: woocommerce, cleaner, database, logs, performance
Requires at least: 5.8
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A lightweight plugin to clean WooCommerce Action Scheduler tasks and logs, keeping your WooCommerce database clean and optimized.

== Description ==

WC Task Cleaner is a lightweight WordPress plugin designed to clean and manage WooCommerce Action Scheduler tasks. 
It helps keep your database tidy, improves performance, and provides a simple interface for log management.

= Features =

* Display pending (future) task statistics  
* Group completed and failed tasks by hook  
* Batch select and clean tasks  
* Synchronized cleanup of Action Scheduler logs  
* Operation logs recorded in a separate table  
* One-click clear all logs with automatic table rebuild  

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/wc-task-cleaner/` directory, or install the plugin through the WordPress Plugins screen directly.  
2. Activate the plugin through the "Plugins" screen in WordPress.  
3. Navigate to **Tools → WC Task Cleaner** to start cleaning WooCommerce task logs.  

== Frequently Asked Questions ==

= Does this plugin support multiple languages? =
Yes, English is the default language. Simplified Chinese support will be added in future releases.

= Is this plugin safe for my database? =
Yes. The plugin only removes WooCommerce Action Scheduler tasks and logs. It does not affect orders, products, or other core WooCommerce data.

== Changelog ==

= 1.0.0 =
* Initial release
* Added pending task statistics
* Grouped completed/failed tasks by hook
* Batch cleanup support
* Synced log cleanup with Action Scheduler
* Operation logs stored in a separate table
* One-click clear logs with auto table rebuild

== Upgrade Notice ==

= 1.0.0 =
Initial release of WC Task Cleaner.
