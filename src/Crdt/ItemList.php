<?php

declare(strict_types=1);

namespace SolutionForest\Collaboration\Crdt;

use SolutionForest\Collaboration\Support\Data;

/**
 * A repeater or builder: an ordered set of items, each a set of sub-fields.
 *
 * The order is a `Sequence` of item keys, so adding, removing and moving
 * items merge like text. A text sub-field, one of `$texts`, is a `Sequence`
 * of characters too, so typing in it merges like a text input. Any other
 * sub-field is a `Register`: the latest edit wins. A simple repeater's single
 * sub-field has the path "".
 *
 * Keep in step with `resources/js/crdt/item-list.js`.
 */
final class ItemList
{
    public const MAX_DEPTH = 6;

    public const MAX_TEXT = 65_535;

    /**
     * @param  array<string, array<string, Register|Sequence>>  $values
     * @param  list<string>  $texts  the sub-field paths that merge by character
     */
    public function __construct(
        public Sequence $order = new Sequence,
        private array $values = [],
        public readonly array $texts = [],
    ) {}

    /** @param array<string, mixed> $data */
    public static function from(array $data): self
    {
        $values = [];

        foreach (Data::map($data['m'] ?? null) as $key => $paths) {
            foreach (Data::map($paths) as $path => $slot) {
                $slot = Data::map($slot);
                $values[$key][$path] = array_key_exists('r', $slot) ? Sequence::from($slot['r']) : Register::from($slot);
            }
        }

        return new self(Sequence::from($data['r'] ?? null), $values, Data::strings($data['t'] ?? null));
    }

    public function __clone()
    {
        $this->order = clone $this->order;
        $this->values = array_map(
            static fn (array $paths): array => array_map(static fn (Register|Sequence $slot): Register|Sequence => clone $slot, $paths),
            $this->values,
        );
    }

    public function isText(string $path): bool
    {
        return in_array($path, $this->texts, true);
    }

    /** A text sub-field's characters, created on first use. */
    public function text(string $key, string $path): Sequence
    {
        $slot = $this->values[$key][$path] ??= new Sequence;

        if (! $slot instanceof Sequence) {
            throw new InvalidOperation('The sub-field does not hold text.');
        }

        return $slot;
    }

    public function textLength(string $key, string $path): int
    {
        $slot = $this->values[$key][$path] ?? null;

        return $slot instanceof Sequence ? $slot->length() : 0;
    }

