<?php

declare(strict_types=1);

namespace SolutionForest\Collaboration\Crdt;

use SolutionForest\Collaboration\Enums\FieldKind;
use SolutionForest\Collaboration\Support\Data;

/**
 * The shared draft of one record. Pure data: no database, HTTP or Filament.
 *
 * The browser implementation is `resources/js/crdt/document.js`. Both replay
 * `tests/Fixtures/convergence.json`, so they cannot drift apart unnoticed.
 */
final class Document
{
    public const SERVER = 0;

    private const MAX_ITEMS = 500_000;

    private const MAX_RANGES = 20_000;

    private const MAX_VALUE_BYTES = 65_535;

    /** @var array<string, Sequence|Register|ItemList> */
    private array $fields = [];

    /** @var array<string, FieldKind> */
    private array $kinds = [];

    private int $clock = 0;

    /** @var array<string, array{int, int, int}> each editor's last batch, its highest clock and that batch's checksum */
    private array $clients = [];

    public static function from(mixed $data): self
    {
        $data = Data::map($data);
        $document = new self;
        $document->clock = Data::int($data['clock'] ?? null);

        foreach (Data::map($data['fields'] ?? null) as $name => $field) {
            $field = Data::map($field);
            $kind = FieldKind::tryFrom(Data::string($field['k'] ?? null));

            if ($kind !== null) {
                $document->kinds[$name] = $kind;
                $document->fields[$name] = match ($kind) {
                    FieldKind::Value => Register::from($field),
                    FieldKind::Items => ItemList::from($field),
                    default => Sequence::from($field['r'] ?? null),
                };
            }
        }

        foreach (Data::map($data['clients'] ?? null) as $key => $seen) {
            $seen = Data::list($seen);
            $document->clients[$key] = [Data::int($seen[0] ?? null), Data::int($seen[1] ?? null), Data::int($seen[2] ?? null)];
        }

        return $document;
    }

    public function __clone()
    {
        $this->fields = array_map(static fn (Sequence|Register|ItemList $field): Sequence|Register|ItemList => clone $field, $this->fields);
    }

    public function clock(): int
    {
        return $this->clock;
    }

    public function has(string $field): bool
    {
        return isset($this->fields[$field]);
    }

    public function kind(string $field): ?FieldKind
    {
        return $this->kinds[$field] ?? null;
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_map(strval(...), array_keys($this->fields));
    }

    /** @param list<string> $texts a list's sub-fields that merge by character */
    public function create(string $field, FieldKind $kind, array $texts = []): void
    {
        $this->kinds[$field] = $kind;
        $this->fields[$field] = match ($kind) {
            FieldKind::Value => new Register,
            FieldKind::Items => new ItemList(texts: $texts),
            default => new Sequence,
        };
    }

    /** @return list<string> a list's sub-fields that merge by character */
    public function texts(string $field): array
    {
        $state = $this->fields[$field] ?? null;

        return $state instanceof ItemList ? $state->texts : [];
    }

    public function value(string $field): mixed
    {
        $state = $this->fields[$field] ?? null;

        return match (true) {
            $state instanceof Register => $state->value,
            $state instanceof ItemList => $state->value(),
            $state instanceof Sequence => $this->kinds[$field] === FieldKind::RichText
                ? RichText::document($state->tokens())
                : $state->text(),
            default => null,
        };
    }

    public function contentEquals(self $other, string $field): bool
    {
        return $this->signature($field) === $other->signature($field);
    }

    /** Copies one field, with its history, from another replica of this document. */
    public function adopt(self $other, string $field): void
    {
        if (isset($other->fields[$field], $other->kinds[$field])) {
            $this->fields[$field] = clone $other->fields[$field];
            $this->kinds[$field] = $other->kinds[$field];
            $this->clock = max($this->clock, $other->clock);
        }
    }

