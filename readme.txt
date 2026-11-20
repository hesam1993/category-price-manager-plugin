=== Category Price Manager for WooCommerce ===
Contributors: hesamst
Tags: woocommerce, bulk edit, price, category
Requires at least: 6.3
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.3.5
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Preview, batch-update and undo product prices across selected WooCommerce categories.

== Description ==

Category Price Manager for WooCommerce lets store managers preview, apply, and undo stored product price changes by product category.

* Increase or decrease prices by a percentage or a fixed amount, or set an exact price.
* Select regular prices, existing sale prices, or both.
* Include subcategories and variations when needed.
* Inspect a 30-record sample before confirming the full snapshot.
* Resume interrupted updates and undo from job history.
* Skip conflicting prices when another tool has changed them.
* Use the included Persian translation and RTL admin layout.

WooCommerce must be installed and active. Operations require both the manage_woocommerce and edit_products capabilities, plus access to the affected products. JavaScript is required on the plugin admin page.

The plugin uses the standard WooCommerce product datastore. Price writes require InnoDB for its two history tables, WordPress posts/postmeta, and the WooCommerce product price lookup table. Custom product datastores and hooks that commit transactions themselves are unsupported. External effects of third-party save hooks, such as webhooks, cannot be rolled back.

== Privacy and external services ==

This plugin does not use external services, telemetry, remote assets, or tracking. Its browser requests go to your own site's WordPress AJAX endpoint. WooCommerce and other installed plugins may separately use external services.

Local history tables store the initiating WordPress user ID, timestamps, selected categories and pricing rules, product/variation IDs, original and proposed regular/sale prices, and processing results. The plugin does not collect customer or order data. Database and product-save failures can write technical diagnostics to the server PHP error log when WP_DEBUG is enabled.

History is retained indefinitely, including after deactivation or deletion, so price snapshots are not unexpectedly lost. To erase it, first complete any needed undo and take a backup, then ask your database administrator to remove only this plugin's cpm_jobs and cpm_items tables (with your site's actual table prefix). Removing history permanently removes the ability to undo those jobs. Multisite stores have separate tables for each site.

== Source code ==

All PHP, JavaScript, CSS and Persian translation source files are included. The JavaScript and CSS are readable and require no build step. No third-party libraries are bundled. Code and included assets are licensed under GPLv2 or later; see license.txt.

== Installation ==
1. Back up your site and database. Test on staging first.
2. In WordPress, go to Plugins > Add New > Upload Plugin and select the ZIP.
3. Activate WooCommerce, then activate this plugin.
4. Open WooCommerce > Category Price Manager.
5. Choose categories, operation, amount, price field and whether to include variations.
6. Prepare preview, verify the sample and totals, then confirm the update.
7. If needed, use Undo from the history panel. Keep this page open while processing.

== How it works ==
- Simple products are edited directly. Variable products are edited via their variation records, not via the parent price range.
- Products in multiple selected categories are only included once.
- Existing sale prices are not auto-created. Empty regular prices are skipped. Invalid sale/regular price combinations are skipped.
- The preview creates a persisted before/after snapshot for each matching product/variation.
- Apply and Undo process up to 5 item records per AJAX request. This requires the browser tab to remain open; interrupted jobs can be resumed from history.
- Undo compares the current price to the plugin-applied price before restoring the original price. Any intervening price edits are marked as conflicts instead of overwritten.
- Only users with both manage_woocommerce and edit_products capabilities can operate the plugin; each job is scoped to its creator.
- Original pricing and job history are kept in custom database tables; uninstalling/deleting the plugin does not automatically delete the history tables.

== Important limitations ==
- Review a full database backup before bulk price operations.
- This version processes variations of variable products when the checkbox is enabled. Selecting variable products without variations will not edit their calculated parent price.
- Scheduled sale dates remain unchanged. The plugin edits the stored regular/sale amounts, and WooCommerce determines the current active price.
- Plugins that dynamically override prices may display storefront prices different from stored prices.
- Price previews show the first 30 matched records, not the entire catalog.
- The snapshot includes categories at the moment of preparation. Later changes to category membership do not alter that snapshot.
- Plugin mutation requests are serialized per site. A busy request is rejected and can be resumed from history. Product rows and metadata are locked while checking and saving prices.
- Variation-heavy catalogs are staged in batches of up to 20 individual records per request. Writes use batches of up to 5 with an 8-second soft budget. Test large catalogs on staging.

== Translations ==
Persian (fa_IR) included with RTL admin support. To use it, set WordPress Settings > General > Site Language to فارسی, or set your user profile language to فارسی. English remains the default for other locales.

== Frequently Asked Questions ==

= Does preparing a preview change prices? =
No. Preparation stores a snapshot. Prices change only after you confirm Apply. Legacy plugin-owned MyISAM tables may be converted to InnoDB during Apply or Undo before price writes begin.

= Does this create new sale prices? =
No. Only existing sale amounts are adjusted. Scheduled sale dates stay unchanged.

= Can I undo every change? =
Undo restores a job's original prices only when current prices still match the values that job applied. Conflicts and uncertain legacy ownership are skipped. Undo is not a database backup.

