<?php

declare(strict_types=1);

namespace LLM_Markdown;

use WP_Post;
use WP_Query;

if (!defined('ABSPATH')) {
	exit;
}

final class Plugin {
	private static ?Plugin $instance = null;

	private Settings $settings;
	private Renderer $renderer;

	private bool $hooks_registered = false;

	private function __construct() {
		$this->settings = Settings::instance();
		$this->renderer = new Renderer($this->settings);
	}

	public static function instance(): Plugin {
		if (null === self::$instance) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public static function activate(): void {
		self::instance()->register_rewrite_rules();
		flush_rewrite_rules();
	}

	public static function deactivate(): void {
		flush_rewrite_rules();
	}

	public function register_hooks(): void {
		if ($this->hooks_registered) {
			return;
		}

		$this->settings->register_hooks();

		add_action('init', [$this, 'register_rewrite_rules'], 10);
		add_filter('query_vars', [$this, 'register_query_vars']);
		add_action('template_redirect', [$this, 'maybe_serve_markdown'], 0);
		add_action('wp_head', [$this, 'output_alternate_link'], 1);
		add_action('pre_get_posts', [$this, 'harden_render_source_query'], 0);
		add_filter('redirect_canonical', [$this, 'maybe_disable_canonical_redirect'], 0, 2);

		$this->hooks_registered = true;
	}

	/**
	 * @param string|false $redirect_url
	 * @param string       $requested_url
	 * @return string|false
	 */
	public function maybe_disable_canonical_redirect($redirect_url, string $requested_url) {
		if ($this->is_render_source_request()) {
			return false;
		}
		return $redirect_url;
	}

	public function harden_render_source_query(WP_Query $q): void {
		if (is_admin() || !$q->is_main_query()) {
			return;
		}

		if (!$this->is_render_source_request()) {
			return;
		}

		if ('page' !== (string) get_option('show_on_front')) {
			return;
		}

		$front_id = (int) get_option('page_on_front');
		if ($front_id <= 0) {
			return;
		}

		$request_uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '';

		// On some installs, "/" ends up with is_home() true and gets rewritten to posts.
		if ($q->is_home() || $q->is_front_page() || '/' === $request_uri) {
			$q->set('page_id', $front_id);
			$q->set('post_type', 'page');

			$q->is_home       = false;
			$q->is_front_page = true;
			$q->is_page       = true;
			$q->is_singular   = true;
		}
	}

	private function is_render_source_request(): bool {
		if (!isset($_SERVER['HTTP_X_LLMMD_RENDER_SOURCE'])) {
			return false;
		}

		$val = trim(
			sanitize_text_field(
				wp_unslash($_SERVER['HTTP_X_LLMMD_RENDER_SOURCE'])
			)
		);
		return '1' === $val;
	}

	public function register_rewrite_rules(): void {
		add_rewrite_rule(
			'^(.+)\.md/?$',
			'index.php?llm_markdown_md=1&llm_markdown_path=$matches[1]',
			'top'
		);
	}

	/**
	 * @param array<int, string> $vars
	 * @return array<int, string>
	 */
	public function register_query_vars(array $vars): array {
		$vars[] = 'llm_markdown_md';
		$vars[] = 'llm_markdown_path';
		return $vars;
	}

	public function maybe_serve_markdown(): void {
		if (is_admin() || wp_doing_ajax() || wp_doing_cron() || $this->is_rest_request()) {
			return;
		}

		if (!$this->is_markdown_route_request()) {
			return;
		}

		// Prevent recursion: if we are being fetched as the loopback HTML source, do not serve Markdown.
		if ($this->is_render_source_request()) {
			$this->send_not_found();
		}

		$post = $this->resolve_markdown_route_post();
		if (!$post instanceof WP_Post) {
			$this->send_not_found();
		}

		if (!$this->can_serve_post($post)) {
			$this->send_not_found();
		}

		$this->send_markdown($post);
	}

	public function output_alternate_link(): void {
		if (!is_singular() || $this->is_markdown_route_request()) {
			return;
		}

		$post = get_queried_object();
		if (!$post instanceof WP_Post) {
			return;
		}

		if (!$this->can_serve_post($post)) {
			return;
		}

		$canonical = get_permalink($post);
		if (!is_string($canonical) || '' === $canonical) {
			return;
		}

		$md_url = $this->build_markdown_url($canonical);

		printf(
			"<link rel=\"alternate\" type=\"text/markdown\" title=\"%s\" href=\"%s\" />\n",
			esc_attr__('Markdown version', 'llm-markdown'),
			esc_url($md_url)
		);
	}

	private function is_markdown_route_request(): bool {
		return '1' === (string) get_query_var('llm_markdown_md', '');
	}

	private function is_rest_request(): bool {
		if (defined('REST_REQUEST') && REST_REQUEST) {
			return true;
		}

		if (!isset($_SERVER['REQUEST_URI'])) {
			return false;
		}

		$request_uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '';
		$rest_prefix = '/' . trailingslashit(rest_get_url_prefix());

		return false !== strpos($request_uri, $rest_prefix);
	}

	private function resolve_markdown_route_post(): ?WP_Post {
		$route_path = trim((string) get_query_var('llm_markdown_path', ''));
		$route_path = trim(rawurldecode($route_path), '/');

		if ('' === $route_path) {
			return null;
		}

		// Front page alias: /index.md -> front page (if static front page enabled).
		if ('index' === strtolower($route_path) && 'page' === (string) get_option('show_on_front')) {
			$front_id = (int) get_option('page_on_front');
			if ($front_id > 0) {
				$front = get_post($front_id);
				if ($front instanceof WP_Post) {
					return $front;
				}
			}
		}

		$segments     = explode('/', $route_path);
		$encoded_path = implode('/', array_map('rawurlencode', $segments));

		$candidates = [
			home_url('/' . $encoded_path),
			home_url('/' . $encoded_path . '/'),
		];

		foreach (array_unique($candidates) as $url) {
			$post_id = url_to_postid($url);
			if ($post_id > 0) {
				$post = get_post($post_id);
				if ($post instanceof WP_Post) {
					return $post;
				}
			}
		}

		$public_types = get_post_types(['public' => true], 'names');
		$post         = get_page_by_path($route_path, OBJECT, $public_types);

		return ($post instanceof WP_Post) ? $post : null;
	}

	private function can_serve_post(WP_Post $post): bool {
		$enabled_types = $this->settings->get_enabled_post_types();
		if (!in_array($post->post_type, $enabled_types, true)) {
			return false;
		}

		if (post_password_required($post)) {
			return false;
		}

		if (function_exists('is_post_publicly_viewable') && !is_post_publicly_viewable($post)) {
			return false;
		}

		if ($this->settings->should_respect_noindex() && $this->is_noindex($post)) {
			return false;
		}

		return true;
	}

	private function is_noindex(WP_Post $post): bool {
		$yoast = (string) get_post_meta($post->ID, '_yoast_wpseo_meta-robots-noindex', true);
		if ('1' === $yoast || 'noindex' === strtolower($yoast)) {
			return true;
		}

		return (bool) apply_filters('llm_markdown_is_noindex_post', false, $post);
	}

	private function send_markdown(WP_Post $post): void {
		$canonical = get_permalink($post);
		if (!is_string($canonical) || '' === $canonical) {
			$canonical = home_url('/');
		}

		$md_url = $this->build_markdown_url($canonical);

		$document = $this->renderer->render_post($post, $canonical, $md_url);
		$document = (string) apply_filters('llm_markdown_markdown_document', $document, $post);

		status_header(200);
		header_remove('Content-Type');
		header('Content-Type: text/markdown; charset=' . get_bloginfo('charset'));
		header('X-Content-Type-Options: nosniff');
		header('Link: <' . esc_url_raw($canonical) . '>; rel="canonical"', false);

		echo $document; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	private function send_not_found(): void {
		global $wp_query;

		if ($wp_query instanceof WP_Query) {
			$wp_query->set_404();
		}

		status_header(404);
		header_remove('Content-Type');
		header('Content-Type: text/plain; charset=' . get_bloginfo('charset'));
		header('X-Content-Type-Options: nosniff');

		echo esc_html__('Not Found', 'llm-markdown');
		exit;
	}

	private function build_markdown_url(string $canonical_url): string {
		$parts = wp_parse_url($canonical_url);
		if (!is_array($parts)) {
			return $canonical_url;
		}

		$path = isset($parts['path']) ? (string) $parts['path'] : '/';
		$path = ('/' === $path) ? '/index' : untrailingslashit($path);

		// PHP 7.4-compatible ends-with check.
		if ('.md' !== substr($path, -3)) {
			$path .= '.md';
		}

		$parts['path'] = $path;

		$url = '';

		if (isset($parts['scheme'])) {
			$url .= $parts['scheme'] . '://';
		}
		if (isset($parts['host'])) {
			$url .= $parts['host'];
		}
		if (isset($parts['port'])) {
			$url .= ':' . (int) $parts['port'];
		}

		$url .= (string) ($parts['path'] ?? '');

		if (isset($parts['query']) && '' !== (string) $parts['query']) {
			$url .= '?' . $parts['query'];
		}
		if (isset($parts['fragment']) && '' !== (string) $parts['fragment']) {
			$url .= '#' . $parts['fragment'];
		}

		return $url;
	}
}
