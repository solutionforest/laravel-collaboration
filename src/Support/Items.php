<?php

declare(strict_types=1);

namespace SolutionForest\Collaboration\Support;

/**
 * A repeater's items as a record keeps them, a list, and as the shared
 * draft keeps them, by key. Each item carries its `key`, so the same item
 * keeps its key from one save to the next and edits to it merge.
 *
 * @internal
 */
final class Items
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public static function byKey(mixed $items): array
    {
        $keyed = [];

        foreach (array_values(Data::map(is_string($items) ? Data::decode($items) : $items)) as $index => $item) {
            $item = Data::map($item);
            $key = Data::string($item['key'] ?? null, 'i'.$index);

            // Keys become ids in the draft: letters, digits, dashes and underscores only.
            $key = preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $key) === 1 ? $key : 'i'.substr(md5($key), 0, 16);

            unset($item['key']);
            $keyed[$key] = $item;
        }

        return $keyed;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function asList(mixed $items): array
    {
        $list = [];

        foreach (Data::map($items) as $key => $item) {
            $list[] = ['key' => (string) $key, ...Data::map($item)];
        }

        return $list;
    }
}