    /**
     * The operations that turn this field into the given value.
     *
     * @param  int|null  $clock  pass the clock of the document the operations will be applied to
     * @return list<array<string, mixed>>
     */
    public function changes(string $field, mixed $value, ?int $clock = null, int $client = self::SERVER): array
    {
        $clock ??= $this->clock;
        $state = $this->fields[$field] ?? null;

        if ($state instanceof Register) {
            $value = Register::normalize($value);

            return Register::normalize($state->value) === $value
                ? []
                : [['o' => 'set', 'f' => $field, 'id' => [$clock + 1, $client], 'v' => $value]];
        }

        if ($state instanceof ItemList) {
            return $state->changes($field, Data::map($value), $clock, $client);
        }

        if (! $state instanceof Sequence) {
            return [];
        }

        $old = $state->tokens();

        if ($this->kinds[$field] === FieldKind::RichText) {
            $new = RichText::tokens($value);
            $normalized = RichText::tokens(RichText::document($old));

            // Paths are relative to the preceding block; compare them in normal form.
            if (count($normalized) === count($old)) {
                $old = array_map(
                    static fn (Token $token, Token $normal): Token => new Token($token->value, $normal->formatting, $token->id),
                    $old,
                    $normalized,
                );
            }
        } else {
            $text = str_replace(["\r\n", "\r"], "\n", Data::string($value));
            $new = array_map(static fn (string $character): Token => new Token($character), mb_str_split($text, 1, 'UTF-8'));
        }

        return Diff::operations($field, $old, $new, $clock, $client);
    }

    /**
     * Applies operations created by this package. Use `receive()` for a browser's.
     *
     * @param  list<array<string, mixed>>  $operations
     */
    public function apply(array $operations): void
    {
        foreach ($operations as $operation) {
            $this->applyOne($operation);
        }
    }

    /**
     * Validates and applies a batch from a browser, exactly once. On failure
     * this instance must be thrown away, as part of the batch may have applied.
     *
     * @param  array<string, FieldKind>  $writable
     * @param  array<string, list<string>>  $allowed  the only values a picked field may take, where known
     * @return list<array<string, mixed>> the operations that were new
     */
    public function receive(int $client, int $batch, mixed $operations, array $writable, array $allowed = []): array
    {
        $key = 'c'.$client;
        [$lastBatch, $lastClock, $lastChecksum] = $this->clients[$key] ?? [0, 0, 0];
        $checksum = crc32(Data::encode($operations));

        if ($batch <= $lastBatch) {
            if ($batch === $lastBatch && $batch > 0 && $checksum !== $lastChecksum) {
                throw new InvalidOperation('A batch number was reused for different changes.');
            }

            return [];
        }

        if ($client === self::SERVER || $batch !== $lastBatch + 1 || ! is_array($operations) || ! array_is_list($operations) || count($operations) > 5000) {
            throw new InvalidOperation('The batch is out of order or malformed.');
        }

        $accepted = [];

        foreach ($operations as $operation) {
            $operation = $this->validate(Data::map($operation), $client, $lastClock, $writable, $allowed);
            $this->applyOne($operation);
            $accepted[] = $operation;

            if (isset($operation['id'])) {
                $lastClock = Id::from($operation['id'])->clock + self::span($operation) - 1;
            }
        }

        $this->clients[$key] = [$batch, $lastClock, $checksum];

        return $accepted;
    }

    public function lastBatch(int $client): int
    {
        return $this->clients['c'.$client][0] ?? 0;
    }

    /**
     * @param  list<string>|null  $only
     * @return array{clock: int, fields: array<string, array<string, mixed>>, clients: array<string, array{int, int, int}>}
     */
    public function toArray(?array $only = null): array
    {
        $fields = [];

        foreach ($this->fields as $name => $state) {
            if ($only !== null && ! in_array((string) $name, $only, true)) {
                continue;
            }

            $fields[(string) $name] = match (true) {
                $state instanceof Register => ['k' => FieldKind::Value->value, ...$state->toArray()],
                $state instanceof ItemList => ['k' => FieldKind::Items->value, ...$state->toArray()],
                default => ['k' => $this->kinds[$name]->value, 'r' => $state->toArray()],
            };
        }

        return ['clock' => $this->clock, 'fields' => $fields, 'clients' => $only === null ? $this->clients : []];
    }

    private function signature(string $field): mixed
    {
        $state = $this->fields[$field] ?? null;

        return match (true) {
            $state instanceof Register => Register::normalize($state->value),
            $state instanceof ItemList => $state->signature(),
            $state instanceof Sequence => $state->signature(),
            default => null,
        };
    }

