<?php
// Run only against a DISPOSABLE database named cpm_test:
// CPM_TEST_DB_HOST=127.0.0.1 php tests/migration-integration.php
// This creates/drops cpm_it_* fixture tables and a temporary restricted DB user.
if ( ! getenv( 'CPM_TEST_DB_HOST' ) ) { fwrite( STDERR, "Set CPM_TEST_DB_HOST to an isolated test database server.\n" ); exit( 1 ); }
define( 'DB_NAME', 'cpm_test' );
define( 'ABSPATH', sys_get_temp_dir() . '/cpm-migration-fixture/' );
@mkdir( ABSPATH . 'wp-admin/includes', 0700, true );
file_put_contents( ABSPATH . 'wp-admin/includes/upgrade.php', '<?php' );
class AjaxExit extends Exception {}
function add_action() {}
function register_activation_hook() {}
function __( $text, $domain = '' ) { return $text; }
function wp_send_json_error( $data, $code = 400 ) { throw new AjaxExit( $data['message'] ); }
function clean_post_cache() {}
function wp_cache_delete() {}
function wc_delete_product_transients() {}
function dbDelta( $sql ) { if ( false === $GLOBALS['wpdb']->query( $sql ) ) { throw new RuntimeException( $GLOBALS['wpdb']->last_error ); } }
class RealDB {
    public $prefix = 'cpm_it_';
    public $posts = 'cpm_it_posts';
    public $postmeta = 'cpm_it_postmeta';
    public $last_error = '';
    public $queries = array();
    public $connection;
    private $suppressed = false;
    public function __construct( $user = 'root', $password = '' ) {
        mysqli_report( MYSQLI_REPORT_OFF );
        $this->connection = new mysqli( getenv( 'CPM_TEST_DB_HOST' ), $user, $password, DB_NAME );
        if ( $this->connection->connect_errno ) { throw new RuntimeException( 'Test DB connection failed' ); }
        $this->connection->set_charset( 'utf8mb4' );
    }
    public function get_charset_collate() { return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'; }
    public function suppress_errors( $new ) { $old = $this->suppressed; $this->suppressed = $new; return $old; }
    public function prepare( $sql, ...$args ) {
        if ( count( $args ) === 1 && is_array( $args[0] ) ) { $args = $args[0]; }
        return preg_replace_callback( '/%[dsi]/', function( $match ) use ( &$args ) {
            $arg = array_shift( $args );
            if ( '%i' === $match[0] ) { return '`' . str_replace( '`', '``', $arg ) . '`'; }
            return '%d' === $match[0] ? (string) (int) $arg : "'" . $this->connection->real_escape_string( $arg ) . "'";
        }, $sql );
    }
    private function execute( $sql ) {
        $this->queries[] = $sql;
        $result = $this->connection->query( $sql );
        $this->last_error = $this->connection->error;
        return $result;
    }
    public function query( $sql ) { $result = $this->execute( $sql ); return false === $result ? false : $this->connection->affected_rows; }
    public function get_results( $sql ) {
        $result = $this->execute( $sql );
        if ( ! $result instanceof mysqli_result ) { return null; }
        $rows = array(); while ( $row = $result->fetch_object() ) { $rows[] = $row; } return $rows;
    }
    public function get_row( $sql ) { $rows = $this->get_results( $sql ); return $rows[0] ?? null; }
    public function get_var( $sql ) { $row = $this->get_row( $sql ); return $row ? array_values( (array) $row )[0] : null; }
}
$wpdb = new RealDB();
require __DIR__ . '/../category-price-manager.php';
$plugin = CPM_Category_Price_Manager::instance();
function invoke_migration( $name, ...$args ) {
    $method = new ReflectionMethod( CPM_Category_Price_Manager::class, $name ); $method->setAccessible( true );
    return $method->invokeArgs( $GLOBALS['plugin'], $args );
}
function verify( $condition, $message ) { if ( ! $condition ) { throw new RuntimeException( $message ); } }
function execute_sql( $sql ) { verify( false !== $GLOBALS['wpdb']->query( $sql ), $GLOBALS['wpdb']->last_error ?: 'SQL failed' ); }
function expect_error( $callback, $fragment ) {
    try { $callback(); } catch ( AjaxExit $e ) { verify( false !== strpos( $e->getMessage(), $fragment ), $e->getMessage() ); return; }
    throw new RuntimeException( 'Expected migration refusal' );
}
$tables = array( 'cpm_it_cpm_jobs', 'cpm_it_cpm_items', 'cpm_it_posts', 'cpm_it_postmeta', 'cpm_it_wc_product_meta_lookup' );
$root = $wpdb;
try {
    foreach ( $tables as $table ) { execute_sql( "DROP TABLE IF EXISTS `$table`" ); }
    CPM_Category_Price_Manager::install();
    foreach ( array_slice( $tables, 2 ) as $table ) { execute_sql( "CREATE TABLE `$table` (id bigint unsigned PRIMARY KEY) ENGINE=InnoDB" ); }
    verify( '' === invoke_migration( 'require_transactions' ), 'New installation did not use InnoDB' );
    echo "PASS: actual install creates InnoDB plugin tables\n";
    foreach ( array_slice( $tables, 0, 2 ) as $table ) { execute_sql( "ALTER TABLE `$table` ENGINE=MyISAM" ); }
    execute_sql( "INSERT INTO cpm_it_cpm_jobs (id,user_id,created_at,updated_at,status,settings,total) VALUES (7,2,NOW(),NOW(),'completed','{\"label\":\"تاریخچه\"}',2),(9,3,NOW(),NOW(),'ready','{\"label\":\"snapshot\"}',1)" );
    execute_sql( "INSERT INTO cpm_it_cpm_items (id,job_id,product_id,before_json,after_json,status,note) VALUES (13,7,101,'{\"regular\":\"100\",\"sale\":\"\"}','{\"regular\":\"110\",\"sale\":\"\"}','applied',''),(15,7,102,'{\"regular\":\"200\"}','{\"regular\":\"220\"}','conflict','قیمت تغییر کرده')" );
    execute_sql( 'ALTER TABLE cpm_it_cpm_jobs AUTO_INCREMENT=900' );
    execute_sql( 'ALTER TABLE cpm_it_cpm_items AUTO_INCREMENT=1000' );
    $original = array();
    foreach ( array_slice( $tables, 0, 2 ) as $table ) { $original[$table] = serialize( $wpdb->get_results( "SELECT * FROM `$table` ORDER BY id" ) ); }
    invoke_migration( 'lock_mutations' );
    $other = new RealDB();
    $lock = 'cpm:' . md5( DB_NAME . ':' . $wpdb->prefix );
    verify( '0' === $other->get_var( $other->prepare( 'SELECT GET_LOCK(%s,0)', $lock ) ), 'Concurrent lock acquisition was allowed' );
    $timeout = $wpdb->get_var( 'SELECT @@SESSION.lock_wait_timeout' );
    verify( 'cpm_it_cpm_jobs' === invoke_migration( 'require_transactions', true ), 'First migration did not convert only jobs' );
    verify( 'MYISAM' === invoke_migration( 'table_engine', 'cpm_it_cpm_items' )['engine'], 'Second table converted in same request' );
    verify( $timeout === $wpdb->get_var( 'SELECT @@SESSION.lock_wait_timeout' ), 'Session timeout leaked' );
    $plugin->cleanup();
    // Simulate a lost response: the next request must discover the committed engine.
    invoke_migration( 'lock_mutations' );
    verify( 'cpm_it_cpm_items' === invoke_migration( 'require_transactions', true ), 'Retry did not resume at items' );
    $plugin->cleanup();
    invoke_migration( 'lock_mutations' );
    verify( '' === invoke_migration( 'require_transactions', true ), 'Repeat migration is not idempotent' );
    $plugin->cleanup();
    foreach ( $original as $table => $rows ) { verify( $rows === serialize( $wpdb->get_results( "SELECT * FROM `$table` ORDER BY id" ) ), 'Rows changed: ' . $table ); }
    verify( '900' === $wpdb->get_var( "SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cpm_it_cpm_jobs'" ), 'Jobs sequence changed' );
    verify( '1000' === $wpdb->get_var( "SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='cpm_it_cpm_items'" ), 'Items sequence changed' );
    verify( null !== $wpdb->get_row( "SHOW INDEX FROM cpm_it_cpm_items WHERE Key_name='job_product' AND Non_unique=0" ), 'Unique snapshot key missing' );
    echo "PASS: real conversion preserves every history/snapshot row, IDs, sequences, and unique key\n";
    echo "PASS: two requests, lock exclusion, timeout restoration, and lost-response retry\n";
    execute_sql( 'ALTER TABLE cpm_it_cpm_jobs ENGINE=MyISAM' );
    execute_sql( "CREATE USER IF NOT EXISTS 'cpm_test_limited'@'%' IDENTIFIED BY 'test-only'" );
    execute_sql( "GRANT SELECT,INSERT,UPDATE,DELETE ON cpm_test.* TO 'cpm_test_limited'@'%'" );
    $wpdb = new RealDB( 'cpm_test_limited', 'test-only' );
    invoke_migration( 'lock_mutations' );
    expect_error( function() { invoke_migration( 'require_transactions', true ); }, 'Could not upgrade plugin table' );
    verify( 'MYISAM' === invoke_migration( 'table_engine', 'cpm_it_cpm_jobs' )['engine'], 'Failed ALTER changed engine' );
    $wpdb = $root;
    verify( $original['cpm_it_cpm_jobs'] === serialize( $wpdb->get_results( 'SELECT * FROM cpm_it_cpm_jobs ORDER BY id' ) ), 'Permission failure changed history' );
    echo "PASS: real ALTER permission denial preserves history and refuses migration\n";
    $other->query( 'LOCK TABLES cpm_it_cpm_jobs READ' );
    invoke_migration( 'lock_mutations' );
    $start = microtime( true );
    expect_error( function() { invoke_migration( 'require_transactions', true ); }, 'Could not upgrade plugin table' );
    $elapsed = microtime( true ) - $start;
    $other->query( 'UNLOCK TABLES' );
    verify( $elapsed < 10 && $elapsed >= 4, 'Metadata-lock wait was not bounded' );
    invoke_migration( 'lock_mutations' );
    verify( 'cpm_it_cpm_jobs' === invoke_migration( 'require_transactions', true ), 'Retry after lock timeout failed' );
    $plugin->cleanup();
    echo "PASS: real blocking table lock times out safely and retry succeeds\n";
} finally {
    $other = null;
    $wpdb = $root;
    $plugin->cleanup();
    foreach ( $tables as $table ) { $wpdb->query( "DROP TABLE IF EXISTS `$table`" ); }
    $wpdb->query( "DROP USER IF EXISTS 'cpm_test_limited'@'%'" );
}
