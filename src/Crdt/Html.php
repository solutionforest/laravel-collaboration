<?php

declare(strict_types=1);

namespace SolutionForest\Collaboration\Crdt;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use SolutionForest\Collaboration\Support\Data;

/**
 * HTML from an editor such as TinyMCE, as the document the rich editor
 * shares, and back. Mirrors `resources/js/adapters/html.js`, so a draft
 * seeded from the database matches what the browser reads from the editor.
 * Only the schema's nodes and marks come through; anything else is text.
 */
final class Html
{
    private const HEADINGS = ['h1' => 1, 'h2' => 2, 'h3' => 3, 'h4' => 4, 'h5' => 5, 'h6' => 6];

    private const MARKS = [
        'strong' => 'bold', 'b' => 'bold', 'em' => 'italic', 'i' => 'italic', 'u' => 'underline',
        's' => 'strike', 'strike' => 'strike', 'del' => 'strike', 'code' => 'code',
        'sub' => 'subscript', 'sup' => 'superscript', 'mark' => 'highlight', 'a' => 'link',
    ];

    private const MARK_TAGS = [
        'bold' => 'strong', 'italic' => 'em', 'underline' => 'u', 'strike' => 's', 'code' => 'code',
        'subscript' => 'sub', 'superscript' => 'sup', 'highlight' => 'mark',
    ];

    private const CONTAINERS = ['body', 'div', 'section', 'article', 'main', 'figure', 'blockquote', 'li', 'td', 'th', 'ul', 'ol', 'table', 'thead', 'tbody', 'tfoot', 'tr', 'trix-editor'];

