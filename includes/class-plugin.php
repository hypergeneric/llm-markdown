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
	private int $markdown_buffer_level = 0;

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
		if ($this->settings->should_discard_early_output()) {
			add_action('init', [$this, 'maybe_start_markdown_output_buffer'], 0);
		}
		add_filter('query_vars', [$this, 'register_query_vars']);
		add_action('template_redirect', [$this, 'maybe_serve_markdown'], 0);
		if ($this->settings->should_enable_content_negotiation()) {
			// Run after WordPress canonical redirects at priority 10.
			add_action('template_redirect', [$this, 'maybe_serve_negotiated_markdown'], 11);
		}
		add_action('wp_head', [$this, 'output_alternate_link'], 1);
		add_action('pre_get_posts', [$this, 'harden_render_source_query'], 0);
		add_filter('redirect_canonical', [$this, 'maybe_disable_canonical_redirect'], 0, 2);
		add_action('update_option_' . Settings::OPTION_NAME, [Renderer::class, 'bump_cache_generation'], 10, 0);
		add_action('switch_theme', [Renderer::class, 'bump_cache_generation'], 10, 0);
		add_action('wp_update_nav_menu', [Renderer::class, 'bump_cache_generation'], 10, 0);

		$this->hooks_registered = true;
	}

	public function maybe_start_markdown_output_buffer(): void {
		$request_uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '';
		if ('' === $request_uri) {
			return;
		}

		$path              = wp_parse_url($request_uri, PHP_URL_PATH);
		$is_markdown_path  = is_string($path) && '.md' === substr(untrailingslashit($path), -3);
		$is_negotiated     = $this->settings->should_enable_content_negotiation()
			&& $this->is_safe_request_method()
			&& $this->request_prefers_markdown();

		if (!$is_markdown_path && !$is_negotiated) {
			return;
		}

		ob_start();
		$this->markdown_buffer_level = ob_get_level();
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

	public function maybe_serve_negotiated_markdown(): void {
		if (!$this->is_safe_request_method()) {
			return;
		}

		if (is_admin() || wp_doing_ajax() || wp_doing_cron() || $this->is_rest_request()) {
			return;
		}

		if ($this->is_render_source_request() || is_feed() || is_preview() || is_trackback() || is_embed()) {
			return;
		}

		if (!is_singular()) {
			return;
		}

		$post = get_queried_object();
		if (!$post instanceof WP_Post || !$this->can_serve_post($post)) {
			return;
		}

		// Both representations must vary so caches do not reuse HTML for Markdown or vice versa.
		$this->add_vary_accept_header();

		if (!$this->request_prefers_markdown()) {
			return;
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

	private function is_safe_request_method(): bool {
		$method = isset($_SERVER['REQUEST_METHOD']) ? sanitize_key(wp_unslash($_SERVER['REQUEST_METHOD'])) : 'get';
		return in_array(strtoupper($method), ['GET', 'HEAD'], true);
	}

	private function request_prefers_markdown(): bool {
		$accept = isset($_SERVER['HTTP_ACCEPT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_ACCEPT'])) : '';
		if ('' === $accept) {
			return false;
		}

		$ranges = $this->parse_accept_header($accept);
		if (empty($ranges)) {
			return false;
		}

		$markdown            = $this->media_quality($ranges, 'text', 'markdown');
		$html                = $this->media_quality($ranges, 'text', 'html');
		$minimum_specificity = $this->settings->should_accept_markdown_wildcards() ? 1 : 2;

		return $markdown['specificity'] >= $minimum_specificity
			&& $markdown['quality'] > 0.0
			&& $markdown['quality'] >= max(0.0, $html['quality']);
	}

	/**
	 * @return array<int, array{type: string, subtype: string, quality: float}>
	 */
	private function parse_accept_header(string $accept): array {
		$ranges = [];

		foreach (explode(',', $accept) as $value) {
			$parts = array_map('trim', explode(';', $value));
			$media = strtolower((string) array_shift($parts));
			if (!preg_match('~^([a-z0-9!#$&^_.+*-]+)/([a-z0-9!#$&^_.+*-]+)$~i', $media, $matches)) {
				continue;
			}

			$quality = 1.0;
			$valid   = true;
			foreach ($parts as $parameter) {
				if (!preg_match('/^q\s*=\s*(.+)$/i', $parameter, $quality_match)) {
					continue;
				}

				$quality_value = trim((string) $quality_match[1]);
				if (!preg_match('/^(?:0(?:\.\d{0,3})?|1(?:\.0{0,3})?)$/', $quality_value)) {
					$valid = false;
					break;
				}

				$quality = (float) $quality_value;
			}

			if (!$valid) {
				continue;
			}

			$ranges[] = [
				'type'    => strtolower((string) $matches[1]),
				'subtype' => strtolower((string) $matches[2]),
				'quality' => $quality,
			];
		}

		return $ranges;
	}

	/**
	 * @param array<int, array{type: string, subtype: string, quality: float}> $ranges
	 * @return array{quality: float, specificity: int}
	 */
	private function media_quality(array $ranges, string $type, string $subtype): array {
		$best = [
			'quality'     => -1.0,
			'specificity' => -1,
		];

		foreach ($ranges as $range) {
			$specificity = -1;
			if ($type === $range['type'] && $subtype === $range['subtype']) {
				$specificity = 2;
			} elseif ($type === $range['type'] && '*' === $range['subtype']) {
				$specificity = 1;
			} elseif ('*' === $range['type'] && '*' === $range['subtype']) {
				$specificity = 0;
			}
			if ($specificity < 0) {
				continue;
			}

			if ($specificity > $best['specificity'] || ($specificity === $best['specificity'] && $range['quality'] > $best['quality'])) {
				$best = [
					'quality'     => $range['quality'],
					'specificity' => $specificity,
				];
			}
		}

		return $best;
	}

	private function add_vary_accept_header(): void {
		if (headers_sent()) {
			return;
		}

		$vary = [];
		foreach (headers_list() as $header_line) {
			if (0 !== stripos($header_line, 'Vary:')) {
				continue;
			}

			$values = explode(',', trim(substr($header_line, 5)));
			foreach ($values as $value) {
				$value = trim($value);
				if ('*' === $value) {
					return;
				}
				if ('' !== $value) {
					$vary[strtolower($value)] = $value;
				}
			}
		}

		$vary['accept'] = 'Accept';
		header_remove('Vary');
		header('Vary: ' . implode(', ', array_values($vary)));
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

		return (bool) apply_filters('llm_markdown_can_serve_post', true, $post);
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
		if ('' === $document) {
			$this->send_unavailable();
		}

		$document = (string) apply_filters('llm_markdown_markdown_document', $document, $post);

		$this->discard_early_output();

		status_header(200);
		header_remove('Content-Type');
		header('Content-Type: text/markdown; charset=' . get_bloginfo('charset'));
		header('X-Content-Type-Options: nosniff');
		header('Link: <' . esc_url_raw($canonical) . '>; rel="canonical"', false);

		echo $document; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	private function send_unavailable(): void {
		$this->discard_early_output();

		status_header(503);
		header_remove('Content-Type');
		header('Content-Type: text/plain; charset=' . get_bloginfo('charset'));
		header('Cache-Control: no-store');
		header('Retry-After: 60');
		header('X-Content-Type-Options: nosniff');

		echo esc_html__('Markdown temporarily unavailable', 'llm-markdown');
		exit;
	}

	private function send_not_found(): void {
		global $wp_query;

		if ($wp_query instanceof WP_Query) {
			$wp_query->set_404();
		}

		$this->discard_early_output();

		status_header(404);
		header_remove('Content-Type');
		header('Content-Type: text/plain; charset=' . get_bloginfo('charset'));
		header('X-Content-Type-Options: nosniff');

		echo esc_html__('Not Found', 'llm-markdown');
		exit;
	}

	private function discard_early_output(): void {
		while ($this->markdown_buffer_level > 0 && ob_get_level() >= $this->markdown_buffer_level) {
			$status = ob_get_status();
			if (!is_array($status) || !isset($status['flags']) || 0 === ($status['flags'] & PHP_OUTPUT_HANDLER_REMOVABLE)) {
				break;
			}

			ob_end_clean();
		}

		$this->markdown_buffer_level = 0;
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
