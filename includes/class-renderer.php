<?php

declare(strict_types=1);

namespace LLM_Markdown;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use WP_Post;

if (!defined('ABSPATH')) {
	exit;
}

final class Renderer {
	private const CACHE_TTL = 12 * HOUR_IN_SECONDS;

	private Settings $settings;

	public function __construct(Settings $settings) {
		$this->settings = $settings;
	}

	public function render_post(WP_Post $post, string $canonical_url, string $markdown_url): string {
		$cache_key = $this->cache_key($post);

		if (!is_user_logged_in()) {
			$cached = get_transient($cache_key);
			if (is_string($cached) && '' !== $cached) {
				return $cached;
			}
		}

		$html     = $this->fetch_rendered_html($canonical_url);
		$doc_html = $this->extract_document_html($html);
		$md_body  = $this->html_to_markdown($doc_html);

		$front_matter = $this->build_front_matter($post, $canonical_url, $markdown_url);
		$front_matter = (array) apply_filters('llm_markdown_front_matter', $front_matter, $post);

		$document = "---\n" . $this->yaml($front_matter) . "---\n\n" . trim($md_body) . "\n";

		if (!is_user_logged_in()) {
			set_transient($cache_key, $document, self::CACHE_TTL);
		}

		return $document;
	}

	private function cache_key(WP_Post $post): string {
		$options_hash = md5((string) wp_json_encode([
			'root'   => $this->settings->get_document_root_selector(),
			'ignore' => $this->settings->get_ignore_selectors(),
			'v'      => 1,
		]));

		$parts = [
			(string) get_current_blog_id(),
			(string) $post->ID,
			(string) $post->post_modified_gmt,
			(string) get_locale(),
			$options_hash,
		];

		return 'llm_markdown_' . md5(implode('|', $parts));
	}

	private function fetch_rendered_html(string $canonical_url): string {
		$host      = strtolower((string) wp_parse_url($canonical_url, PHP_URL_HOST));
		$sslverify = true;

		// PHP 7.4-compatible ends-with.
		if (is_string($host) && '' !== $host && '.lndo.site' === substr($host, -9)) {
			$sslverify = false;
		}

		$response = wp_safe_remote_get($canonical_url, [
			'timeout'            => 15,
			'redirection'        => 3,
			'sslverify'          => $sslverify,
			'reject_unsafe_urls' => true,
			'headers'            => [
				'Accept'               => 'text/html,application/xhtml+xml',
				'X-LLMMD-Render-Source' => '1',
			],
			'user-agent'         => 'LLMMD/1.0.0',
		]);

		if (is_wp_error($response)) {
			return '';
		}

		$code = (int) wp_remote_retrieve_response_code($response);
		if ($code < 200 || $code >= 300) {
			return '';
		}

		$ctype = (string) wp_remote_retrieve_header($response, 'content-type');
		if ('' !== $ctype && false === stripos($ctype, 'text/html')) {
			// Avoid caching/processing non-HTML responses.
			return '';
		}

		return (string) wp_remote_retrieve_body($response);
	}

	private function extract_document_html(string $html): string {
		if ('' === trim($html) || !class_exists(DOMDocument::class)) {
			return '';
		}

		$dom = new DOMDocument('1.0', 'UTF-8');

		$flags = 0;
		if (defined('LIBXML_HTML_NOIMPLIED')) {
			$flags |= LIBXML_HTML_NOIMPLIED;
		}
		if (defined('LIBXML_HTML_NODEFDTD')) {
			$flags |= LIBXML_HTML_NODEFDTD;
		}

		$prev   = libxml_use_internal_errors(true);
		$loaded = $dom->loadHTML('<?xml encoding="UTF-8">' . $html, $flags);
		libxml_clear_errors();
		libxml_use_internal_errors($prev);

		if (!$loaded) {
			return '';
		}

		$xpath = new DOMXPath($dom);

		$root = $this->select_first($xpath, (string) $this->settings->get_document_root_selector());
		if (!$root instanceof DOMNode) {
			$root = $this->select_first($xpath, 'main');
		}
		if (!$root instanceof DOMNode) {
			$root = $this->select_first($xpath, 'article');
		}
		if (!$root instanceof DOMNode) {
			$root = $xpath->query('//body')->item(0);
		}
		if (!$root instanceof DOMNode) {
			return '';
		}

		// Always ignore these.
		$this->remove_by_selectors($xpath, $root, 'script,style,noscript,template');

		// User ignore selectors.
		$ignore = (string) $this->settings->get_ignore_selectors();
		if ('' !== $ignore) {
			$this->remove_by_selectors($xpath, $root, $ignore);
		}

		return trim($this->inner_html($root));
	}

