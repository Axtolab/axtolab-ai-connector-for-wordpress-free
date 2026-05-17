<?php
/**
 * MCP Gateway Changelog
 *
 * Records before/after snapshots of mutating MCP tool calls so the AI
 * (or an admin) can review what changed and roll it back. The capture
 * side records — rollback logic per target type lives in REST handlers.
 *
 * Distinct from Axtolab_AI_Connector_Audit_Log: that's a thin activity log
 * (timestamp + tool name + outcome). This is the durable diff store
 * with full snapshots needed to reverse changes.
 *
 * @package WP_MCP_Gateway
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Axtolab_AI_Connector_Changelog {

	const TABLE = 'axtolab_ai_connector_changelog';

	/**
	 * Default retention in days. Override via
	 * `axtolab_ai_connector_changelog_retention_days` filter.
	 */
	const RETENTION_DAYS = 90;

	/**
	 * Action types captured. Free-form (varchar) so add-ons can extend,
	 * but these are the canonical values.
	 */
	const ACTION_CREATE  = 'create';
	const ACTION_UPDATE  = 'update';
	const ACTION_DELETE  = 'delete';
	const ACTION_TRASH   = 'trash';
	const ACTION_RESTORE = 'restore';
	const ACTION_PUBLISH = 'publish';
	const ACTION_MOVE    = 'move';
	const ACTION_ASSIGN  = 'assign';

	/**
	 * Source of the change. `mcp_connection` is the default for any
	 * tool dispatched through the MCP transport / REST namespace.
	 */
	const SOURCE_MCP        = 'mcp_connection';
	const SOURCE_WP_ADMIN   = 'wp_admin';
	const SOURCE_AUTOMATION = 'automation';

	/**
	 * Create the changelog table on plugin activation. Uses dbDelta
	 * so re-runs are safe and column additions can land on upgrades.
	 *
	 * @return void
	 */
	public static function create_table() {
		global $wpdb;

		$table   = $wpdb->prefix . self::TABLE;
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			created_by BIGINT UNSIGNED DEFAULT NULL,
			connection_id VARCHAR(64) NOT NULL DEFAULT '',
			session_id VARCHAR(128) NOT NULL DEFAULT '',
			tool_name VARCHAR(64) NOT NULL DEFAULT '',
			source VARCHAR(32) NOT NULL DEFAULT 'mcp_connection',
			target_type VARCHAR(32) NOT NULL DEFAULT '',
			target_id VARCHAR(64) NOT NULL DEFAULT '',
			action VARCHAR(32) NOT NULL DEFAULT '',
			before_snapshot LONGTEXT NULL,
			after_snapshot LONGTEXT NULL,
			rolled_back_at DATETIME DEFAULT NULL,
			rollback_change_id BIGINT UNSIGNED DEFAULT NULL,
			redo_of_change_id BIGINT UNSIGNED DEFAULT NULL,
			note VARCHAR(255) NOT NULL DEFAULT '',
			PRIMARY KEY (id),
			KEY idx_session (session_id),
			KEY idx_target (target_type, target_id),
			KEY idx_created (created_at),
			KEY idx_status (rolled_back_at),
			KEY idx_tool (tool_name)
		) $charset;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Drop the changelog table. Only used on full uninstall.
	 *
	 * @return void
	 */
	public static function drop_table() {
		global $wpdb;
		$table = esc_sql( $wpdb->prefix . self::TABLE );
		$wpdb->query( "DROP TABLE IF EXISTS $table" );
	}

	/**
	 * Whether changelog capture is enabled. Default: on. Disable via
	 * the `changelog_enabled` plugin setting (admin-toggleable later).
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		$settings = get_option( 'axtolab_ai_connector_settings', array() );
		if ( isset( $settings['changelog_enabled'] ) ) {
			return (bool) $settings['changelog_enabled'];
		}
		return true;
	}

	/**
	 * Record a new changelog entry.
	 *
	 * Required keys in $args:
	 *   - target_type (string): post|page|option|menu|term|theme_mod|...
	 *   - target_id (string|int): identifier within the target type.
	 *   - action (string): one of self::ACTION_*.
	 *   - tool_name (string): the MCP tool that drove the change.
	 *
	 * Optional keys:
	 *   - before, after: arbitrary array/scalar; JSON-encoded on store.
	 *   - connection_id, session_id, source, note.
	 *   - redo_of_change_id: when this row reapplies an earlier rollback.
	 *
	 * @param array $args
	 * @return int|false  Inserted row id, or false if disabled / failed.
	 */
	public static function record( array $args ) {
		if ( ! self::is_enabled() ) {
			return false;
		}

		$required = array( 'target_type', 'target_id', 'action', 'tool_name' );
		foreach ( $required as $key ) {
			if ( ! isset( $args[ $key ] ) || '' === $args[ $key ] ) {
				return false;
			}
		}

		global $wpdb;

		$table = $wpdb->prefix . self::TABLE;
		if ( ! self::table_exists( $table ) ) {
			return false;
		}

		$row = array(
			'created_by'         => get_current_user_id() ? (int) get_current_user_id() : null,
			'connection_id'      => isset( $args['connection_id'] ) ? substr( (string) $args['connection_id'], 0, 64 ) : '',
			'session_id'         => isset( $args['session_id'] ) ? substr( (string) $args['session_id'], 0, 128 ) : '',
			'tool_name'          => substr( (string) $args['tool_name'], 0, 64 ),
			'source'             => substr( (string) ( isset( $args['source'] ) ? $args['source'] : self::SOURCE_MCP ), 0, 32 ),
			'target_type'        => substr( (string) $args['target_type'], 0, 32 ),
			'target_id'          => substr( (string) $args['target_id'], 0, 64 ),
			'action'             => substr( (string) $args['action'], 0, 32 ),
			'before_snapshot'    => isset( $args['before'] ) ? wp_json_encode( $args['before'] ) : null,
			'after_snapshot'     => isset( $args['after'] ) ? wp_json_encode( $args['after'] ) : null,
			'redo_of_change_id'  => isset( $args['redo_of_change_id'] ) ? (int) $args['redo_of_change_id'] : null,
			'note'               => isset( $args['note'] ) ? substr( (string) $args['note'], 0, 255 ) : '',
		);

		$inserted = $wpdb->insert( $table, $row );
		if ( false === $inserted ) {
			return false;
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * Mark a change as rolled back. Optionally link to the new
	 * changelog row that recorded the rollback itself.
	 *
	 * @param int $change_id          Original change id being undone.
	 * @param int $rollback_change_id Changelog id of the undo action.
	 * @return bool
	 */
	public static function mark_rolled_back( $change_id, $rollback_change_id = 0 ) {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;

		$updated = $wpdb->update(
			$table,
			array(
				'rolled_back_at'     => current_time( 'mysql', true ),
				'rollback_change_id' => $rollback_change_id ? (int) $rollback_change_id : null,
			),
			array( 'id' => (int) $change_id )
		);

		return false !== $updated;
	}

	/**
	 * Clear the rolled_back_at marker — used when a redo brings the
	 * original change back into effect.
	 *
	 * @param int $change_id
	 * @return bool
	 */
	public static function clear_rolled_back( $change_id ) {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;
		$updated = $wpdb->update(
			$table,
			array( 'rolled_back_at' => null, 'rollback_change_id' => null ),
			array( 'id' => (int) $change_id )
		);
		return false !== $updated;
	}

	/**
	 * Fetch a single change including snapshots, JSON-decoded.
	 *
	 * @param int $id
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$table = esc_sql( $wpdb->prefix . self::TABLE );

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM $table WHERE id = %d", (int) $id ),
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		return self::hydrate_row( $row, true );
	}

	/**
	 * Query the changelog with filters.
	 *
	 * Filters (all optional):
	 *   per_page, offset, session_id, target_type, target_id, tool_name,
	 *   source, action, status ('rolled_back' | 'pending' | 'all'),
	 *   since (ISO 8601 / mysql datetime).
	 *
	 * Snapshots are not returned in list mode (to keep payloads sane);
	 * call self::get() for the full row.
	 *
	 * @param array $args
	 * @return array{items: array, total: int}
	 */
	public static function query( array $args = array() ) {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;

		$per_page = min( isset( $args['per_page'] ) ? (int) $args['per_page'] : 50, 200 );
		$offset   = max( isset( $args['offset'] ) ? (int) $args['offset'] : 0, 0 );

		$where = array( '1=1' );
		$vals  = array();

		foreach ( array( 'session_id', 'target_type', 'target_id', 'tool_name', 'source', 'action' ) as $key ) {
			if ( ! empty( $args[ $key ] ) ) {
				$where[] = "$key = %s";
				$vals[]  = (string) $args[ $key ];
			}
		}

		if ( ! empty( $args['status'] ) ) {
			if ( 'rolled_back' === $args['status'] ) {
				$where[] = 'rolled_back_at IS NOT NULL';
			} elseif ( 'pending' === $args['status'] ) {
				$where[] = 'rolled_back_at IS NULL';
			}
		}

		if ( ! empty( $args['since'] ) ) {
			$where[] = 'created_at >= %s';
			$vals[]  = (string) $args['since'];
		}

		$where_sql = implode( ' AND ', $where );

			$count_sql = "SELECT COUNT(*) FROM $table WHERE $where_sql";
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query fragments are built from fixed clauses above; values are prepared when present.
			$total     = $vals ? (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $vals ) ) : (int) $wpdb->get_var( $count_sql );

		$select_cols = 'id, created_at, created_by, connection_id, session_id, tool_name, source, target_type, target_id, action, rolled_back_at, rollback_change_id, redo_of_change_id, note';
		$list_sql    = "SELECT $select_cols FROM $table WHERE $where_sql ORDER BY id DESC LIMIT %d OFFSET %d";
		$list_vals   = array_merge( $vals, array( $per_page, $offset ) );

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Query fragments are built from fixed clauses above; values are prepared.
			$rows = $wpdb->get_results( $wpdb->prepare( $list_sql, $list_vals ), ARRAY_A );
		if ( ! $rows ) {
			$rows = array();
		}

		$items = array();
		foreach ( $rows as $row ) {
			$items[] = self::hydrate_row( $row, false );
		}

		return array(
			'items' => $items,
			'total' => $total,
		);
	}

	/**
	 * Prune entries older than the retention window.
	 *
	 * @return int Rows deleted.
	 */
	public static function prune() {
		global $wpdb;

		$days = (int) apply_filters( 'axtolab_ai_connector_changelog_retention_days', self::RETENTION_DAYS );
		if ( $days <= 0 ) {
			return 0;
		}

		$table   = esc_sql( $wpdb->prefix . self::TABLE );
		$cutoff  = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE created_at < %s", $cutoff ) );

		return $deleted ? (int) $deleted : 0;
	}

	/**
	 * Whether the table exists. Avoids errors if the change runs
	 * before activation has had a chance to create it.
	 *
	 * @param string $table Already-prefixed table name.
	 * @return bool
	 */
	private static function table_exists( $table ) {
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	}

	/**
	 * Decode a raw row into an array suitable for API responses.
	 *
	 * @param array $row              Raw DB row.
	 * @param bool  $include_snapshots Include before/after.
	 * @return array
	 */
	private static function hydrate_row( array $row, $include_snapshots ) {
		$out = array(
			'id'                  => (int) $row['id'],
			'created_at'          => $row['created_at'],
			'created_by'          => isset( $row['created_by'] ) ? (int) $row['created_by'] : null,
			'connection_id'       => isset( $row['connection_id'] ) ? $row['connection_id'] : '',
			'session_id'          => isset( $row['session_id'] ) ? $row['session_id'] : '',
			'tool_name'           => isset( $row['tool_name'] ) ? $row['tool_name'] : '',
			'source'              => isset( $row['source'] ) ? $row['source'] : '',
			'target_type'         => isset( $row['target_type'] ) ? $row['target_type'] : '',
			'target_id'           => isset( $row['target_id'] ) ? $row['target_id'] : '',
			'action'              => isset( $row['action'] ) ? $row['action'] : '',
			'rolled_back_at'      => isset( $row['rolled_back_at'] ) ? $row['rolled_back_at'] : null,
			'rollback_change_id'  => isset( $row['rollback_change_id'] ) && $row['rollback_change_id'] ? (int) $row['rollback_change_id'] : null,
			'redo_of_change_id'   => isset( $row['redo_of_change_id'] ) && $row['redo_of_change_id'] ? (int) $row['redo_of_change_id'] : null,
			'note'                => isset( $row['note'] ) ? $row['note'] : '',
		);

		if ( $include_snapshots ) {
			$out['before'] = isset( $row['before_snapshot'] ) && null !== $row['before_snapshot']
				? json_decode( $row['before_snapshot'], true )
				: null;
			$out['after'] = isset( $row['after_snapshot'] ) && null !== $row['after_snapshot']
				? json_decode( $row['after_snapshot'], true )
				: null;
		}

		return $out;
	}
}

if ( ! class_exists( 'MCP_Gateway_Changelog', false ) ) {
	class_alias( 'Axtolab_AI_Connector_Changelog', 'MCP_Gateway_Changelog' );
}