= Must I keep the browser tab open? =
Yes, while a job is running. Interrupted preparation, updates and undo can be resumed from history by the user who created the job.

= What happens when I delete the plugin? =
Product prices and job history are retained. See Privacy and external services for history removal instructions.

== Changelog ==

= 1.3.5 =
* Scope the remaining Plugin Check exceptions to the uncached progress write and generated category IN query.
* Preserve pricing, query parameters, permissions, snapshots and undo behavior.

= 1.3.4 =
* Use prepared identifiers and integer placeholders for database queries and legacy table migration.
* Verify AJAX nonces directly at each endpoint before processing sanitized request fields.
* Keep live transaction, lock and snapshot queries uncached, with narrowly documented review exceptions.
* Limit technical error logging to sites with WP_DEBUG enabled.
* Preserve the bundled Persian translation fallback for manual ZIP installs without a directory language pack.

= 1.3.3 =
* Prepared directory metadata and contributor attribution for hesamst.
* Added license, privacy, source, installation and FAQ documentation.
* Validate scalar AJAX fields and require both manager capabilities on the admin page.
* Made persisted preview result labels discoverable by translation tools.

= 1.3.2 =
- Fixes the missing upgrade path for legacy cpm_jobs and cpm_items tables using MyISAM. Apply and Undo automatically convert these two plugin-owned tables to InnoDB before changing prices.
- Native engine conversion retains job history, snapshots, row IDs, indexes, and auto-increment values. Back up the database before installing this update.
- Converts and verifies at most one table per request. No price writes occur in a request that changes a table engine; subsequent requests resume using the actual engine state.
- Checks every required table before conversion. WordPress/WooCommerce tables and unknown storage engines are never automatically converted.
- Serializes creation, staging, conversion, apply, and undo. Verifies database lock ownership before conversion, limits metadata-lock waits to five seconds, and restores the session timeout afterward.
- A denied ALTER privilege, unavailable InnoDB, insufficient disk space, or blocked table stops the operation with an actionable message. Database privileges are not modified.
- Large native conversions may exceed the browser request timeout. The server may still be working: wait, then retry from history. A successful conversion is not repeated after a lost response.

= 1.3.1 =
- Added a SHOW TABLE STATUS fallback when information_schema cannot report the storage engine.
- Database compatibility errors now list every affected table and distinguish a non-InnoDB engine from failed inspection or missing metadata.
- Preserves transaction protection: non-InnoDB or unverifiable tables still block writes. This update does not convert tables or change database permissions.
- If Apply reports a non-InnoDB table, ask your host to back up the database and convert the named table. If inspection fails or no engine is reported, ask the host to verify table existence and metadata access for the WordPress database user.

= 1.3.0 =
- Author: Hesam MM.
- Fixed failed preview switches applying a different job from the displayed preview.
- Prices and their item/undo records now commit in one transaction. Save exceptions and failed item updates roll back; interrupted items remain retryable.
- Prices already equal to a proposed target are conflicts, not proof that this job changed them. Undo also skips ambiguous legacy records marked "Already at target price".
- Added server-side mutation serialization, per-item row locks, and checked database counts and job updates.
- Fixed disabled history controls after completion/errors; added history pagination with interrupted jobs listed first.
- Enforced product and variation-parent read/edit permissions during staging, preview, apply, and undo.
- Safe writes require InnoDB for the plugin tables, WordPress posts/postmeta, and WooCommerce product price lookup table. Existing non-InnoDB tables are not silently converted during normal requests; writes stop with an explanation.
- Transaction guarantees cover the standard WooCommerce product datastore. Custom datastores and hooks that commit transactions themselves are unsupported. External side effects from third-party hooks (such as webhooks) cannot be rolled back.
- Existing snapshots remain usable. Ambiguous legacy changes are conservatively skipped rather than attributed to this plugin.

= 1.2.0 =
- Preview is a non-submitting button. If JavaScript is unavailable, a warning stays visible; the browser cannot navigate to a blank admin page when Preview is clicked.
- Removed redundant explicit full parent-variable-product synchronization after each variation save. WooCommerce CRUD handles its normal variation hooks and cache/lookup updates; parent price cache is invalidated once per affected parent per batch.
- Fixed exact-price operation sending a missing adjustment method.
- Adds AJAX timeout, redirect and non-JSON error messages, smaller write batches, PHP work time budget, and retry-safe price verification.
- Existing jobs and undo history remain stored in the same tables. If a job was interrupted previously, it may be resumed from Update history.
- If any AJAX request still fails with HTTP 500, enable WP_DEBUG_LOG on a staging environment and inspect wp-content/debug.log or your host's PHP error log.

== Upgrade Notice ==

= 1.3.5 =
Clarifies the three remaining Plugin Check database warnings. Runtime pricing and history behavior are unchanged.

= 1.3.4 =
Plugin Check follow-up: prepared SQL identifiers and timeout values, explicit AJAX nonce validation and conditional diagnostics. Existing prices, snapshots and undo history are preserved.

= 1.3.3 =
Directory metadata, documentation, AJAX input validation and translation extraction improvements. Existing price snapshots and history are preserved.