    /** @param array<string, mixed> $operation */
    private function applyOne(array $operation): void
    {
        $type = Data::string($operation['o'] ?? null);
        $state = $this->fields[Data::string($operation['f'] ?? null)] ?? throw new InvalidOperation('The field does not exist.');

        if ($state instanceof Register) {
            if ($type !== 'set') {
                throw new InvalidOperation('A value can only be set.');
            }

            $state->set($operation['v'] ?? null, Id::from($operation['id'] ?? null));
        } elseif ($state instanceof ItemList && $type === 'put') {
            $state->put(Data::string($operation['i'] ?? null), Data::string($operation['p'] ?? null), $operation['v'] ?? null, Id::from($operation['id'] ?? null));
        } elseif ($state instanceof ItemList && isset($operation['p'])) {
            $this->applyToSequence($state->text(Data::string($operation['i'] ?? null), Data::string($operation['p'])), $type, $operation);
        } elseif ($state instanceof ItemList) {
            $this->applyToSequence($state->order, $type, $operation);
        } elseif ($type === 'ins') {
            $value = $operation['v'] ?? null;
            $formatting = [];

            foreach (Data::map($operation['a'] ?? null) as $key => $item) {
                $formatting[$key] = Data::string($item);
            }

            $state->insert(
                Id::from($operation['id'] ?? null),
                isset($operation['after']) ? Id::from($operation['after']) : null,
                is_string($value) ? $value : Data::map($value),
                $formatting,
            );
        } elseif ($type === 'del' || $type === 'fmt') {
            foreach (Data::list($operation['ids'] ?? null) as $range) {
                $from = Id::from($range);
                $length = Data::int(Data::list($range)[2] ?? null);

                if ($type === 'del') {
                    $state->delete($from, $length);
                } else {
                    $state->format($from, $length, Data::string($operation['k'] ?? null), Data::nullableString($operation['v'] ?? null), Id::from($operation['id'] ?? null));
                }
            }
        } else {
            throw new InvalidOperation('The operation is unknown.');
        }

        if (isset($operation['id'])) {
            $this->clock = max($this->clock, Id::from($operation['id'])->clock + self::span($operation) - 1);
        }
    }

    /** @param array<string, mixed> $operation */
    private function applyToSequence(Sequence $sequence, string $type, array $operation): void
    {
        if ($type === 'ins') {
            $value = $operation['v'] ?? null;

            $sequence->insert(
                Id::from($operation['id'] ?? null),
                isset($operation['after']) ? Id::from($operation['after']) : null,
                is_string($value) ? $value : Data::map($value),
                [],
            );

            return;
        }

        if ($type !== 'del') {
            throw new InvalidOperation('The operation does not suit this field.');
        }

        foreach (Data::list($operation['ids'] ?? null) as $range) {
            $sequence->delete(Id::from($range), Data::int(Data::list($range)[2] ?? null));
        }
    }

