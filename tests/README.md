# Candidate regression checks

Run from the repository root:

```sh
php -l category-price-manager.php
php -l tests/plugin-regression.php
php tests/plugin-regression.php
node --check assets/admin.js
node tests/admin-regression.cjs
python3 tools/release.py
```

The PHP suite uses WordPress/WooCommerce stubs and a transactional database fake. It covers atomic Apply/Undo, conflicts, partial-save rollback, interrupted progress, table-engine inspection and migration, locks, permissions, nonces, request shape, decimal amounts, and prepared identifier arguments. It does not replace live WordPress or database testing.

The JavaScript suite executes the shipped admin script with a small DOM fixture and controlled AJAX responses. It covers stale preview prevention, upgrade phases, recovery of history controls and pagination.

## Native migration fixture

`migration-integration.php` requires PHP mysqli and a disposable MySQL/MariaDB database named `cpm_test`, with test-only administrative credentials. It creates/drops `cpm_it_*` tables and a temporary restricted database user. Never point it at production.

```sh
CPM_TEST_DB_HOST=127.0.0.1 php tests/migration-integration.php
```

The fixture verifies actual engine conversion, rows/IDs/index/sequence preservation, advisory lock exclusion, timeout restoration, denied ALTER and retry. The helper now supports `%i`. It uses lightweight WordPress stubs and is not a full WooCommerce integration test.

PHP, WP-CLI and database tests were unavailable during preparation of 1.3.4. All test tooling is excluded from the runtime ZIP.