    private const TEXT_BLOCKS = ['p', 'pre', 'figcaption', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'];

    private const SKIP = ['script', 'style', 'template', 'colgroup'];

    /** @return array<string, mixed> a TipTap-style document */
    public static function toDocument(string $html): array
    {
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?><!doctype html><html><body>'.$html.'</body></html>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $body = $dom->getElementsByTagName('body')->item(0);
        $content = $body === null ? [] : self::blocks($body);

        return ['type' => 'doc', 'content' => $content === [] ? [['type' => 'paragraph']] : $content];
    }

    /** @param array<string, mixed> $node */
    public static function fromDocument(array $node): string
    {
        $children = self::children($node);
        $all = self::all(...);
        $flat = self::flat(...);
        $level = Data::int(Data::map($node['attrs'] ?? null)['level'] ?? null, 2);

        return match ($node['type'] ?? null) {
            'doc' => $all($children),
            'paragraph' => '<p>'.self::inlineHtml($children).'</p>',
            'heading' => "<h{$level}>".self::inlineHtml($children)."</h{$level}>",
            'codeBlock' => '<pre>'.self::escape(implode('', array_map(static fn (array $child): string => is_string($child['text'] ?? null) ? $child['text'] : '', $children))).'</pre>',
            'blockquote' => '<blockquote>'.$all($children).'</blockquote>',
            'bulletList' => '<ul>'.$all($children).'</ul>',
            'orderedList' => '<ol>'.$all($children).'</ol>',
            'listItem' => '<li>'.$flat($node).'</li>',
            'horizontalRule' => '<hr>',
            'table' => '<table><tbody>'.$all($children).'</tbody></table>',
            'tableRow' => '<tr>'.$all($children).'</tr>',
            'tableCell' => '<td>'.$flat($node).'</td>',
            'tableHeader' => '<th>'.$flat($node).'</th>',
            default => $children === [] ? '' : '<p>'.self::inlineHtml(array_merge(...array_map(static fn (array $child): array => self::children($child) ?: [$child], $children))).'</p>',
        };
    }

    /** @param list<array<string, mixed>> $nodes */
    private static function all(array $nodes): string
    {
        return implode('', array_map(self::fromDocument(...), $nodes));
    }

    /**
     * A cell or item holding one paragraph is written as just its text, as editors write it.
     *
     * @param  array<string, mixed>  $parent
     */
    private static function flat(array $parent): string
    {
        $content = self::children($parent);

        return count($content) === 1 && ($content[0]['type'] ?? null) === 'paragraph' ? self::inlineHtml(self::children($content[0])) : self::all($content);
    }

    /** @return list<array<string, mixed>> */
    private static function blocks(DOMNode $parent): array
    {
        $blocks = [];
        $loose = [];

        foreach (self::childList($parent) as $child) {
            if ($child instanceof DOMElement && (in_array(strtolower($child->tagName), self::SKIP, true) || self::isFurniture($child))) {
                continue;
            }

            if (! self::isBlock($child)) {
                $loose[] = $child;

                continue;
            }

            array_push($blocks, ...self::looseParagraph($loose), ...self::block($child));
            $loose = [];
        }

        return [...$blocks, ...self::looseParagraph($loose)];
    }

    /**
     * Inline content outside any block, as the paragraph it reads as.
     *
     * @param  list<DOMNode>  $nodes
     * @return list<array<string, mixed>>
     */
    private static function looseParagraph(array $nodes): array
    {
        $content = array_values(array_filter($nodes, static fn (DOMNode $node): bool => ! ($node instanceof DOMText && trim(self::visible($node->data)) === '')));

        return $content === [] ? [] : [self::textBlock('paragraph', null, $content)];
    }

    /** @return list<array<string, mixed>> */
    private static function block(DOMNode $node): array
    {
        if (! $node instanceof DOMElement) {
            return [];
        }

        $tag = strtolower($node->tagName);

        return match (true) {
            $tag === 'hr' => [['type' => 'horizontalRule']],
            isset(self::HEADINGS[$tag]) => [self::textBlock('heading', ['level' => self::HEADINGS[$tag]], self::childList($node))],
            $tag === 'pre' => [self::textBlock('codeBlock', null, self::childList($node), code: true)],
            $tag === 'p' || $tag === 'figcaption' => [self::textBlock('paragraph', null, self::childList($node))],
            $tag === 'ul' || $tag === 'ol' => self::listOf($node, $tag),
            $tag === 'blockquote' => [self::container('blockquote', $node)],
            $tag === 'table' => self::table($node),
            default => self::blocks($node),
        };
    }

    /** @return list<array<string, mixed>> */
    private static function listOf(DOMElement $list, string $tag): array
    {
        $items = [];
        $isBullet = false;

        foreach (self::childList($list) as $child) {
            if ($child instanceof DOMElement && strtolower($child->tagName) === 'li') {
                $isBullet = $isBullet || $child->getAttribute('data-list') === 'bullet';
                $items[] = self::container('listItem', $child);
            }
        }

        $type = $tag === 'ol' && ! $isBullet ? 'orderedList' : 'bulletList';

        return $items === [] ? [] : [['type' => $type, 'content' => $items]];
    }

    /** @return list<array<string, mixed>> */
    private static function table(DOMElement $table): array
    {
        $rows = [];

        foreach ($table->getElementsByTagName('tr') as $row) {
            $cells = [];

            foreach (self::childList($row) as $cell) {
                if ($cell instanceof DOMElement && in_array(strtolower($cell->tagName), ['td', 'th'], true)) {
                    $cells[] = self::container(strtolower($cell->tagName) === 'th' ? 'tableHeader' : 'tableCell', $cell);
                }
            }

            $rows[] = ['type' => 'tableRow', 'content' => $cells];
        }

        return $rows === [] ? [] : [['type' => 'table', 'content' => $rows]];
    }

    /** @return array<string, mixed> */
    private static function container(string $type, DOMElement $element): array
    {
        $content = self::blocks($element);

        return ['type' => $type, 'content' => $content === [] ? [['type' => 'paragraph']] : $content];
    }

    /**
     * @param  array<string, mixed>|null  $attrs
     * @param  list<DOMNode>  $nodes
     * @return array<string, mixed>
     */
    private static function textBlock(string $type, ?array $attrs, array $nodes, bool $code = false): array
    {
        $content = [];
        self::inline($nodes, [], $content, $code);

        // A last line break only gives an empty line its height.
        if (($content[count($content) - 1]['type'] ?? null) === 'hardBreak') {
            array_pop($content);
        }

        $node = ['type' => $type];

        if ($attrs !== null) {
            $node['attrs'] = $attrs;
        }

        if ($content !== []) {
            $node['content'] = $content;
        }

        return $node;
    }

    /**
     * @param  list<DOMNode>  $nodes
     * @param  list<array<string, mixed>>  $marks
     * @param  list<array<string, mixed>>  $into
     */
    private static function inline(array $nodes, array $marks, array &$into, bool $code): void
    {
        foreach ($nodes as $node) {
            if ($node instanceof DOMText) {
                self::text($node->data, $marks, $into);

                continue;
            }

            if (! $node instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($node->tagName);

            if (in_array($tag, self::SKIP, true) || self::isFurniture($node)) {
                continue;
            }

            if ($tag === 'br') {
                $code ? self::text("\n", $marks, $into) : $into[] = ['type' => 'hardBreak'];

                continue;
            }

            if ($tag === 'img') {
                $attrs = array_filter(['src' => $node->getAttribute('src'), 'alt' => $node->getAttribute('alt')], static fn (string $value): bool => $value !== '');
                $into[] = ['type' => 'image', 'attrs' => $attrs] + ($marks === [] ? [] : ['marks' => $marks]);

                continue;
            }

            $mark = $code ? null : (self::MARKS[$tag] ?? null);
            $inner = $marks;

            if ($mark === 'link') {
                $href = $node->getAttribute('href');
                $inner[] = ['type' => 'link', 'attrs' => $href === '' ? [] : ['href' => $href]];
            } elseif ($mark !== null) {
                $inner[] = ['type' => $mark];
            }

            self::inline(self::childList($node), $inner, $into, $code);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $marks
     * @param  list<array<string, mixed>>  $into
     */
    private static function text(string $raw, array $marks, array &$into): void
    {
        // Editors keep runs of spaces as no-break spaces; the text is the same.
        $text = str_replace("\u{00A0}", ' ', self::visible($raw));

        if ($text === '') {
            return;
        }

        $last = count($into) - 1;

        if ($last >= 0 && ($into[$last]['type'] ?? null) === 'text' && ($into[$last]['marks'] ?? []) === $marks && is_string($into[$last]['text'] ?? null)) {
            $into[$last]['text'] .= $text;

            return;
        }

        $into[] = ['type' => 'text', 'text' => $text] + ($marks === [] ? [] : ['marks' => $marks]);
    }

    private static function visible(string $text): string
    {
        return str_replace(["\u{FEFF}", "\u{200B}"], '', $text);
    }

    private static function isBlock(DOMNode $node): bool
    {
        if (! $node instanceof DOMElement) {
            return false;
        }

        $tag = strtolower($node->tagName);

        if ($tag === 'figure') {
            foreach (self::childList($node) as $child) {
                if (self::isBlock($child)) {
                    return true;
                }
            }

            return false;
        }

        return in_array($tag, self::CONTAINERS, true) || in_array($tag, self::TEXT_BLOCKS, true) || $tag === 'hr';
    }

    private static function isFurniture(DOMElement $element): bool
    {
        $classes = explode(' ', $element->getAttribute('class'));

        return $element->getAttribute('data-mce-bogus') === 'all'
            || in_array('ql-ui', $classes, true)
            || in_array('ql-cursor', $classes, true)
            || in_array('ck-fake-selection-container', $classes, true)
            || $element->hasAttribute('data-ck-unsafe-element')
            || ($element->getAttribute('contenteditable') === 'false' && ! in_array(strtolower($element->tagName), ['img', 'figure'], true) && ! $element->hasAttribute('data-trix-attachment'));
    }

    /** @return list<DOMNode> */
    private static function childList(DOMNode $node): array
    {
        $children = [];

        foreach ($node->childNodes as $child) {
            $children[] = $child;
        }

        return $children;
    }

    /**
     * @param  array<string, mixed>  $node
     * @return list<array<string, mixed>>
     */
    private static function children(array $node): array
    {
        $children = [];

        foreach (Data::list($node['content'] ?? null) as $child) {
            if (is_array($child)) {
                $children[] = Data::map($child);
            }
        }

        return $children;
    }

    /** @param list<array<string, mixed>> $nodes */
    private static function inlineHtml(array $nodes): string
    {
        $html = '';

        foreach ($nodes as $node) {
            $inner = match ($node['type'] ?? null) {
                'text' => str_replace('  ', " \u{00A0}", self::escape(is_string($node['text'] ?? null) ? $node['text'] : '')),
                'hardBreak' => '<br>',
                'image' => '<img src="'.self::escape(self::attribute($node, 'src')).'"'.(self::attribute($node, 'alt') === '' ? '' : ' alt="'.self::escape(self::attribute($node, 'alt')).'"').'>',
                default => '',
            };

            foreach (array_reverse(Data::list($node['marks'] ?? null)) as $mark) {
                if (! is_array($mark)) {
                    continue;
                }

                $mark = Data::map($mark);

                $type = is_string($mark['type'] ?? null) ? $mark['type'] : '';

                if ($type === 'link') {
                    $inner = '<a href="'.self::escape(self::attribute($mark, 'href')).'">'.$inner.'</a>';

                    continue;
                }

                $tag = self::MARK_TAGS[$type] ?? 'span';
                $inner = "<{$tag}>{$inner}</{$tag}>";
            }

            $html .= $inner;
        }

        // Editors drop a space at either end of a line; a no-break space they keep, and it reads back as a space.
        $html = (string) preg_replace(['/^ /', '/ $/'], "\u{00A0}", $html);

        // A last line break needs a second to show; reading drops that second one.
        if (($nodes[count($nodes) - 1]['type'] ?? null) === 'hardBreak') {
            $html .= '<br>';
        }

        return $html === '' ? '<br>' : $html;
    }

    /** @param array<string, mixed> $node */
    private static function attribute(array $node, string $name): string
    {
        $value = is_array($node['attrs'] ?? null) ? ($node['attrs'][$name] ?? null) : null;

        return is_scalar($value) ? (string) $value : '';
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
