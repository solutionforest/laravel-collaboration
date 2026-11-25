<?php

declare(strict_types=1);

namespace SolutionForest\Collaboration\Presence;

final readonly class Palette
{
    /** Every colour carries white text at WCAG AA contrast. */
    public const DEFAULT = [
        '#1a73e8', '#c5221f', '#137333', '#9334e6', '#b45309', '#007b83', '#b80672', '#174ea6',
        '#5f6b12', '#8e4b2c', '#4f46e5', '#be123c', '#0f6e56', '#7c3aed', '#9a3412', '#0369a1',
    ];

    /** @param list<string> $colors */
    public function __construct(private array $colors = self::DEFAULT) {}

    public function colorFor(string $key): string
    {
        $colors = $this->usable();

        return $colors[crc32($key) % count($colors)];
    }

    /**
     * Colours for everybody in one room: nobody shares one until they run out.
     * A key keeps its own colour unless somebody who joined earlier has it.
     *
     * @param  list<string>  $keys  in the order they joined
     * @return array<string, string>
     */
    public function assign(array $keys): array
    {
        $colors = $this->usable();
        $count = count($colors);
        $taken = [];
        $assigned = [];

        foreach ($keys as $key) {
            if (isset($assigned[$key])) {
                continue;
            }

            if (count($taken) === $count) {
                $taken = [];
            }

            $index = crc32($key) % $count;

            while (isset($taken[$index])) {
                $index = ($index + 1) % $count;
            }

            $taken[$index] = true;
            $assigned[$key] = $colors[$index];
        }

        return $assigned;
    }

    /** @return non-empty-list<string> */
    private function usable(): array
    {
        $colors = array_values(array_filter($this->colors, static fn (string $color): bool => preg_match('/^#[0-9a-f]{6}$/iD', $color) === 1));

        return $colors === [] ? self::DEFAULT : $colors;
    }
}
