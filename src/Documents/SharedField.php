<?php

declare(strict_types=1);

namespace SolutionForest\Collaboration\Documents;

use SolutionForest\Collaboration\Crdt\ItemList;
use SolutionForest\Collaboration\Crdt\RichTextSchema;
use SolutionForest\Collaboration\Enums\FieldKind;
use SolutionForest\Collaboration\Support\Data;

/** A field's kind and the value the database currently holds for it. */
final readonly class SharedField
{
    private const MAX_BYTES = 1_000_000;

    /** @param list<string> $texts a list's sub-fields that merge by character */
    public function __construct(
        public string $name,
        public FieldKind $kind,
        public mixed $value,
        public array $texts = [],
    ) {}

    public function canBeShared(): bool
    {
        $encoded = Data::encode($this->value);

        if (strlen($encoded) > self::MAX_BYTES) {
            return false;
        }

        return match ($this->kind) {
            FieldKind::Text => $this->value === null || is_scalar($this->value),
            FieldKind::RichText => RichTextSchema::canShareLosslessly(['type' => 'doc', ...Data::decode($encoded)]),
            FieldKind::Value => self::isSimple($this->value),
            FieldKind::Items => $this->value === null || (is_array($this->value) && self::hasSimpleItems($this->value)),
        };
    }

    private static function isSimple(mixed $value): bool
    {
        return $value === null
            || ! is_array($value)
            || array_filter($value, static fn (mixed $item): bool => $item !== null && ! is_scalar($item)) === [];
    }

    /** @param array<mixed> $items */
    private static function hasSimpleItems(array $items): bool
    {
        if (count($items) > 500) {
            return false;
        }

        foreach ($items as $key => $item) {
            if (! is_string($key) || preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $key) !== 1) {
                return false;
            }

            foreach (ItemList::flatten($item) as $leaf) {
                if (! self::isSimple($leaf)) {
                    return false;
                }
            }
        }

        return true;
    }
}
