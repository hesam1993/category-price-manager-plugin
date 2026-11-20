<?php
// Run: php tests/plugin-regression.php
// Fault-injection unit tests. The fake database models transaction rollback;
// real InnoDB lock behavior still requires WordPress/MySQL integration testing.
define( 'ABSPATH', '/' );
define( 'DB_NAME', 'cpm_test' );
class AjaxExit extends Exception {
    public $data;
    public $success;
    public function __construct( $data, $success ) { parent::__construct( 'AJAX exit' ); $this->data = $data; $this->success = $success; }
}
class WooCommerce {}
function add_action() {}
function register_activation_hook() {}
function __( $s, $domain = '' ) { return $s; }
function translate( $s, $domain = '' ) { return $s; }
function wp_verify_nonce( $nonce, $action ) { return 'valid-nonce' === $nonce && 'cpm_admin' === $action ? 1 : false; }
function sanitize_text_field( $v ) { return is_scalar( $v ) ? trim( preg_replace( '/%[a-f0-9]{2}/i', '', strip_tags( (string) $v ) ) ) : ''; }
function current_user_can( $cap, $id = 0 ) { return ! in_array( $id, $GLOBALS['denied'], true ) && ! in_array( $cap, $GLOBALS['denied_caps'], true ); }
function get_current_user_id() { return 7; }
function current_time() { return '2026-11-11 12:00:00'; }
function get_date_from_gmt( $s, $format ) { return $s; }
function absint( $v ) { return abs( (int) $v ); }
function wp_send_json_error( $data, $code = 400 ) { throw new AjaxExit( $data, false ); }
function wp_send_json_success( $data ) { throw new AjaxExit( $data, true ); }
function clean_post_cache() {}
function wp_cache_delete() {}
function wc_delete_product_transients() {}
function wp_json_encode( $v ) { return json_encode( $v ); }
function wc_get_price_decimals() { return 2; }
function wc_format_decimal( $n, $dp = 2 ) { return number_format( $n, $dp, '.', '' ); }
function wp_unslash( $v ) { return is_array( $v ) ? array_map( 'wp_unslash', $v ) : ( is_string( $v ) ? stripslashes( $v ) : $v ); }
function sanitize_key( $v ) { return preg_replace( '/[^a-z0-9_-]/', '', strtolower( $v ) ); }
function term_exists() { return true; }
function is_wp_error() { return false; }
function get_term_children() { return array(); }
function wc_get_product( $id ) { return new FakeProduct( $id ); }
class FakeProduct {
    private $id;
    private $regular;
    private $sale;
    public function __construct( $id ) { $this->id = $id; $this->read( $this ); }
    public function read( &$product ) { $product->regular = $GLOBALS['wpdb']->prices['regular']; $product->sale = $GLOBALS['wpdb']->prices['sale']; }
    public function get_data_store() { return $this; }
    public function get_id() { return $this->id; }
    public function get_parent_id() { return $GLOBALS['parent_id']; }
    public function is_type( $type ) { return in_array( $GLOBALS['parent_id'] ? 'variation' : 'simple', (array) $type, true ); }
    public function get_name() { return 'Restricted product'; }
    public function get_regular_price( $context = '' ) { return $this->regular; }
    public function get_sale_price( $context = '' ) { return $this->sale; }
    public function set_regular_price( $v ) { $this->regular = $v; }
    public function set_sale_price( $v ) { $this->sale = $v; }
    public function save() {
        $db = $GLOBALS['wpdb'];
        $db->writes++;
        $db->prices['regular'] = $this->regular;
        if ( 'partial' === $db->save_failure ) { throw new RuntimeException( 'Partial write failure' ); }
        $db->prices['sale'] = $this->sale;
        if ( 'after' === $db->save_failure ) { throw new RuntimeException( 'Post-save hook failure' ); }
    }
}
class FakeDB {
    public $prefix = 'wp_';
    public $posts = 'wp_posts';
    public $postmeta = 'wp_postmeta';
    public $term_relationships = 'wp_term_relationships';
    public $term_taxonomy = 'wp_term_taxonomy';
    public $last_error = '';
    public $prices = array( 'regular' => '100', 'sale' => '' );
    public $status = 'pending';
    public $job_status = 'ready';
    public $save_failure = '';
    public $fail_item = false;
    public $fail_job = false;
    public $fail_counts = false;
    public $lock_available = true;
    public $engine = 'InnoDB';
    public $engines = array();
    public $alter_failure = false;
    public $alter_noop = false;
    public $lock_owned = true;
    public $lock_timeout = 100;
    public $timeout_failure = false;
    public $metadata_failure = false;
    public $metadata_empty = false;
    public $status_failure = false;
    public $status_missing = false;
    public $errors_suppressed = false;
    public $writes = 0;
    public $inserts = 0;
    public $insert_id = 1;
    public $last_insert = array();
    public $prepared = array();
    public $queries = array();
    public $history = array();
    public $records = array();
    private $snapshot;
    public function suppress_errors( $value ) { $old = $this->errors_suppressed; $this->errors_suppressed = $value; return $old; }
    public function prepare( $sql, ...$args ) {
        if ( 1 === count( $args ) && is_array( $args[0] ) ) { $args = $args[0]; }
        $this->prepared[] = array( $sql, $args );
        $prepared = preg_replace_callback( '/%[dsi]/', function( $match ) use ( &$args ) {
            $value = array_shift( $args );
            if ( '%d' === $match[0] ) { return (string) (int) $value; }
            if ( '%i' === $match[0] ) { return '`' . str_replace( '`', '``', $value ) . '`'; }
            return "'" . str_replace( "'", "''", $value ) . "'";
        }, $sql );
        check( ! $args, 'SQL had unconsumed prepare arguments' );
        return $prepared;
    }
    public function query( $sql ) {
        $this->queries[] = $sql; $this->last_error = '';
        if ( preg_match( '/^ALTER TABLE `([^`]+)` ENGINE=InnoDB$/', $sql, $match ) ) {
            if ( $this->alter_failure ) { $this->last_error = 'ALTER command denied'; return false; }
            if ( ! $this->alter_noop ) { $this->engines[$match[1]] = 'InnoDB'; }
            return 0; // Successful DDL can report zero affected rows.
        }
        if ( preg_match( '/^SET SESSION lock_wait_timeout = (\d+)$/', $sql, $match ) ) {
            if ( $this->timeout_failure ) { $this->last_error = 'Cannot change session setting'; return false; }
            $this->lock_timeout = (int) $match[1]; return 0;
        }
        if ( 'START TRANSACTION' === $sql ) { $this->snapshot = array( $this->prices, $this->status ); }
        if ( 'ROLLBACK' === $sql && $this->snapshot ) { list( $this->prices, $this->status ) = $this->snapshot; $this->snapshot = null; }
        if ( 'COMMIT' === $sql ) { $this->snapshot = null; }
        return 1;
    }
    public function get_var( $sql ) {
        $this->queries[] = $sql; $this->last_error = '';
        if ( false !== strpos( $sql, 'IS_USED_LOCK' ) ) { return $this->lock_owned ? '1' : '0'; }
        if ( false !== strpos( $sql, '@@SESSION.lock_wait_timeout' ) ) { return $this->lock_timeout; }
        if ( false !== strpos( $sql, 'GET_LOCK' ) ) { return $this->lock_available ? '1' : '0'; }
        if ( false !== strpos( $sql, 'RELEASE_LOCK' ) ) { return '1'; }
        if ( false !== strpos( $sql, 'SELECT ENGINE' ) ) {
            if ( $this->metadata_failure ) { $this->last_error = 'Metadata denied'; return null; }
            preg_match( "/TABLE_NAME = '([^']+)'/", $sql, $match );
            return $this->metadata_empty ? null : ( $this->engines[$match[1]] ?? $this->engine );
        }
        if ( false !== strpos( $sql, 'SELECT status' ) ) { return $this->status; }
        if ( false !== strpos( $sql, 'COUNT(*)' ) ) { return $this->inserts; }
        return null;
    }
    public function get_row( $sql ) {
        $this->queries[] = $sql; $this->last_error = '';
        if ( false !== strpos( $sql, 'SHOW TABLE STATUS' ) ) {
            if ( $this->status_failure ) { $this->last_error = 'Status denied'; return null; }
            preg_match( "/Name = '([^']+)'/", $sql, $match );
            return $this->status_missing ? null : (object) array( 'Engine' => $this->engines[$match[1]] ?? $this->engine );
        }
        if ( ! preg_match( '/WHERE id = 1 AND user_id = 7$/', $sql ) ) { return null; }
        return (object) array( 'id' => 1, 'user_id' => 7, 'status' => $this->job_status, 'cursor_id' => 0, 'total' => 1, 'settings' => json_encode( array( 'categories' => array(1), 'descendants' => false, 'variations' => true ) ) );
    }
    public function get_results( $sql ) {
        $this->queries[] = $sql; $this->last_error = '';
        if ( false !== strpos( $sql, 'GROUP BY status' ) ) {
            if ( $this->fail_counts ) { $this->last_error = 'Injected count error'; return null; }
            return array( (object) array( 'status' => $this->status, 'n' => 1 ) );
        }
        if ( false !== strpos( $sql, 'SELECT meta_key' ) ) { return array( (object) array( 'meta_key' => '_regular_price', 'meta_value' => $this->prices['regular'] ), (object) array( 'meta_key' => '_sale_price', 'meta_value' => $this->prices['sale'] ) ); }
        if ( false !== strpos( $sql, 'FOR UPDATE' ) ) { return array(); }
        if ( false !== strpos( $sql, 'LIMIT 21 OFFSET' ) ) { return $this->history; }
        if ( false !== strpos( $sql, 'SELECT DISTINCT p.ID' ) ) { return $this->records; }
        if ( false !== strpos( str_replace( '`', '', $sql ), 'FROM wp_cpm_items' ) ) { return array( item() ); }
        throw new RuntimeException( 'Unexpected query: ' . $sql );
    }
    public function update( $table, $data, $where, $formats = null, $where_formats = null ) {
        if ( 'wp_cpm_items' === $table ) {
            if ( $this->fail_item ) { $this->last_error = 'Injected item error'; return false; }
            if ( $where['status'] !== $this->status ) { return 0; }
            $this->status = $data['status'];
        } else {
            if ( $this->fail_job ) { $this->last_error = 'Injected job error'; return false; }
            if ( isset( $data['status'] ) ) { $this->job_status = $data['status']; }
        }
        return 1;
    }
    public function insert( $table, $data, $formats ) { $this->inserts++; $this->last_insert = $data; return 1; }
}
require __DIR__ . '/../category-price-manager.php';
function reset_case() { $GLOBALS['wpdb'] = new FakeDB(); $GLOBALS['denied'] = array(); $GLOBALS['denied_caps'] = array(); $GLOBALS['parent_id'] = 0; $_POST = array( 'nonce' => 'valid-nonce', 'job_id' => 1 ); }
function item( $note = '' ) { return (object) array( 'id' => 1, 'job_id' => 1, 'product_id' => 10, 'parent_id' => 0, 'before_json' => '{"regular":"100","sale":""}', 'after_json' => '{"regular":"110","sale":""}', 'status' => $GLOBALS['wpdb']->status, 'note' => $note ); }
function invoke( $method, ...$args ) { $r = new ReflectionMethod( CPM_Category_Price_Manager::class, $method ); $r->setAccessible( true ); return $r->invokeArgs( CPM_Category_Price_Manager::instance(), $args ); }
function check( $condition, $message ) { if ( ! $condition ) { throw new RuntimeException( $message ); } }
function ajax( $callback, $success ) { try { $callback(); } catch ( AjaxExit $e ) { check( $e->success === $success, 'Unexpected AJAX success state' ); return $e->data; } throw new RuntimeException( 'Expected AJAX exit' ); }
function test_case( $name, $callback ) { reset_case(); $callback(); CPM_Category_Price_Manager::instance()->cleanup(); echo 'PASS: ' . $name . "\n"; }
reset_case();
test_case( 'apply commits price and ownership together; undo restores both', function() {
    invoke( 'process_item', item(), false );
    check( '110' === $GLOBALS['wpdb']->prices['regular'] && 'applied' === $GLOBALS['wpdb']->status, 'Apply failed' );
    invoke( 'process_item', item(), true );
    check( '100' === $GLOBALS['wpdb']->prices['regular'] && 'undone' === $GLOBALS['wpdb']->status, 'Undo failed' );
} );
test_case( 'external target equality is not claimed', function() {
    $GLOBALS['wpdb']->prices['regular'] = '110'; invoke( 'process_item', item(), false );
    check( 'conflict' === $GLOBALS['wpdb']->status && 0 === $GLOBALS['wpdb']->writes, 'External write was claimed' );
} );
foreach ( array( 'partial', 'after' ) as $failure ) {
    test_case( $failure . ' save exception rolls back and remains retryable', function() use ( $failure ) {
        $GLOBALS['wpdb']->save_failure = $failure;
        ajax( function() { invoke( 'process_item', item(), false ); }, false );
        check( '100' === $GLOBALS['wpdb']->prices['regular'] && 'pending' === $GLOBALS['wpdb']->status, 'Rollback failed' );
        $GLOBALS['wpdb']->save_failure = ''; invoke( 'process_item', item(), false );
        check( 'applied' === $GLOBALS['wpdb']->status, 'Retry failed' );
    } );
}
test_case( 'failed progress persistence rolls back the price', function() {
    $GLOBALS['wpdb']->fail_item = true;
    ajax( function() { invoke( 'process_item', item(), false ); }, false );
    check( '100' === $GLOBALS['wpdb']->prices['regular'] && 'pending' === $GLOBALS['wpdb']->status, 'Price persisted without undo ownership' );
} );
test_case( 'lost response retry cannot process an already applied item', function() {
    invoke( 'process_item', item(), false );
    ajax( function() { invoke( 'process_item', item(), false ); }, false );
    check( 1 === $GLOBALS['wpdb']->writes && 'applied' === $GLOBALS['wpdb']->status, 'Duplicate write' );
} );
test_case( 'legacy uncertain ownership is not undone', function() {
    $GLOBALS['wpdb']->status = 'applied'; $GLOBALS['wpdb']->prices['regular'] = '110';
    invoke( 'process_item', item( 'Already at target price' ), true );
    check( 'conflict' === $GLOBALS['wpdb']->status && '110' === $GLOBALS['wpdb']->prices['regular'], 'Ambiguous legacy write undone' );
} );
test_case( 'concurrent mutation is rejected before reading a job', function() {
    $GLOBALS['wpdb']->lock_available = false;
    ajax( function() { CPM_Category_Price_Manager::instance()->ajax_apply(); }, false );
    check( 0 === $GLOBALS['wpdb']->writes && 1 === count( $GLOBALS['wpdb']->queries ), 'Busy job was processed' );
} );
test_case( 'failed counts do not mark a job completed', function() {
    $GLOBALS['wpdb']->fail_counts = true;
    ajax( function() { CPM_Category_Price_Manager::instance()->ajax_apply(); }, false );
    check( 'applying' === $GLOBALS['wpdb']->job_status, 'Falsely completed job' );
} );
test_case( 'failed job update stops before price writes', function() {
    $GLOBALS['wpdb']->fail_job = true;
    ajax( function() { CPM_Category_Price_Manager::instance()->ajax_apply(); }, false );
    check( 0 === $GLOBALS['wpdb']->writes, 'Wrote despite job error' );
} );
test_case( 'nontransactional tables prevent price writes', function() {
    $GLOBALS['wpdb']->engine = 'MyISAM';
    ajax( function() { CPM_Category_Price_Manager::instance()->ajax_apply(); }, false );
    check( 0 === $GLOBALS['wpdb']->writes, 'Unsafe storage engine accepted' );
} );
test_case( 'restricted products are not staged or previewed', function() {
    $GLOBALS['denied'] = array( 10 ); $GLOBALS['wpdb']->job_status = 'staging';
    $GLOBALS['wpdb']->records = array( (object) array( 'ID' => 10, 'post_parent' => 0 ) );
    ajax( function() { CPM_Category_Price_Manager::instance()->ajax_stage(); }, true );
    check( 0 === $GLOBALS['wpdb']->inserts, 'Restricted product staged' );
    $data = ajax( function() { CPM_Category_Price_Manager::instance()->ajax_preview(); }, true );
    check( array() === $data['sample'], 'Restricted snapshot leaked' );
} );
test_case( 'variation access also requires parent permissions', function() {
    $GLOBALS['parent_id'] = 20; $GLOBALS['denied'] = array( 20 );
    check( false === invoke( 'can_access_product', new FakeProduct( 10 ) ), 'Parent permissions ignored' );
} );
test_case( 'history is paginated and reports a next page', function() {
    $_POST['page'] = 2;
    for ( $i = 0; $i < 21; $i++ ) { $GLOBALS['wpdb']->history[] = (object) array( 'id' => $i + 1, 'created_at' => '2026-11-11', 'status' => 'completed', 'total' => 1, 'settings' => '{"operation":"increase","amount":"10","method":"percent","field":"regular"}' ); }
    $data = ajax( function() { CPM_Category_Price_Manager::instance()->ajax_history(); }, true );
    check( 20 === count( $data['jobs'] ) && 2 === $data['page'] && $data['has_more'], 'Incorrect page results' );
    check( false !== strpos( $GLOBALS['wpdb']->queries[0], 'OFFSET 20' ), 'Wrong offset' );
} );
test_case( 'blocked metadata query falls back to table status', function() {
    $GLOBALS['wpdb']->metadata_failure = true;
    invoke( 'require_transactions' );
    check( false === $GLOBALS['wpdb']->errors_suppressed, 'Error display setting not restored' );
    check( 5 === count( array_filter( $GLOBALS['wpdb']->queries, function( $q ) { return false !== strpos( $q, 'SHOW TABLE STATUS WHERE Name =' ); } ) ), 'Did not inspect all exact table names' );
} );
test_case( 'empty metadata result also falls back', function() {
    $GLOBALS['wpdb']->metadata_empty = true;
    invoke( 'require_transactions' );
    check( 10 === count( $GLOBALS['wpdb']->queries ), 'Fallback missing' );
} );
test_case( 'wrong engine reports table names and engine', function() {
    $GLOBALS['wpdb']->engine = 'MyISAM';
    $data = ajax( function() { invoke( 'require_transactions' ); }, false );
    check( false !== strpos( $data['message'], 'wp_postmeta uses MYISAM' ), 'No actionable engine diagnosis' );
    check( false !== strpos( $data['message'], 'wp_cpm_items uses MYISAM' ), 'Did not list all affected tables' );
} );
test_case( 'failed inspection is distinguished from wrong engine', function() {
    $GLOBALS['wpdb']->metadata_failure = true;
    $GLOBALS['wpdb']->status_failure = true;
    $data = ajax( function() { invoke( 'require_transactions' ); }, false );
    check( false !== strpos( $data['message'], 'Cannot inspect table wp_posts.' ), 'Incorrect diagnosis' );
    check( false === strpos( $data['message'], 'convert' ), 'Suggested conversion without evidence' );
    check( false === $GLOBALS['wpdb']->errors_suppressed, 'Error display setting not restored on failure' );
} );
test_case( 'missing or hidden table does not pass verification', function() {
    $GLOBALS['wpdb']->metadata_empty = true;
    $GLOBALS['wpdb']->status_missing = true;
    $data = ajax( function() { invoke( 'require_transactions' ); }, false );
    check( false !== strpos( $data['message'], 'No storage engine was reported for table wp_cpm_jobs.' ), 'Missing-table diagnosis absent' );
} );
function legacy_tables() { $GLOBALS['wpdb']->engines = array( 'wp_cpm_jobs' => 'MyISAM', 'wp_cpm_items' => 'MyISAM' ); }
function apply_request() { return ajax( function() { CPM_Category_Price_Manager::instance()->ajax_apply(); }, true ); }
test_case( 'legacy tables migrate one per request before prices change', function() {
    legacy_tables();
    $one = apply_request();
    check( false === $one['done'] && 'database_upgrade' === $one['phase'], 'Migration response missing' );
    check( 'InnoDB' === $GLOBALS['wpdb']->engines['wp_cpm_jobs'] && 'MyISAM' === $GLOBALS['wpdb']->engines['wp_cpm_items'], 'More than one table converted' );
    check( 0 === $GLOBALS['wpdb']->writes && 'pending' === $GLOBALS['wpdb']->status, 'Migration changed prices or ownership' );
    check( 100 === $GLOBALS['wpdb']->lock_timeout, 'Session timeout not restored' );
    $two = apply_request();
    check( 'database_upgrade' === $two['phase'] && 0 === $GLOBALS['wpdb']->writes, 'Prices changed in DDL request' );
    $three = apply_request();
    check( $three['done'] && 1 === $GLOBALS['wpdb']->writes && 'applied' === $GLOBALS['wpdb']->status, 'Apply failed after upgrade' );
    check( 2 === count( array_filter( $GLOBALS['wpdb']->queries, function( $q ) { return 0 === strpos( $q, 'ALTER TABLE' ); } ) ), 'Unnecessary repeat conversion' );
} );
test_case( 'ALTER failure leaves history and prices untouched and can be retried', function() {
    legacy_tables(); $GLOBALS['wpdb']->alter_failure = true;
    $data = ajax( function() { CPM_Category_Price_Manager::instance()->ajax_apply(); }, false );
    check( false !== strpos( $data['message'], 'Could not upgrade plugin table wp_cpm_jobs' ), 'Wrong failure message' );
    check( 'MyISAM' === $GLOBALS['wpdb']->engines['wp_cpm_jobs'] && 'pending' === $GLOBALS['wpdb']->status && 0 === $GLOBALS['wpdb']->writes, 'Failed upgrade changed data' );
    check( 100 === $GLOBALS['wpdb']->lock_timeout && ! $GLOBALS['wpdb']->errors_suppressed, 'Session state not restored' );
    $GLOBALS['wpdb']->alter_failure = false; apply_request();
    check( 'InnoDB' === $GLOBALS['wpdb']->engines['wp_cpm_jobs'], 'Retry failed' );
} );
test_case( 'silent engine substitution does not permit price writes', function() {
    legacy_tables(); $GLOBALS['wpdb']->alter_noop = true;
    $data = ajax( function() { CPM_Category_Price_Manager::instance()->ajax_apply(); }, false );
    check( false !== strpos( $data['message'], 'Could not verify' ) && 0 === $GLOBALS['wpdb']->writes, 'Unverified engine accepted' );
} );
test_case( 'unsafe core table prevents all automatic conversion', function() {
    legacy_tables(); $GLOBALS['wpdb']->engines['wp_postmeta'] = 'MyISAM';
    ajax( function() { CPM_Category_Price_Manager::instance()->ajax_apply(); }, false );
    check( ! array_filter( $GLOBALS['wpdb']->queries, function( $q ) { return 0 === strpos( $q, 'ALTER TABLE' ); } ), 'Converted before complete preflight' );
} );
test_case( 'lost database lock prevents conversion', function() {
    legacy_tables(); $GLOBALS['wpdb']->lock_owned = false;
    ajax( function() { CPM_Category_Price_Manager::instance()->ajax_apply(); }, false );
    check( ! array_filter( $GLOBALS['wpdb']->queries, function( $q ) { return 0 === strpos( $q, 'ALTER TABLE' ); } ), 'DDL executed without lock ownership' );
} );
test_case( 'failed lock-timeout setup prevents conversion', function() {
    legacy_tables(); $GLOBALS['wpdb']->timeout_failure = true;
    ajax( function() { CPM_Category_Price_Manager::instance()->ajax_apply(); }, false );
    check( ! array_filter( $GLOBALS['wpdb']->queries, function( $q ) { return 0 === strpos( $q, 'ALTER TABLE' ); } ), 'DDL executed without bounded wait' );
} );
test_case( 'migration cannot alter a non-plugin table', function() {
    invoke( 'lock_mutations' );
    ajax( function() { invoke( 'migrate_plugin_table', 'wp_posts' ); }, false );
    check( ! array_filter( $GLOBALS['wpdb']->queries, function( $q ) { return 0 === strpos( $q, 'ALTER TABLE' ); } ), 'Allowlist bypassed' );
} );
test_case( 'legacy Undo migrates first and restores prices afterward', function() {
    legacy_tables(); $GLOBALS['wpdb']->status = 'applied'; $GLOBALS['wpdb']->job_status = 'completed'; $GLOBALS['wpdb']->prices['regular'] = '110';
    for ( $i = 0; $i < 2; $i++ ) {
        $data = ajax( function() { CPM_Category_Price_Manager::instance()->ajax_undo(); }, true );
        check( 'database_upgrade' === $data['phase'] && '110' === $GLOBALS['wpdb']->prices['regular'], 'Undo altered prices during migration' );
    }
    $data = ajax( function() { CPM_Category_Price_Manager::instance()->ajax_undo(); }, true );
    check( $data['done'] && '100' === $GLOBALS['wpdb']->prices['regular'] && 'undone' === $GLOBALS['wpdb']->status, 'Undo failed after upgrade' );
} );

