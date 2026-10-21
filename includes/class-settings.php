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
		add_action('admin_post_llm_markdown_test_selectors', [$this, 'handle_test_selectors']);

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

	public function should_show_editor_tools(): bool {
		$options = $this->get_options();
		return !empty($options['show_editor_tools']);
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

		add_settings_field(
			'show_editor_tools',
			esc_html__('Editor Tools', 'llm-markdown'),
			[$this, 'render_show_editor_tools_field'],
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
			$out['show_editor_tools']     = isset($raw['show_editor_tools']) ? 1 : 0;
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

	public function render_show_editor_tools_field(): void {
		$options = $this->get_options();

		printf(
			'<label><input type="checkbox" name="%1$s[show_editor_tools]" value="1" %2$s /> %3$s</label>',
			esc_attr(self::OPTION_NAME),
			checked(!empty($options['show_editor_tools']), true, false),
			esc_html__('Show Markdown status and actions in supported post editors.', 'llm-markdown')
		);

		echo '<p class="description">';
		echo esc_html__('Adds a Markdown panel to the block editor and a compact Markdown box to the Classic Editor. Disable this after setup if editors no longer need these tools.', 'llm-markdown');
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
			esc_html__('Do not publish Markdown for content marked noindex.', 'llm-markdown')
		);

		echo '<p class="description">';
		echo esc_html__('Honors WordPress search visibility and effective noindex settings from Yoast SEO, Rank Math, and All in One SEO. Keeps the Markdown route and discovery links unavailable. Enabled by default.', 'llm-markdown');
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
				<?php if ('dom' === $active_tab) : ?>
					<?php $this->render_selector_test_tool(); ?>
				<?php endif; ?>
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

	public function handle_test_selectors(): void {
		if (!current_user_can('manage_options')) {
			wp_die(esc_html__('You are not allowed to test Markdown extraction.', 'llm-markdown'));
		}

		check_admin_referer('llm_markdown_test_selectors');

		$raw_url = isset($_POST['llm_markdown_test_url'])
			? sanitize_text_field(wp_unslash($_POST['llm_markdown_test_url']))
			: '';
		$url = esc_url_raw($raw_url, ['http', 'https']);

		if ('' === $url || !$this->is_same_origin_url($url)) {
			$result = [
				'success' => false,
				'url'     => $raw_url,
				'message' => __('Enter a valid URL from this site.', 'llm-markdown'),
			];
		} else {
			$renderer     = new Renderer($this);
			$result       = $renderer->diagnose_extraction($url);
			$result['url'] = $url;
		}

		set_transient($this->selector_test_transient_key(), $result, MINUTE_IN_SECONDS);

		$redirect = add_query_arg(
			[
				'page'                       => 'llm-markdown',
				'tab'                        => 'dom',
				'llm_markdown_selector_test' => '1',
			],
			admin_url('options-general.php')
		);

		wp_safe_redirect($redirect);
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

	private function render_selector_test_tool(): void {
		$test_result = null;
		if (isset($_GET['llm_markdown_selector_test'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$stored = get_transient($this->selector_test_transient_key());
			if (is_array($stored)) {
				$test_result = $stored;
			}
			delete_transient($this->selector_test_transient_key());
		}
		?>

		<hr />
		<h2><?php echo esc_html__('Content Extraction Test', 'llm-markdown'); ?></h2>
		<p><?php echo esc_html__('Test the configured main content selectors against a rendered page. Results apply only to the URL tested because themes can use different templates for different content types.', 'llm-markdown'); ?></p>
		<form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
			<input type="hidden" name="action" value="llm_markdown_test_selectors" />
			<?php wp_nonce_field('llm_markdown_test_selectors'); ?>
			<label for="llm-markdown-test-url" class="screen-reader-text"><?php echo esc_html__('URL to test', 'llm-markdown'); ?></label>
			<input
				type="url"
				id="llm-markdown-test-url"
				name="llm_markdown_test_url"
				class="regular-text code"
				value="<?php echo esc_attr(is_array($test_result) && isset($test_result['url']) ? (string) $test_result['url'] : $this->default_selector_test_url()); ?>"
				required
			/>
			<?php submit_button(esc_html__('Test Extraction', 'llm-markdown'), 'secondary', 'submit', false); ?>
		</form>
		<?php
		if (is_array($test_result)) {
			$this->render_selector_test_result($test_result);
		}
	}

	/**
	 * @param array<string, mixed> $result
	 */
	private function render_selector_test_result(array $result): void {
		if (empty($result['success'])) {
			$message = isset($result['message']) ? (string) $result['message'] : __('The extraction test failed.', 'llm-markdown');
			echo '<div class="notice notice-error inline"><p>' . esc_html($message) . '</p></div>';
			return;
		}

		$fallback = isset($result['fallback']) ? (string) $result['fallback'] : '';
		if ('' !== $fallback) {
			/* translators: %s: fallback HTML element name. */
			$message = sprintf(__('None of the configured selectors matched. Extraction fell back to %s.', 'llm-markdown'), $fallback);
			printf(
				'<div class="notice notice-warning inline"><p>%s</p></div>',
				esc_html($message)
			);
		} else {
			echo '<div class="notice notice-success inline"><p>' . esc_html__('A configured content selector matched.', 'llm-markdown') . '</p></div>';
		}

		$matched = isset($result['matched_selector']) ? (string) $result['matched_selector'] : '';
		$element = isset($result['element']) ? (string) $result['element'] : '';
		$count   = isset($result['match_count']) ? (int) $result['match_count'] : 0;
		?>
		<table class="widefat striped">
			<tbody>
				<tr>
					<th scope="row"><?php echo esc_html__('Configured selectors', 'llm-markdown'); ?></th>
					<td><code><?php echo esc_html((string) ($result['configured'] ?? '')); ?></code></td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__('Selected selector', 'llm-markdown'); ?></th>
					<td><code><?php echo esc_html($matched); ?></code></td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__('Selected element', 'llm-markdown'); ?></th>
					<td><code><?php echo esc_html($element); ?></code></td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__('Matches for selected selector', 'llm-markdown'); ?></th>
					<td><?php echo esc_html(number_format_i18n($count)); ?></td>
				</tr>
			</tbody>
		</table>
		<?php if (!empty($result['selectors']) && is_array($result['selectors'])) : ?>
			<h3><?php echo esc_html__('Configured Selector Results', 'llm-markdown'); ?></h3>
			<table class="widefat striped">
				<thead>
					<tr>
						<th scope="col"><?php echo esc_html__('Selector', 'llm-markdown'); ?></th>
						<th scope="col"><?php echo esc_html__('Result', 'llm-markdown'); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($result['selectors'] as $selector_result) : ?>
						<?php
						if (!is_array($selector_result)) {
							continue;
						}
						$selector = isset($selector_result['selector']) ? (string) $selector_result['selector'] : '';
						if (empty($selector_result['valid'])) {
							$status = __('Unsupported selector', 'llm-markdown');
						} elseif (!empty($selector_result['selected'])) {
							$status = __('Selected', 'llm-markdown');
						} else {
							$selector_matches = isset($selector_result['matches']) ? (int) $selector_result['matches'] : 0;
							if (0 === $selector_matches) {
								$status = __('No match', 'llm-markdown');
							} else {
								/* translators: %s: number of HTML elements matching the selector. */
								$format = _n('%s match; lower priority', '%s matches; lower priority', $selector_matches, 'llm-markdown');
								$status = sprintf($format, number_format_i18n($selector_matches));
							}
						}
						?>
						<tr>
							<td><code><?php echo esc_html($selector); ?></code></td>
							<td><?php echo esc_html($status); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<h3><?php echo esc_html__('Markdown Preview', 'llm-markdown'); ?></h3>
		<p class="description"><?php echo esc_html__('The first 500 characters of the generated Markdown body, after excluded content is removed. Front matter is not included.', 'llm-markdown'); ?></p>
		<?php if ('' !== (string) ($result['markdown_preview'] ?? '')) : ?>
			<pre class="widefat" style="max-width: 800px; padding: 12px; white-space: pre-wrap; overflow-wrap: anywhere;"><?php echo esc_html((string) $result['markdown_preview']); ?></pre>
		<?php else : ?>
			<p><?php echo esc_html__('No Markdown content remains after exclusions.', 'llm-markdown'); ?></p>
		<?php endif; ?>
		<?php
	}

	private function selector_test_transient_key(): string {
		return 'llmmd_selector_test_' . get_current_user_id();
	}

	private function default_selector_test_url(): string {
		$post_ids = get_posts(
			[
				'post_type'      => $this->get_enabled_post_types(),
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'fields'         => 'ids',
			]
		);

		if (!empty($post_ids)) {
			$permalink = get_permalink((int) $post_ids[0]);
			if (is_string($permalink) && '' !== $permalink) {
				return $permalink;
			}
		}

		return home_url('/');
	}

	private function is_same_origin_url(string $url): bool {
		$tested = wp_parse_url($url);
		$home   = wp_parse_url(home_url('/'));
		if (!is_array($tested) || !is_array($home)) {
			return false;
		}

		$tested_scheme = strtolower((string) ($tested['scheme'] ?? ''));
		$home_scheme   = strtolower((string) ($home['scheme'] ?? ''));
		$tested_host   = strtolower((string) ($tested['host'] ?? ''));
		$home_host     = strtolower((string) ($home['host'] ?? ''));
		$tested_port   = isset($tested['port']) ? (int) $tested['port'] : ('https' === $tested_scheme ? 443 : 80);
		$home_port     = isset($home['port']) ? (int) $home['port'] : ('https' === $home_scheme ? 443 : 80);

		return in_array($tested_scheme, ['http', 'https'], true)
			&& $tested_scheme === $home_scheme
			&& '' !== $tested_host
			&& $tested_host === $home_host
			&& $tested_port === $home_port;
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
			'show_editor_tools'           => 1,
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
