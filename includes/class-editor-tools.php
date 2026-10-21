<?php

declare(strict_types=1);

namespace LLM_Markdown;

use WP_Post;
use WP_Post_Type;

if (!defined('ABSPATH')) {
	exit;
}

final class Editor_Tools {
	private Settings $settings;
	private Plugin $plugin;
	private bool $hooks_registered = false;

	public function __construct(Settings $settings, Plugin $plugin) {
		$this->settings = $settings;
		$this->plugin   = $plugin;
	}

	public function register_hooks(): void {
		if ($this->hooks_registered) {
			return;
		}

		add_action('add_meta_boxes', [$this, 'register_classic_meta_box'], 10, 2);
		add_action('enqueue_block_editor_assets', [$this, 'enqueue_block_editor_assets']);
		add_action('init', [$this, 'register_exclusion_meta'], 20);
		add_action('save_post', [$this, 'save_classic_exclusion'], 10, 2);

		$this->hooks_registered = true;
	}

	public function register_exclusion_meta(): void {
		$post_types = get_post_types(['public' => true], 'objects');

		foreach ($post_types as $post_type) {
			if (!$post_type instanceof WP_Post_Type || 'attachment' === $post_type->name) {
				continue;
			}

			if ($post_type->show_in_rest && !post_type_supports($post_type->name, 'custom-fields')) {
				add_post_type_support($post_type->name, 'custom-fields');
			}

			register_post_meta(
				$post_type->name,
				Plugin::POST_EXCLUSION_META_KEY,
				[
					'type'              => 'boolean',
					'single'            => true,
					'default'           => false,
					'show_in_rest'      => (bool) $post_type->show_in_rest,
					'sanitize_callback' => static function ($value): bool {
						return (bool) rest_sanitize_boolean($value);
					},
					'auth_callback'     => static function ($allowed, $meta_key, $post_id): bool {
						return current_user_can('edit_post', (int) $post_id);
					},
				]
			);
		}
	}

	public function register_classic_meta_box(string $post_type, WP_Post $post): void {
		if (!$this->can_show_for_post($post) || use_block_editor_for_post($post)) {
			return;
		}

		add_meta_box(
			'llm-markdown-editor-tools',
			esc_html__('Markdown', 'llm-markdown'),
			[$this, 'render_classic_meta_box'],
			$post_type,
			'side',
			'default'
		);

		$this->enqueue_shared_assets();
		wp_enqueue_script(
			'llm-markdown-classic-editor-tools',
			LLMMD_URL . 'assets/editor-tools-classic.js',
			[],
			$this->asset_version('assets/editor-tools-classic.js'),
			true
		);

		wp_localize_script(
			'llm-markdown-classic-editor-tools',
			'llmMarkdownClassicEditorTools',
			[
				'copying'    => __('Copying…', 'llm-markdown'),
				'copied'     => __('Markdown copied.', 'llm-markdown'),
				'copyFailed' => __('Markdown could not be copied.', 'llm-markdown'),
				'httpError'  => __('Markdown returned HTTP', 'llm-markdown'),
				'loading'    => __('Loading Markdown…', 'llm-markdown'),
				'view'       => __('View Markdown', 'llm-markdown'),
				'hide'       => __('Hide Markdown', 'llm-markdown'),
			]
		);
	}

