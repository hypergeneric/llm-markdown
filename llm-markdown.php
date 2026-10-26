<?php
/**
 * Plugin Name:       LLM Markdown – Markdown Endpoints for AI Agents
 * Plugin URI:        https://compiledrogue.com/
 * Description:       Serves clean Markdown versions of WordPress posts, pages, and custom post types for AI agents, LLM tools, and content pipelines.
 * Version:           1.1.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Compiled Rogue
 * Author URI:        https://compiledrogue.com
 * License:           GPL2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       llm-markdown
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
	exit;
}

define('LLMMD_FILE', __FILE__);
define('LLMMD_PATH', plugin_dir_path(__FILE__));
define('LLMMD_URL', plugin_dir_url(__FILE__));

require_once LLMMD_PATH . 'includes/class-settings.php';
require_once LLMMD_PATH . 'includes/class-renderer.php';
require_once LLMMD_PATH . 'includes/class-editor-tools.php';
require_once LLMMD_PATH . 'includes/class-plugin.php';

register_activation_hook(LLMMD_FILE, ['\LLM_Markdown\Plugin', 'activate']);
register_deactivation_hook(LLMMD_FILE, ['\LLM_Markdown\Plugin', 'deactivate']);

add_action('plugins_loaded', static function (): void {
	\LLM_Markdown\Plugin::instance()->register_hooks();
});
