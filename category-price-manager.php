<?php
/**
 * Copyright (C) 2026 Hesam MM (hesamst)
 *
 * Plugin Name: Category Price Manager for WooCommerce
 * Description: Preview, safely batch-edit, and undo category price changes for simple products and variations.
 * Version: 1.3.5
 * Requires at least: 6.3
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * Author: Hesam MM
 * Author URI: https://profiles.wordpress.org/hesamst/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: category-price-manager
 * Domain Path: /languages
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class CPM_Category_Price_Manager {
    const VERSION = '1.3.5';
    const STAGE_BATCH = 20;
    const WRITE_BATCH = 5;
    private static $instance;
    private $jobs;
    private $items;
    private $page_hook = '';
    private $mutation_lock = '';
    private $transaction = false;
    private $transaction_product = 0;
    private $transaction_parent = 0;

    public static function instance() {
        if ( ! self::$instance ) { self::$instance = new self(); }
        return self::$instance;
    }
    private function __construct() {
        global $wpdb;
        $this->jobs = $wpdb->prefix . 'cpm_jobs';
        $this->items = $wpdb->prefix . 'cpm_items';
        add_action( 'init', array( $this, 'load_translations' ) );
        add_action( 'admin_menu', array( $this, 'menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
        foreach ( array( 'create', 'stage', 'preview', 'apply', 'undo', 'history' ) as $action ) {
            add_action( 'wp_ajax_cpm_' . $action, array( $this, 'ajax_' . $action ) );
        }
        add_action( 'admin_notices', array( $this, 'dependency_notice' ) );
        register_shutdown_function( array( $this, 'cleanup' ) );
    }
    public function load_translations() {
        if ( ! is_textdomain_loaded( 'category-price-manager' ) ) {
            // Register bundled translations for manual ZIP installs and sites without a directory language pack.
            // phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound -- Required for the bundled Persian fallback on supported WordPress versions; called on init.
            load_plugin_textdomain( 'category-price-manager', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
        }
    }
    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $jobs = $wpdb->prefix . 'cpm_jobs';
        $items = $wpdb->prefix . 'cpm_items';
        dbDelta( "CREATE TABLE $jobs (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'staging',
            settings longtext NOT NULL,
            cursor_id bigint(20) unsigned NOT NULL DEFAULT 0,
            total bigint(20) unsigned NOT NULL DEFAULT 0,
            applied bigint(20) unsigned NOT NULL DEFAULT 0,
            skipped bigint(20) unsigned NOT NULL DEFAULT 0,
            undone bigint(20) unsigned NOT NULL DEFAULT 0,
            conflicts bigint(20) unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            KEY user_status (user_id,status)
        ) ENGINE=InnoDB $charset;" );
        dbDelta( "CREATE TABLE $items (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            job_id bigint(20) unsigned NOT NULL,
            product_id bigint(20) unsigned NOT NULL,
            parent_id bigint(20) unsigned NOT NULL DEFAULT 0,
            before_json longtext NOT NULL,
            after_json longtext NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            note varchar(200) NOT NULL DEFAULT '',
            PRIMARY KEY  (id),
            UNIQUE KEY job_product (job_id,product_id),
            KEY job_status (job_id,status,id)
        ) ENGINE=InnoDB $charset;" );
    }
    public function dependency_notice() {
        if ( ! class_exists( 'WooCommerce' ) && current_user_can( 'manage_options' ) ) {
            echo '<div class="notice notice-error"><p>' . esc_html__( 'Category Price Manager requires WooCommerce to be active.', 'category-price-manager' ) . '</p></div>';
        }
    }
    public function menu() {
        if ( ! class_exists( 'WooCommerce' ) ) { return; }
        $this->page_hook = add_submenu_page( 'woocommerce', __( 'Category Price Manager', 'category-price-manager' ), __( 'Category Price Manager', 'category-price-manager' ), 'manage_woocommerce', 'category-price-manager', array( $this, 'page' ) );
    }
    public function assets( $hook ) {
        if ( ! $this->page_hook || $hook !== $this->page_hook ) { return; }
        wp_enqueue_style( 'cpm-admin', plugins_url( 'assets/admin.css', __FILE__ ), array(), self::VERSION );
        if ( is_rtl() ) { wp_add_inline_style( 'cpm-admin', '.cpm-wrap{direction:rtl;text-align:right}.cpm-wrap .cpm-grid,.cpm-wrap .cpm-panel{direction:rtl}.cpm-wrap .cpm-table-wrap table,.cpm-wrap .cpm-history table{text-align:right}.cpm-wrap #cpm-prepare{align-self:flex-start}' ); }
        wp_enqueue_script( 'cpm-admin', plugins_url( 'assets/admin.js', __FILE__ ), array(), self::VERSION, true );
        wp_localize_script( 'cpm-admin', 'CPM', array(
            'ajax' => admin_url( 'admin-ajax.php' ),
            'nonce' => wp_create_nonce( 'cpm_admin' ),
            'currency' => get_woocommerce_currency_symbol(),
            'decimals' => wc_get_price_decimals(),
            'i18n' => array(
                'Request failed.' => __( 'Request failed.', 'category-price-manager' ),
                'Request timed out. The operation can be resumed from Update History.' => __( 'Request timed out. The operation can be resumed from Update History.', 'category-price-manager' ),
                'The server returned a page instead of a JSON response. Check the browser Network tab and PHP error log.' => __( 'The server returned a page instead of a JSON response. Check the browser Network tab and PHP error log.', 'category-price-manager' ),
                'The request was redirected to a login page. Log in again and retry.' => __( 'The request was redirected to a login page. Log in again and retry.', 'category-price-manager' ),
                'Check the PHP error log if the server returned a blank page.' => __( 'Check the PHP error log if the server returned a blank page.', 'category-price-manager' ),
                'Applied:' => __( 'Applied:', 'category-price-manager' ),
                'Skipped:' => __( 'Skipped:', 'category-price-manager' ),
                'Conflicts:' => __( 'Conflicts:', 'category-price-manager' ),
                'Undone:' => __( 'Undone:', 'category-price-manager' ),
                'product records matched.' => __( 'product records matched.', 'category-price-manager' ),
                'ready to change;' => __( 'ready to change;', 'category-price-manager' ),
                'skipped by pricing rules.' => __( 'skipped by pricing rules.', 'category-price-manager' ),
                'No products found for these categories and settings.' => __( 'No products found for these categories and settings.', 'category-price-manager' ),
                'Preview ready. Confirm to begin updating prices.' => __( 'Preview ready. Confirm to begin updating prices.', 'category-price-manager' ),
                'Updating prices in batches. Keep this page open.' => __( 'Updating prices in batches. Keep this page open.', 'category-price-manager' ),
                'Preparing safe price updates. Keep this page open.' => __( 'Preparing safe price updates. Keep this page open.', 'category-price-manager' ),
                'Restoring original prices in batches. Keep this page open.' => __( 'Restoring original prices in batches. Keep this page open.', 'category-price-manager' ),
                'Update completed.' => __( 'Update completed.', 'category-price-manager' ),
                'Undo completed.' => __( 'Undo completed.', 'category-price-manager' ),
                'Select at least one category.' => __( 'Select at least one category.', 'category-price-manager' ),
                'Collecting products and saving original prices:' => __( 'Collecting products and saving original prices:', 'category-price-manager' ),
                'records so far…' => __( 'records so far…', 'category-price-manager' ),
                'Apply all previewed category price changes? This can affect your storefront immediately.' => __( 'Apply all previewed category price changes? This can affect your storefront immediately.', 'category-price-manager' ),
                'Paused:' => __( 'Paused:', 'category-price-manager' ),
                'You can resume from the history section.' => __( 'You can resume from the history section.', 'category-price-manager' ),
                'No jobs yet.' => __( 'No jobs yet.', 'category-price-manager' ),
                'Previous page' => __( 'Previous page', 'category-price-manager' ),
                'Next page' => __( 'Next page', 'category-price-manager' ),
                'Date / job' => __( 'Date / job', 'category-price-manager' ),
                'Rule' => __( 'Rule', 'category-price-manager' ),
                'Status' => __( 'Status', 'category-price-manager' ),
                'Products' => __( 'Products', 'category-price-manager' ),
                'Results' => __( 'Results', 'category-price-manager' ),
                'Action' => __( 'Action', 'category-price-manager' ),
                'Undo' => __( 'Undo', 'category-price-manager' ),
                'Resume undo' => __( 'Resume undo', 'category-price-manager' ),
                'Resume update' => __( 'Resume update', 'category-price-manager' ),
                'Resume preview' => __( 'Resume preview', 'category-price-manager' ),
                'Load preview' => __( 'Load preview', 'category-price-manager' ),
                'Undo this price update wherever the price still matches the value it set?' => __( 'Undo this price update wherever the price still matches the value it set?', 'category-price-manager' ),
                'Collecting snapshot:' => __( 'Collecting snapshot:', 'category-price-manager' ),
                'records…' => __( 'records…', 'category-price-manager' ),
                'Could not load history:' => __( 'Could not load history:', 'category-price-manager' ),
                'currency' => __( 'currency', 'category-price-manager' ),
                'regular' => __( 'regular', 'category-price-manager' ),
                'sale' => __( 'sale', 'category-price-manager' ),
                'both' => __( 'both', 'category-price-manager' ),
                'increase' => __( 'increase', 'category-price-manager' ),
                'decrease' => __( 'decrease', 'category-price-manager' ),
                'set' => __( 'set', 'category-price-manager' ),
                'staging' => __( 'staging', 'category-price-manager' ),
                'ready' => __( 'ready', 'category-price-manager' ),
                'applying' => __( 'applying', 'category-price-manager' ),
                'completed' => __( 'completed', 'category-price-manager' ),
                'undoing' => __( 'undoing', 'category-price-manager' ),
                'undone' => __( 'undone', 'category-price-manager' ),
            ),
        ) );
    }
    public function page() {
        if ( ! current_user_can( 'manage_woocommerce' ) || ! current_user_can( 'edit_products' ) || ! class_exists( 'WooCommerce' ) ) { return; }
        $categories = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'orderby' => 'name' ) );
        ?>
        <div class="wrap cpm-wrap"><h1><?php esc_html_e( 'Category Price Manager', 'category-price-manager' ); ?></h1>
            <p><?php esc_html_e( 'Prepare a snapshot, inspect the proposed changes, then apply in small batches. Undo skips prices changed by another process after this plugin updated them.', 'category-price-manager' ); ?></p>
            <div class="cpm-grid"><section class="cpm-panel"><h2><?php esc_html_e( 'New category price update', 'category-price-manager' ); ?></h2>
                <p class="notice notice-warning inline" id="cpm-js-status"><?php esc_html_e( 'JavaScript is required. If this message remains visible, the plugin script did not load; check your browser console and cache.', 'category-price-manager' ); ?></p>
                <form id="cpm-form" method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=category-price-manager' ) ); ?>">
                    <label for="cpm-categories"><strong><?php esc_html_e( 'Categories', 'category-price-manager' ); ?></strong> <?php esc_html_e( '(Ctrl/Cmd-click for multiple)', 'category-price-manager' ); ?></label>
                    <select id="cpm-categories" name="categories[]" multiple required size="7">
                    <?php if ( ! is_wp_error( $categories ) ) : foreach ( $categories as $cat ) : ?>
                        <option value="<?php echo esc_attr( $cat->term_id ); ?>"><?php echo esc_html( $cat->name ); ?></option>
                    <?php endforeach; endif; ?></select>
                    <label class="cpm-check"><input type="checkbox" name="descendants" value="1" checked> <?php esc_html_e( 'Include subcategories', 'category-price-manager' ); ?></label>
                    <label for="cpm-operation"><?php esc_html_e( 'Operation', 'category-price-manager' ); ?></label>
                    <select id="cpm-operation" name="operation"><option value="increase"><?php esc_html_e( 'Increase', 'category-price-manager' ); ?></option><option value="decrease"><?php esc_html_e( 'Decrease', 'category-price-manager' ); ?></option><option value="set"><?php esc_html_e( 'Set exact price', 'category-price-manager' ); ?></option></select>
                    <label for="cpm-method"><?php esc_html_e( 'Adjustment', 'category-price-manager' ); ?></label>
                    <select id="cpm-method" name="method"><option value="percent"><?php esc_html_e( 'Percentage (%)', 'category-price-manager' ); ?></option><option value="fixed"><?php esc_html_e( 'Fixed amount (store currency)', 'category-price-manager' ); ?></option></select>
                    <label for="cpm-amount"><?php esc_html_e( 'Amount', 'category-price-manager' ); ?></label><input id="cpm-amount" name="amount" type="number" min="0" step="any" required placeholder="<?php echo esc_attr__( 'e.g. 10', 'category-price-manager' ); ?>">
                    <label for="cpm-field"><?php esc_html_e( 'Price to change', 'category-price-manager' ); ?></label>
                    <select id="cpm-field" name="field"><option value="regular"><?php esc_html_e( 'Regular price', 'category-price-manager' ); ?></option><option value="sale"><?php esc_html_e( 'Sale price (existing sales only)', 'category-price-manager' ); ?></option><option value="both"><?php esc_html_e( 'Both (existing sales only)', 'category-price-manager' ); ?></option></select>
                    <label class="cpm-check"><input type="checkbox" name="variations" value="1" checked> <?php esc_html_e( 'Include variations of variable products', 'category-price-manager' ); ?></label>
                    <p class="description"><?php esc_html_e( 'Unpriced products are skipped. The sale-price option only affects products that already have a sale price. Invalid sale/regular combinations are skipped rather than silently changing discounts.', 'category-price-manager' ); ?></p>
                    <button type="button" class="button button-primary" id="cpm-prepare"><?php esc_html_e( 'Prepare preview', 'category-price-manager' ); ?></button>
                </form>
            </section><section class="cpm-panel" aria-live="polite"><h2><?php esc_html_e( 'Preview / progress', 'category-price-manager' ); ?></h2>
                <div id="cpm-feedback"><?php esc_html_e( 'Select categories and prepare a preview. No prices change before you confirm.', 'category-price-manager' ); ?></div>
                <div id="cpm-preview" hidden><p id="cpm-summary"></p><div class="cpm-table-wrap"><table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Product', 'category-price-manager' ); ?></th><th><?php esc_html_e( 'Current regular / sale', 'category-price-manager' ); ?></th><th><?php esc_html_e( 'Proposed regular / sale', 'category-price-manager' ); ?></th><th><?php esc_html_e( 'Result', 'category-price-manager' ); ?></th></tr></thead><tbody id="cpm-preview-body"></tbody></table></div>
                <p class="description"><?php esc_html_e( 'Preview shows the first 30 products. All matching products are snapshotted before applying. Changes made after the preview are skipped as conflicts.', 'category-price-manager' ); ?></p>
                <button type="button" class="button button-primary" id="cpm-apply"><?php esc_html_e( 'Confirm and apply changes', 'category-price-manager' ); ?></button></div>
                <div id="cpm-progress" hidden><progress id="cpm-bar" max="100" value="0"></progress> <span id="cpm-count"></span></div>
            </section></div>
            <section class="cpm-panel cpm-history"><h2><?php esc_html_e( 'Update history and undo', 'category-price-manager' ); ?></h2><p class="description"><?php esc_html_e( 'Undo restores original regular and sale prices only when the current values match those applied by that job. Undo cannot reverse changes made by other tools in the meantime.', 'category-price-manager' ); ?></p><div id="cpm-history"></div></section>
        </div><?php
    }
    private function guard() {
        if ( ! current_user_can( 'manage_woocommerce' ) || ! current_user_can( 'edit_products' ) || ! class_exists( 'WooCommerce' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied or WooCommerce inactive.', 'category-price-manager' ) ), 403 );
        }
    }
    private function job( $id ) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom job ownership and resumable state must be read fresh after authorization.
        $job = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE id = %d AND user_id = %d", $this->jobs, $id, get_current_user_id() ) );
        if ( ! $job ) { wp_send_json_error( array( 'message' => __( 'Job not found.', 'category-price-manager' ) ), 404 ); }
        return $job;
    }
    private function fail( $message ) {
        $this->cleanup();
        wp_send_json_error( array( 'message' => $message ), 400 );
    }
    private function log_error( $message ) {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Intentional failure diagnostics, enabled only by the site's WP_DEBUG setting.
            error_log( '[Category Price Manager] ' . $message );
        }
    }
    private function check_database() {
        global $wpdb;
        if ( $wpdb->last_error ) { $this->fail( __( 'Database operation failed. No completion was recorded; retry from history.', 'category-price-manager' ) ); }
    }
    private function update_job( $id, $data ) {
        global $wpdb;
        $data['updated_at'] = current_time( 'mysql', true );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Persist custom job progress immediately; live job reads intentionally bypass caches.
        if ( false === $wpdb->update( $this->jobs, $data, array( 'id' => $id ) ) ) {
            $this->fail( __( 'Could not save job progress. Retry from history.', 'category-price-manager' ) );
        }
    }
    private function can_access_product( $product ) {
        if ( ! $product || ! current_user_can( 'read_post', $product->get_id() ) || ! current_user_can( 'edit_post', $product->get_id() ) ) { return false; }
        $parent = $product->is_type( 'variation' ) ? $product->get_parent_id() : 0;
        return ! $parent || ( current_user_can( 'read_post', $parent ) && current_user_can( 'edit_post', $parent ) );
    }
    private function lock_mutations() {
        global $wpdb;
        // One site-wide lock also serializes different jobs targeting the same product.
        $name = 'cpm:' . md5( DB_NAME . ':' . $wpdb->prefix );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Connection-level advisory lock acquisition cannot be cached or replaced by a WordPress object API.
        $locked = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $name ) );
        if ( '1' !== (string) $locked ) {
            $this->fail( __( 'Another price request is still running. Wait briefly, then resume from history.', 'category-price-manager' ) );
        }
        $this->mutation_lock = $name;
    }
    private function table_engine( $table ) {
        global $wpdb;
        $previous = $wpdb->suppress_errors( true );
        try {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Inspect actual storage engines on every retry; cached metadata could incorrectly permit price writes.
            $engine = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $table ) );
            $error = (bool) $wpdb->last_error;
            if ( $error || ! is_string( $engine ) || '' === trim( $engine ) ) {
                // Exact equality: underscores in prefixes are not LIKE wildcards.
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Inspect actual storage engines on every retry; cached metadata could incorrectly permit price writes.
                $status = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS WHERE Name = %s', $table ) );
                $error = (bool) $wpdb->last_error;
                $engine = ! $error && $status && isset( $status->Engine ) ? $status->Engine : null;
            }
        } finally {
            $wpdb->suppress_errors( $previous );
        }
        return array( 'engine' => is_string( $engine ) ? strtoupper( trim( $engine ) ) : '', 'error' => $error );
    }
    private function migrate_plugin_table( $table ) {
        global $wpdb;
        // DDL implicitly commits. Never run it inside a price transaction, and
        // never accept a table name supplied by an AJAX request.
        if ( $this->transaction || ! $this->mutation_lock || ! in_array( $table, array( $this->jobs, $this->items ), true ) ) {
            $this->fail( __( 'Database upgrade cannot run safely in this request. Retry from history.', 'category-price-manager' ) );
        }
        // A connection replacement can lose a MySQL named lock while leaving
        // the PHP property set. Check actual ownership before issuing DDL.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Connection state and the allowlisted legacy engine migration require direct database operations.
        $owned = $wpdb->get_var( $wpdb->prepare( 'SELECT IS_USED_LOCK(%s) = CONNECTION_ID()', $this->mutation_lock ) );
        if ( '1' !== (string) $owned ) {
            $this->fail( __( 'Database upgrade cannot run safely in this request. Retry from history.', 'category-price-manager' ) );
        }
        $previous = $wpdb->suppress_errors( true );
        $timeout = null;
        $result = false;
        $error = '';
        try {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Connection state and the allowlisted legacy engine migration require direct database operations.
            $timeout = $wpdb->get_var( 'SELECT @@SESSION.lock_wait_timeout' );
            $error = $wpdb->last_error;
            if ( ! $error && is_numeric( $timeout ) ) {
                // Avoid waiting indefinitely behind a long-running metadata lock.
                $bounded_timeout = min( 5, max( 1, (int) $timeout ) );
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Connection state and the allowlisted legacy engine migration require direct database operations.
                if ( false !== $wpdb->query( $wpdb->prepare( 'SET SESSION lock_wait_timeout = %d', $bounded_timeout ) ) ) {
                    // Native engine conversion preserves rows, keys, and AUTO_INCREMENT.
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Convert only an allowlisted plugin-owned MyISAM table under the verified mutation lock, outside price transactions.
                    $result = $wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ENGINE=InnoDB', $table ) );
                }
                $error = $wpdb->last_error;
            }
        } finally {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Connection state and the allowlisted legacy engine migration require direct database operations.
            if ( is_numeric( $timeout ) ) { $wpdb->query( $wpdb->prepare( 'SET SESSION lock_wait_timeout = %d', (int) $timeout ) ); }
            $wpdb->suppress_errors( $previous );
        }
        if ( false === $result || $error ) {
            $this->log_error( 'Table migration ' . $table . ': ' . $error );
            /* translators: %s: plugin database table name. */
            $this->fail( sprintf( __( 'Could not upgrade plugin table %s to InnoDB. No product prices were changed in this request. Ask your host to check ALTER permission, InnoDB availability, free disk space, and database locks, then retry.', 'category-price-manager' ), $table ) );
        }
        $verified = $this->table_engine( $table );
        if ( $verified['error'] || 'INNODB' !== $verified['engine'] ) {
            /* translators: %s: plugin database table name. */
            $this->fail( sprintf( __( 'Could not verify the InnoDB upgrade for plugin table %s. No product prices were changed in this request. Retry after your host checks the table.', 'category-price-manager' ), $table ) );
        }
    }
    private function require_transactions( $allow_migration = false ) {
        global $wpdb;
        $problems = array();
        $migrations = array();
        foreach ( array( $this->jobs, $this->items, $wpdb->posts, $wpdb->postmeta, $wpdb->prefix . 'wc_product_meta_lookup' ) as $table ) {
            $metadata = $this->table_engine( $table );
            $engine = $metadata['engine'];
            if ( $metadata['error'] ) {
                /* translators: %s: database table name. */
                $problems[] = sprintf( __( 'Cannot inspect table %s. Ask your host to allow SHOW TABLE STATUS for the WordPress database user.', 'category-price-manager' ), $table );
            } elseif ( '' === $engine ) {
                /* translators: %s: database table name. */
                $problems[] = sprintf( __( 'No storage engine was reported for table %s. Ask your host to check that the table exists, is accessible, and is not a view.', 'category-price-manager' ), $table );
            } elseif ( 'INNODB' !== $engine ) {
                if ( $allow_migration && 'MYISAM' === $engine && in_array( $table, array( $this->jobs, $this->items ), true ) ) {
                    $migrations[] = $table;
                } else {
                    /* translators: 1: database table name, 2: storage engine. */
                    $problems[] = sprintf( __( 'Table %1$s uses %2$s; InnoDB is required. Ask your host to back up the database and convert this table to InnoDB.', 'category-price-manager' ), $table, $engine );
                }
            }
        }
        // Complete preflight before any DDL: an unrelated incompatible table
        // must not trigger a partial, unnecessary plugin upgrade.
        if ( $problems ) { $this->fail( implode( ' ', $problems ) ); }
        // At most one native table rebuild per request. A retry inspects the
        // actual engine, so a lost response never causes an unnecessary rebuild.
        if ( $migrations ) {
            $this->migrate_plugin_table( $migrations[0] );
            return $migrations[0];
        }
        return '';
    }
    private function clear_product_cache( $id ) {
        if ( ! $id ) { return; }
        clean_post_cache( $id );
        wp_cache_delete( $id, 'post_meta' );
        if ( class_exists( 'WC_Cache_Helper' ) ) { WC_Cache_Helper::invalidate_cache_group( 'product_' . $id ); }
        if ( class_exists( '\Automattic\WooCommerce\Internal\Caches\ProductCache' ) ) {
            wc_get_container()->get( '\Automattic\WooCommerce\Internal\Caches\ProductCache' )->remove( $id );
        }
        wc_delete_product_transients( $id );
    }
    public function cleanup() {
        global $wpdb;
        if ( $this->transaction ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Rollback and advisory lock release apply to this database connection and must execute immediately.
            $wpdb->query( 'ROLLBACK' );
            $this->transaction = false;
        }
        // Also evict values cached before a rollback, including deferred parent sync.
        $product_id = $this->transaction_product;
        $parent_id = $this->transaction_parent;
        $this->transaction_product = 0;
        $this->transaction_parent = 0;
        if ( $this->mutation_lock ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Rollback and advisory lock release apply to this database connection and must execute immediately.
            $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $this->mutation_lock ) );
            $this->mutation_lock = '';
        }
        $this->clear_product_cache( $product_id );
        $this->clear_product_cache( $parent_id );
    }
    private function settings( $input ) {
        $categories = array_values( array_unique( array_filter( $input['categories'] ) ) );
        if ( ! $categories || count( $categories ) > 100 ) { $this->fail( __( 'Select 1–100 categories.', 'category-price-manager' ) ); }
        foreach ( $categories as $id ) { if ( ! term_exists( $id, 'product_cat' ) ) { $this->fail( __( 'One of the selected categories no longer exists.', 'category-price-manager' ) ); } }
        $operation = $input['operation'];
        $method = $input['method'];
        $field = $input['field'];
        if ( ! in_array( $operation, array( 'increase', 'decrease', 'set' ), true ) || ! in_array( $method, array( 'percent', 'fixed' ), true ) || ! in_array( $field, array( 'regular', 'sale', 'both' ), true ) ) { $this->fail( __( 'Invalid pricing rule.', 'category-price-manager' ) ); }
        $raw = $input['amount'];
        if ( ! preg_match( '/^(?:\d+)(?:\.\d{1,8})?$/D', $raw ) || ! is_finite( (float) $raw ) || (float) $raw > 1000000000 ) { $this->fail( __( 'Enter a valid non-negative amount (up to 1,000,000,000).', 'category-price-manager' ) ); }
        if ( 'percent' === $method && 'set' !== $operation && (float) $raw > 100000 ) { $this->fail( __( 'Percentage is too large.', 'category-price-manager' ) ); }
        if ( 'decrease' === $operation && 'percent' === $method && (float) $raw > 100 ) { $this->fail( __( 'Decreases cannot exceed 100%.', 'category-price-manager' ) ); }
        return array( 'categories' => $categories, 'descendants' => $input['descendants'], 'operation' => $operation, 'method' => $method, 'field' => $field, 'amount' => $raw, 'variations' => $input['variations'] );
    }
    public function ajax_create() {
        if ( ! isset( $_POST['nonce'] ) || ! is_string( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'cpm_admin' ) ) {
            wp_send_json_error( array( 'message' => __( 'Your session has expired. Refresh this page and retry.', 'category-price-manager' ) ), 403 );
        }
        $this->guard(); global $wpdb;
        $categories = array();
        if ( isset( $_POST['categories'] ) && is_array( $_POST['categories'] ) ) {
            // Inspect shape before absint: coercing nested arrays could select an unintended category.
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each unslashed scalar is sanitized with absint below; nested values are rejected.
            foreach ( wp_unslash( $_POST['categories'] ) as $category ) {
                if ( ! is_scalar( $category ) ) { $this->fail( __( 'Select 1–100 categories.', 'category-price-manager' ) ); }
                $categories[] = absint( $category );
            }
        }
        $input = array(
            'categories' => $categories,
            'operation' => isset( $_POST['operation'] ) && is_string( $_POST['operation'] ) ? sanitize_key( wp_unslash( $_POST['operation'] ) ) : '',
            'method' => isset( $_POST['method'] ) && is_string( $_POST['method'] ) ? sanitize_key( wp_unslash( $_POST['method'] ) ) : '',
            'field' => isset( $_POST['field'] ) && is_string( $_POST['field'] ) ? sanitize_key( wp_unslash( $_POST['field'] ) ) : '',
            'amount' => isset( $_POST['amount'] ) && is_string( $_POST['amount'] ) ? sanitize_text_field( wp_unslash( $_POST['amount'] ) ) : '',
            'descendants' => isset( $_POST['descendants'] ) && is_scalar( $_POST['descendants'] ) && 1 === absint( wp_unslash( $_POST['descendants'] ) ),
            'variations' => isset( $_POST['variations'] ) && is_scalar( $_POST['variations'] ) && 1 === absint( wp_unslash( $_POST['variations'] ) ),
        );
        $settings = $this->settings( $input );
        $this->lock_mutations();
        $now = current_time( 'mysql', true );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Insert the authorized job into the plugin-owned history table using typed wpdb CRUD.
        $ok = $wpdb->insert( $this->jobs, array( 'user_id' => get_current_user_id(), 'created_at' => $now, 'updated_at' => $now, 'settings' => wp_json_encode( $settings ), 'status' => 'staging' ), array( '%d', '%s', '%s', '%s', '%s' ) );
        if ( ! $ok ) { $this->fail( __( 'Could not create job. Check the plugin database tables.', 'category-price-manager' ) ); }
        $job_id = (int) $wpdb->insert_id;
        $this->cleanup();
        wp_send_json_success( array( 'job_id' => $job_id ) );
    }
    private function category_ids( $s ) {
        $ids = $s['categories'];
        if ( $s['descendants'] ) {
            foreach ( $s['categories'] as $id ) {
                $children = get_term_children( $id, 'product_cat' );
                if ( ! is_wp_error( $children ) ) { $ids = array_merge( $ids, array_map( 'absint', $children ) ); }
            }
        }
        return array_values( array_unique( $ids ) );
    }
    private function current_prices( $product ) {
        return array( 'regular' => (string) $product->get_regular_price( 'edit' ), 'sale' => (string) $product->get_sale_price( 'edit' ) );
    }
    private function new_price( $old, $s ) {
        $value = (float) $old;
        $amount = (float) $s['amount'];
        $delta = 'percent' === $s['method'] && 'set' !== $s['operation'] ? $value * $amount / 100 : $amount;
        if ( 'set' === $s['operation'] ) { $new = $amount; }
        elseif ( 'increase' === $s['operation'] ) { $new = $value + $delta; }
        else { $new = $value - $delta; }
        if ( ! is_finite( $new ) || $new < 0 || $new > 100000000000 ) { return false; }
        return wc_format_decimal( round( $new, wc_get_price_decimals() ), wc_get_price_decimals() );
    }
    /* Translators: product skip/conflict reasons are persisted as stable English keys, translated on display.
     * No regular price; Out-of-range price; Sale must be lower than regular price; No change;
     * Product missing or changed type; No product edit permission; Price changed since snapshot;
     * Could not verify saved price; Save failed; Ready.
     */
    private function proposed( $before, $s ) {
        $after = $before;
        if ( '' === $before['regular'] ) { return array( $before, 'No regular price' ); }
        $fields = 'both' === $s['field'] ? array( 'regular', 'sale' ) : array( $s['field'] );
        foreach ( $fields as $field ) {
            if ( '' === $before[$field] ) { if ( 'regular' === $field ) { return array( $before, 'No regular price' ); } continue; }
            $new = $this->new_price( $before[$field], $s );
            if ( false === $new ) { return array( $before, 'Out-of-range price' ); }
            $after[$field] = $new;
        }
        if ( '' !== $after['sale'] && '' !== $after['regular'] && (float) $after['sale'] >= (float) $after['regular'] ) {
            return array( $before, 'Sale must be lower than regular price' );
        }
        if ( $after === $before ) { return array( $before, 'No change' ); }
        return array( $after, '' );
    }
    private function insert_item( $job_id, $product, $parent_id, $s ) {
        global $wpdb;
        $before = $this->current_prices( $product );
        list( $after, $note ) = $this->proposed( $before, $s );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Persist custom snapshots and detect retry duplicates against the current database state.
        $ok = $wpdb->insert( $this->items, array( 'job_id' => $job_id, 'product_id' => $product->get_id(), 'parent_id' => $parent_id, 'before_json' => wp_json_encode( $before ), 'after_json' => wp_json_encode( $after ), 'status' => $note ? 'skipped' : 'pending', 'note' => $note ), array( '%d', '%d', '%d', '%s', '%s', '%s', '%s' ) );
        if ( false === $ok ) {
            // A repeated stage after a network failure can re-insert already
            // staged products. Ignore only that known duplicate case.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Persist custom snapshots and detect retry duplicates against the current database state.
            $exists = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM %i WHERE job_id = %d AND product_id = %d LIMIT 1",
                $this->items, $job_id, $product->get_id()
            ) );
            if ( ! $exists ) { $this->fail( __( 'Unable to save a product snapshot. Check the database error log.', 'category-price-manager' ) ); }
        }
    }
    public function ajax_stage() {
        if ( ! isset( $_POST['nonce'] ) || ! is_string( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'cpm_admin' ) ) {
            wp_send_json_error( array( 'message' => __( 'Your session has expired. Refresh this page and retry.', 'category-price-manager' ) ), 403 );
        }
        $this->guard(); global $wpdb;
        $this->lock_mutations();
        $job = $this->job( isset( $_POST['job_id'] ) && is_scalar( $_POST['job_id'] ) ? absint( wp_unslash( $_POST['job_id'] ) ) : 0 );
        if ( 'staging' !== $job->status ) { $this->fail( __( 'Job is not in staging mode.', 'category-price-manager' ) ); }
        $s = json_decode( $job->settings, true );
        $ids = $this->category_ids( $s );
        if ( ! $ids ) { $this->fail( __( 'No categories available.', 'category-price-manager' ) ); }
        // Stage at most BATCH individual product/variation records per AJAX call.
        // Previously, loading all children for 25 variable parents could exhaust
        // PHP memory or execution time and return an empty/500 AJAX response.
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $args = array_merge(
            array( $wpdb->posts, $wpdb->posts, $wpdb->term_relationships, $wpdb->term_taxonomy ),
            $ids,
            array( 'product', ! empty( $s['variations'] ) ? 'product_variation' : 'product', (int) $job->cursor_id, self::STAGE_BATCH )
        );
        $variations = ! empty( $s['variations'] );
        // Parent categories also select variation rows; only the next STAGE_BATCH candidates are read.
        // Only %d tokens are interpolated. wpdb::prepare accepts the argument array:
        // four identifiers, count($ids) category values, two post types, cursor and limit.
        // Scope these two static-analysis exceptions to this one dynamic IN query.
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read the live catalog and custom snapshot counts during resumable staging; stale cached results are unsafe.
        $records = $wpdb->get_results( $wpdb->prepare(
            "SELECT DISTINCT p.ID, p.post_parent FROM %i p
            INNER JOIN %i parent ON parent.ID = CASE WHEN p.post_type = 'product_variation' THEN p.post_parent ELSE p.ID END
            INNER JOIN %i tr ON tr.object_id = parent.ID
            INNER JOIN %i tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
            WHERE tt.taxonomy = 'product_cat' AND tt.term_id IN ($placeholders)
            AND p.post_type IN (%s,%s)
            AND parent.post_type = 'product'
            AND parent.post_status IN ('publish','private','draft','pending','future')
            AND p.post_status NOT IN ('trash','auto-draft')
            AND p.ID > %d ORDER BY p.ID ASC LIMIT %d",
            $args
        ) );
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
        if ( null === $records || ( $wpdb->last_error && ! $records ) ) {
            $this->fail( __( 'Unable to query products. Check the database error log.', 'category-price-manager' ) );
        }
        foreach ( $records as $record ) {
            $product = wc_get_product( (int) $record->ID );
            if ( ! $this->can_access_product( $product ) || ! $product->is_type( array( 'simple', 'variation' ) ) ) { continue; }
            if ( $product->is_type( 'variation' ) && ! $variations ) { continue; }
            $parent_id = $product->is_type( 'variation' ) ? (int) $record->post_parent : 0;
            $this->insert_item( (int) $job->id, $product, $parent_id, $s );
        }
        $cursor = $records ? (int) end( $records )->ID : (int) $job->cursor_id;
        $done = count( $records ) < self::STAGE_BATCH;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read the live catalog and custom snapshot counts during resumable staging; stale cached results are unsafe.
        $count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE job_id = %d", $this->items, $job->id ) );
        $this->check_database();
        $data = array( 'cursor_id' => $cursor );
        if ( $done ) { $data['total'] = $count; $data['status'] = 'ready'; }
        $this->update_job( $job->id, $data );
        $this->cleanup();
        wp_send_json_success( array( 'done' => $done, 'count' => $count, 'job_id' => (int) $job->id ) );
    }
    private function job_counts( $id ) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read current per-job results after each batch; cached progress can incorrectly report completion.
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT status, COUNT(*) AS n FROM %i WHERE job_id = %d GROUP BY status", $this->items, $id ) );
        $this->check_database();
        if ( ! is_array( $rows ) ) { $this->fail( __( 'Could not count job items.', 'category-price-manager' ) ); }
        $counts = array( 'pending' => 0, 'applied' => 0, 'skipped' => 0, 'undone' => 0, 'conflict' => 0 );
        foreach ( $rows as $row ) { $counts[$row->status] = (int) $row->n; }
        return $counts;
    }
    public function ajax_preview() {
        if ( ! isset( $_POST['nonce'] ) || ! is_string( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'cpm_admin' ) ) {
            wp_send_json_error( array( 'message' => __( 'Your session has expired. Refresh this page and retry.', 'category-price-manager' ) ), 403 );
        }
        $this->guard(); global $wpdb;
        $job = $this->job( isset( $_POST['job_id'] ) && is_scalar( $_POST['job_id'] ) ? absint( wp_unslash( $_POST['job_id'] ) ) : 0 );
        if ( 'ready' !== $job->status ) { $this->fail( __( 'Preview is not ready yet.', 'category-price-manager' ) ); }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read the authorized custom snapshot for the current preview without stale cross-request results.
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT product_id, parent_id, before_json, after_json, status, note FROM %i WHERE job_id = %d ORDER BY id ASC LIMIT 30", $this->items, $job->id ) );
        $this->check_database();
        $sample = array();
        foreach ( $rows as $row ) {
            $product = wc_get_product( (int) $row->product_id );
            if ( ! $this->can_access_product( $product ) ) { continue; }
            $sample[] = array( 'product' => $product ? $product->get_name() . ' (#' . $row->product_id . ')' : 'Product #' . $row->product_id, 'before' => json_decode( $row->before_json, true ), 'after' => json_decode( $row->after_json, true ), 'note' => $this->note_label( $row->note ) );
        }
        wp_send_json_success( array( 'job_id' => (int) $job->id, 'total' => (int) $job->total, 'counts' => $this->job_counts( $job->id ), 'sample' => $sample ) );
    }
    private function note_label( $note ) {
        // Keep stored keys stable for existing snapshots and translation extraction.
        $labels = array(
            'Ready' => __( 'Ready', 'category-price-manager' ),
            'No regular price' => __( 'No regular price', 'category-price-manager' ),
            'Out-of-range price' => __( 'Out-of-range price', 'category-price-manager' ),
            'Sale must be lower than regular price' => __( 'Sale must be lower than regular price', 'category-price-manager' ),
            'No change' => __( 'No change', 'category-price-manager' ),
            'Product missing or changed type' => __( 'Product missing or changed type', 'category-price-manager' ),
            'No product edit permission' => __( 'No product edit permission', 'category-price-manager' ),
            'Price changed since snapshot' => __( 'Price changed since snapshot', 'category-price-manager' ),
            'Could not verify saved price' => __( 'Could not verify saved price', 'category-price-manager' ),
            'Save failed' => __( 'Save failed', 'category-price-manager' ),
            'Legacy price ownership is uncertain' => __( 'Legacy price ownership is uncertain', 'category-price-manager' ),
            'Invalid snapshot' => __( 'Invalid snapshot', 'category-price-manager' ),
        );
        return isset( $labels[$note ?: 'Ready'] ) ? $labels[$note ?: 'Ready'] : $note;
    }
    private function matching( $a, $b ) {
        // Price strings can vary in formatting, but blank is distinct from zero.
        foreach ( array( 'regular', 'sale' ) as $key ) {
            if ( ! isset( $a[$key] ) || ! isset( $b[$key] ) ) { return false; }
            if ( '' === $a[$key] || '' === $b[$key] ) { if ( $a[$key] !== $b[$key] ) { return false; } }
            elseif ( abs( (float) $a[$key] - (float) $b[$key] ) > 0.0000001 ) { return false; }
        }
        return true;
    }
    private function update_prices( $product, $prices ) {
        // Use WooCommerce CRUD, never raw _regular_price / _sale_price meta updates.
        // A variation save triggers WooCommerce's own hooks, cache and lookup handling.
        // Re-running WC_Product_Variable::sync() after *each* variation is redundant
        // and can make large product families exceed the server request timeout.
        $product->set_regular_price( $prices['regular'] );
        $product->set_sale_price( $prices['sale'] );
        $product->save();
    }
    private function stored_prices( $id ) {
        global $wpdb;
        // Read the database, not WooCommerce/object caches, when verifying ownership.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Verify persisted prices inside the transaction rather than trusting object-cache values.
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM %i WHERE post_id = %d AND meta_key IN ('_regular_price','_sale_price') ORDER BY meta_id ASC", $wpdb->postmeta, $id ) );
        if ( $wpdb->last_error || ! is_array( $rows ) ) { throw new RuntimeException( 'Could not verify persisted prices.' ); }
        $prices = array( 'regular' => '', 'sale' => '' );
        $seen = array();
        foreach ( $rows as $row ) {
            $key = '_regular_price' === $row->meta_key ? 'regular' : 'sale';
            if ( isset( $seen[$key] ) ) { throw new RuntimeException( 'Duplicate price metadata requires repair.' ); }
            $seen[$key] = true;
            $prices[$key] = (string) $row->meta_value;
        }
        return $prices;
    }
    private function process_item( $row, $undo ) {
        global $wpdb;
        $in_status = $undo ? 'applied' : 'pending';
        $id = (int) $row->product_id;
        $this->transaction_product = $id;
        $this->transaction_parent = (int) $row->parent_id;
        try {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Transaction boundaries and row locks must execute on this connection; cached reads cannot protect price ownership.
            if ( false === $wpdb->query( 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ' ) || false === $wpdb->query( 'START TRANSACTION' ) ) {
                throw new RuntimeException( 'Could not start price transaction.' );
            }
            $this->transaction = true;
            // Claim the item and lock product metadata before reading the expected prices.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Transaction boundaries and row locks must execute on this connection; cached reads cannot protect price ownership.
            $status = $wpdb->get_var( $wpdb->prepare( "SELECT status FROM %i WHERE id = %d FOR UPDATE", $this->items, $row->id ) );
            if ( $wpdb->last_error || $status !== $in_status ) { throw new RuntimeException( 'Item was already claimed or could not be locked.' ); }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Transaction boundaries and row locks must execute on this connection; cached reads cannot protect price ownership.
            $wpdb->get_results( $wpdb->prepare( "SELECT ID FROM %i WHERE ID = %d FOR UPDATE", $wpdb->posts, $id ) );
            if ( $wpdb->last_error ) { throw new RuntimeException( 'Could not lock product.' ); }
            // Lock the full post_id range, including gaps for missing sale metadata.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Transaction boundaries and row locks must execute on this connection; cached reads cannot protect price ownership.
            $wpdb->get_results( $wpdb->prepare( "SELECT meta_id FROM %i WHERE post_id = %d FOR UPDATE", $wpdb->postmeta, $id ) );
            if ( $wpdb->last_error ) { throw new RuntimeException( 'Could not lock product metadata.' ); }
            clean_post_cache( $id );
            wp_cache_delete( $id, 'post_meta' );
            $product = wc_get_product( $id );
            if ( $product ) {
                // Construct afresh: cloning WC_Data may clear metadata IDs.
                $class = get_class( $product );
                $product = new $class( $id );
                $this->transaction_parent = (int) $product->get_parent_id();
            }
            $before = json_decode( $row->before_json, true );
            $after = json_decode( $row->after_json, true );
            $expected = $undo ? $after : $before;
            $target = $undo ? $before : $after;
            $result = $undo ? 'undone' : 'applied';
            $note = '';
            if ( ! $product || ! $product->is_type( array( 'simple', 'variation' ) ) ) {
                $result = 'conflict'; $note = 'Product missing or changed type';
            } elseif ( ! $this->can_access_product( $product ) ) {
                $result = 'conflict'; $note = 'No product edit permission';
            } elseif ( $undo && 'Already at target price' === $row->note ) {
                // Pre-1.3 jobs inferred ownership from equality; that is not proof.
                $result = 'conflict'; $note = 'Legacy price ownership is uncertain';
            } elseif ( ! is_array( $before ) || ! is_array( $after ) || ! $this->matching( $before, $before ) || ! $this->matching( $after, $after ) ) {
                $result = 'conflict'; $note = 'Invalid snapshot';
            } elseif ( ! $this->matching( $this->stored_prices( $id ), $expected ) ) {
                // Equality with the target does NOT prove that this job wrote it.
                $result = 'conflict'; $note = 'Price changed since snapshot';
            } else {
                // Exceptions, including post-save hook failures, roll back the price
                // and leave the original item state available for a safe retry.
                $this->update_prices( $product, $target );
                if ( ! $this->matching( $this->stored_prices( $id ), $target ) ) {
                    throw new RuntimeException( 'Could not verify saved price.' );
                }
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Transaction boundaries and row locks must execute on this connection; cached reads cannot protect price ownership.
            $updated = $wpdb->update( $this->items, array( 'status' => $result, 'note' => $note ), array( 'id' => $row->id, 'status' => $in_status ), array( '%s', '%s' ), array( '%d', '%s' ) );
            if ( 1 !== $updated ) { throw new RuntimeException( 'Could not persist price ownership.' ); }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Transaction boundaries and row locks must execute on this connection; cached reads cannot protect price ownership.
            if ( false === $wpdb->query( 'COMMIT' ) ) { throw new RuntimeException( 'Could not commit price transaction.' ); }
            $this->transaction = false;
        } catch ( Throwable $e ) {
            $this->log_error( 'Product ' . $id . ': ' . $e->getMessage() );
            $this->fail( __( 'Price update could not be committed. The item remains retryable; resume from history after checking the error log.', 'category-price-manager' ) );
        }
        $this->clear_product_cache( $this->transaction_product );
        $this->clear_product_cache( $this->transaction_parent );
        $this->transaction_product = 0;
        $this->transaction_parent = 0;
    }
    private function process( $job, $undo ) {
        global $wpdb;
        $upgraded = $this->require_transactions( true );
        if ( $upgraded ) {
            $counts = $this->job_counts( $job->id );
            $this->cleanup();
            // No product write or transaction in a request that executes DDL.
            wp_send_json_success( array( 'done' => false, 'phase' => 'database_upgrade', 'status' => $job->status, 'total' => (int) $job->total, 'counts' => $counts, 'job_id' => (int) $job->id ) );
        }
        $in_status = $undo ? 'applied' : 'pending';
        // Persist direction before the first write, so any interrupted job is resumable.
        $this->update_job( $job->id, array( 'status' => $undo ? 'undoing' : 'applying' ) );
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read the current pending/applied custom rows under the mutation lock for a resumable batch.
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM %i WHERE job_id = %d AND status = %s ORDER BY id ASC LIMIT %d",
            $this->items, $job->id, $in_status, self::WRITE_BATCH
        ) );
        $this->check_database();
        if ( ! is_array( $rows ) ) { $this->fail( __( 'Unable to read the batch.', 'category-price-manager' ) ); }
        $started_at = microtime( true );
        foreach ( $rows as $row ) {
            if ( microtime( true ) - $started_at > 8 ) { break; }
            $this->process_item( $row, $undo );
        }
        $counts = $this->job_counts( $job->id );
        $remaining = $undo ? $counts['applied'] : $counts['pending'];
        $done = 0 === $remaining;
        $status = $done ? ( $undo ? 'undone' : 'completed' ) : ( $undo ? 'undoing' : 'applying' );
        $this->update_job( $job->id, array( 'status' => $status, 'applied' => $counts['applied'], 'skipped' => $counts['skipped'], 'undone' => $counts['undone'], 'conflicts' => $counts['conflict'] ) );
        $this->cleanup();
        wp_send_json_success( array( 'done' => $done, 'status' => $status, 'total' => (int) $job->total, 'counts' => $counts, 'job_id' => (int) $job->id ) );
    }
    public function ajax_apply() {
        if ( ! isset( $_POST['nonce'] ) || ! is_string( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'cpm_admin' ) ) {
            wp_send_json_error( array( 'message' => __( 'Your session has expired. Refresh this page and retry.', 'category-price-manager' ) ), 403 );
        }
        $this->guard();
        $this->lock_mutations();
        $job = $this->job( isset( $_POST['job_id'] ) && is_scalar( $_POST['job_id'] ) ? absint( wp_unslash( $_POST['job_id'] ) ) : 0 );
        if ( ! in_array( $job->status, array( 'ready', 'applying' ), true ) ) { $this->fail( __( 'This job cannot be applied.', 'category-price-manager' ) ); }
        $this->process( $job, false );
    }
    public function ajax_undo() {
        if ( ! isset( $_POST['nonce'] ) || ! is_string( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'cpm_admin' ) ) {
            wp_send_json_error( array( 'message' => __( 'Your session has expired. Refresh this page and retry.', 'category-price-manager' ) ), 403 );
        }
        $this->guard();
        $this->lock_mutations();
        $job = $this->job( isset( $_POST['job_id'] ) && is_scalar( $_POST['job_id'] ) ? absint( wp_unslash( $_POST['job_id'] ) ) : 0 );
        if ( ! in_array( $job->status, array( 'completed', 'undoing' ), true ) ) { $this->fail( __( 'Only a completed update can be undone.', 'category-price-manager' ) ); }
        $this->process( $job, true );
    }
    public function ajax_history() {
        if ( ! isset( $_POST['nonce'] ) || ! is_string( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'cpm_admin' ) ) {
            wp_send_json_error( array( 'message' => __( 'Your session has expired. Refresh this page and retry.', 'category-price-manager' ) ), 403 );
        }
        $this->guard(); global $wpdb;
        $page = isset( $_POST['page'] ) && is_scalar( $_POST['page'] ) ? max( 1, min( 1000000, absint( wp_unslash( $_POST['page'] ) ) ) ) : 1;
        // Fetch one extra record to detect the next page without a second count query.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read current user-scoped custom job state so interrupted operations are immediately resumable.
        $jobs = $wpdb->get_results( $wpdb->prepare( "SELECT id, created_at, status, settings, total FROM %i WHERE user_id = %d ORDER BY CASE WHEN status IN ('staging','applying','undoing') THEN 0 ELSE 1 END, id DESC LIMIT 21 OFFSET %d", $this->jobs, get_current_user_id(), ( $page - 1 ) * 20 ) );
        $this->check_database();
        if ( ! is_array( $jobs ) ) { $this->fail( __( 'Could not load history.', 'category-price-manager' ) ); }
        $has_more = count( $jobs ) > 20;
        $jobs = array_slice( $jobs, 0, 20 );
        $data = array();
        foreach ( $jobs as $job ) {
            $s = json_decode( $job->settings, true );
            $data[] = array( 'id' => (int) $job->id, 'date' => get_date_from_gmt( $job->created_at, 'Y-m-d H:i' ), 'status' => $job->status, 'total' => (int) $job->total, 'counts' => $this->job_counts( $job->id ), 'rule_parts' => array( 'operation' => $s['operation'], 'amount' => $s['amount'], 'method' => $s['method'], 'field' => $s['field'] ) );
        }
        wp_send_json_success( array( 'jobs' => $data, 'page' => $page, 'has_more' => $has_more ) );
    }
}
register_activation_hook( __FILE__, array( 'CPM_Category_Price_Manager', 'install' ) );
add_action( 'plugins_loaded', array( 'CPM_Category_Price_Manager', 'instance' ) );
