<?php
/**
Plugin Name: Database Collation Fix
Plugin URL: https://davejesch.com/plugins/databasecollationfix
Description: Convert tables using utf8mb4_unicode_520_ci or utf8_unicode_520_ci collation to standard collation on a cron interval.
Version: 1.2.11
Author: Dave Jesch
Author URI: http://davejesch.com
Network: True
Text Domain: databasecollationfix
Domain path: /language
License: GPLv2
License URI: http://www.gnu.org/license/gpl-20.0.html
*/

if ( ! defined( 'ABSPATH' ) ) exit;

class DS_DatabaseCollationFix
{
	private static $_instance = null;

	const VERSION = '1.2.11';
	const CRON_NAME = 'ds_database_collation_fix';

	/* Collation Algorithm to change database to: */
	private $_collation = 'utf8mb4_unicode_ci';
	/* List of Collation Algorithms to look for and change */
	private $_change_collation = array(
		'utf8mb4_unicode_520_ci',
		'utf8_unicode_520_ci',
	);

	private $_report = FALSE;
	private $_output = FALSE;

	private $table_count = 0;			// count number of tables updated
	private $column_count = 0;			// count number of columns updated
	private $index_count = 0;			// count number of indexes updated

	/**
	 * Constructor
	 */
	private function __construct()
	{
		if (!defined('DAY_IN_SECONDS'))
			define('DAY_IN_SECONDS', 60 * 60 * 24);
		add_action('init', array($this, 'init'));

		// use override from wp-config.php if provided -- and not an undesired algorithm
		if (defined('DB_COLLATE')) {
			$collation = DB_COLLATE;
			if (!empty($collation) && !in_array(DB_COLLATE, $this->_change_collation))
				$this->_collation = DB_COLLATE;
		}
//$this->_collation = 'utf8_general_ci';			// force collation to this instead
	}

	/**
	 * Obtains a singleton instance of the plugin
	 * @return DS_DatabaseCollationFix instance
	 */
	public static function get_instance()
	{
		if (null === self::$_instance)
			self::$_instance = new self();
		return self::$_instance;
	}

	/**
	 * Callback for 'init' action. Used to set up cron.
	 */
	public function init()
	{
$this->_log(__METHOD__.'() starting');
		$next = wp_next_scheduled(self::CRON_NAME);
		if (FALSE === $next)
			$this->_update_schedule(DAY_IN_SECONDS);

		add_action(self::CRON_NAME, array(__CLASS__, 'cron_run'));

		if (is_admin() && current_user_can('manage_options'))
			add_action('admin_menu', array($this, 'admin_menu'));
	}

	/**
	 * Updates the Cron schedule, adding the CRON_NAME to be triggered once per day at midnight.
	 * @param string $interval
	 */
	private function _update_schedule($interval = null)
	{
		if ( defined( 'WP_INSTALLING' ) && WP_INSTALLING )
			return;				// do nothing if WP is trying to install

		$time_start = strtotime('yesterday');
		if (null === $time_start)
			$interval = DAY_IN_SECONDS;

		$timestamp = $time_start + $interval;
		wp_clear_scheduled_hook(self::CRON_NAME);
		wp_schedule_event($timestamp, 'daily', self::CRON_NAME);
	}

	/**
	 * Callback for the cron operation (set up in init() method)
	 */
	public static function cron_run()
	{
		$uc = self::get_instance();
		$uc->modify_collation();
	}

