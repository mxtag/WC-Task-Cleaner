<?php
/**
 * Plugin Name: WC Task Cleaner
 * Plugin URI:  https://github.com/mxtag/WC-Task-Cleaner
 * Description: Clean up WooCommerce Action Scheduler tasks to optimize database performance and improve site speed.
 * Version:     1.0.0
 * Author:      mxtag
 * Author URI:  https://www.mxtag.com
 * License:     GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wc-task-cleaner
 * Domain Path: /languages
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WCTaskCleaner' ) ) :

class WCTaskCleaner {
	private $as_actions;
	private $as_logs;
	private $log_table;

	public function __construct() {
		global $wpdb;
		$this->as_actions = $wpdb->prefix . 'actionscheduler_actions';
		$this->as_logs    = $wpdb->prefix . 'actionscheduler_logs';
		$this->log_table  = $wpdb->prefix . 'wc_task_cleaner_logs';

		// WordPress 4.6+ auto-loads translations for wordpress.org hosted plugins.
		// Text Domain and Domain Path in header are sufficient for automatic loading.

		add_action( 'admin_init', array( $this, 'handle_actions' ) );
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'add_settings_link' ) );
	}

	/**
	 * Create the log table on activation.
	 */
	public static function activate() {
		global $wpdb;
		$table_name = $wpdb->prefix . 'wc_task_cleaner_logs';

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

		// Optional: record the version for future upgrades.
		update_option( 'wc_task_cleaner_version', '1.0.0' );
	}

	/**
	 * Check if a table exists.
     * Uses exact match for table name (no esc_like needed, since no wildcard search).
	 */
	private function table_exists( $table_name ) {
		global $wpdb;
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );
		return $found === $table_name;
	}

	/**
	 * Add a settings link in the plugin list.
	 */
	public function add_settings_link( $links ) {
		$settings_link = '<a href="' . esc_url( admin_url( 'tools.php?page=wc-task-cleaner' ) ) . '">' . esc_html__( 'Settings', 'wc-task-cleaner' ) . '</a>';
		array_unshift( $links, $settings_link );
		return $links;
	}

	/**
	 * Add the admin menu page.
	 */
	public function add_admin_menu() {
		add_management_page(
			__( 'WC Task Cleaner', 'wc-task-cleaner' ),
			__( 'WC Task Cleaner', 'wc-task-cleaner' ),
			'manage_options',
			'wc-task-cleaner',
			array( $this, 'admin_page' )
		);
	}

	/**
	 * Get the count of pending tasks.
	 */
	private function get_pending_count() {
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		global $wpdb;
		$table = esc_sql( $this->as_actions );
		// Admin-only maintenance query; no user input; table identifier escaped.
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}` WHERE status = 'pending'" );
		// phpcs:enable
		return $count;
	}

	/**
	 * Get completed tasks grouped by hook.
	 */
	private function get_completed_tasks() {
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		global $wpdb;
		$table = esc_sql( $this->as_actions );

		// Safe: no user input; table identifier escaped; admin-only view.
		$rows = $wpdb->get_results(
			"
			SELECT 
				hook,
				COUNT(*) AS count,
				(SELECT MIN(scheduled_date_gmt) FROM `{$table}` WHERE hook = a.hook AND status = 'pending') AS next_time
			FROM `{$table}` a
			WHERE status = 'complete'
			GROUP BY hook
			ORDER BY count DESC
			",
			ARRAY_A
		);
		// phpcs:enable
		return $rows;
	}

	/**
	 * Get failed tasks grouped by hook.
	 */
	private function get_failed_tasks() {
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		global $wpdb;
		$table = esc_sql( $this->as_actions );

		// Safe: no user input; table identifier escaped; admin-only view.
		$rows = $wpdb->get_results(
			"SELECT hook, COUNT(*) AS count FROM `{$table}` WHERE status = 'failed' GROUP BY hook ORDER BY count DESC",
			ARRAY_A
		);
		// phpcs:enable
		return $rows;
	}

	/**
	 * Handle submitted actions.
	 */
	public function handle_actions() {
		// Only check permissions and process when there is a submission.
		if ( isset( $_POST['do'] ) ) {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'wc-task-cleaner' ) );
			}

			check_admin_referer( 'wc_task_cleaner_action' );

			$action  = sanitize_text_field( wp_unslash( $_POST['do'] ) );
			$message = '';

			switch ( $action ) {
				case 'clean_all':
					$this->clean_completed_failed();
					$message = __( 'All completed and failed tasks have been cleaned.', 'wc-task-cleaner' );
					break;

				case 'clean_selected':
					if ( isset( $_POST['selected_hooks'] ) && is_array( $_POST['selected_hooks'] ) ) {
						$selected_hooks = array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['selected_hooks'] ) );
						$this->clean_selected_hooks( $selected_hooks );
						$message = __( 'Selected tasks have been cleaned.', 'wc-task-cleaner' );
					}
					break;

				case 'clean_failed':
					$this->clean_failed_tasks();
					$message = __( 'All failed tasks have been cleaned.', 'wc-task-cleaner' );
					break;

				case 'clear_logs':
					$this->clear_logs();
					$message = __( 'All logs have been cleared.', 'wc-task-cleaner' );
					break;
			}

			if ( $message ) {
				$this->redirect_with_message( $message );
			}
		}
	}

	/**
	 * Clean completed and failed tasks.
	 */
	private function clean_completed_failed() {
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		global $wpdb;

		$actions = esc_sql( $this->as_actions );
		$logs    = esc_sql( $this->as_logs );

		// Count first.
		$completed_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$actions}` WHERE status IN ('complete','failed')" );

		// Delete logs first, then delete actions.
		$wpdb->query( "DELETE FROM `{$logs}` WHERE action_id IN (SELECT action_id FROM `{$actions}` WHERE status IN ('complete','failed'))" );
		$wpdb->query( "DELETE FROM `{$actions}` WHERE status IN ('complete','failed')" );
		// phpcs:enable

		$this->log_operation(
			__( 'Clean All', 'wc-task-cleaner' ),
			sprintf(
				/* translators: %d: number of tasks cleaned */
				__( 'Cleaned %d completed and failed tasks', 'wc-task-cleaner' ),
				$completed_count
			)
		);
	}

	/**
	 * Clean selected hooks (complete only).
	 */
	private function clean_selected_hooks( $hooks ) {
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		global $wpdb;

		$actions = esc_sql( $this->as_actions );
		$logs    = esc_sql( $this->as_logs );

		$hooks = array_map( 'sanitize_text_field', (array) $hooks );
		$hooks = array_filter( $hooks );

		if ( empty( $hooks ) ) {
			// phpcs:enable
			return;
		}

		$placeholders = implode( ',', array_fill( 0, count( $hooks ), '%s' ) );

		// COUNT.
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM `{$actions}` WHERE hook IN ($placeholders) AND status = 'complete'",
				$hooks
			)
		);

		// DELETE logs referencing selected hooks.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM `{$logs}` WHERE action_id IN (
					SELECT action_id FROM `{$actions}` WHERE hook IN ($placeholders) AND status = 'complete'
				)",
				$hooks
			)
		);

		// DELETE actions for selected hooks.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM `{$actions}` WHERE hook IN ($placeholders) AND status = 'complete'",
				$hooks
			)
		);
		// phpcs:enable

		$this->log_operation(
			__( 'Clean Selected', 'wc-task-cleaner' ),
			sprintf(
				/* translators: 1: number of tasks cleaned, 2: comma-separated list of hooks */
				__( 'Cleaned %1$d tasks from selected hooks: %2$s', 'wc-task-cleaner' ),
				$count,
				implode( ', ', $hooks )
			)
		);
	}

	/**
	 * Clean failed tasks.
	 */
	private function clean_failed_tasks() {
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		global $wpdb;

		$actions = esc_sql( $this->as_actions );
		$logs    = esc_sql( $this->as_logs );

		$failed_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$actions}` WHERE status = 'failed'" );

		$wpdb->query( "DELETE FROM `{$logs}` WHERE action_id IN (SELECT action_id FROM `{$actions}` WHERE status = 'failed')" );
		$wpdb->query( "DELETE FROM `{$actions}` WHERE status = 'failed'" );
		// phpcs:enable

		$this->log_operation(
			__( 'Clean Failed', 'wc-task-cleaner' ),
			sprintf(
				/* translators: %d: number of failed tasks cleaned */
				__( 'Cleaned %d failed tasks', 'wc-task-cleaner' ),
				$failed_count
			)
		);
	}

	/**
	 * Clear all logs (DROP + recreate).
	 */
	private function clear_logs() {
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
		global $wpdb;

		if ( $this->table_exists( $this->log_table ) ) {
			$table = esc_sql( $this->log_table );
			// Safe and intentional: plugin-owned table maintenance.
			$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
		}
		// phpcs:enable

		self::activate(); // Rebuild the empty table.
	}

	/**
	 * Log an operation to the custom log table.
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
	 * Get operation logs (latest 50).
	 */
	private function get_logs() {
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		global $wpdb;

		if ( ! $this->table_exists( $this->log_table ) ) {
			// phpcs:enable
			return array();
		}

		$table = esc_sql( $this->log_table );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Escaped table identifier; no user input; admin-only read.
		$rows  = $wpdb->get_results( "SELECT * FROM `{$table}` ORDER BY timestamp DESC LIMIT 50", ARRAY_A );
		// phpcs:enable
		return $rows;
	}

	/**
	 * Render the admin page.
	 */
	public function admin_page() {
		// Verify nonce for message display to satisfy WPCS recommendation.
		if ( isset( $_GET['msg'], $_GET['_wctc'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wctc'] ) ), 'wctc_msg' ) ) {
			$msg = sanitize_text_field( wp_unslash( $_GET['msg'] ) );
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
		}

		$pending_count   = $this->get_pending_count();
		$completed_tasks = $this->get_completed_tasks();
		$failed_tasks    = $this->get_failed_tasks();
		$logs            = $this->get_logs();
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'WC Task Cleaner', 'wc-task-cleaner' ); ?></h1>

			<div class="card" style="max-width: none; width: 100%;">
				<h2><?php echo esc_html__( 'Task Statistics', 'wc-task-cleaner' ); ?></h2>
				<p>
					<?php echo esc_html__( 'Pending tasks:', 'wc-task-cleaner' ); ?>
					<strong><?php echo esc_html( number_format_i18n( $pending_count ) ); ?></strong>
				</p>

				<form method="post" action="<?php echo esc_url( admin_url( 'tools.php?page=wc-task-cleaner' ) ); ?>">
					<?php wp_nonce_field( 'wc_task_cleaner_action' ); ?>
					<input type="hidden" name="do" value="clean_all">
					<button type="submit" class="button button-primary"
						onclick="return confirm('<?php echo esc_js( __( 'Confirm to clean [Completed + Failed] tasks and their Action Scheduler logs?', 'wc-task-cleaner' ) ); ?>');">
						<?php echo esc_html__( 'Clean All Completed + Failed Tasks', 'wc-task-cleaner' ); ?>
					</button>
				</form>
			</div>

			<?php if ( ! empty( $completed_tasks ) ) : ?>
			<div class="card" style="max-width: none; width: 100%;">
				<h2><?php echo esc_html__( 'Completed Tasks', 'wc-task-cleaner' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'tools.php?page=wc-task-cleaner' ) ); ?>">
					<?php wp_nonce_field( 'wc_task_cleaner_action' ); ?>
					<input type="hidden" name="do" value="clean_selected">

					<table class="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<td class="manage-column column-cb check-column">
									<input type="checkbox" id="select-all">
								</td>
								<th><?php echo esc_html__( 'Hook Name', 'wc-task-cleaner' ); ?></th>
								<th><?php echo esc_html__( 'Count', 'wc-task-cleaner' ); ?></th>
								<th><?php echo esc_html__( 'Next Scheduled Run (GMT)', 'wc-task-cleaner' ); ?></th>
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
								<td><?php echo esc_html( $task['next_time'] ? $task['next_time'] : __( '—', 'wc-task-cleaner' ) ); ?></td>
							</tr>
							<?php endforeach; ?>
						</tbody>
					</table>

					<p class="submit">
						<button type="submit" class="button"><?php echo esc_html__( 'Clean Selected', 'wc-task-cleaner' ); ?></button>
					</p>
				</form>
			</div>
			<?php endif; ?>

			<?php if ( ! empty( $failed_tasks ) ) : ?>
			<div class="card" style="max-width: none; width: 100%;">
				<h2><?php echo esc_html__( 'Failed Tasks', 'wc-task-cleaner' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'tools.php?page=wc-task-cleaner' ) ); ?>">
					<?php wp_nonce_field( 'wc_task_cleaner_action' ); ?>
					<input type="hidden" name="do" value="clean_failed">
					<button type="submit" class="button"><?php echo esc_html__( 'Clean All Failed Tasks', 'wc-task-cleaner' ); ?></button>
				</form>

				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th><?php echo esc_html__( 'Hook Name', 'wc-task-cleaner' ); ?></th>
							<th><?php echo esc_html__( 'Count', 'wc-task-cleaner' ); ?></th>
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

			<div class="card" style="max-width: none; width: 100%;">
				<h2><?php echo esc_html__( 'Operation Logs', 'wc-task-cleaner' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'tools.php?page=wc-task-cleaner' ) ); ?>">
					<?php wp_nonce_field( 'wc_task_cleaner_action' ); ?>
					<input type="hidden" name="do" value="clear_logs">
					<button type="submit" class="button"
						onclick="return confirm('<?php echo esc_js( __( 'Will directly rebuild the log table (DROP+CREATE) and cannot be recovered. Confirm to continue?', 'wc-task-cleaner' ) ); ?>');">
						<?php echo esc_html__( 'Clear All Logs', 'wc-task-cleaner' ); ?>
					</button>
				</form>

				<?php if ( ! empty( $logs ) ) : ?>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th><?php echo esc_html__( 'Operation', 'wc-task-cleaner' ); ?></th>
							<th><?php echo esc_html__( 'Details', 'wc-task-cleaner' ); ?></th>
							<th><?php echo esc_html__( 'Time', 'wc-task-cleaner' ); ?></th>
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
				<p><?php esc_html_e( 'No operation logs yet.', 'wc-task-cleaner' ); ?></p>
				<?php endif; ?>
			</div>
		</div>

		<script>
		(function() {
			var selectAll = document.getElementById('select-all');
			if (selectAll) {
				selectAll.addEventListener('change', function() {
					var checkboxes = document.querySelectorAll('input[name="selected_hooks[]"]');
					for (var i = 0; i < checkboxes.length; i++) {
						checkboxes[i].checked = this.checked;
					}
				});
			}
		})();
		</script>
		<?php
	}

	/**
	 * Redirect with a success message (includes a nonce for display verification).
	 */
	private function redirect_with_message( $message ) {
		$redirect_url = add_query_arg(
			array(
				'page'  => 'wc-task-cleaner',
				'msg'   => rawurlencode( $message ),
				'_wctc' => wp_create_nonce( 'wctc_msg' ),
			),
			admin_url( 'tools.php' )
		);

		wp_safe_redirect( $redirect_url );
		exit;
	}
}

// Initialize the plugin.
register_activation_hook( __FILE__, array( 'WCTaskCleaner', 'activate' ) );
new WCTaskCleaner();

endif;