	private function select_first(DOMXPath $xpath, string $css_selector): ?DOMNode {
		// Support a simple CSV list: ".entry-content, main, article".
		$selectors = preg_split('/[,\r\n]+/', $css_selector, -1, PREG_SPLIT_NO_EMPTY) ?: [];
		$selectors = array_map('trim', $selectors);

		foreach ($selectors as $sel) {
			if ('' === $sel) {
				continue;
			}

			$xpath_expr = $this->css_to_xpath($sel);
			if ('' === $xpath_expr) {
				continue;
			}

			$list = $xpath->query($xpath_expr);
			if (false === $list || 0 === $list->length) {
				continue;
			}

			$node = $list->item(0);
			if ($node instanceof DOMNode) {
				return $node;
			}
		}

		return null;
	}

	private function remove_by_selectors(DOMXPath $xpath, DOMNode $context, string $selectors_csv): void {
		$selectors = preg_split('/[,\r\n]+/', $selectors_csv, -1, PREG_SPLIT_NO_EMPTY) ?: [];
		$selectors = array_map('trim', $selectors);
		$selectors = array_filter($selectors, static function ($s): bool {
			return '' !== $s;
		});

		if (empty($selectors)) {
			return;
		}

		$to_remove = [];

		foreach ($selectors as $sel) {
			$expr = $this->css_to_xpath($sel, true);
			if ('' === $expr) {
				continue;
			}

			$nodes = $xpath->query($expr, $context);
			if (false === $nodes) {
				continue;
			}

			foreach ($nodes as $n) {
				if ($n instanceof DOMNode) {
					$to_remove[] = $n;
				}
			}
		}

		foreach ($to_remove as $n) {
			if ($n->parentNode instanceof DOMNode) {
				$n->parentNode->removeChild($n);
			}
		}
	}

	/**
	 * Very small CSS selector support:
	 * - #id
	 * - tag
	 * - .class
	 * - tag.class
	 * - tag#id
	 *
	 * @param bool $relative When true, returns ".//" scoped expressions
	 */
	private function css_to_xpath(string $selector, bool $relative = false): string {
		$selector = trim($selector);
		if ('' === $selector) {
			return '';
		}

		$scope = $relative ? './/' : '//';

		// Normalize whitespace around '>'
		$selector = preg_replace('/\s*>\s*/u', ' > ', $selector);
		$selector = preg_replace('/\s+/u', ' ', (string) $selector);
		$selector = trim((string) $selector);

		$tokens = explode(' ', $selector);
		if (empty($tokens)) {
			return '';
		}

		$xpath = '';
		$axis  = $scope; // first selector uses scope

		foreach ($tokens as $tok) {
			if ('' === $tok) {
				continue;
			}

			if ('>' === $tok) {
				$axis = '/';
				continue;
			}

			$step = $this->css_simple_to_xpath_step($tok);
			if ('' === $step) {
				return '';
			}

			$xpath .= ('' === $xpath ? $axis : $axis) . $step;

			// default combinator between subsequent selectors is descendant
			$axis = '//';
		}

		return $xpath;
	}

	private function css_simple_to_xpath_step(string $simple): string {
		$simple = trim($simple);
		if ('' === $simple) {
			return '';
		}

		$tag     = '*';
		$id      = '';
		$classes = [];

		// Split tag from the rest: "tag#id.a.b" or "#id.a" or ".a.b"
		if (preg_match('/^([a-z0-9_-]+)(.*)$/i', $simple, $m)) {
			// If it starts with "." or "#", tag is implied "*"
			if ('.' !== $simple[0] && '#' !== $simple[0]) {
				$tag = strtolower((string) $m[1]);
				$rest = (string) ($m[2] ?? '');
			} else {
				$rest = $simple;
			}
		} else {
			$rest = $simple;
		}

		// Extract id
		if (preg_match('/#([a-zA-Z0-9_-]+)/', $rest, $m)) {
			$id = (string) $m[1];
		}

		// Extract classes (supports .a.b.c)
		if (preg_match_all('/\.([a-zA-Z0-9_-]+)/', $rest, $m)) {
			$classes = array_values(array_unique(array_map('strval', $m[1] ?? [])));
		}

		$pred = [];
		if ('' !== $id) {
			$pred[] = "@id='{$id}'";
		}
		foreach ($classes as $cls) {
			$pred[] = "contains(concat(' ', normalize-space(@class), ' '), ' {$cls} ')";
		}

		$step = $tag;
		if (!empty($pred)) {
			$step .= '[' . implode(' and ', $pred) . ']';
		}

		return $step;
	}

