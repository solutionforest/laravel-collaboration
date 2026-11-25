<?php

declare(strict_types=1);

namespace SolutionForest\Collaboration\Crdt;

use SolutionForest\Collaboration\Support\Data;

/**
 * The rich content that may travel between editors.
 *
 * One editor's operations are rendered in everybody else's browser, so this is
 * an allow-list: an unknown node is refused and an unknown attribute dropped.
 * Attributes equal to their default are dropped too, so content parsed by PHP
 * and content produced by the browser compare as equal.
 *
 * Keep in step with `resources/js/crdt/schema.js`.
 */
final class RichTextSchema
{
    public const TEXT_BLOCKS = ['paragraph', 'heading', 'codeBlock', 'detailsSummary'];

    public const INLINE = ['hardBreak', 'image', 'mergeTag'];

    private const NODES = [
        'paragraph' => ['textAlign'],
        'heading' => ['level', 'textAlign'],
        'codeBlock' => ['language'],
        'detailsSummary' => [],
        'details' => [],
        'detailsContent' => [],
        'blockquote' => [],
        'bulletList' => [],
        'orderedList' => ['start', 'type'],
        'listItem' => [],
        'horizontalRule' => [],
        'lead' => [],
        'grid' => ['data-cols', 'data-from-breakpoint'],
        'gridColumn' => ['data-col-span'],
        'table' => [],
        'tableRow' => [],
        'tableCell' => ['align', 'colspan', 'colwidth', 'rowspan'],
        'tableHeader' => ['align', 'colspan', 'colwidth', 'rowspan'],
        'hardBreak' => [],
        'image' => ['alt', 'height', 'id', 'src', 'title', 'width'],
        'mergeTag' => ['id'],
    ];

    private const MARKS = [
        'bold' => [],
        'italic' => [],
        'underline' => [],
        'strike' => [],
        'code' => [],
        'subscript' => [],
        'superscript' => [],
        'small' => [],
        'highlight' => [],
        'textColor' => ['data-color'],
        'link' => ['href', 'rel', 'target', 'title'],
    ];

    private const DEFAULTS = [
        'colspan' => 1,
        'rowspan' => 1,
        'start' => 1,
        'data-cols' => 2,
        'data-col-span' => 1,
        'data-from-breakpoint' => 'md',
        'textAlign' => 'start',
    ];

    /** @return array<string, int|string|list<int>> */
    public static function node(string $type, mixed $attributes = null): array
    {
        return self::attributes(
            self::NODES[$type] ?? throw new InvalidOperation('Rich content node ['.$type.'] cannot be shared live.'),
            Data::map($attributes),
        );
    }

    /** @return array<string, int|string|list<int>> */
    public static function mark(string $type, mixed $attributes = null): array
    {
        return self::attributes(
            self::MARKS[$type] ?? throw new InvalidOperation('Rich content mark ['.$type.'] cannot be shared live.'),
            Data::map($attributes),
        );
    }

    /**
     * Whether stored content can be edited live without losing any of it.
     */
    public static function canShareLosslessly(mixed $node): bool
    {
        $node = Data::map($node);
        $type = Data::string($node['type'] ?? null);

        if (! in_array($type, ['doc', 'text'], true) && ! isset(self::NODES[$type])) {
            return false;
        }

        $attributes = Data::map($node['attrs'] ?? null);

        // A base64 image has nowhere to go: it is neither a URL nor an upload.
        if ($type === 'image' && blank($attributes['id'] ?? null) && ! isset(self::node($type, $attributes)['src'])) {
            return false;
        }

        foreach (Data::list($node['marks'] ?? null) as $mark) {
            $mark = Data::map($mark);
            $markType = Data::string($mark['type'] ?? null);

            if (! isset(self::MARKS[$markType]) || ($markType === 'link' && filled(Data::map($mark['attrs'] ?? null)['class'] ?? null))) {
                return false;
            }
        }

        foreach (Data::list($node['content'] ?? null) as $child) {
            if (! self::canShareLosslessly($child)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $allowed
     * @param  array<string, mixed>  $given
     * @return array<string, int|string|list<int>>
     */
    private static function attributes(array $allowed, array $given): array
    {
        $attributes = [];

        foreach ($allowed as $name) {
            $value = self::attribute($name, $given[$name] ?? null);

            if ($value !== null && (self::DEFAULTS[$name] ?? null) !== $value) {
                $attributes[$name] = $value;
            }
        }

        ksort($attributes, SORT_STRING);

        return $attributes;
    }

    /** @return int|string|list<int>|null */
    private static function attribute(string $name, mixed $value): int|string|array|null
    {
        if ($name === 'colwidth') {
            $widths = [];

            foreach (Data::list($value) as $width) {
                $widths[] = min(10000, max(0, Data::int($width)));
            }

            return $widths === [] ? null : $widths;
        }

        if (! is_scalar($value) || is_bool($value) || $value === '') {
            return null;
        }

        if (in_array($name, ['level', 'start', 'colspan', 'rowspan', 'data-cols', 'data-col-span'], true)) {
            return is_numeric($value) ? min($name === 'level' ? 6 : 1000, max(1, (int) $value)) : null;
        }

        $value = (string) $value;

        $valid = match ($name) {
            'href' => self::isSafeUrl($value, ['http', 'https', 'mailto', 'tel']),
            'src' => self::isSafeUrl($value, ['http', 'https']),
            'target' => $value === '_blank',
            'rel' => preg_match('/^[a-z ]{1,120}$/D', $value) === 1,
            'textAlign', 'align' => in_array($value, ['start', 'center', 'end', 'justify', 'left', 'right'], true),
            'width', 'height' => preg_match('/^\d+(\.\d+)?(px|%|em|rem)?$/D', $value) === 1,
            'data-color', 'language', 'type', 'data-from-breakpoint' => preg_match('/^[\w #(),.%+\/-]{1,80}$/uD', $value) === 1,
            default => mb_strlen($value, 'UTF-8') <= 2048,
        };

        return $valid ? $value : null;
    }

    /** @param list<string> $schemes */
    private static function isSafeUrl(string $url, array $schemes): bool
    {
        // Browsers ignore whitespace and control characters inside a scheme.
        if (strlen($url) > 8192 || preg_match('/[\x00-\x20\x7F]/', $url) === 1) {
            return false;
        }

        if (preg_match('/^([^\/?#]*):/', $url, $match) !== 1) {
            return true;
        }

        return in_array(strtolower($match[1]), $schemes, true);
    }
}