	/**
	 * This method does the work of modifying the collation used by the database tables and their columns
	 * @param boolean $report TRUE to output report of operations; otherwise FALSE
	 */
	public function modify_collation($report = FALSE)
	{
$this->_log_action(__METHOD__);
		global $wpdb;

		$force = FALSE;
		$force_algorithm = 'utf8mb4_unicode_ci';
		if (isset($_SERVER['REQUEST_METHOD']) && 'POST' === $_SERVER['REQUEST_METHOD']) {
			// note: nonce verification done in admin_page
			if (isset($_POST['force-collation']) && '1' === $_POST['force-collation']) {  // phpcs:ignore
				if (isset($_POST['force-collation-algorithm'])) {  // phpcs:ignore
					// CVE-2023-23997 make sure forced algorithm is an expected value
					$value = sanitize_key($_POST['force-collation-algorithm']);  // phpcs:ignore
					$algos = array('utf8mb4_unicode_ci', 'utf8mb4_general_ci', 'utf8_unicode_ci', 'utf8_general_ci');
					if (in_array($value, $algos)) {
						$force_algorithm = $value;
						$force = TRUE;
					}
				}
				$this->_collation = $force_algorithm;
			}
		}

		$this->_report = $report;
		$this->table_count = $this->column_count = $this->index_count = 0;
		if ($report) {
			echo '<div style="width:100%; margin-top:15px">';
			if ($force) {
				// translators: %s the new collation algorithm to use
				echo '<p>', esc_html(sprintf(__('Forcing Collation Algorithm to: <b>%s</b>.', 'databasecollationfix'),
					$this->_collation)), '</p>';
			}
		}

		// translators: %s the new collation algorithm to use
		$this->_report(sprintf(__('Changing database Collation Algorithm to: %s', 'databasecollationfix'),
			$this->_collation));
		$sql = 'ALTER DATABASE `' . DB_NAME . '` COLLATE=' . $this->_collation;
		$res = $wpdb->query($sql);  // phpcs:ignore
$this->_report($sql, TRUE);

		// search for this collation method
		$collation = 'utf8mb4_unicode_520_ci';
		$collation_term = " COLLATE={$collation}";

		// get all tables that match $wpdb's table prefix
		$sql = "SHOW TABLES LIKE '{$wpdb->prefix}%'";
		$res = $wpdb->get_col($sql);  // phpcs:ignore
		if (null !== $res) {
			foreach ($res as $table) {
				$this->modify_table($table, $collation_term, $this->_collation, $force);
		}

		// translators: %1$s number of tables altered %2$d number of database columns altered %3$d number of database indexes altered
		$this->_report(sprintf(__('Altered %1$d tables, %2$d columns and %3$d indexes.', 'databasecollationfix'),
			$this->table_count, $this->column_count, $this->index_count));
		if ($report)
			echo '</div>';
		}
	}

