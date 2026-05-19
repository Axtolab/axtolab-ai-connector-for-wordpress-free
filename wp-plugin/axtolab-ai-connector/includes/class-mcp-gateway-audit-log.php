<?php
/**
 * MCP Gateway Audit Log
 *
 * Lightweight activity log for AI agent actions. Stores a rolling log
 * of tool calls with timestamp, connection, tool name, and outcome.
 *
 * @package WP_MCP_Gateway
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Axtolab_AI_Connector_Audit_Log
 */
class Axtolab_AI_Connector_Audit_Log {

	/**
	 * Database table name (without prefix).
	 */
	const TABLE = 'axtolab_ai_connector_audit_log';

	/**
	 * Maximum log entries before pruning.
	 */
	const MAX_ENTRIES = 5000;

	/**
	 * Default retention in days.
	 */
	const RETENTION_DAYS = 30;

	/**
	 * Create the audit log table on plugin activation.
	 *
	 * @return void
	 */
	public static function create_table() {
		global $wpdb;

		$table   = $wpdb->prefix . self::TABLE;
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS $table (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			connection_id VARCHAR(64) NOT NULL DEFAULT '',
			connection_label VARCHAR(128) NOT NULL DEFAULT '',
			tool_name VARCHAR(128) NOT NULL DEFAULT '',
			post_id BIGINT UNSIGNED DEFAULT NULL,
			outcome VARCHAR(16) NOT NULL DEFAULT 'success',
			detail TEXT,
			ip_address VARCHAR(45) NOT NULL DEFAULT '',
			PRIMARY KEY (id),
			KEY idx_created_at (created_at),
			KEY idx_tool_name (tool_name),
			KEY idx_connection_id (connection_id)
		) $charset;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Drop the audit log table on plugin uninstall.
	 *
	 * @return void
	 */
	public static function drop_table() {
		global $wpdb;
		$table = esc_sql( $wpdb->prefix . self::TABLE );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- Dropping the plugin-owned audit-log table on uninstall; no WP API exists for schema changes.
		$wpdb->query( "DROP TABLE IF EXISTS $table" );
	}

	/**
	 * Log an action.
	 *
	 * @param string   $tool_name        Tool or action name.
	 * @param string   $outcome          'success' or 'error'.
	 * @param string   $detail           Optional detail text.
	 * @param int|null $post_id          Related post ID.
	 * @param string   $connection_id    Connection identifier.
	 * @param string   $connection_label Connection display label.
	 * @return void
	 */
	public static function log( $tool_name, $outcome = 'success', $detail = '', $post_id = null, $connection_id = '', $connection_label = '' ) {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE;

		// Check if table exists (avoid errors before activation).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Existence check for the plugin-owned audit-log table; SHOW TABLES is the only way to verify table presence and is not cacheable.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return;
		}

		$ip = '';
		if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Insert into the plugin-owned audit-log table; no WP API exists for custom tables and row-level cache would add invalidation complexity without correctness benefit.
		$wpdb->insert(
			$table,
			array(
				'connection_id'    => substr( (string) $connection_id, 0, 64 ),
				'connection_label' => substr( (string) $connection_label, 0, 128 ),
				'tool_name'        => substr( (string) $tool_name, 0, 128 ),
				'post_id'          => $post_id ? (int) $post_id : null,
				'outcome'          => in_array( $outcome, array( 'success', 'error' ), true ) ? $outcome : 'success',
				'detail'           => $detail ? substr( (string) $detail, 0, 65535 ) : null,
				'ip_address'       => substr( $ip, 0, 45 ),
			),
			array( '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
		);
	}

	/**
	 * Query log entries.
	 *
	 * @param array $args Query arguments (per_page, offset, tool_name, connection_id, outcome).
	 * @return array
	 */
	public static function query( $args = array() ) {
		global $wpdb;

		$table    = $wpdb->prefix . self::TABLE;
		$per_page = min( isset( $args['per_page'] ) ? (int) $args['per_page'] : 50, 100 );
		$offset   = max( isset( $args['offset'] ) ? (int) $args['offset'] : 0, 0 );

		$where = array( '1=1' );
		$vals  = array();

		if ( ! empty( $args['tool_name'] ) ) {
			$where[] = 'tool_name = %s';
			$vals[]  = $args['tool_name'];
		}

		if ( ! empty( $args['connection_id'] ) ) {
			$where[] = 'connection_id = %s';
			$vals[]  = $args['connection_id'];
		}

		if ( ! empty( $args['outcome'] ) ) {
			$where[] = 'outcome = %s';
			$vals[]  = $args['outcome'];
		}

		$where_sql = implode( ' AND ', $where );
		$sql       = "SELECT * FROM $table WHERE $where_sql ORDER BY created_at DESC LIMIT %d OFFSET %d";
		$vals[]    = $per_page;
		$vals[]    = $offset;

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read from the plugin-owned audit-log table; query fragments are built from fixed clauses above and values are prepared.
			$results = $wpdb->get_results( $wpdb->prepare( $sql, $vals ), ARRAY_A );

		return $results ? $results : array();
	}

	/**
	 * Get the total count of log entries.
	 *
	 * @return int
	 */
	public static function count() {
		global $wpdb;
		$table = esc_sql( $wpdb->prefix . self::TABLE );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Count from the plugin-owned audit-log table; row-level cache would not improve correctness here.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" );
	}

	/**
	 * Prune old entries beyond retention period.
	 *
	 * @param int $days Retention period in days.
	 * @return int Number of rows deleted.
	 */
	public static function prune( $days = 0 ) {
		global $wpdb;

		if ( $days <= 0 ) {
			$days = self::RETENTION_DAYS;
		}

		$table  = esc_sql( $wpdb->prefix . self::TABLE );
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Retention prune on the plugin-owned audit-log table; runs from a daily cron, no cache layer applies.
		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE created_at < %s", $cutoff ) );

		return $deleted ? (int) $deleted : 0;
	}
}

if ( ! class_exists( 'MCP_Gateway_Audit_Log', false ) ) {
	class_alias( 'Axtolab_AI_Connector_Audit_Log', 'MCP_Gateway_Audit_Log' );
}
