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

		$out = array_values(array_unique($out));
		return !empty($out) ? $out : $this->default_post_types();
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
			'llm_markdown_main',
			esc_html__('Markdown Output', 'llm-markdown'),
			static function (): void {
				echo '<p>' . esc_html__('Configure which post types expose .md routes, and how the plugin extracts content from rendered HTML.', 'llm-markdown') . '</p>';
			},
			'llm-markdown'
		);

		add_settings_field(
			'post_types',
			esc_html__('Enabled Post Types', 'llm-markdown'),
			[$this, 'render_post_types_field'],
			'llm-markdown',
			'llm_markdown_main'
		);

		add_settings_field(
			'document_root_selector',
			esc_html__('Document Root Selector', 'llm-markdown'),
			[$this, 'render_document_root_selector_field'],
			'llm-markdown',
			'llm_markdown_main'
		);

		add_settings_field(
			'ignore_selectors',
			esc_html__('Ignore Selectors', 'llm-markdown'),
			[$this, 'render_ignore_selectors_field'],
			'llm-markdown',
			'llm_markdown_main'
		);

		add_settings_field(
			'respect_noindex',
			esc_html__('Respect Noindex', 'llm-markdown'),
			[$this, 'render_respect_noindex_field'],
			'llm-markdown',
			'llm_markdown_main'
		);
	}

	/**
	 * @param mixed $raw
	 * @return array<string, mixed>
	 */
	public function sanitize_settings($raw): array {
		$defaults  = $this->defaults();
		$available = array_keys($this->get_available_post_type_objects());

		if (!is_array($raw)) {
			return $defaults;
		}

		$out = $defaults;

		$selected = $raw['post_types'] ?? [];
		$clean    = [];

		if (is_array($selected)) {
			foreach ($selected as $pt) {
				$pt = sanitize_key((string) $pt);
				if ('' !== $pt && in_array($pt, $available, true)) {
					$clean[] = $pt;
				}
			}
		}

		$clean             = array_values(array_unique($clean));
		$out['post_types'] = !empty($clean) ? $clean : $this->default_post_types();

		$root = (string) ($raw['document_root_selector'] ?? $defaults['document_root_selector']);
		$root = sanitize_text_field(wp_unslash($root));
		$root = trim($root);
		$root = substr($root, 0, 500);

		$out['document_root_selector'] = ('' === $root)
			? (string) $defaults['document_root_selector']
			: $root;

		$ignore = (string) ($raw['ignore_selectors'] ?? $defaults['ignore_selectors']);
		$ignore = sanitize_textarea_field(wp_unslash($ignore));
		$ignore = trim($ignore);
		$ignore = substr($ignore, 0, 2000);

		$out['ignore_selectors'] = $ignore;

		// Checkbox: when unchecked, it may be absent from $_POST entirely.
		$out['respect_noindex'] = isset($raw['respect_noindex']) ? 1 : 0;

		return $out;
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
	}

	public function render_document_root_selector_field(): void {
		$options = $this->get_options();

		printf(
			'<input type="text" class="regular-text" name="%1$s[document_root_selector]" value="%2$s" />',
			esc_attr(self::OPTION_NAME),
			esc_attr((string) ($options['document_root_selector'] ?? (string) $this->defaults()['document_root_selector']))
		);

		echo '<p class="description">';
		echo esc_html__('CSS selector used to locate the main content container in the rendered page HTML (e.g. main, article, #content). You can provide multiple selectors separated by commas; the first match is used.', 'llm-markdown');
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
		echo esc_html__('Enter selectors separated by commas and/or new lines.', 'llm-markdown');
		echo '</p>';
	}

	public function render_respect_noindex_field(): void {
		$options = $this->get_options();

		printf(
			'<label><input type="checkbox" name="%1$s[respect_noindex]" value="1" %2$s /> %3$s</label>',
			esc_attr(self::OPTION_NAME),
			checked(!empty($options['respect_noindex']), true, false),
			esc_html__('Do not expose Markdown for content marked noindex (Yoast).', 'llm-markdown')
		);
	}

	public function render_settings_page(): void {
		if (!current_user_can('manage_options')) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html__('Markdown Output Settings', 'llm-markdown'); ?></h1>
			<form action="options.php" method="post">
				<?php
				settings_fields('llm_markdown_group');
				do_settings_sections('llm-markdown');
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * @return array<string, mixed>
	 */
	private function defaults(): array {
		return [
			'post_types'             => $this->default_post_types(),
			'document_root_selector' => 'main, article, #content, #main-content, #app',
			'ignore_selectors'       => 'header, footer, nav, form',
			'respect_noindex'        => 1,
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