	/**
	 * Modifies collation for a specific table
	 * @param string $table Name of the table to modify
	 * @param string $collation_term The collation term
	 * @param string $force_algorithm The collation algorithm to set
	 * @param boolean $force True to force update; otherwise false
	 */
	public function modify_table($table, $collation_term, $force_algorithm, $force = false)
	{
		global $wpdb;

$this->_log(__METHOD__.'() checking table ' . $table);
		// create a list of any indexes that need to be recreated after column alterations
		$indexes = array();

		// translators: %s database table name
		$this->_report(sprintf(__('Checking table "%s"...', 'databasecollationfix'), $table));
		// check how the table was created
		$sql = "SHOW CREATE TABLE `{$table}`";
		$create_table_res = $wpdb->get_row($sql, ARRAY_A);  // phpcs:ignore
$this->_log(__METHOD__.'() res=' . var_export($create_table_res, TRUE));  // phpcs:ignore
		$create_table = $create_table_res['Create Table'];
//$this->_report('create table=' . $create_table);

		// drop any FULLTEXT indexes #8
		// ALTER TABLE `tablename` DROP INDEX `idxname`
		$offset = 0;
//$this->_report('create table: ' . var_export($create_table, TRUE));
		while ($offset < strlen($create_table)) {
			$pos = stripos($create_table, 'FULLTEXT KEY', $offset);
$this->_report('searching FULLTEXT INDEX: ' . var_export($pos, TRUE));  // phpcs:ignore
			if (FALSE === $pos)
				break;

			// found a fulltext index
			$idx_name = substr($create_table, $pos + 14);
			$end_quote = strpos($idx_name, '`');
			$idx_name = substr($idx_name, 0, $end_quote);
			$col_names = substr($create_table, $pos + 15 + strlen($idx_name) + 1);
			$close_paren = strpos($col_names, ')');
			$col_names = substr($col_names, 0, $close_paren + 1);

			// move offset pointer to end of FULLTEXT INDEX so we can look for any remaining indexes
			$offset += $pos + 13 + strlen($idx_name) + strlen($col_names);
$this->_report('found index [' . $idx_name . '] of [' . $col_names . ']');
			$sql = "CREATE FULLTEXT INDEX `{$idx_name}` ON `{$table}` {$col_names}";
//$this->_report("creating index update: {$sql}");
			$indexes[] = $sql;					// add to list of indexes to recreate after column alterations
			++$this->index_count;
			$sql = "ALTER TABLE `{$table}` DROP INDEX `{$idx_name}`";
$this->_report('removing index: ' . $sql);
			$wpdb->query($sql);  // phpcs:ignore
		}

		// determine current collation value
		$old_coll = '';
		$pos = strpos($create_table, ' COLLATE=');
		if (FALSE !== $pos) {
			$old_coll = substr($create_table, $pos + 9);
			$pos = strpos($old_coll, ' ');
			if (FALSE !== $pos)
				$old_coll = substr($old_coll, 0, $pos);
//$this->_report('current table collation="' . $old_coll . "'");
		}
//$this->_report('- current collation: ' . $old_coll);

//		if (!empty($old_coll) && $old_coll !== $force_algorithm && !in_array($old_coll, $this->_change_collation)) {
//			$this->_change_collation[] = $old_coll;
//$this->_report('++ adding collation algorithm to change list: ' . implode(', ', $this->_change_collation));
//		}

		// check table collation and modify if it's an undesired algorithm
		$mod = FALSE;
		if (in_array($old_coll, $this->_change_collation) ||
			($force && $old_coll !== $force_algorithm)) {
$this->_log(__METHOD__.'() checking collation: ' . $collation_term);
			$new_coll = $force ? $force_algorithm : $this->_collation;
			// translators: %1$s current collation algorithm %2$s new collation algorithm
			$this->_report(sprintf(__('- found "%1$s" and ALTERing to "%2$s"...', 'databasecollationfix'),
				$old_coll, $new_coll));
			++$this->table_count;

			$sql = "ALTER TABLE `{$table}` COLLATE={$new_coll}";
$this->_report($sql, TRUE);
			$alter = $wpdb->query($sql);  // phpcs:ignore
$this->_log(__METHOD__.'() sql=' . $sql . ' res=' . var_export($alter, TRUE));  // phpcs:ignore
			$mod = TRUE;
		}
		if (!$mod) {
			$this->_report(__('- no ALTERations required.', 'databasecollationfix'));
		}

		// check column collation and modify if it's an undesired algorithm
		$sql = "SHOW FULL COLUMNS FROM `{$table}`";
		$columns_res = $wpdb->get_results($sql, ARRAY_A);  // phpcs:ignore
		if (null !== $columns_res) {
			foreach ($columns_res as $row) {
$this->_log(__METHOD__.'() checking collation of column `' . $row['Field'] . '`: `' . $row['Collation'] . '`: (' . implode(',', $this->_change_collation) . ')');
//$this->_report(__LINE__ . ':row [' . var_export($row, true) . ']', true); #!#
				// if it's not a text/char field skip it
//$this->_report('row type for ' . var_export($row, TRUE) . ' is: ' . $row['Type']);
				// include column type of 'enum' when checking collation algorithms #7
				if (FALSE === stripos($row['Type'], 'text') &&
					FALSE === stripos($row['Type'], 'char') &&
					FALSE === stripos($row['Type'], 'enum')) {
					continue;
				}
				// if the column is using an undesired Collation Algorithm
				if (in_array($row['Collation'], $this->_change_collation) ||
					($force && $row['Collation'] !== $this->_collation)) {
$this->_log(__METHOD__.'() updating column\'s collation');
					$null = 'NO' === $row['Null'] ? 'NOT NULL' : 'NULL';
					// $default = !empty($row['Default']) ? " DEFAULT '{$row['Default']}' " : '';
					$default = (null !== $row['Default']) ? " DEFAULT '{$row['Default']}' " : '';
//$this->_report(__LINE__ . ':default [' . $default . ']', true); #!#

					// translators: %1$s database column name %2$s current collumn collation value %3$s new collation value
					$this->_report(sprintf(__('- found column `%1$s` with collation of "%2$s" and ALTERing to "%3$s".', 'databasecollationfix'),
						$row['Field'], $row['Collation'], $this->_collation));
//					$row['Collation'] = $this->_collation;
					++$this->column_count;

					// alter the table, changing the column's Collation Algorithm
					$sql = "ALTER TABLE `{$table}`
						CHANGE `{$row['Field']}` `{$row['Field']}` {$row['Type']} COLLATE {$this->_collation} {$null} {$default}";
$this->_report($sql, TRUE);
					$alter_res = $wpdb->query($sql);  // phpcs:ignore
$this->_log(__METHOD__.'() alter=' . $sql . ' res=' . var_export($alter_res, TRUE));  // phpcs:ignore
				}
			}
		}

		// replace any FULLTEXT indexes that were dropped #8
		// CREATE FULLTEXT INDEX `idxname` ON `tablename` (col1, col2)
		foreach ($indexes as $idx) {
$this->_report('adding back the index: ' . $idx);
			// note: query constructed above with sanitized data
			$wpdb->query($idx);  // phpcs:ignore
		}
	}

