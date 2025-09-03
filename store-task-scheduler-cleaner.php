<?php
/**
 * Plugin Name: Store Task Scheduler Cleaner
 * Plugin URI:  https://github.com/mxtag/store-task-scheduler-cleaner
 * Description: Clean WooCommerce Action Scheduler tasks (complete/failed) to optimize database performance and speed up your store.
 * Version:     1.0.0
 * Author:      mxtag
 * Author URI:  https://www.mxtag.com
 * License:     GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: store-task-scheduler-cleaner
 * Domain Path: /languages
 * Requires Plugins: woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class STSCleaner_Plugin {
	/** @var string WooCommerce Action Scheduler actions table. */
	private $as_actions;

	/** @var string WooCommerce Action Scheduler logs table. */
	private $as_logs;

	/** @var string Plugin-owned log table. */
	private $log_table;

	public function __construct() {
		global $wpdb;

		// Action Scheduler core tables (from WooCommerce).
		$this->as_actions = $wpdb->prefix . 'actionscheduler_actions';
		$this->as_logs    = $wpdb->prefix . 'actionscheduler_logs';

		// Plugin-owned table: use a distinctive prefix to avoid conflicts.
		$this->log_table  = $wpdb->prefix . 'store_tsc_logs';

		add_action( 'admin_init', array( $this, 'handle_actions' ) );
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'add_settings_link' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// Optional soft check for WooCommerce on older cores (<6.5) where "Requires Plugins" header is ignored.
		add_action( 'admin_notices', array( $this, 'maybe_notice_missing_wc' ) );
	}

	/**
	 * Activation: create plugin-owned log table.
	 */
	public static function activate() {
		global $wpdb;
		$table_name = $wpdb->prefix . 'store_tsc_logs';

		$charset_collate = $wpdb->get_charset_collate();
		$sql             = "CREATE TABLE {$table_name} (
			id mediumint(9) NOT NULL AUTO_INCREMENT,
			operation varchar(100) NOT NULL,
			details text NOT NULL,
			timestamp datetime DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( 'stscleaner_version', '1.0.0' );
	}

	/**
	 * Show admin notice if WooCommerce is inactive (best-effort for older cores).
	 */
	public function maybe_notice_missing_wc() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		if ( class_exists( 'WooCommerce' ) ) {
			return;
		}
		// Translators: admin notice when WooCommerce is missing.
		$message = esc_html__( 'Store Task Scheduler Cleaner requires WooCommerce to be active.', 'store-task-scheduler-cleaner' );
		echo '<div class="notice notice-warning"><p>' . $message . '</p></div>';
	}

	/**
	 * Enqueue admin assets only on our tools page. No inline <script>.
	 */
	public function enqueue_assets( $hook ) {
		if ( 'tools_page_store-task-scheduler-cleaner' !== $hook ) {
			return;
		}

		$ver = '1.0.0';

		wp_register_script(
			'stscleaner-admin-js',
			plugins_url( 'assets/admin.js', __FILE__ ),
			array( 'jquery' ),
			$ver,
			true
		);

		wp_localize_script(
			'stscleaner-admin-js',
			'STSCleanerI18n',
			array(
				'confirmCleanAll'        => __( 'Confirm to clean Completed + Failed tasks and their Action Scheduler logs?', 'store-task-scheduler-cleaner' ),
				'confirmClearLogs'       => __( 'This will DROP and recreate the plugin log table. This action cannot be undone. Continue?', 'store-task-scheduler-cleaner' ),
				'pleaseSelectAtLeastOne' => __( 'Please select at least one hook to clean.', 'store-task-scheduler-cleaner' ),
			)
		);

		wp_enqueue_script( 'stscleaner-admin-js' );
	}

	/**
	 * Add "Settings" quick link.
	 */
	public function add_settings_link( $links ) {
		$url           = admin_url( 'tools.php?page=store-task-scheduler-cleaner' );
		$settings_link = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'store-task-scheduler-cleaner' ) . '</a>';
		array_unshift( $links, $settings_link );
		return $links;
	}

	/**
	 * Add Tools > Store Task Scheduler Cleaner page.
	 */
	public function add_admin_menu() {
		add_management_page(
			__( 'Store Task Scheduler Cleaner', 'store-task-scheduler-cleaner' ),
			__( 'Store Task Scheduler Cleaner', 'store-task-scheduler-cleaner' ),
			'manage_options',
			'store-task-scheduler-cleaner',
			array( $this, 'admin_page' )
		);
	}

	/**
	 * Exact table existence check (no wildcards).
	 */
	private function table_exists( $table_name ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );
		return $found === $table_name;
	}

	/**
	 * Get pending tasks count.
	 */
	private function get_pending_count() {
		global $wpdb;
		$table = esc_sql( $this->as_actions );
		// Admin-only, no user input; identifier escaped.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}` WHERE status = 'pending'" );
	}

	/**
	 * Get completed tasks grouped by hook, plus next pending run time.
	 */
	private function get_completed_tasks() {
		global $wpdb;
		$table = esc_sql( $this->as_actions );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$sql = "
			SELECT 
				hook,
				COUNT(*) AS count,
				(SELECT MIN(scheduled_date_gmt) FROM `{$table}` WHERE hook = a.hook AND status = 'pending') AS next_time
			FROM `{$table}` a
			WHERE status = 'complete'
			GROUP BY hook
			ORDER BY count DESC
		";
		return $wpdb->get_results( $sql, ARRAY_A );
	}

	/**
	 * Get failed tasks grouped by hook.
	 */
	private function get_failed_tasks() {
		global $wpdb;
		$table = esc_sql( $this->as_actions );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$sql = "SELECT hook, COUNT(*) AS count FROM `{$table}` WHERE status = 'failed' GROUP BY hook ORDER BY count DESC";
		return $wpdb->get_results( $sql, ARRAY_A );
	}

	/**
	 * Process POST actions.
	 */
	public function handle_actions() {
		if ( ! isset( $_POST['do'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'store-task-scheduler-cleaner' ) );
		}
		check_admin_referer( 'stscleaner_action' );

		$action  = sanitize_text_field( wp_unslash( $_POST['do'] ) );
		$message = '';

		switch ( $action ) {
			case 'clean_all':
				$this->clean_completed_failed();
				$message = __( 'All completed and failed tasks have been cleaned.', 'store-task-scheduler-cleaner' );
				break;

			case 'clean_selected':
				if ( isset( $_POST['selected_hooks'] ) && is_array( $_POST['selected_hooks'] ) ) {
					$selected_hooks = array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['selected_hooks'] ) );
					$this->clean_selected_hooks( $selected_hooks );
					$message = __( 'Selected tasks have been cleaned.', 'store-task-scheduler-cleaner' );
				}
				break;

			case 'clean_failed':
				$this->clean_failed_tasks();
				$message = __( 'All failed tasks have been cleaned.', 'store-task-scheduler-cleaner' );
				break;

			case 'clear_logs':
				$this->clear_logs();
				$message = __( 'All logs have been cleared.', 'store-task-scheduler-cleaner' );
				break;
		}

		if ( $message ) {
			$this->redirect_with_message( $message );
		}
	}

	/**
	 * Clean complete/failed tasks (and their AS logs).
	 */
	private function clean_completed_failed() {
		global $wpdb;
		$actions = esc_sql( $this->as_actions );
		$logs    = esc_sql( $this->as_logs );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$completed_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$actions}` WHERE status IN ('complete','failed')" );

		// Delete logs first, then actions.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM `{$logs}` WHERE action_id IN (SELECT action_id FROM `{$actions}` WHERE status IN ('complete','failed'))" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM `{$actions}` WHERE status IN ('complete','failed')" );

		$this->log_operation(
			__( 'Clean All', 'store-task-scheduler-cleaner' ),
			sprintf(
				/* translators: %d: number of tasks cleaned */
				__( 'Cleaned %d completed and failed tasks', 'store-task-scheduler-cleaner' ),
				$completed_count
			)
		);
	}

	/**
	 * Clean selected hooks (complete only).
	 *
	 * @param string[] $hooks Hook names.
	 */
	private function clean_selected_hooks( $hooks ) {
		global $wpdb;

		$actions = esc_sql( $this->as_actions );
		$logs    = esc_sql( $this->as_logs );

		$hooks = array_map( 'sanitize_text_field', (array) $hooks );
		$hooks = array_filter( $hooks );

		if ( empty( $hooks ) ) {
			return;
		}

		$placeholders = implode( ',', array_fill( 0, count( $hooks ), '%s' ) );

		// Count.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM `{$actions}` WHERE hook IN ($placeholders) AND status = 'complete'",
				$hooks
			)
		);

		// Delete logs referencing selected hooks.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM `{$logs}` WHERE action_id IN (
					SELECT action_id FROM `{$actions}` WHERE hook IN ($placeholders) AND status = 'complete'
				)",
				$hooks
			)
		);

		// Delete actions for selected hooks.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM `{$actions}` WHERE hook IN ($placeholders) AND status = 'complete'",
				$hooks
			)
		);

		$this->log_operation(
			__( 'Clean Selected', 'store-task-scheduler-cleaner' ),
			sprintf(
				/* translators: 1: number of tasks cleaned, 2: comma-separated list of hooks */
				__( 'Cleaned %1$d tasks from selected hooks: %2$s', 'store-task-scheduler-cleaner' ),
				$count,
				implode( ', ', $hooks )
			)
		);
	}

	/**
	 * Clean failed tasks.
	 */
	private function clean_failed_tasks() {
		global $wpdb;

		$actions = esc_sql( $this->as_actions );
		$logs    = esc_sql( $this->as_logs );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$failed_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$actions}` WHERE status = 'failed'" );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM `{$logs}` WHERE action_id IN (SELECT action_id FROM `{$actions}` WHERE status = 'failed')" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM `{$actions}` WHERE status = 'failed'" );

		$this->log_operation(
			__( 'Clean Failed', 'store-task-scheduler-cleaner' ),
			sprintf(
				/* translators: %d: number of failed tasks cleaned */
				__( 'Cleaned %d failed tasks', 'store-task-scheduler-cleaner' ),
				$failed_count
			)
		);
	}

	/**
	 * DROP + recreate plugin log table.
	 */
	private function clear_logs() {
		global $wpdb;

		if ( $this->table_exists( $this->log_table ) ) {
			$table = esc_sql( $this->log_table );
			// Safe and intentional: plugin-owned table maintenance.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
			$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
		}

		self::activate();
	}

	/**
	 * Write to plugin log table.
	 */
	private function log_operation( $operation, $details ) {
		global $wpdb;

		if ( $this->table_exists( $this->log_table ) ) {
			$wpdb->insert(
				$this->log_table,
				array(
					'operation' => sanitize_text_field( $operation ),
					'details'   => sanitize_textarea_field( $details ),
					'timestamp' => current_time( 'mysql' ),
				)
			);
		}
	}

	/**
	 * Fetch latest 50 plugin operations.
	 */
	private function get_logs() {
		global $wpdb;

		if ( ! $this->table_exists( $this->log_table ) ) {
			return array();
		}

		$table = esc_sql( $this->log_table );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( "SELECT * FROM `{$table}` ORDER BY timestamp DESC LIMIT 50", ARRAY_A );
	}

	/**
	 * Admin page renderer.
	 */
	public function admin_page() {
		// Display success message (nonce-verified).
		if ( isset( $_GET['msg'], $_GET['_stsc'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_stsc'] ) ), 'stsc_msg' ) ) {
			$msg = sanitize_text_field( wp_unslash( $_GET['msg'] ) );
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
		}

		$pending_count   = $this->get_pending_count();
		$completed_tasks = $this->get_completed_tasks();
		$failed_tasks    = $this->get_failed_tasks();
		$logs            = $this->get_logs();
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Store Task Scheduler Cleaner', 'store-task-scheduler-cleaner' ); ?></h1>

			<div class="card" style="max-width:none;width:100%;">
				<h2><?php echo esc_html__( 'Task Statistics', 'store-task-scheduler-cleaner' ); ?></h2>
				<p>
					<?php echo esc_html__( 'Pending tasks:', 'store-task-scheduler-cleaner' ); ?>
					<strong><?php echo esc_html( number_format_i18n( $pending_count ) ); ?></strong>
				</p>

				<form method="post" action="<?php echo esc_url( admin_url( 'tools.php?page=store-task-scheduler-cleaner' ) ); ?>">
					<?php wp_nonce_field( 'stscleaner_action' ); ?>
					<input type="hidden" name="do" value="clean_all">
					<button type="submit" id="stsc-clean-all" class="button button-primary">
						<?php echo esc_html__( 'Clean All Completed + Failed Tasks', 'store-task-scheduler-cleaner' ); ?>
					</button>
				</form>
			</div>

			<?php if ( ! empty( $completed_tasks ) ) : ?>
			<div class="card" style="max-width:none;width:100%;">
				<h2><?php echo esc_html__( 'Completed Tasks', 'store-task-scheduler-cleaner' ); ?></h2>

				<form id="stsc-clean-selected-form" method="post" action="<?php echo esc_url( admin_url( 'tools.php?page=store-task-scheduler-cleaner' ) ); ?>">
					<?php wp_nonce_field( 'stscleaner_action' ); ?>
					<input type="hidden" name="do" value="clean_selected">

					<table class="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<td class="manage-column column-cb check-column">
									<input type="checkbox" id="select-all">
								</td>
								<th><?php echo esc_html__( 'Hook Name', 'store-task-scheduler-cleaner' ); ?></th>
								<th><?php echo esc_html__( 'Count', 'store-task-scheduler-cleaner' ); ?></th>
								<th><?php echo esc_html__( 'Next Scheduled Run (GMT)', 'store-task-scheduler-cleaner' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $completed_tasks as $task ) : ?>
							<tr>
								<th class="check-column">
									<input type="checkbox" name="selected_hooks[]" value="<?php echo esc_attr( $task['hook'] ); ?>">
								</th>
								<td><?php echo esc_html( $task['hook'] ); ?></td>
								<td><?php echo esc_html( number_format_i18n( $task['count'] ) ); ?></td>
								<td><?php echo esc_html( $task['next_time'] ? $task['next_time'] : '—' ); ?></td>
							</tr>
							<?php endforeach; ?>
						</tbody>
					</table>

					<p class="submit">
						<button type="submit" class="button"><?php echo esc_html__( 'Clean Selected', 'store-task-scheduler-cleaner' ); ?></button>
					</p>
				</form>
			</div>
			<?php endif; ?>

			<?php if ( ! empty( $failed_tasks ) ) : ?>
			<div class="card" style="max-width:none;width:100%;">
				<h2><?php echo esc_html__( 'Failed Tasks', 'store-task-scheduler-cleaner' ); ?></h2>

				<form method="post" action="<?php echo esc_url( admin_url( 'tools.php?page=store-task-scheduler-cleaner' ) ); ?>">
					<?php wp_nonce_field( 'stscleaner_action' ); ?>
					<input type="hidden" name="do" value="clean_failed">
					<button type="submit" class="button"><?php echo esc_html__( 'Clean All Failed Tasks', 'store-task-scheduler-cleaner' ); ?></button>
				</form>

				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th><?php echo esc_html__( 'Hook Name', 'store-task-scheduler-cleaner' ); ?></th>
							<th><?php echo esc_html__( 'Count', 'store-task-scheduler-cleaner' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $failed_tasks as $task ) : ?>
						<tr>
							<td><?php echo esc_html( $task['hook'] ); ?></td>
							<td><?php echo esc_html( number_format_i18n( $task['count'] ) ); ?></td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php endif; ?>

			<div class="card" style="max-width:none;width:100%;">
				<h2><?php echo esc_html__( 'Operation Logs', 'store-task-scheduler-cleaner' ); ?></h2>

				<form method="post" action="<?php echo esc_url( admin_url( 'tools.php?page=store-task-scheduler-cleaner' ) ); ?>">
					<?php wp_nonce_field( 'stscleaner_action' ); ?>
					<input type="hidden" name="do" value="clear_logs">
					<button type="submit" id="stsc-clear-logs" class="button">
						<?php echo esc_html__( 'Clear All Logs', 'store-task-scheduler-cleaner' ); ?>
					</button>
				</form>

				<?php if ( ! empty( $logs ) ) : ?>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th><?php echo esc_html__( 'Operation', 'store-task-scheduler-cleaner' ); ?></th>
							<th><?php echo esc_html__( 'Details', 'store-task-scheduler-cleaner' ); ?></th>
							<th><?php echo esc_html__( 'Time', 'store-task-scheduler-cleaner' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $logs as $log ) : ?>
						<tr>
							<td><?php echo esc_html( $log['operation'] ); ?></td>
							<td><?php echo esc_html( $log['details'] ); ?></td>
							<td><?php echo esc_html( $log['timestamp'] ); ?></td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<?php else : ?>
				<p><?php esc_html_e( 'No operation logs yet.', 'store-task-scheduler-cleaner' ); ?></p>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Safe redirect with message + nonce.
	 */
	private function redirect_with_message( $message ) {
		$redirect_url = add_query_arg(
			array(
				'page'  => 'store-task-scheduler-cleaner',
				'msg'   => rawurlencode( $message ),
				'_stsc' => wp_create_nonce( 'stsc_msg' ),
			),
			admin_url( 'tools.php' )
		);

		wp_safe_redirect( $redirect_url );
		exit;
	}
}

// Bootstrap.
register_activation_hook( __FILE__, array( 'STSCleaner_Plugin', 'activate' ) );
new STSCleaner_Plugin();
