<?php

declare(strict_types=1);

use SolutionForest\Collaboration\Presence\Initials;
use SolutionForest\Collaboration\Presence\Palette;

it('only uses colours that white text is readable on', function (string $color): void {
    $luminance = collect(str_split(substr($color, 1), 2))
        ->map(fn (string $hex): float => hexdec($hex) / 255)
        ->map(fn (float $value): float => $value <= 0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4)
        ->zip([0.2126, 0.7152, 0.0722])
        ->sum(fn ($pair): float => $pair[0] * $pair[1]);

    expect(1.05 / ($luminance + 0.05))->toBeGreaterThanOrEqual(4.5);
})->with(Palette::DEFAULT);

it('never puts an invalid colour into a style attribute', function (): void {
    expect((new Palette(['red;background:url(x)']))->colorFor('someone'))->toMatch('/^#[0-9a-f]{6}$/');
});

it('gives everybody in a room a different colour until they run out', function (): void {
    $keys = array_map(fn (int $number): string => "user-{$number}", range(1, 40));
    $colors = (new Palette)->assign($keys);

    expect(array_unique(array_slice($colors, 0, count(Palette::DEFAULT))))->toHaveCount(count(Palette::DEFAULT))
        ->and($colors)->toHaveCount(40);
});

it('makes initials from any name', function (string $name, string $initials): void {
    expect(Initials::for($name))->toBe($initials);
})->with([
    ['Alice Chen', 'AC'],
    ['陳大文', '陳'],
    ['Ana de la Vega', 'AV'],
    ['  ', '?'],
]);
