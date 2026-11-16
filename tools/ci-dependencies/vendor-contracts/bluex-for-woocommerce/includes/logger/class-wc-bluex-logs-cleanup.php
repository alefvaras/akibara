<?php

defined('ABSPATH') || exit;

class WC_BlueX_Logs_Cleanup
{
	const HOOK = 'bluex_clean_logs';
	const RETENTION_DAYS = 7;

	public static function init()
	{
		add_action(self::HOOK, array(__CLASS__, 'purge'));
		add_action('init', array(__CLASS__, 'schedule'));
	}

	public static function schedule()
	{
		if (!wp_next_scheduled(self::HOOK)) {
			wp_schedule_event(time(), 'daily', self::HOOK);
		}
	}

	public static function unschedule()
	{
		wp_clear_scheduled_hook(self::HOOK);
	}

	/**
	 * @param int $now Unix timestamp.
	 * @return string
	 */
	public static function cutoff($now)
	{
		return gmdate('Y-m-d H:i:s', $now - self::RETENTION_DAYS * DAY_IN_SECONDS);
	}

	public static function purge()
	{
		global $wpdb;
		$table_name = $wpdb->prefix . 'bluex_logs';

		if ($wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") !== $table_name) {
			return;
		}

		$wpdb->query($wpdb->prepare("DELETE FROM {$table_name} WHERE log_timestamp < %s", self::cutoff(time())));
	}
}
