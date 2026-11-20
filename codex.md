# Project guidance

## Project

Category Price Manager for WooCommerce previews, applies, and undoes stored product price changes by category. The plugin slug and text domain are `category-price-manager`. Runtime compatibility starts at WordPress 6.3 and PHP 7.4, with WooCommerce active.

## Repository map

- `category-price-manager.php`: plugin bootstrap, admin page, AJAX endpoints, database operations, and pricing jobs.
- `assets/admin.js` and `assets/admin.css`: admin interactions and styling.
- `languages/`: gettext template and Persian PO/MO translations.
- `tests/`: PHP regression suite, JavaScript regression suite, and MySQL migration fixture.
- `tools/release.py`: translation generation, metadata validation, and ZIP packaging.
- `.wordpress-org/`: directory artwork, excluded from runtime archives.
- `README.md`: installation and usage documentation.
- `readme.txt`: WordPress.org metadata, documentation, and changelog.
- `.codex/`: ignored local workspace.

## Implementation rules

- Keep the main plugin file and `readme.txt` at the repository root.
- Write runtime PHP compatible with PHP 7.4 and follow WordPress coding conventions.
- Use WordPress and WooCommerce APIs for product access, permissions, translation, and URL generation.
- Require `manage_woocommerce`, `edit_products`, and product access checks for price operations. Keep jobs scoped to their owner.
- Verify AJAX nonces, validate request shapes, sanitize inputs, escape output, and prepare SQL values and identifiers.
- Preserve transaction boundaries, mutation locks, checked database writes, and atomic price/history updates.
- Require InnoDB for price writes. Limit automatic engine conversion to plugin-owned history tables.
- Preserve snapshot-based Apply and conflict-aware Undo. Do not overwrite intervening price changes.
- Keep batch processing resumable and maintain consistent preview, progress, and history controls.
- Use the `category-price-manager` text domain and preserve Persian and RTL support.
- Keep runtime version constants, plugin headers, and `readme.txt` stable tags consistent.

## Checks

Run commands from the repository root:

```sh
php -l category-price-manager.php
php -l tests/plugin-regression.php
php tests/plugin-regression.php
node --check assets/admin.js
node tests/admin-regression.cjs
python3 tools/release.py
```

The release script regenerates PO/MO/POT files and produces `dist/category-price-manager-<version>-review-candidate.zip`. Review generated translation changes.

The database fixture requires PHP mysqli and a disposable `cpm_test` database:

```sh
CPM_TEST_DB_HOST=127.0.0.1 php tests/migration-integration.php
```

It creates and drops fixture tables and a restricted test user. Use test-only credentials and an isolated database. See `tests/README.md` for fixture details. Report any checks that could not run.

## Packaging and documentation

- Package exactly one `category-price-manager/` directory containing runtime files, translations, `readme.txt`, and licenses.
- Exclude tests, tools, local configuration, development notes, and directory artwork from the installation ZIP.
- Keep user instructions in `README.md` and directory documentation in `readme.txt`.
- Keep this file focused on current project structure, conventions, and commands.
- Record compatibility claims only from verified environments.
- Make changes on `develop`. Commit, rewrite history, merge, or push only when requested.