    /**
     * @param  array<string, mixed>  $operation
     * @param  array<string, FieldKind>  $writable
     * @param  array<string, list<string>>  $allowed
     * @return array<string, mixed> only the keys this package understands
     */
    private function validate(array $operation, int $client, int $lastClock, array $writable, array $allowed): array
    {
        $field = Data::string($operation['f'] ?? null);
        $kind = $writable[$field] ?? null;

        if ($kind === null || $kind !== $this->kind($field)) {
            throw new InvalidOperation('This field is not writable.');
        }

        $type = $operation['o'] ?? null;
        $clean = ['o' => $type, 'f' => $field];

        if ($type === 'del' || $type === 'fmt') {
            $clean['ids'] = $this->validateRanges($operation['ids'] ?? null);
        }

        if ($kind === FieldKind::Items) {
            return $this->validateItemOperation($clean, $operation, $client, $lastClock, $allowed[$field] ?? [], $allowed[$field."\0files"] ?? null);
        }

        if ($type === 'del' && $kind !== FieldKind::Value) {
            return $clean;
        }

        $id = Id::from($operation['id'] ?? null);

        if ($id->client !== $client || $id->clock <= $lastClock || $id->clock > $this->clock + self::MAX_ITEMS) {
            throw new InvalidOperation('The operation id is not valid for this editor.');
        }

        $clean['id'] = $id->toArray();
        $value = $operation['v'] ?? null;
        $isRich = $kind === FieldKind::RichText;

        if ($type === 'set' && $kind === FieldKind::Value) {
            if (! self::isSimpleValue($value) || strlen(Data::encode($value)) > self::MAX_VALUE_BYTES) {
                throw new InvalidOperation('The value cannot be shared.');
            }

            if (isset($allowed[$field]) && ! self::isAllowed($value, $allowed[$field])) {
                throw new InvalidOperation('The value is not one this editor may choose.');
            }

            return [...$clean, 'v' => $value];
        }

        if ($type === 'fmt' && $isRich) {
            $key = $operation['k'] ?? null;

            if (! is_string($key) || ($value !== null && ! is_string($value))) {
                throw new InvalidOperation('The formatting is malformed.');
            }

            RichText::assertFormatting($key, $value, $key === 'b');

            return [...$clean, 'k' => $key, 'v' => $value];
        }

        if ($type !== 'ins' || $kind === FieldKind::Value) {
            throw new InvalidOperation('The operation does not suit this field.');
        }

        $after = isset($operation['after']) ? Id::from($operation['after']) : null;

        if ($after !== null && $id->clock <= $after->clock) {
            throw new InvalidOperation('The insertion precedes its position.');
        }

        $isBlock = $isRich && $value === ['b' => 1];

        if (is_string($value)) {
            if ($value === '' || ! mb_check_encoding($value, 'UTF-8')) {
                throw new InvalidOperation('The inserted text is not valid.');
            }
        } elseif (! $isBlock) {
            if (! $isRich || ! is_array($value) || array_keys($value) !== ['n'] || ! is_string($value['n'])) {
                throw new InvalidOperation('The inserted item is not valid.');
            }

            RichText::assertEmbed($value['n']);
        }

        $sequence = $this->fields[$field];
        $length = is_string($value) ? mb_strlen($value, 'UTF-8') : 1;

        if ($sequence instanceof Sequence && $sequence->length() + $length > self::MAX_ITEMS) {
            throw new InvalidOperation('The field is too large to edit live.');
        }

        $formatting = [];

        foreach (Data::map($operation['a'] ?? null) as $key => $item) {
            if (! $isRich || ! is_string($item)) {
                throw new InvalidOperation('The formatting is malformed.');
            }

            RichText::assertFormatting($key, $item, $isBlock);
            $formatting[$key] = $item;
        }

        if ($isBlock && ! isset($formatting['b'])) {
            throw new InvalidOperation('A block needs a path.');
        }

        $clean += ['after' => $after?->toArray(), 'v' => $value];

        return $formatting === [] ? $clean : [...$clean, 'a' => $formatting];
    }

    /**
     * @param  array<string, mixed>  $clean
     * @param  array<string, mixed>  $operation
     * @param  list<string>  $paths  the sub-field paths items may have
     * @param  list<string>|null  $files  for uploads: the only files an item may be
     * @return array<string, mixed>
     */
    private function validateItemOperation(array $clean, array $operation, int $client, int $lastClock, array $paths, ?array $files = null): array
    {
        $list = $this->fields[Data::string($clean['f'])] ?? null;
        $type = $clean['o'];
        $value = $operation['v'] ?? null;

        if (! $list instanceof ItemList) {
            throw new InvalidOperation('The field is not a list.');
        }

        if (isset($operation['p']) && $type !== 'put') {
            return $this->validateItemText($list, $clean, $operation, $client, $lastClock, $paths);
        }

        if ($type === 'del') {
            return $clean;
        }

        $id = Id::from($operation['id'] ?? null);

        if ($id->client !== $client || $id->clock <= $lastClock || $id->clock > $this->clock + self::MAX_ITEMS) {
            throw new InvalidOperation('The operation id is not valid for this editor.');
        }

        $clean['id'] = $id->toArray();

        if ($type === 'ins') {
            $key = is_array($value) && array_keys($value) === ['i'] ? $value['i'] : null;

            // A new item needs a new random key; "record-5" may only move, never be invented.
            $isNewKey = is_string($key) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD', $key) === 1;

            if (! is_string($key) || (! $isNewKey && ! $list->hasKey($key))) {
                throw new InvalidOperation('The item key is not valid.');
            }

            $after = isset($operation['after']) ? Id::from($operation['after']) : null;

            if ($after !== null && $id->clock <= $after->clock) {
                throw new InvalidOperation('The insertion precedes its position.');
            }

            if ($list->order->length() >= 2000) {
                throw new InvalidOperation('The list is too long to edit live.');
            }

            return [...$clean, 'after' => $after?->toArray(), 'v' => ['i' => $key]];
        }

        if ($type !== 'put') {
            throw new InvalidOperation('The operation does not suit this field.');
        }

        $key = $operation['i'] ?? null;
        $path = $operation['p'] ?? null;

        if (! is_string($key) || ! $list->hasKey($key) || ! is_string($path) || ! in_array($path, $paths, true) || $list->isText($path)) {
            throw new InvalidOperation('The item or sub-field is not valid.');
        }

        if (! self::isSimpleValue($value) || strlen(Data::encode($value)) > self::MAX_VALUE_BYTES) {
            throw new InvalidOperation('The value cannot be shared.');
        }

        // An upload may only name a file stored for this draft, or one the record already has.
        if ($files !== null && $value !== null && ! in_array($value, $files, true)) {
            throw new InvalidOperation('The file was not uploaded here.');
        }

        return [...$clean, 'i' => $key, 'p' => $path, 'v' => $value];
    }