	private function inner_html(DOMNode $node): string {
		if (!$node->ownerDocument instanceof DOMDocument) {
			return '';
		}

		$out = '';
		foreach ($node->childNodes as $child) {
			$out .= (string) $node->ownerDocument->saveHTML($child);
		}

		return $out;
	}

	/**
	 * Minimal HTML -> Markdown conversion.
	 */
	private function html_to_markdown(string $html): string {
		if ('' === trim($html)) {
			return '';
		}

		if (!class_exists(DOMDocument::class)) {
			return $this->plain_text($html);
		}

		$dom = new DOMDocument('1.0', 'UTF-8');

		$flags = 0;
		if (defined('LIBXML_HTML_NOIMPLIED')) {
			$flags |= LIBXML_HTML_NOIMPLIED;
		}
		if (defined('LIBXML_HTML_NODEFDTD')) {
			$flags |= LIBXML_HTML_NODEFDTD;
		}

		$wrapped = '<!DOCTYPE html><html><body>' . $html . '</body></html>';

		$prev = libxml_use_internal_errors(true);
		$loaded = $dom->loadHTML('<?xml encoding="UTF-8">' . $wrapped, $flags);
		libxml_clear_errors();
		libxml_use_internal_errors($prev);

		if (!$loaded) {
			return $this->plain_text($html);
		}

		$body = $dom->getElementsByTagName('body')->item(0);
		if (!$body instanceof DOMNode) {
			return $this->plain_text($html);
		}

		$md = $this->convert_children($body, 0);
		$md = preg_replace("/\n{3,}/", "\n\n", (string) $md);
		$md = $this->cleanup_markdown_lines((string) $md);

		return trim((string) $md);
	}

	private function cleanup_markdown_lines(string $md): string {
		$md = str_replace(["\r\n", "\r"], "\n", $md);

		$lines = explode("\n", $md);
		foreach ($lines as $i => $line) {
			// Remove leading whitespace before headings.
			$line = preg_replace('/^\s+(#{1,6}\s+)/u', '$1', (string) $line);

			// Remove accidental leading whitespace before list markers ONLY at shallow depths.
			// (Keeps nested list indentation intact: your nested lists use two-space repeats.)
			$line = preg_replace('/^(?:\s)([-*+]\s|\d+\.\s)/u', '$1', (string) $line);

			$lines[$i] = $line;
		}

		$md = implode("\n", $lines);
		$md = preg_replace("/\n{3,}/", "\n\n", (string) $md);

		return trim((string) $md);
	}

	private function convert_children(DOMNode $node, int $list_depth): string {
		$out = '';

		foreach ($node->childNodes as $child) {
			$out .= $this->convert_node($child, $list_depth);
		}

		return $out;
	}

	private function text_node_is_block_padding(DOMNode $node): bool {
		$parent = $node->parentNode;
		if (!$parent instanceof DOMNode || XML_ELEMENT_NODE !== $parent->nodeType) {
			return false;
		}

		$blockish = [
			'body','div','section','article','main','header','footer','aside',
			'ul','ol','li','table','thead','tbody','tr','td','th',
		];

		$parent_tag = strtolower((string) $parent->nodeName);
		if (!in_array($parent_tag, $blockish, true)) {
			return false;
		}

		// If either adjacent sibling is a block element, this whitespace is just indentation.
		$prev = $node->previousSibling;
		while ($prev instanceof DOMNode && XML_TEXT_NODE === $prev->nodeType && '' === trim((string) $prev->nodeValue)) {
			$prev = $prev->previousSibling;
		}

		$next = $node->nextSibling;
		while ($next instanceof DOMNode && XML_TEXT_NODE === $next->nodeType && '' === trim((string) $next->nodeValue)) {
			$next = $next->nextSibling;
		}

		if ($prev instanceof DOMNode && XML_ELEMENT_NODE === $prev->nodeType) {
			return true;
		}
		if ($next instanceof DOMNode && XML_ELEMENT_NODE === $next->nodeType) {
			return true;
		}

		return false;
	}

