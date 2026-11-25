<?php

declare(strict_types=1);

namespace SolutionForest\Collaboration\Crdt;

use JsonException;
use SolutionForest\Collaboration\Support\Data;

/**
 * Translates a TipTap document to and from a flat sequence.
 *
 * Every block becomes a marker, followed by its characters. The marker carries
 * the path of nodes down to that block as a formatting value, so splitting a
 * paragraph inserts one item, joining deletes one, and wrapping it in a list
 * never touches the text somebody else is typing.
 *
 * Keep in step with `resources/js/crdt/rich-text.js`.
 */
final class RichText
{
    private const CONTINUES = 0;

    private const STARTS = 1;

    private const LEAF = 2;

    private const MAX_DEPTH = 24;

    /** @var list<Token> */
    private array $tokens = [];

    private function __construct() {}

    /** @return list<Token> */
    public static function tokens(mixed $document): array
    {
        // Normalises the mixture of objects and arrays returned by TipTap for PHP.
        $document = Data::decode(Data::encode($document ?? []));
        $walker = new self;
        $walker->children(Data::list($document['content'] ?? null), []);

        return $walker->tokens === [] ? [self::paragraph()] : $walker->tokens;
    }

    /**
     * @param  list<Token>  $tokens
     * @return array<string, mixed>
     */
    public static function document(array $tokens): array
    {
        $root = new TreeNode('doc');
        /** @var list<TreeNode> $open */
        $open = [];
        $block = null;

        foreach ($tokens as $token) {
            if (! $token->isBlock()) {
                if ($block === null) {
                    $block = new TreeNode('paragraph');
                    $parent = $open === [] ? $root : $open[count($open) - 1];
                    $parent->children[] = $block;
                }

                $block->children[] = self::inline($token);

                continue;
            }

            $path = self::path($token->formatting['b'] ?? null);
            $leaf = $path[count($path) - 1];
            $depth = 0;
            $parent = $root;

            foreach (array_slice($path, 0, -1) as [$type, $attributes, $flag]) {
                if ($flag === self::CONTINUES && isset($open[$depth]) && $open[$depth]->type === $type) {
                    $parent = $open[$depth];
                } else {
                    $node = new TreeNode($type, $attributes);
                    $parent->children[] = $node;
                    $open = [...array_slice($open, 0, $depth), $node];
                    $parent = $node;
                }

                $depth++;
            }

            $open = array_slice($open, 0, $depth);
            $node = new TreeNode($leaf[0], $leaf[1]);
            $parent->children[] = $node;
            $block = $leaf[2] === self::LEAF ? null : $node;
        }

        if ($root->children === []) {
            $root->children[] = new TreeNode('paragraph');
        }

        return $root->toArray();
    }

    /**
     * Checks a formatting value received from a browser.
     */
    public static function assertFormatting(string $key, ?string $value, bool $isBlock): void
    {
        if ($key === 'b') {
            if (! $isBlock || $value === null || ! self::isCanonicalPath($value)) {
                throw new InvalidOperation('A block path is not valid.');
            }

            return;
        }

        if ($isBlock || ! str_starts_with($key, 'm:')) {
            throw new InvalidOperation('Formatting ['.$key.'] is not valid here.');
        }

        $type = substr($key, 2);

        if ($value === null) {
            RichTextSchema::mark($type);

            return;
        }

        if (self::markValue($type, $value === '1' ? [] : Data::decode($value)) !== $value) {
            throw new InvalidOperation('A mark is not in canonical form.');
        }
    }

    public static function assertEmbed(string $encoded): void
    {
        $node = Data::decode($encoded);
        $type = Data::string($node['type'] ?? null);

        if (! in_array($type, RichTextSchema::INLINE, true) || self::embed($type, $node['attrs'] ?? null) !== $encoded) {
            throw new InvalidOperation('An embedded node is not valid.');
        }
    }

    /**
     * @param  list<mixed>  $children
     * @param  list<array{string, array<string, int|string|list<int>>, int}>  $ancestors
     */
    private function children(array $children, array $ancestors): void
    {
        $loose = false;

        foreach ($children as $child) {
            $child = Data::map($child);
            $type = Data::string($child['type'] ?? null);

            // HTML may contain text or an image outside of a paragraph.
            if ($type === 'text' || in_array($type, RichTextSchema::INLINE, true)) {
                if (! $loose) {
                    $this->block([...$ancestors, ['paragraph', [], self::CONTINUES]]);
                    $ancestors = self::continued($ancestors);
                    $loose = true;
                }

                $this->content($child);

                continue;
            }

            $loose = false;
            $attributes = RichTextSchema::node($type, $child['attrs'] ?? null);
            $content = Data::list($child['content'] ?? null);

            if (count($ancestors) >= self::MAX_DEPTH) {
                throw new InvalidOperation('Rich content is nested too deeply.');
            }

            if (in_array($type, RichTextSchema::TEXT_BLOCKS, true)) {
                $this->block([...$ancestors, [$type, $attributes, self::CONTINUES]]);

                foreach ($content as $inline) {
                    $this->content(Data::map($inline));
                }
            } elseif ($content === []) {
                $this->block([...$ancestors, [$type, $attributes, self::LEAF]]);
            } else {
                $this->children($content, [...$ancestors, [$type, $attributes, self::STARTS]]);
            }

            $ancestors = self::continued($ancestors);
        }
    }