// Publication hardening checks: run alongside the transaction regression suite.
test_case( 'nested categories are rejected before coercion', function() {
    $_POST = array( 'nonce' => 'valid-nonce', 'categories' => array( array( 1 ) ) );
    $data = ajax( function() { CPM_Category_Price_Manager::instance()->ajax_create(); }, false );
    check( 'Select 1–100 categories.' === $data['message'], 'Nested category input was accepted' );
} );
foreach ( array( 'operation', 'method', 'field' ) as $malformed_field ) {
    test_case( 'array pricing field rejected: ' . $malformed_field, function() use ( $malformed_field ) {
        $_POST = array( 'nonce' => 'valid-nonce', 'categories' => array( '1' ), 'operation' => 'increase', 'method' => 'fixed', 'field' => 'regular', 'amount' => '10' );
        $_POST[$malformed_field] = array( 'unexpected' );
        $data = ajax( function() { CPM_Category_Price_Manager::instance()->ajax_create(); }, false );
        check( 'Invalid pricing rule.' === $data['message'], 'Malformed pricing field was accepted' );
    } );
}
test_case( 'literal preview labels preserve persisted keys', function() {
    check( 'Ready' === invoke( 'note_label', '' ), 'Empty note fallback changed' );
    check( 'Price changed since snapshot' === invoke( 'note_label', 'Price changed since snapshot' ), 'Conflict label changed' );
    check( 'Legacy custom note' === invoke( 'note_label', 'Legacy custom note' ), 'Unknown legacy note was lost' );
} );

