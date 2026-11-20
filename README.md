# Category Price Manager for WooCommerce

Preview, apply, and undo bulk product price changes by WooCommerce category.

**Version:** 1.3.5 · **Author:** [Hesam MM](https://profiles.wordpress.org/hesamst/) · **License:** GPL-2.0-or-later

## Features

- Increase or decrease prices by a percentage or fixed amount, or set an exact price.
- Update regular prices, existing sale prices, or both.
- Include subcategories and product variations.
- Review totals and a sample of up to 30 products before applying changes.
- Resume interrupted operations and undo changes from job history.
- Detect intervening price edits and skip conflicts during undo.
- Use the included Persian translation and RTL admin layout.

## Requirements

- WordPress 6.3 or newer.
- PHP 7.4 or newer.
- WooCommerce installed and active, using its standard product datastore.
- InnoDB for the plugin history tables, WordPress posts/postmeta tables, and WooCommerce product price lookup table.
- A WordPress account with both `manage_woocommerce` and `edit_products`, plus permission to access the affected products and variation parents.
- JavaScript enabled in the browser.

## Installation

1. Back up your store and database, and test the plugin on a staging site.
2. Install and activate WooCommerce.
3. In WordPress, open **Plugins → Add New → Upload Plugin** and upload the plugin release ZIP.
4. Activate **Category Price Manager for WooCommerce**.
5. Open **WooCommerce → Category Price Manager**.

If installing manually, place the plugin files in `wp-content/plugins/category-price-manager/`. The main file must be at `category-price-manager/category-price-manager.php`.

### Building an installation ZIP from source

From the repository root, with Python 3 installed:

```sh
python3 tools/release.py
```

Upload the generated `dist/category-price-manager-1.3.5-review-candidate.zip`. It contains the runtime plugin files in one `category-price-manager/` folder. Tests, development notes, and directory artwork are excluded.

## Usage

1. Open **WooCommerce → Category Price Manager**.
2. Choose the product categories and whether to include subcategories and variations.
3. Select the operation, amount, and price fields to update.
4. Prepare a preview. Check the sample, totals, and proposed prices.
5. Confirm the update and keep the browser tab open while it processes.
6. Review the results in history. If interrupted, resume the job using the account that created it.
7. To reverse a job, choose **Undo** in history and keep the tab open until processing finishes.

For example, to increase regular prices by 10%, choose the categories, select an increase with the percentage method, enter `10`, and select regular prices. Review the preview before confirming.

## Price behavior and limitations

- Preparing a preview saves a snapshot; it does not change product prices.
- Products in multiple selected categories are included only once.
- Variations are processed when enabled; calculated variable-parent prices are not edited directly.
- Empty regular prices are skipped. New sale prices are not created, and scheduled sale dates remain unchanged.
- Undo restores original prices only when current prices still match the values applied by that job. Conflicts are skipped.
- Third-party pricing plugins may display prices different from the stored amounts this plugin edits.
- Custom product datastores and hooks that commit their own transactions are unsupported. External effects of save hooks cannot be undone.

## Frequently asked questions

### Can I close the tab while an update runs?

Keep it open while processing. If the operation is interrupted, reopen the page and resume it from history with the same user account.

### Why does Apply report a database compatibility error?

Price updates require the tables listed under Requirements to use InnoDB. The plugin can convert its own legacy MyISAM history tables during Apply or Undo. It does not convert WordPress or WooCommerce tables. Ask your host to inspect any tables named in the error. If a conversion times out, allow the server time to finish before retrying.

### Is Undo a replacement for a backup?

No. Undo relies on saved history and checks for price conflicts. Keep a database backup before bulk operations.

### How do I enable Persian?

Set the site language under **Settings → General**, or your user profile language, to **فارسی**.

## Data and privacy

The plugin stores job owners, timestamps, pricing rules, product IDs, before/after prices, and results in the site's `cpm_jobs` and `cpm_items` tables, using the site's table prefix. It does not collect customer or order data or send telemetry to external services.

Deactivation and deletion retain product prices and history. To remove history permanently, finish any needed undo, take a backup, and ask a database administrator to remove only these two plugin-owned tables. Removing them removes the ability to undo those jobs.

## Support and contributing

Report reproducible issues through the repository's issue tracker, including WordPress, WooCommerce, PHP, and plugin versions, steps to reproduce, and the exact error. Remove credentials and private store data from reports.

Development context is in [codex.md](codex.md), and regression commands are in [tests/README.md](tests/README.md). See [readme.txt](readme.txt) for the changelog and directory documentation. Live compatibility checks remain necessary before production use; this is a review candidate.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE) for attribution and [license.txt](license.txt) for the full license.