    /**
     * Typing in a list's text sub-field.
     *
     * @param  array<string, mixed>  $clean
     * @param  array<string, mixed>  $operation
     * @param  list<string>  $paths
     * @return array<string, mixed>
     */
    private function validateItemText(ItemList $list, array $clean, array $operation, int $client, int $lastClock, array $paths): array
    {
        $key = $operation['i'] ?? null;
        $path = $operation['p'] ?? null;

        if (! is_string($key) || ! $list->hasKey($key) || ! is_string($path) || ! in_array($path, $paths, true) || ! $list->isText($path)) {
            throw new InvalidOperation('The item or sub-field is not valid.');
        }

        $clean += ['i' => $key, 'p' => $path];

        if ($clean['o'] === 'del') {
            return $clean;
        }

        if ($clean['o'] !== 'ins') {
            throw new InvalidOperation('The operation does not suit this field.');
        }

        $id = Id::from($operation['id'] ?? null);
        $after = isset($operation['after']) ? Id::from($operation['after']) : null;
        $value = $operation['v'] ?? null;

        if ($id->client !== $client || $id->clock <= $lastClock || $id->clock > $this->clock + self::MAX_ITEMS) {
            throw new InvalidOperation('The operation id is not valid for this editor.');
        }

        if ($after !== null && $id->clock <= $after->clock) {
            throw new InvalidOperation('The insertion precedes its position.');
        }

        if (! is_string($value) || $value === '' || ! mb_check_encoding($value, 'UTF-8')) {
            throw new InvalidOperation('The inserted text is not valid.');
        }

        if ($list->textLength($key, $path) + mb_strlen($value, 'UTF-8') > ItemList::MAX_TEXT) {
            throw new InvalidOperation('The sub-field is too large to edit live.');
        }

        return [...$clean, 'id' => $id->toArray(), 'after' => $after?->toArray(), 'v' => $value];
    }

    /** @return list<array{int, int, int}> */
    private function validateRanges(mixed $ranges): array
    {
        if (! is_array($ranges) || $ranges === [] || count($ranges) > self::MAX_RANGES) {
            throw new InvalidOperation('The ranges are malformed.');
        }

        $clean = [];

        foreach ($ranges as $range) {
            $from = Id::from($range);
            $length = Data::list($range)[2] ?? null;

            if (! is_int($length) || $length < 1 || $length > self::MAX_ITEMS || $from->clock + $length - 1 > $this->clock) {
                throw new InvalidOperation('A range is out of bounds.');
            }

            $clean[] = [$from->clock, $from->client, $length];
        }

        return $clean;
    }

    /**
     * Whether a picked value is among the options this editor was offered.
     *
     * @param  list<string>  $allowed
     */
    public static function isAllowed(mixed $value, array $allowed): bool
    {
        foreach (is_array($value) ? $value : [$value] as $item) {
            $item = is_bool($item) ? ($item ? '1' : '0') : $item;

            if ($item !== null && $item !== '' && ! in_array(Data::string($item), $allowed, true)) {
                return false;
            }
        }

        return true;
    }

    private static function isSimpleValue(mixed $value): bool
    {
        if ($value === null || is_scalar($value)) {
            return true;
        }

        return is_array($value) && array_is_list($value) && array_filter($value, static fn (mixed $item): bool => $item !== null && ! is_scalar($item)) === [];
    }

    /** @param array<string, mixed> $operation */
    private static function span(array $operation): int
    {
        $value = $operation['v'] ?? null;

        return ($operation['o'] ?? null) === 'ins' && is_string($value) ? max(1, mb_strlen($value, 'UTF-8')) : 1;
    }
}