function create_input( $overrides = array() ) {
    $_POST = array_merge( array( 'nonce' => 'valid-nonce', 'categories' => array( '1', '1', '0' ), 'operation' => 'increase', 'method' => 'fixed', 'field' => 'regular', 'amount' => '10.25', 'descendants' => '1', 'variations' => '0' ), $overrides );
}
foreach ( array( 'create', 'stage', 'preview', 'apply', 'undo', 'history' ) as $action ) {
    foreach ( array( 'missing', 'invalid', 'array' ) as $nonce_case ) {
        test_case( $action . ' rejects ' . $nonce_case . ' nonce before database access', function() use ( $action, $nonce_case ) {
            if ( 'missing' === $nonce_case ) { unset( $_POST['nonce'] ); }
            else { $_POST['nonce'] = 'array' === $nonce_case ? array( 'valid-nonce' ) : 'invalid'; }
            $data = ajax( function() use ( $action ) { CPM_Category_Price_Manager::instance()->{ 'ajax_' . $action }(); }, false );
            check( false !== strpos( $data['message'], 'session has expired' ), 'Unexpected nonce response' );
            check( ! $GLOBALS['wpdb']->queries && ! $GLOBALS['wpdb']->inserts && ! $GLOBALS['wpdb']->writes, 'Unauthorized request reached the database' );
        } );
    }
    foreach ( array( 'manage_woocommerce', 'edit_products' ) as $cap ) {
        test_case( $action . ' rejects missing ' . $cap . ' before database access', function() use ( $action, $cap ) {
            $GLOBALS['denied_caps'] = array( $cap );
            ajax( function() use ( $action ) { CPM_Category_Price_Manager::instance()->{ 'ajax_' . $action }(); }, false );
            check( ! $GLOBALS['wpdb']->queries && ! $GLOBALS['wpdb']->inserts && ! $GLOBALS['wpdb']->writes, 'Missing capability reached the database' );
        } );
    }
}
test_case( 'create normalizes categories and checkbox values', function() {
    create_input();
    ajax( function() { CPM_Category_Price_Manager::instance()->ajax_create(); }, true );
    $settings = json_decode( $GLOBALS['wpdb']->last_insert['settings'], true );
    check( array( 1 ) === $settings['categories'] && true === $settings['descendants'] && false === $settings['variations'], 'Settings normalization failed' );
    check( '10.25' === $settings['amount'], 'Decimal amount changed' );
} );
foreach ( array( '-1', '1e2', '10,25', 'NaN', '1000000001', '1.123456789', array( '10' ) ) as $bad_amount ) {
    test_case( 'reject invalid amount ' . json_encode( $bad_amount ), function() use ( $bad_amount ) {
        create_input( array( 'amount' => $bad_amount ) );
        ajax( function() { CPM_Category_Price_Manager::instance()->ajax_create(); }, false );
        check( 0 === $GLOBALS['wpdb']->inserts && ! $GLOBALS['wpdb']->queries, 'Invalid amount created a job' );
    } );
}
test_case( 'array job ID cannot select job one', function() {
    $_POST['job_id'] = array( '1' );
    ajax( function() { CPM_Category_Price_Manager::instance()->ajax_preview(); }, false );
    check( 0 === $GLOBALS['wpdb']->writes, 'Malformed job ID changed a price' );
} );
test_case( 'job lookup scopes ID to the current owner', function() {
    invoke( 'job', 1 );
    $prepared = $GLOBALS['wpdb']->prepared[0];
    check( false !== strpos( $prepared[0], 'WHERE id = %d AND user_id = %d' ) && array( 'wp_cpm_jobs', 1, 7 ) === $prepared[1], 'Job owner constraint was lost' );
} );
test_case( 'staging prepares every table, category, type and cursor', function() {
    $GLOBALS['wpdb']->job_status = 'staging';
    ajax( function() { CPM_Category_Price_Manager::instance()->ajax_stage(); }, true );
    $queries = array_values( array_filter( $GLOBALS['wpdb']->prepared, function( $q ) { return false !== strpos( $q[0], 'SELECT DISTINCT p.ID' ); } ) );
    check( 1 === count( $queries ), 'Missing staging query' );
    check( array( 'wp_posts', 'wp_posts', 'wp_term_relationships', 'wp_term_taxonomy', 1, 'product', 'product_variation', 0, 20 ) === $queries[0][1], 'Staging prepare argument order changed' );
    check( false !== strpos( $queries[0][0], 'p.post_type IN (%s,%s)' ), 'Product type was interpolated' );
} );
test_case( 'identifier placeholder quotes embedded backticks', function() {
    check( '`prefix``table`' === $GLOBALS['wpdb']->prepare( '%i', 'prefix`table' ), 'Identifier escaping failed' );
} );