	/**
	 * Outputs reporting information to the screen when performing ALTERations on demand.
	 * @param string $message Message to output
	 * @param boolean $debug TRUE if debugging data; otherwise FALSE
	 */
	private function _report($message, $debug = FALSE)
	{
//echo $message, PHP_EOL;
		if ($this->_report || $this->_output) {
			$out = TRUE;
			if ($debug && (!defined('WP_DEBUG') || !WP_DEBUG))
				$out = FALSE;

			if ($out || $this->_output)
				echo esc_html($message), ($this->_output ? PHP_EOL : '<br/>');
		}
	}

	/**
	 * Sets the output flag, used in reporting
	 * @param boolean $output TRUE to perform output; FALSE for no output
	 */
	public function set_output($output)
	{
		$this->_output = $output;
	}

	/**
	 * Callback for the 'admin_menu' action. Used to add the menu item to the Tools menu
	 */
	public function admin_menu()
	{
		add_management_page(__('Collation Fix', 'databasecollationfix'),	// page title
			__('Collation Fix', 'databasecollationfix'),					// menu title
			'manage_options',												// capability
			'ds-db-collation',												// menu_slug
			array($this, 'admin_page'));									// callback
	}

	/**
	 * Outputs the contents of the tool's admin page.
	 */
	public function admin_page()
	{
		$perform_action = false;

		if (isset($_SERVER['REQUEST_METHOD']) && 'POST' === $_SERVER['REQUEST_METHOD'] &&
			isset($_POST['collation-fix']) && isset($_POST['collation-nonce'])) {
			if (wp_verify_nonce(sanitize_key($_POST['collation-nonce']), 'collation-action') &&
				current_user_can('manage_options')) {
				$perform_action = true;
			}
		}
//echo '<pre>', 'perform=', ($perform_action ? 'true' : 'false'), PHP_EOL, var_export($_POST, true), '</pre>';

		echo '<div class="wrap">';
		// translators: %1$s the plugin's version number
		echo '<h2>', esc_html(sprintf(__('Database Collation Fix%1$s tool', 'databasecollationfix'), ' v' . self::VERSION)), '</h2>';
		echo '<p>', esc_html(__('This tool is used to convert your site\'s database tables from using the ...unicode_520_ci Collation Algorithms to use a slightly older, but more compatible utf8mb4_unicode_ci Collation Algorithm.', 'databasecollationfix')), '</p>';
		echo '<p>', esc_html(__('The tool will automatically run every 24 hours and change any newly created database table. Or, you can use the button below to perform the database alterations on demand.', 'databasecollationfix')), '</p>';

		echo '<form action="', esc_url(add_query_arg('action', 'run')), '" method="post">';
		echo '<p>';
		wp_nonce_field('collation-action', 'collation-nonce', true, true);
		echo '<input type="hidden" name="force-collation" value="0" />';
		echo '<input type="checkbox" name="force-collation" value="1" />';
		echo '&nbsp;', esc_html(__('Force Collation Algorithm to: ', 'databasecollationfix'));

		echo '<select name="force-collation-algorithm">';
		echo '<option value="utf8mb4_unicode_ci">utf8mb4_unicode_ci</option>';
		echo '<option value="utf8mb4_general_ci">utf8mb4_general_ci</option>';
		echo '<option value="utf8_unicode_ci">utf8_unicode_ci</option>';
		echo '<option value="utf8_general_ci">utf8_general_ci</option>';
		echo '</select>';
		echo '</p>';

		echo '<input type="submit" name="collation-fix" class="button-primary" value="',
			esc_html(__('Fix Database Collation', 'databasecollationfix')), '" />';
		echo '</form>';

		if ($perform_action) {
			$this->modify_collation(TRUE);		// perform collation changes, with reporting
		}

		echo '</div>';
	}