	private function convert_node(DOMNode $node, int $list_depth): string {
		if (XML_TEXT_NODE === $node->nodeType) {
			$text = (string) $node->nodeValue;

			// Drop pure formatting whitespace between block nodes.
			if ('' === trim($text) && $this->text_node_is_block_padding($node)) {
				return '';
			}

			return $this->normalize_text($text);
		}

		if (XML_ELEMENT_NODE !== $node->nodeType) {
			return '';
		}

		$tag = strtolower($node->nodeName);

		switch ($tag) {
			case 'script':
			case 'style':
			case 'noscript':
			case 'template':
			case 'iframe':
				return '';

			case 'h1':
			case 'h2':
			case 'h3':
			case 'h4':
			case 'h5':
			case 'h6':
				$level = (int) substr($tag, 1);
				$text  = trim($this->convert_children($node, $list_depth));
				$text  = preg_replace('/\s+/u', ' ', (string) $text);
				return ('' === $text) ? '' : str_repeat('#', $level) . ' ' . $text . "\n\n";

			case 'p':
			case 'div':
			case 'section':
			case 'article':
			case 'main':
				$text = trim($this->convert_children($node, $list_depth));
				return ('' === $text) ? '' : $text . "\n\n";

			case 'br':
				return "  \n";

			case 'strong':
			case 'b':
				$text = trim($this->convert_children($node, $list_depth));
				return ('' === $text) ? '' : '**' . $text . '**';

			case 'em':
			case 'i':
				$text = trim($this->convert_children($node, $list_depth));
				return ('' === $text) ? '' : '*' . $text . '*';

			case 'code':
				if ($node->parentNode instanceof DOMNode && 'pre' === strtolower($node->parentNode->nodeName)) {
					return $this->normalize_code((string) $node->textContent);
				}
				$text = trim($this->convert_children($node, $list_depth));
				$text = str_replace('`', '\`', $text);
				return ('' === $text) ? '' : '`' . $text . '`';

			case 'pre':
				$text = $this->normalize_code((string) $node->textContent);
				return ('' === $text) ? '' : "```\n" . $text . "\n```\n\n";

			case 'a':
				$href = ($node instanceof DOMElement) ? trim((string) $node->getAttribute('href')) : '';

				// Flatten all nested content to a single line.
				$text = $this->normalize_inline_text((string) $node->textContent);

				if ('' === $href) {
					return $text;
				}
				if ('' === $text) {
					$text = $href;
				}

				return '[' . $text . '](' . $href . ')' . "\n";

			case 'ul':
				return $this->convert_list($node, false, $list_depth);

			case 'ol':
				return $this->convert_list($node, true, $list_depth);

			case 'blockquote':
				$text = trim($this->convert_children($node, $list_depth));
				if ('' === $text) {
					return '';
				}
				$lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
				$lines = array_map(static fn($l) => '> ' . rtrim((string) $l), $lines);
				return implode("\n", $lines) . "\n\n";

			default:
				return $this->convert_children($node, $list_depth);
		}
	}

	private function convert_list(DOMNode $node, bool $ordered, int $depth): string {
		$out   = '';
		$index = 1;

		foreach ($node->childNodes as $child) {
			if (XML_ELEMENT_NODE !== $child->nodeType || 'li' !== strtolower($child->nodeName)) {
				continue;
			}

			$out .= $this->convert_list_item($child, $ordered, $index, $depth);
			$index++;
		}

		return ('' === $out) ? '' : rtrim($out, "\n") . "\n\n";
	}