	public function render_classic_meta_box(WP_Post $post): void {
		$eligibility = $this->plugin->get_post_eligibility($post);
		$markdown_url = $this->plugin->get_markdown_url($post);
		$available = $eligibility['eligible'] && '' !== $markdown_url;
		$excluded = $this->plugin->is_post_excluded($post);
		wp_nonce_field('llm_markdown_post_exclusion_' . $post->ID, 'llm_markdown_post_exclusion_nonce');
		?>
		<div class="llmmd-classic-tools" data-markdown-url="<?php echo esc_url($markdown_url); ?>">
			<p class="llmmd-status llmmd-status--<?php echo $available ? 'available' : 'unavailable'; ?>">
				<span class="llmmd-status__dot" aria-hidden="true"></span>
				<strong><?php echo $available ? esc_html__('Available', 'llm-markdown') : esc_html__('Unavailable', 'llm-markdown'); ?></strong>
			</p>
			<p><?php echo esc_html($eligibility['message']); ?></p>
			<p class="llmmd-actions">
				<button type="button" class="button button-primary llmmd-copy-markdown" <?php disabled(!$available); ?>>
					<?php echo esc_html__('Copy Markdown', 'llm-markdown'); ?>
				</button>
				<?php if ($available) : ?>
					<button type="button" class="button llmmd-view-markdown" aria-expanded="false" aria-controls="llmmd-preview-<?php echo esc_attr((string) $post->ID); ?>">
						<?php echo esc_html__('View Markdown', 'llm-markdown'); ?>
					</button>
					<a class="button" href="<?php echo esc_url($markdown_url); ?>" target="_blank" rel="noopener noreferrer">
						<?php echo esc_html__('Open Markdown URL', 'llm-markdown'); ?>
					</a>
				<?php else : ?>
					<button type="button" class="button" disabled><?php echo esc_html__('View Markdown', 'llm-markdown'); ?></button>
					<button type="button" class="button" disabled><?php echo esc_html__('Open Markdown URL', 'llm-markdown'); ?></button>
				<?php endif; ?>
			</p>
			<p class="description llmmd-copy-feedback" aria-live="polite"></p>
			<div id="llmmd-preview-<?php echo esc_attr((string) $post->ID); ?>" class="llmmd-classic-preview" hidden>
				<p><?php echo esc_html__('Last saved public Markdown', 'llm-markdown'); ?></p>
				<pre tabindex="0"></pre>
			</div>
			<p class="description"><?php echo esc_html__('Markdown reflects the last saved public version.', 'llm-markdown'); ?></p>
			<hr />
			<label>
				<input type="checkbox" name="llm_markdown_disabled" value="1" <?php checked($excluded); ?> />
				<?php echo esc_html__('Disable Markdown for this content', 'llm-markdown'); ?>
			</label>
			<p class="description"><?php echo esc_html__('The normal HTML page remains available.', 'llm-markdown'); ?></p>
		</div>
		<?php
	}

	public function enqueue_block_editor_assets(): void {
		$post = get_post();
		if (!$post instanceof WP_Post || !$this->can_show_for_post($post)) {
			return;
		}

		$this->enqueue_shared_assets();

		wp_enqueue_script(
			'llm-markdown-block-editor-tools',
			LLMMD_URL . 'assets/editor-tools-block.js',
			['wp-components', 'wp-data', 'wp-editor', 'wp-element', 'wp-i18n', 'wp-plugins'],
			$this->asset_version('assets/editor-tools-block.js'),
			true
		);

		$eligibility = $this->plugin->get_post_eligibility($post, true);
		$canonical = get_permalink($post);
		$canonical = is_string($canonical) ? $canonical : '';

		wp_localize_script(
			'llm-markdown-block-editor-tools',
			'llmMarkdownEditorTools',
			[
				'eligible'     => $eligibility['eligible'],
				'code'         => $eligibility['code'],
				'message'      => $eligibility['message'],
				'canonicalUrl' => $canonical,
				'markdownUrl'  => $this->plugin->get_markdown_url($post),
				'excluded'     => $this->plugin->is_post_excluded($post),
				'metaKey'      => Plugin::POST_EXCLUSION_META_KEY,
			]
		);
	}

	public function save_classic_exclusion(int $post_id, WP_Post $post): void {
		if (
			wp_is_post_autosave($post_id)
			|| wp_is_post_revision($post_id)
			|| !isset($_POST['llm_markdown_post_exclusion_nonce'])
			|| !wp_verify_nonce(
				sanitize_text_field(wp_unslash($_POST['llm_markdown_post_exclusion_nonce'])),
				'llm_markdown_post_exclusion_' . $post_id
			)
			|| !current_user_can('edit_post', $post_id)
		) {
			return;
		}

		$post_type = get_post_type_object($post->post_type);
		if (!$post_type instanceof WP_Post_Type || !$post_type->public || 'attachment' === $post_type->name) {
			return;
		}

		if (isset($_POST['llm_markdown_disabled'])) {
			update_post_meta($post_id, Plugin::POST_EXCLUSION_META_KEY, '1');
		} else {
			delete_post_meta($post_id, Plugin::POST_EXCLUSION_META_KEY);
		}
	}

	private function can_show_for_post(WP_Post $post): bool {
		if (!current_user_can('edit_post', $post->ID)) {
			return false;
		}

		$post_type = get_post_type_object($post->post_type);
		return $post_type instanceof WP_Post_Type
			&& $post_type->public
			&& 'attachment' !== $post_type->name;
	}

	private function enqueue_shared_assets(): void {
		wp_enqueue_style(
			'llm-markdown-editor-tools',
			LLMMD_URL . 'assets/editor-tools.css',
			[],
			$this->asset_version('assets/editor-tools.css')
		);
	}

	/**
	 * @return string|int
	 */
	private function asset_version(string $relative_path) {
		$path = LLMMD_PATH . $relative_path;
		$modified = file_exists($path) ? filemtime($path) : false;
		return false !== $modified ? $modified : '1.1.0';
	}
}