	/**
	 * Performs logging, including the DS action that was last triggered before the logging was done
	 * @param string $method The method name calling this method
	 */
	private function _log_action($method)
	{
		$action = $site_name = '';
		global $ds_runtime;
		if ( isset( $ds_runtime ) ) {
			if ( FALSE !== $ds_runtime->last_ui_event ) {
				$action = $ds_runtime->last_ui_event->action;

				$site_name = '';
				if ( in_array( $ds_runtime->last_ui_event->action, ['site_copied', 'site_moved'] ) ) {
					$site_name = $ds_runtime->last_ui_event->info[1];
				} else {
					$site_name = $ds_runtime->last_ui_event->info[0];
				}
			}
		}

		$this->_log( $method . '() on action "' . $action . '" for site name "' . $site_name . '"' );
	}

	/**
	 * Performs logging for debugging purposes
	 * @param string $msg The data to be logged
	 * @param boolean $backtrace TRUE to also log backtrace information
	 */
	private function _log($msg, $backtrace = FALSE)
	{
return;
		$file = dirname(__FILE__) . '/~log.txt';
		$fh = @fopen($file, 'a+');  // phpcs:ignore
		if (FALSE !== $fh) {
			if (null === $msg)
				fwrite($fh, current_time('mysql', false));  // phpcs:ignore
			else
				fwrite($fh, current_time('mysql', false) . $msg . "\r\n");  // phpcs:ignore

			if ($backtrace) {
				$callers = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);	// phpcs:ignore
				array_shift($callers);
				$path = dirname(dirname(dirname(plugin_dir_path(__FILE__)))) . DIRECTORY_SEPARATOR;

				$n = 1;
				foreach ($callers as $caller) {
					$func = $caller['function'] . '()';
					if (isset($caller['class']) && !empty($caller['class'])) {
						$type = '->';
						if (isset($caller['type']) && !empty($caller['type']))
							$type = $caller['type'];
						$func = $caller['class'] . $type . $func;
					}
					$file = isset($caller['file']) ? $caller['file'] : '';
					$file = str_replace('\\', '/', str_replace($path, '', $file));
					if (isset($caller['line']) && !empty($caller['line']))
						$file .= ':' . $caller['line'];
					$frame = $func . ' - ' . $file;
					$out = '    #' . ($n++) . ': ' . $frame . PHP_EOL;
					fwrite($fh, $out);  // phpcs:ignore
					if (self::$_debug_output)
						echo $out;		// phpcs:ignore
				}
			}

			fclose($fh);  // phpcs:ignore
		}
	}
}

DS_DatabaseCollationFix::get_instance();