	private function convert_list_item(DOMNode $node, bool $ordered, int $index, int $depth): string {
		$inline = '';
		$nested = '';

		foreach ($node->childNodes as $child) {
			if (XML_ELEMENT_NODE === $child->nodeType) {
				$t = strtolower($child->nodeName);
				if ('ul' === $t || 'ol' === $t) {
					$nested .= $this->convert_list($child, 'ol' === $t, $depth + 1);
					continue;
				}
			}
			$inline .= $this->convert_node($child, $depth);
		}

		$inline = preg_replace('/\s+/u', ' ', trim((string) $inline));
		$pad    = str_repeat('  ', $depth);
		$lead   = $ordered ? ($index . '. ') : '- ';

		$line = $pad . $lead . $inline . "\n";
		if ('' !== $nested) {
			$line .= $nested;
		}

		return $line;
	}

	private function normalize_inline_text(string $text): string {
		$text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$text = str_replace(["\r\n", "\r", "\n"], ' ', $text);
		$text = preg_replace('/\s+/u', ' ', (string) $text);
		return trim((string) $text);
	}

	private function normalize_text(string $text): string {
		$text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$text = str_replace(["\r\n", "\r", "\n"], ' ', $text);
		$text = preg_replace('/\s+/u', ' ', $text);
		return (string) $text;
	}

	private function normalize_code(string $text): string {
		$text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$text = str_replace(["\r\n", "\r"], "\n", $text);
		return trim($text, "\n");
	}

	private function plain_text(string $html): string {
		$text = wp_strip_all_tags($html, true);
		$text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$text = str_replace(["\r\n", "\r"], "\n", $text);
		$text = preg_replace('/\n{3,}/', "\n\n", (string) $text);
		return trim((string) $text);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function build_front_matter(WP_Post $post, string $canonical_url, string $markdown_url): array {
		$data = [
			'title'        => html_entity_decode((string) get_the_title($post), ENT_QUOTES | ENT_HTML5, 'UTF-8'),
			'id'           => (string) $post->ID,
			'type'         => (string) $post->post_type,
			'slug'         => (string) $post->post_name,
			'published_at' => (string) get_post_time('c', true, $post),
			'modified_at'  => (string) get_post_modified_time('c', true, $post),
			'url'          => $canonical_url,
			'markdown_url' => $markdown_url,
		];

		$excerpt = trim(wp_strip_all_tags((string) get_the_excerpt($post), true));
		if ('' === $excerpt) {
			$excerpt = trim(wp_strip_all_tags((string) $post->post_content, true));
		}
		if ('' !== $excerpt) {
			$excerpt = html_entity_decode($excerpt, ENT_QUOTES | ENT_HTML5, 'UTF-8');
			$excerpt = preg_replace('/\s+/u', ' ', $excerpt);
			$words   = preg_split('/\s+/u', (string) $excerpt, -1, PREG_SPLIT_NO_EMPTY) ?: [];
			if (count($words) > 40) {
				$excerpt = implode(' ', array_slice($words, 0, 40)) . '...';
			}
			$data['excerpt'] = (string) $excerpt;
		}

		$taxes = get_object_taxonomies($post->post_type, 'names');
		foreach ($taxes as $tax) {
			if (!taxonomy_exists($tax)) {
				continue;
			}

			$names = wp_get_post_terms($post->ID, $tax, ['fields' => 'names']);
			if (is_wp_error($names) || empty($names)) {
				continue;
			}

			$key = 'taxonomy_' . $this->yaml_key((string) $tax);
			$data[$key] = array_values(array_unique(array_map('strval', $names)));
		}

		return $data;
	}

	private function yaml(array $data): string {
		$lines = [];

		foreach ($data as $key => $value) {
			$key = $this->yaml_key((string) $key);
			if ('' === $key) {
				continue;
			}

			if (is_array($value)) {
				$lines[] = $key . ':';
				foreach ($value as $item) {
					$lines[] = '  - ' . $this->yaml_scalar((string) $item);
				}
				continue;
			}

			$lines[] = $key . ': ' . $this->yaml_scalar((string) $value);
		}

		return implode("\n", $lines) . "\n";
	}

	private function yaml_key(string $key): string {
		$key = strtolower($key);
		$key = preg_replace('/[^a-z0-9_]+/', '_', $key);
		return trim((string) $key, '_');
	}

	private function yaml_scalar(string $value): string {
		$value = str_replace('\\', '\\\\', $value);
		$value = str_replace('"', '\\"', $value);
		$value = str_replace("\r", '', $value);
		$value = str_replace("\n", '\n', $value);
		return '"' . $value . '"';
	}
}
