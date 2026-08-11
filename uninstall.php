<?php
/**
 * Remove LLM Markdown settings and cached documents.
 *
 * @package LLM_Markdown
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

/**
 * Remove all data owned by the plugin from the current site.
 */
function llm_markdown_delete_site_data(): void {
	global $wpdb;

	delete_option('llm_markdown_settings');
	delete_option('llm_markdown_cache_generation');

	$value_pattern   = $wpdb->esc_like('_transient_llm_markdown_') . '%';
	$timeout_pattern = $wpdb->esc_like('_transient_timeout_llm_markdown_') . '%';
	$option_names    = (array) $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Transient keys must be discovered for complete uninstall cleanup.
		$wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$value_pattern,
			$timeout_pattern
		)
	);

	foreach ($option_names as $option_name) {
		$transient_name = preg_replace('/^_transient_(?:timeout_)?/', '', (string) $option_name);
		if (is_string($transient_name) && '' !== $transient_name) {
			delete_transient($transient_name);
		}
	}

	// Also remove database rows when an external object cache is currently active.
	$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Removes database-backed transients when an external object cache is active.
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$value_pattern,
			$timeout_pattern
		)
	);
}

if (is_multisite()) {
	$site_ids = get_sites(
		[
			'fields' => 'ids',
			'number' => 0,
		]
	);

	foreach ($site_ids as $site_id) {
		switch_to_blog((int) $site_id);
		llm_markdown_delete_site_data();
		restore_current_blog();
	}
} else {
	llm_markdown_delete_site_data();
}
