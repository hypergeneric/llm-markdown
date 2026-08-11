<?php

declare(strict_types=1);

namespace LLM_Markdown;

use WP_Post_Type;

if (!defined('ABSPATH')) {
	exit;
}

final class Settings {
	public const OPTION_NAME = 'llm_markdown_settings';

	private static ?Settings $instance = null;

	private bool $hooks_registered = false;

	public static function instance(): Settings {
		if (null === self::$instance) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register_hooks(): void {
		if ($this->hooks_registered) {
			return;
		}

		add_action('admin_menu', [$this, 'register_admin_menu'], 20);
		add_action('admin_init', [$this, 'register_settings'], 20);
		add_action('admin_post_llm_markdown_purge_cache', [$this, 'handle_purge_cache']);

		$this->hooks_registered = true;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_options(): array {
		$stored = get_option(self::OPTION_NAME, []);
		if (!is_array($stored)) {
			$stored = [];
		}

		return wp_parse_args($stored, $this->defaults());
	}

	/**
	 * @return array<int, string>
	 */
	public function get_enabled_post_types(): array {
		$options   = $this->get_options();
		$available = array_keys($this->get_available_post_type_objects());
		$selected  = $options['post_types'] ?? [];

		if (!is_array($selected)) {
			$selected = [];
		}

		$out = [];
		foreach ($selected as $pt) {
			$pt = sanitize_key((string) $pt);
			if ('' !== $pt && in_array($pt, $available, true)) {
				$out[] = $pt;
			}
		}

		return array_values(array_unique($out));
	}

	public function get_document_root_selector(): string {
		$options = $this->get_options();
		$sel     = trim((string) ($options['document_root_selector'] ?? $this->defaults()['document_root_selector']));

		return ('' === $sel) ? (string) $this->defaults()['document_root_selector'] : $sel;
	}

	public function get_ignore_selectors(): string {
		$options = $this->get_options();
		return trim((string) ($options['ignore_selectors'] ?? (string) $this->defaults()['ignore_selectors']));
	}

	public function should_respect_noindex(): bool {
		$options = $this->get_options();
		return !empty($options['respect_noindex']);
	}

	public function should_include_images(): bool {
		$options = $this->get_options();
		return !empty($options['include_images']);
	}

	public function should_discard_early_output(): bool {
		$options = $this->get_options();
		return !empty($options['discard_early_output']);
	}

	public function should_enable_content_negotiation(): bool {
		$options = $this->get_options();
		return !empty($options['enable_content_negotiation']);
	}

	public function should_accept_markdown_wildcards(): bool {
		$options = $this->get_options();
		return !empty($options['accept_markdown_wildcards']);
	}

	public function register_admin_menu(): void {
		add_options_page(
			esc_html__('Markdown Output Settings', 'llm-markdown'),
			esc_html__('Markdown Output', 'llm-markdown'),
			'manage_options',
			'llm-markdown',
			[$this, 'render_settings_page']
		);
	}

	public function register_settings(): void {
		register_setting(
			'llm_markdown_group',
			self::OPTION_NAME,
			[
				'type'              => 'array',
				'sanitize_callback' => [$this, 'sanitize_settings'],
				'default'           => $this->defaults(),
			]
		);

		add_settings_section(
			'llm_markdown_options',
			esc_html__('Options', 'llm-markdown'),
			static function (): void {
				echo '<p>' . esc_html__('Configure which content is exposed as Markdown and its standard output behavior.', 'llm-markdown') . '</p>';
			},
			'llm-markdown-options'
		);

		add_settings_field(
			'post_types',
			esc_html__('Content Types', 'llm-markdown'),
			[$this, 'render_post_types_field'],
			'llm-markdown-options',
			'llm_markdown_options'
		);

		add_settings_field(
			'respect_noindex',
			esc_html__('Honor Noindex', 'llm-markdown'),
			[$this, 'render_respect_noindex_field'],
			'llm-markdown-options',
			'llm_markdown_options'
		);

		add_settings_field(
			'include_images',
			esc_html__('Images in Markdown', 'llm-markdown'),
			[$this, 'render_include_images_field'],
			'llm-markdown-options',
			'llm_markdown_options'
		);

		add_settings_field(
			'discard_early_output',
			esc_html__('Response Cleanup', 'llm-markdown'),
			[$this, 'render_discard_early_output_field'],
			'llm-markdown-options',
			'llm_markdown_options'
		);

		add_settings_section(
			'llm_markdown_dom',
			esc_html__('DOM & Selectors', 'llm-markdown'),
			static function (): void {
				echo '<p>' . esc_html__('Configure how the plugin locates and filters content in rendered HTML.', 'llm-markdown') . '</p>';
			},
			'llm-markdown-dom'
		);

		add_settings_field(
			'document_root_selector',
			esc_html__('Main Content Selectors', 'llm-markdown'),
			[$this, 'render_document_root_selector_field'],
			'llm-markdown-dom',
			'llm_markdown_dom'
		);

		add_settings_field(
			'ignore_selectors',
			esc_html__('Excluded Content Selectors', 'llm-markdown'),
			[$this, 'render_ignore_selectors_field'],
			'llm-markdown-dom',
			'llm_markdown_dom'
		);

		add_settings_section(
			'llm_markdown_advanced',
			esc_html__('Advanced', 'llm-markdown'),
			static function (): void {
				echo '<p>' . esc_html__('Advanced delivery options require careful testing with your site, server, proxy, and CDN configuration.', 'llm-markdown') . '</p>';
			},
			'llm-markdown-advanced'
		);

		add_settings_field(
			'enable_content_negotiation',
			esc_html__('Header-Based Markdown', 'llm-markdown'),
			[$this, 'render_enable_content_negotiation_field'],
			'llm-markdown-advanced',
			'llm_markdown_advanced'
		);

		add_settings_field(
			'accept_markdown_wildcards',
			esc_html__('Wildcard Accept Headers', 'llm-markdown'),
			[$this, 'render_accept_markdown_wildcards_field'],
			'llm-markdown-advanced',
			'llm_markdown_advanced'
		);
	}

	/**
	 * @param mixed $raw
	 * @return array<string, mixed>
	 */
	public function sanitize_settings($raw): array {
		$defaults = $this->defaults();
		$stored   = get_option(self::OPTION_NAME, []);
		$stored   = is_array($stored) ? $stored : [];
		$out      = wp_parse_args($stored, $defaults);

		if (!is_array($raw)) {
			return $out;
		}

		$tab = isset($raw['_settings_tab']) ? sanitize_key((string) wp_unslash($raw['_settings_tab'])) : 'all';
		if ('all' === $tab || 'options' === $tab) {
			$available = array_keys($this->get_available_post_type_objects());
			$selected  = $raw['post_types'] ?? [];
			$clean     = [];

			if (is_array($selected)) {
				foreach ($selected as $pt) {
					$pt = sanitize_key((string) $pt);
					if ('' !== $pt && in_array($pt, $available, true)) {
						$clean[] = $pt;
					}
				}
			}

			$out['post_types']          = array_values(array_unique($clean));
			$out['include_images']       = isset($raw['include_images']) ? 1 : 0;
			$out['discard_early_output'] = isset($raw['discard_early_output']) ? 1 : 0;
			$out['respect_noindex']      = isset($raw['respect_noindex']) ? 1 : 0;
		}

		if ('all' === $tab || 'dom' === $tab) {
			$root = (string) ($raw['document_root_selector'] ?? $out['document_root_selector']);
			$root = sanitize_text_field(wp_unslash($root));
			$root = substr(trim($root), 0, 500);

			$out['document_root_selector'] = ('' === $root)
				? (string) $defaults['document_root_selector']
				: $root;

			$ignore = (string) ($raw['ignore_selectors'] ?? $out['ignore_selectors']);
			$ignore = sanitize_textarea_field(wp_unslash($ignore));
			$out['ignore_selectors'] = substr(trim($ignore), 0, 2000);
		}

		if ('all' === $tab || 'advanced' === $tab) {
			$out['enable_content_negotiation'] = isset($raw['enable_content_negotiation']) ? 1 : 0;
			$out['accept_markdown_wildcards']   = isset($raw['accept_markdown_wildcards']) ? 1 : 0;
		}

		return $out;
	}

	public function render_include_images_field(): void {
		$options = $this->get_options();

		printf(
			'<label><input type="checkbox" name="%1$s[include_images]" value="1" %2$s /> %3$s</label>',
			esc_attr(self::OPTION_NAME),
			checked(!empty($options['include_images']), true, false),
			esc_html__('Include content images in the Markdown document.', 'llm-markdown')
		);

		echo '<p class="description">';
		echo esc_html__('Converts images found inside the selected content area to Markdown image syntax. Lazy-loaded source attributes, alt text, and titles are used when available.', 'llm-markdown');
		echo '</p>';
	}

	public function render_discard_early_output_field(): void {
		$options = $this->get_options();

		printf(
			'<label><input type="checkbox" name="%1$s[discard_early_output]" value="1" %2$s /> %3$s</label>',
			esc_attr(self::OPTION_NAME),
			checked(!empty($options['discard_early_output']), true, false),
			esc_html__('Remove unexpected output before sending Markdown.', 'llm-markdown')
		);

		echo '<p class="description">';
		echo esc_html__('Compatibility option for themes, plugins, or hosting layers that emit HTML, notices, or other unwanted content before .md or negotiated Markdown responses. Disabled by default.', 'llm-markdown');
		echo '</p>';
	}

	public function render_enable_content_negotiation_field(): void {
		$options = $this->get_options();

		printf(
			'<label><input type="checkbox" name="%1$s[enable_content_negotiation]" value="1" %2$s /> %3$s</label>',
			esc_attr(self::OPTION_NAME),
			checked(!empty($options['enable_content_negotiation']), true, false),
			esc_html__('Allow normal page URLs to return Markdown when explicitly requested.', 'llm-markdown')
		);

		echo '<p class="description">';
		echo esc_html__('Clients that send an Accept: text/markdown request header can receive Markdown from the normal canonical URL. Standard browser requests continue to receive HTML.', 'llm-markdown');
		echo '</p>';
		echo '<p class="description"><strong>';
		echo esc_html__('Advanced feature:', 'llm-markdown');
		echo '</strong> ';
		echo esc_html__('Content negotiation relies on caches and CDNs correctly honoring the Vary: Accept response header. Misconfigured caching may cause the wrong representation of a page to be served. Markdown responses also bypass normal browser-side analytics and JavaScript. Enable this only if you understand your site’s caching and delivery configuration.', 'llm-markdown');
		echo '</p>';
		echo '<p class="description">';
		echo esc_html__('After enabling, clear all site, server, CDN, and proxy caches and test both HTML and Markdown responses before using this in production.', 'llm-markdown');
		echo '</p>';
	}

	public function render_accept_markdown_wildcards_field(): void {
		$options = $this->get_options();

		printf(
			'<label><input type="checkbox" name="%1$s[accept_markdown_wildcards]" value="1" %2$s /> %3$s</label>',
			esc_attr(self::OPTION_NAME),
			checked(!empty($options['accept_markdown_wildcards']), true, false),
			esc_html__('Also allow text/* requests to select Markdown.', 'llm-markdown')
		);

		echo '<p class="description">';
		echo esc_html__('Applies only to Header-Based Markdown. The conservative default requires an explicit text/markdown media range. Enable this to honor the broader text/* range according to its quality value; */* alone always receives HTML.', 'llm-markdown');
		echo '</p>';
	}

	public function render_post_types_field(): void {
		$options         = $this->get_options();
		$selected        = $options['post_types'] ?? $this->default_post_types();
		$selected        = is_array($selected) ? $selected : $this->default_post_types();
		$available_types = $this->get_available_post_type_objects();

		foreach ($available_types as $post_type => $obj) {
			if (!$obj instanceof WP_Post_Type) {
				continue;
			}

			$checked = in_array($post_type, $selected, true);

			printf(
				'<label style="display:block;margin-bottom:6px;"><input type="checkbox" name="%1$s[post_types][]" value="%2$s" %3$s /> %4$s <code>(%5$s)</code></label>',
				esc_attr(self::OPTION_NAME),
				esc_attr($post_type),
				checked($checked, true, false),
				esc_html($obj->labels->singular_name),
				esc_html($post_type)
			);
		}

		echo '<p class="description">';
		echo esc_html__('Select the public WordPress content types that should expose Markdown. Leave every item unchecked to disable all Markdown output.', 'llm-markdown');
		echo '</p>';
	}

	public function render_document_root_selector_field(): void {
		$options = $this->get_options();

		printf(
			'<input type="text" class="regular-text" name="%1$s[document_root_selector]" value="%2$s" />',
			esc_attr(self::OPTION_NAME),
			esc_attr((string) ($options['document_root_selector'] ?? (string) $this->defaults()['document_root_selector']))
		);

		echo '<p class="description">';
		echo esc_html__('Enter simple CSS selectors for the primary content container, in priority order. Separate selectors with commas; the first match is used. Supports tags, IDs, classes, descendants, and direct children.', 'llm-markdown');
		echo '</p>';
	}

	public function render_ignore_selectors_field(): void {
		$options = $this->get_options();
		$value   = isset($options['ignore_selectors']) ? (string) $options['ignore_selectors'] : '';

		printf(
			'<textarea name="%1$s[ignore_selectors]" rows="12" class="large-text code" placeholder="header, footer&#10;.foo .bar&#10;#nav">%2$s</textarea>',
			esc_attr(self::OPTION_NAME),
			esc_textarea($value)
		);

		echo '<p class="description">';
		echo esc_html__('Elements matching these simple CSS selectors are removed before conversion. Use commas or new lines to separate selectors; common examples include navigation, forms, banners, and related-content blocks.', 'llm-markdown');
		echo '</p>';
	}

	public function render_respect_noindex_field(): void {
		$options = $this->get_options();

		printf(
			'<label><input type="checkbox" name="%1$s[respect_noindex]" value="1" %2$s /> %3$s</label>',
			esc_attr(self::OPTION_NAME),
			checked(!empty($options['respect_noindex']), true, false),
			esc_html__('Do not publish Markdown for content marked noindex by Yoast SEO.', 'llm-markdown')
		);

		echo '<p class="description">';
		echo esc_html__('Keeps the Markdown route and alternate link unavailable when Yoast SEO marks a post as noindex. Enabled by default.', 'llm-markdown');
		echo '</p>';
	}

	public function render_settings_page(): void {
		if (!current_user_can('manage_options')) {
			return;
		}

		$active_tab = $this->get_active_tab();
		?>
		<div class="wrap">
			<h1><?php echo esc_html__('Markdown Output Settings', 'llm-markdown'); ?></h1>
			<?php $this->render_settings_tabs($active_tab); ?>

			<?php if ('tools' === $active_tab) : ?>
				<?php $this->render_tools_tab(); ?>
			<?php else : ?>
				<form action="options.php" method="post">
					<?php
					settings_fields('llm_markdown_group');
					?>
					<input type="hidden" name="<?php echo esc_attr(self::OPTION_NAME); ?>[_settings_tab]" value="<?php echo esc_attr($active_tab); ?>" />
					<?php
					do_settings_sections('llm-markdown-' . $active_tab);
					submit_button();
					?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	public function handle_purge_cache(): void {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You are not allowed to manage Markdown settings.', 'llm-markdown'));
		}

		check_admin_referer('llm_markdown_purge_cache');
		Renderer::bump_cache_generation();

		$url = add_query_arg(
			[
				'page'                       => 'llm-markdown',
				'tab'                        => 'tools',
				'llm_markdown_cache_cleared' => '1',
			],
			admin_url('options-general.php')
		);

		wp_safe_redirect($url);
		exit;
	}

	private function get_active_tab(): string {
		$active_tab = isset($_GET['tab']) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			? sanitize_key((string) wp_unslash($_GET['tab'])) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			: 'options';

		$tabs = ['options', 'dom', 'advanced', 'tools'];
		return in_array($active_tab, $tabs, true) ? $active_tab : 'options';
	}

	private function render_settings_tabs(string $active_tab): void {
		$tabs = [
			'options'  => esc_html__('Options', 'llm-markdown'),
			'dom'      => esc_html__('DOM & Selectors', 'llm-markdown'),
			'advanced' => esc_html__('Advanced', 'llm-markdown'),
			'tools'    => esc_html__('Tools', 'llm-markdown'),
		];

		echo '<nav class="nav-tab-wrapper" aria-label="' . esc_attr__('Markdown settings', 'llm-markdown') . '">';
		foreach ($tabs as $tab => $label) {
			$url = add_query_arg(
				[
					'page' => 'llm-markdown',
					'tab'  => $tab,
				],
				admin_url('options-general.php')
			);

			$class = 'nav-tab' . (($active_tab === $tab) ? ' nav-tab-active' : '');

			printf(
				'<a class="%1$s" href="%2$s" aria-current="%3$s">%4$s</a>',
				esc_attr($class),
				esc_url($url),
				esc_attr(($active_tab === $tab) ? 'page' : 'false'),
				esc_html($label)
			);
		}
		echo '</nav>';
	}

	private function render_tools_tab(): void {
		if (isset($_GET['llm_markdown_cache_cleared'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Markdown cache cleared.', 'llm-markdown') . '</p></div>';
		}
		?>
		<h2><?php echo esc_html__('Markdown Cache', 'llm-markdown'); ?></h2>
		<p><?php echo esc_html__('Generated Markdown is cached to avoid rendering and converting the same page on every request. Clear it after changing theme templates, navigation, widgets, shortcodes, or other site-wide content that may appear in Markdown output.', 'llm-markdown'); ?></p>
		<form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
			<input type="hidden" name="action" value="llm_markdown_purge_cache" />
			<?php wp_nonce_field('llm_markdown_purge_cache'); ?>
			<?php submit_button(esc_html__('Clear Markdown Cache', 'llm-markdown'), 'secondary', 'submit', false); ?>
		</form>
		<?php
	}

	/**
	 * @return array<string, mixed>
	 */
	private function defaults(): array {
		return [
			'post_types'                 => $this->default_post_types(),
			'document_root_selector'     => 'main, article, #content, #main-content, #app',
			'ignore_selectors'           => 'header, footer, nav, form',
			'respect_noindex'            => 1,
			'include_images'             => 0,
			'discard_early_output'       => 0,
			'enable_content_negotiation' => 0,
			'accept_markdown_wildcards'   => 0,
		];
	}

	/**
	 * @return array<int, string>
	 */
	private function default_post_types(): array {
		$available = array_keys($this->get_available_post_type_objects());
		$defaults  = array_values(array_intersect(['post', 'page'], $available));
		return !empty($defaults) ? $defaults : $available;
	}

	/**
	 * @return array<string, WP_Post_Type>
	 */
	private function get_available_post_type_objects(): array {
		$objects = get_post_types(['public' => true], 'objects');
		unset($objects['attachment']);
		return $objects;
	}
}