    /** @param list<array{string, array<string, int|string|list<int>>, int}> $path */
    private function block(array $path): void
    {
        $this->tokens[] = new Token(['b' => 1], ['b' => Data::encode(array_map(
            static fn (array $step): array => [$step[0], $step[1] === [] ? 0 : $step[1], $step[2]],
            $path,
        ))]);
    }

    /** @param array<string, mixed> $node */
    private function content(array $node): void
    {
        $formatting = [];

        foreach (Data::list($node['marks'] ?? null) as $mark) {
            $mark = Data::map($mark);
            $type = Data::string($mark['type'] ?? null);
            $formatting['m:'.$type] = self::markValue($type, $mark['attrs'] ?? null);
        }

        ksort($formatting, SORT_STRING);
        $type = Data::string($node['type'] ?? null);

        if ($type !== 'text') {
            if (! in_array($type, RichTextSchema::INLINE, true)) {
                throw new InvalidOperation('Rich content node ['.$type.'] cannot be shared live.');
            }

            $this->tokens[] = new Token(['n' => self::embed($type, $node['attrs'] ?? null)], $formatting);

            return;
        }

        foreach (mb_str_split(Data::string($node['text'] ?? null), 1, 'UTF-8') as $character) {
            $this->tokens[] = new Token($character, $formatting);
        }
    }

    /**
     * @param  list<array{string, array<string, int|string|list<int>>, int}>  $ancestors
     * @return list<array{string, array<string, int|string|list<int>>, int}>
     */
    private static function continued(array $ancestors): array
    {
        return array_map(static fn (array $step): array => [$step[0], $step[1], self::CONTINUES], $ancestors);
    }

    private static function paragraph(): Token
    {
        return new Token(['b' => 1], ['b' => Data::encode([['paragraph', 0, self::CONTINUES]])]);
    }

    private static function markValue(string $type, mixed $attributes): string
    {
        $attributes = RichTextSchema::mark($type, $attributes);

        return $attributes === [] ? '1' : Data::encode($attributes);
    }

    private static function embed(string $type, mixed $attributes): string
    {
        $attributes = RichTextSchema::node($type, $attributes);

        return Data::encode($attributes === [] ? ['type' => $type] : ['attrs' => $attributes, 'type' => $type]);
    }

    private static function inline(Token $token): TreeNode
    {
        $marks = [];

        foreach ($token->formatting as $key => $value) {
            if (str_starts_with($key, 'm:')) {
                $attributes = $value === '1' ? [] : Data::decode($value);
                $marks[] = ['type' => substr($key, 2), ...($attributes === [] ? [] : ['attrs' => $attributes])];
            }
        }

        if (is_string($token->value)) {
            return new TreeNode('text', text: $token->value, marks: $marks);
        }

        $node = Data::decode($token->value['n'] ?? null);

        return new TreeNode(Data::string($node['type'] ?? null), Data::map($node['attrs'] ?? null), marks: $marks);
    }

    /** @return non-empty-list<array{string, array<string, mixed>, int}> */
    private static function path(?string $encoded): array
    {
        $path = [];

        foreach (Data::list($encoded === null ? null : json_decode($encoded, true)) as $step) {
            $step = Data::list($step);
            $path[] = [Data::string($step[0] ?? null), Data::map($step[1] ?? null), Data::int($step[2] ?? null)];
        }

        return $path === [] ? [['paragraph', [], self::CONTINUES]] : $path;
    }

    private static function isCanonicalPath(string $encoded): bool
    {
        try {
            $steps = json_decode($encoded, true, 12, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return false;
        }

        if (! is_array($steps) || ! array_is_list($steps) || $steps === [] || count($steps) > self::MAX_DEPTH) {
            return false;
        }

        $canonical = [];
        $last = count($steps) - 1;

        foreach ($steps as $index => $step) {
            if (! is_array($step) || ! is_string($step[0] ?? null) || ! is_int($step[2] ?? null)) {
                return false;
            }

            [$type, $flag] = [$step[0], $step[2]];
            $isTextBlock = in_array($type, RichTextSchema::TEXT_BLOCKS, true);

            $valid = ! in_array($type, RichTextSchema::INLINE, true) && match (true) {
                $index < $last => ! $isTextBlock && in_array($flag, [self::CONTINUES, self::STARTS], true),
                $isTextBlock => $flag === self::CONTINUES,
                default => $flag === self::LEAF,
            };

            if (! $valid) {
                return false;
            }

            $attributes = RichTextSchema::node($type, $step[1] ?? null);
            $canonical[] = [$type, $attributes === [] ? 0 : $attributes, $flag];
        }

        return Data::encode($canonical) === $encoded;
    }
}
