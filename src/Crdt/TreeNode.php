<?php

declare(strict_types=1);

namespace SolutionForest\Collaboration\Crdt;

/** @internal */
final class TreeNode
{
    /** @var list<self> */
    public array $children = [];

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>  $marks
     */
    public function __construct(
        public string $type,
        public array $attributes = [],
        public string $text = '',
        public array $marks = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $node = ['type' => $this->type];

        if ($this->attributes !== []) {
            $node['attrs'] = $this->attributes;
        }

        if ($this->marks !== []) {
            $node['marks'] = $this->marks;
        }

        if ($this->type === 'text') {
            $node['text'] = $this->text;

            return $node;
        }

        $children = [];
        $previous = null;

        foreach ($this->children as $child) {
            if ($child->type === 'text' && $previous?->type === 'text' && $previous->marks === $child->marks) {
                $previous->text .= $child->text;

                continue;
            }

            $children[] = $previous = $child->type === 'text' ? clone $child : $child;
        }

        if ($children !== []) {
            $node['content'] = array_map(static fn (self $child): array => $child->toArray(), $children);
        }

        return $node;
    }
}