    /** @return list<string> visible item keys, each once */
    public function keys(): array
    {
        $keys = [];

        foreach ($this->order->tokens() as $token) {
            $key = is_array($token->value) ? Data::string($token->value['i'] ?? null) : '';

            if ($key !== '' && ! in_array($key, $keys, true)) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    public function hasKey(string $key): bool
    {
        foreach ($this->order->tokens(includeDeleted: true) as $token) {
            if (is_array($token->value) && ($token->value['i'] ?? null) === $key) {
                return true;
            }
        }

        return false;
    }

    public function put(string $key, string $path, mixed $value, Id $at): void
    {
        $slot = $this->values[$key][$path] ??= new Register;

        if (! $slot instanceof Register) {
            throw new InvalidOperation('A text sub-field is edited by character.');
        }

        $slot->set($value, $at);
    }

    /** @return array<string, mixed> */
    public function value(): array
    {
        $items = [];

        foreach ($this->keys() as $key) {
            $items[$key] = self::unflatten(array_map(
                static fn (Register|Sequence $slot): mixed => $slot instanceof Register ? $slot->value : self::textValue($slot),
                $this->values[$key] ?? [],
            ));
        }

        return $items;
    }

    /** Empty text is no value, as an empty input is in Filament. */
    private static function textValue(Sequence $sequence): ?string
    {
        $text = $sequence->text();

        return $text === '' ? null : $text;
    }

    /** @return list<array{list<array{string, mixed}>}> content without keys, which differ between page loads */
    public function signature(): array
    {
        return self::contentOf($this->value());
    }

    /**
     * @param  array<array-key, mixed>  $items
     * @return list<array{list<array{string, mixed}>}>
     */
    public static function contentOf(array $items): array
    {
        $content = [];

        foreach ($items as $item) {
            $leaves = self::flatten($item);
            ksort($leaves, SORT_STRING);
            $content[] = [array_map(static fn (string $path, mixed $value): array => [$path, Register::normalize($value)], array_keys($leaves), $leaves)];
        }

        return $content;
    }

    /**
     * @param  array<string, mixed>  $items  the new value, keyed by item
     * @return list<array<string, mixed>>
     */
    public function changes(string $field, array $items, int $clock, int $client): array
    {
        $items = $this->adoptKeys($items);
        $old = array_values(array_filter(
            $this->order->tokens(),
            static fn (Token $token): bool => is_array($token->value),
        ));
        $seen = [];

        // A key that two people moved at once is shown once; the copy is removed here.
        $old = array_map(static function (Token $token) use (&$seen): Token {
            $key = Data::string(Data::map($token->value)['i'] ?? null);
            $duplicate = isset($seen[$key]);
            $seen[$key] = true;

            return $duplicate ? new Token(['i' => "\0".$key], [], $token->id) : $token;
        }, $old);

        $new = array_map(static fn (string $key): Token => new Token(['i' => $key]), array_map(strval(...), array_keys($items)));
        $operations = Diff::operations($field, $old, $new, $clock, $client);
        $clock = self::clockAfter($operations, $clock);

        foreach ($items as $key => $item) {
            $key = (string) $key;
            $leaves = self::flatten($item);

            foreach (array_keys($this->values[$key] ?? []) as $path) {
                $leaves += [$path => null];
            }

            foreach ($leaves as $path => $value) {
                $path = (string) $path;
                $slot = $this->values[$key][$path] ?? null;

                if ($slot instanceof Sequence || ($slot === null && $this->isText($path))) {
                    $text = self::textOf($value);
                    $characters = array_map(static fn (string $character): Token => new Token($character), $text === '' ? [] : mb_str_split($text, 1, 'UTF-8'));
                    $edits = array_map(
                        static fn (array $operation): array => [...$operation, 'i' => $key, 'p' => $path],
                        Diff::operations($field, $slot?->tokens() ?? [], $characters, $clock, $client),
                    );
                    $clock = self::clockAfter($edits, $clock);
                    array_push($operations, ...$edits);

                    continue;
                }

                $value = Register::normalize($value);

                if (Register::normalize($slot?->value) !== $value) {
                    $operations[] = ['o' => 'put', 'f' => $field, 'i' => $key, 'p' => $path, 'id' => [++$clock, $client], 'v' => $value];
                }
            }
        }

        return $operations;
    }

    /** What a text sub-field holds, whatever the form sent. */
    public static function textOf(mixed $value): string
    {
        return is_scalar($value) && ! is_bool($value) ? str_replace(["\r\n", "\r"], "\n", (string) $value) : '';
    }

    /** @param list<array<string, mixed>> $operations */
    private static function clockAfter(array $operations, int $clock): int
    {
        foreach ($operations as $operation) {
            if (isset($operation['id'])) {
                $value = $operation['v'] ?? null;
                $span = ($operation['o'] ?? null) === 'ins' && is_string($value) ? mb_strlen($value, 'UTF-8') : 1;
                $clock = max($clock, Id::from($operation['id'])->clock + $span - 1);
            }
        }

        return $clock;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $state = [
            'r' => $this->order->toArray(),
            'm' => array_map(
                static fn (array $paths): array => array_map(
                    static fn (Register|Sequence $slot): array => $slot instanceof Register ? $slot->toArray() : ['r' => $slot->toArray()],
                    $paths,
                ),
                $this->values,
            ),
        ];

        return $this->texts === [] ? $state : [...$state, 't' => $this->texts];
    }

    /**
     * The same items loaded again get new random keys. An item whose key is
     * new, but whose content matches an item that is gone, takes that key.
     *
     * @param  array<string, mixed>  $items
     * @return array<string, mixed>
     */
    private function adoptKeys(array $items): array
    {
        $current = $this->value();
        $unused = array_diff_key($current, $items);
        $adopted = [];

        foreach ($items as $key => $item) {
            $key = (string) $key;

            if (! isset($current[$key])) {
                foreach ($unused as $old => $content) {
                    if (self::contentOf([$content]) === self::contentOf([$item])) {
                        unset($unused[$old]);
                        $key = (string) $old;

                        break;
                    }
                }
            }

            $adopted[$key] = $item;
        }

        return $adopted;
    }

    /** @return array<string, mixed> */
    public static function flatten(mixed $value, string $prefix = '', int $depth = 0): array
    {
        if ($value instanceof \stdClass) {
            $value = get_object_vars($value);
        }

        // An empty item or list is nothing, however PHP and JSON happen to spell it.
        if ($value === []) {
            return [$prefix => null];
        }

        if (! is_array($value) || array_is_list($value) || $depth >= self::MAX_DEPTH) {
            return [$prefix => $value];
        }

        $leaves = [];

        foreach ($value as $key => $item) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            $leaves += self::flatten($item, $path, $depth + 1);
        }

        return $leaves === [] ? [$prefix => null] : $leaves;
    }

    /** @param array<string, mixed> $leaves */
    public static function unflatten(array $leaves): mixed
    {
        if (array_key_exists('', $leaves)) {
            return $leaves[''];
        }

        $value = [];

        // In the order the sub-fields were first written, which is the form's order.
        foreach ($leaves as $path => $leaf) {
            $value = self::set($value, explode('.', (string) $path), $leaf);
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $value
     * @param  list<string>  $segments
     * @return array<string, mixed>
     */
    private static function set(array $value, array $segments, mixed $leaf): array
    {
        $segment = array_shift($segments);

        if ($segment === null) {
            return $value;
        }

        $value[$segment] = $segments === [] ? $leaf : self::set(Data::map(is_array($value[$segment] ?? null) ? $value[$segment] : []), $segments, $leaf);

        return $value;
    }
}
