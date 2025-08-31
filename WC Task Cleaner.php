<?php
/**
 * Plugin Name: WC Task Cleaner
 * Plugin URI:  https://www.mxtag.com
 * Description: Clean up WooCommerce Action Scheduler tasks to optimize database performance and improve site speed.
 * Version:     1.0.0
 * Author:      mxtag
 * Author URI:  https://www.mxtag.com
 * License:     GPLv2 or later
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('WCTaskCleaner')):

class WCTaskCleaner {
    private $as_actions;
    private $as_logs;
    private $log_table;

    public function __construct() {
        global $wpdb;
        $this->as_actions = $wpdb->prefix . 'actionscheduler_actions';
        $this->as_logs = $wpdb->prefix . 'actionscheduler_logs';
        $this->log_table = $wpdb->prefix . 'wc_task_cleaner_logs';
        
        add_action('admin_init', [$this, 'handle_actions']);
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), [$this, 'add_settings_link']);
    }

    /**
     * Create log table on activation
     */
    public static function activate() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wc_task_cleaner_logs';
        
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            operation varchar(100) NOT NULL,
            details text NOT NULL,
            timestamp datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    /**
     * Check if table exists
     */
    private function table_exists($table_name) {
        global $wpdb;
        return $wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name;
    }

    /**
     * Add settings link to plugin page
     */
    public function add_settings_link($links) {
        $settings_link = '<a href="' . esc_url(admin_url('tools.php?page=wc-task-cleaner')) . '">Settings</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_management_page(
            'WC Task Cleaner',
            'WC Task Cleaner',
            'manage_options',
            'wc-task-cleaner',
            [$this, 'admin_page']
        );
    }

    /**
     * Get pending tasks count
     */
    private function get_pending_count() {
        global $wpdb;
        return $wpdb->get_var("SELECT COUNT(*) FROM {$this->as_actions} WHERE status = 'pending'");
    }

    /**
     * Get completed tasks grouped by hook
     */
    private function get_completed_tasks() {
        global $wpdb;
        return $wpdb->get_results("
            SELECT 
                hook,
                COUNT(*) as count,
                (SELECT MIN(scheduled_date_gmt) FROM {$this->as_actions} WHERE hook=a.hook AND status='pending') AS next_time
            FROM {$this->as_actions} a 
            WHERE status = 'complete' 
            GROUP BY hook 
            ORDER BY count DESC
        ", ARRAY_A);
    }

    /**
     * Get failed tasks grouped by hook
     */
    private function get_failed_tasks() {
        global $wpdb;
        return $wpdb->get_results("
            SELECT hook, COUNT(*) as count 
            FROM {$this->as_actions} 
            WHERE status = 'failed' 
            GROUP BY hook 
            ORDER BY count DESC
        ", ARRAY_A);
    }

    /**
     * Handle form actions
     */
    public function handle_actions() {
        if (!current_user_can('manage_options')) {
            wp_die('You do not have sufficient permissions to access this page.');
        }

        if (isset($_POST['do'])) {
            check_admin_referer('wc_task_cleaner_action');
            
            $action = sanitize_text_field($_POST['do']);
            $message = '';

            switch ($action) {
                case 'clean_all':
                    $this->clean_completed_failed();
                    $message = 'All completed and failed tasks have been cleaned.';
                    break;

                case 'clean_selected':
                    if (isset($_POST['selected_hooks']) && is_array($_POST['selected_hooks'])) {
                        $this->clean_selected_hooks($_POST['selected_hooks']);
                        $message = 'Selected tasks have been cleaned.';
                    }
                    break;

                case 'clean_failed':
                    $this->clean_failed_tasks();
                    $message = 'All failed tasks have been cleaned.';
                    break;

                case 'clear_logs':
                    $this->clear_logs();
                    $message = 'All logs have been cleared.';
                    break;
            }

            if ($message) {
                $this->redirect_with_message($message);
            }
        }
    }

    /**
     * Clean completed and failed tasks
     */
    private function clean_completed_failed() {
        global $wpdb;
        
        $completed_count = $wpdb->get_var("SELECT COUNT(*) FROM {$this->as_actions} WHERE status IN ('complete', 'failed')");
        
        $wpdb->query("DELETE FROM {$this->as_logs} WHERE action_id IN (SELECT action_id FROM {$this->as_actions} WHERE status IN ('complete', 'failed'))");
        $wpdb->query("DELETE FROM {$this->as_actions} WHERE status IN ('complete', 'failed')");
        
        $this->log_operation('Clean All', "Cleaned $completed_count completed and failed tasks");
    }

    /**
     * Clean selected hooks
     */
    private function clean_selected_hooks($hooks) {
        global $wpdb;
        
        $hooks = array_map('sanitize_text_field', $hooks);
        $placeholders = implode(',', array_fill(0, count($hooks), '%s'));
        
        $count = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->as_actions} WHERE hook IN ($placeholders) AND status = 'complete'", $hooks));
        
        $wpdb->query($wpdb->prepare("DELETE FROM {$this->as_logs} WHERE action_id IN (SELECT action_id FROM {$this->as_actions} WHERE hook IN ($placeholders) AND status = 'complete')", $hooks));
        $wpdb->query($wpdb->prepare("DELETE FROM {$this->as_actions} WHERE hook IN ($placeholders) AND status = 'complete'", $hooks));
        
        $this->log_operation('Clean Selected', "Cleaned $count tasks from selected hooks: " . implode(', ', $hooks));
    }

    /**
     * Clean failed tasks
     */
    private function clean_failed_tasks() {
        global $wpdb;
        
        $failed_count = $wpdb->get_var("SELECT COUNT(*) FROM {$this->as_actions} WHERE status = 'failed'");
        
        $wpdb->query("DELETE FROM {$this->as_logs} WHERE action_id IN (SELECT action_id FROM {$this->as_actions} WHERE status = 'failed')");
        $wpdb->query("DELETE FROM {$this->as_actions} WHERE status = 'failed'");
        
        $this->log_operation('Clean Failed', "Cleaned $failed_count failed tasks");
    }

    /**
     * Clear all logs
     */
    private function clear_logs() {
        global $wpdb;
        
        if ($this->table_exists($this->log_table)) {
            $wpdb->query("DROP TABLE `{$this->log_table}`");
        }
        
        self::activate();
    }

    /**
     * Log operation
     */
    private function log_operation($operation, $details) {
        global $wpdb;
        
        if ($this->table_exists($this->log_table)) {
            $wpdb->insert(
                $this->log_table,
                [
                    'operation' => $operation,
                    'details' => $details,
                    'timestamp' => current_time('mysql')
                ]
            );
        }
    }

    /**
     * Get operation logs
     */
    private function get_logs() {
        global $wpdb;
        
        if (!$this->table_exists($this->log_table)) {
            return [];
        }
        
        return $wpdb->get_results("SELECT * FROM {$this->log_table} ORDER BY timestamp DESC LIMIT 50", ARRAY_A);
    }

    /**
     * Admin page content
     */
    public function admin_page() {
        if (isset($_GET['msg'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(wp_unslash($_GET['msg'])) . '</p></div>';
        }
        
        $pending_count = $this->get_pending_count();
        $completed_tasks = $this->get_completed_tasks();
        $failed_tasks = $this->get_failed_tasks();
        $logs = $this->get_logs();
        ?>
        <div class="wrap">
            <h1>WC Task Cleaner</h1>
            
            <div class="card" style="max-width: none; width: 100%;">
                <h2>Task Statistics</h2>
                <p>Pending tasks: <strong><?php echo number_format_i18n($pending_count); ?></strong></p>
                
                <form method="post" action="<?php echo esc_url(admin_url('tools.php?page=wc-task-cleaner')); ?>">
                    <?php wp_nonce_field('wc_task_cleaner_action'); ?>
                    <input type="hidden" name="do" value="clean_all">
                    <button type="submit" class="button button-primary" 
                            onclick="return confirm('Confirm to clean [Completed + Failed] tasks and their Action Scheduler logs?');">
                        Clean All Completed + Failed Tasks
                    </button>
                </form>
            </div>

            <?php if (!empty($completed_tasks)): ?>
            <div class="card" style="max-width: none; width: 100%;">
                <h2>Completed Tasks</h2>
                <form method="post" action="<?php echo esc_url(admin_url('tools.php?page=wc-task-cleaner')); ?>">
                    <?php wp_nonce_field('wc_task_cleaner_action'); ?>
                    <input type="hidden" name="do" value="clean_selected">
                    
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <td class="manage-column column-cb check-column">
                                    <input type="checkbox" id="select-all">
                                </td>
                                <th>Hook Name</th>
                                <th>Count</th>
                                <th>Next Scheduled Run (GMT)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($completed_tasks as $task): ?>
                            <tr>
                                <th class="check-column">
                                    <input type="checkbox" name="selected_hooks[]" value="<?php echo esc_attr($task['hook']); ?>">
                                </th>
                                <td><?php echo esc_html($task['hook']); ?></td>
                                <td><?php echo number_format_i18n($task['count']); ?></td>
                                <td><?php echo esc_html($task['next_time'] ?: '—'); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    
                    <p class="submit">
                        <button type="submit" class="button">Clean Selected</button>
                    </p>
                </form>
            </div>
            <?php endif; ?>

            <?php if (!empty($failed_tasks)): ?>
            <div class="card" style="max-width: none; width: 100%;">
                <h2>Failed Tasks</h2>
                <form method="post" action="<?php echo esc_url(admin_url('tools.php?page=wc-task-cleaner')); ?>">
                    <?php wp_nonce_field('wc_task_cleaner_action'); ?>
                    <input type="hidden" name="do" value="clean_failed">
                    <button type="submit" class="button">Clean All Failed Tasks</button>
                </form>
                
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Hook Name</th>
                            <th>Count</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($failed_tasks as $task): ?>
                        <tr>
                            <td><?php echo esc_html($task['hook']); ?></td>
                            <td><?php echo number_format_i18n($task['count']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <div class="card" style="max-width: none; width: 100%;">
                <h2>Operation Logs</h2>
                <form method="post" action="<?php echo esc_url(admin_url('tools.php?page=wc-task-cleaner')); ?>">
                    <?php wp_nonce_field('wc_task_cleaner_action'); ?>
                    <input type="hidden" name="do" value="clear_logs">
                    <button type="submit" class="button" 
                            onclick="return confirm('Will directly rebuild the log table (DROP+CREATE), cannot be recovered. Confirm to continue?');">
                        Clear All Logs
                    </button>
                </form>
                
                <?php if (!empty($logs)): ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Operation</th>
                            <th>Details</th>
                            <th>Time</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $log): ?>
                        <tr>
                            <td><?php echo esc_html($log['operation']); ?></td>
                            <td><?php echo esc_html($log['details']); ?></td>
                            <td><?php echo esc_html($log['timestamp']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <p>No operation logs yet.</p>
                <?php endif; ?>
            </div>
        </div>

        <script>
        document.getElementById('select-all').addEventListener('change', function() {
            const checkboxes = document.querySelectorAll('input[name="selected_hooks[]"]');
            checkboxes.forEach(checkbox => checkbox.checked = this.checked);
        });
        </script>
        <?php
    }

    /**
     * Redirect with success message
     */
    private function redirect_with_message($message) {
        $redirect_url = add_query_arg([
            'page' => 'wc-task-cleaner',
            'msg' => rawurlencode($message)
        ], admin_url('tools.php'));
        
        wp_safe_redirect($redirect_url);
        exit;
    }
}

// Initialize plugin
register_activation_hook(__FILE__, ['WCTaskCleaner', 'activate']);
new WCTaskCleaner();

endif;
