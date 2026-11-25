<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use SolutionForest\Collaboration\Merge\ThreeWayMerge;

it('sees edits inside repeaters, whose item keys are random', function (): void {
    $items = fn (string ...$names): array => collect($names)->mapWithKeys(fn (string $name): array => [(string) Str::uuid() => ['name' => $name]])->all();

    expect(ThreeWayMerge::untouched($items('a', 'b'), $items('a', 'b')))->toBeTrue()
        ->and(ThreeWayMerge::untouched($items('a', 'b'), $items('a', 'changed')))->toBeFalse()
        ->and(ThreeWayMerge::untouched($items('a', 'b'), $items('b', 'a')))->toBeFalse()
        ->and(ThreeWayMerge::merge(['rows' => $items('a')], ['rows' => $items('mine')], ['rows' => $items('theirs')])->conflicts)->toBe(['rows']);
});

it('sees a relationship repeater being reordered', function (): void {
    $base = ['record-1' => ['name' => 'a'], 'record-2' => ['name' => 'b']];

    expect(ThreeWayMerge::untouched($base, array_reverse($base, true)))->toBeFalse();
});

it('ignores attributes the browser adds to rich content', function (): void {
    $document = fn (array $attributes): array => ['type' => 'doc', 'content' => [
        ['type' => 'paragraph', ...$attributes, 'content' => [['type' => 'text', 'text' => 'Hi']]],
    ]];

    expect(ThreeWayMerge::untouched($document([]), $document(['attrs' => ['textAlign' => 'start']])))->toBeTrue();
});

enum MergeStatus: string
{
    case Draft = 'draft';
}

enum MergeSize
{
    case Large;
}

it('takes their change to a field I did not touch, and keeps mine where they did not change it', function (): void {
    $result = ThreeWayMerge::merge(
        ['title' => 'Old', 'excerpt' => 'Old', 'status' => 'draft'],
        ['title' => 'Mine', 'excerpt' => 'Old', 'status' => 'draft'],
        ['title' => 'Old', 'excerpt' => 'Theirs', 'status' => 'draft'],
    );

    expect($result->data)->toBe(['title' => 'Mine', 'excerpt' => 'Theirs', 'status' => 'draft'])
        ->and($result->taken)->toBe(['excerpt'])
        ->and($result->conflicts)->toBe([])
        ->and($result->hasConflicts())->toBeFalse();
});

it('is no conflict when both made the same change', function (): void {
    $result = ThreeWayMerge::merge(['title' => 'Old'], ['title' => 'Same'], ['title' => 'Same']);

    expect($result->conflicts)->toBe([])->and($result->data['title'])->toBe('Same');
});

it('compares values the way a form fills them', function (mixed $left, mixed $right, bool $same): void {
    expect(ThreeWayMerge::same($left, $right))->toBe($same);
})->with([
    'a backed enum and its value' => [MergeStatus::Draft, 'draft', true],
    'a pure enum and its name' => [MergeSize::Large, 'Large', true],
    'dates at the same moment' => [new DateTimeImmutable('2026-01-02 03:04:05+00:00'), new DateTime('2026-01-02 03:04:05+00:00'), true],
    'a Stringable and its text' => [str('Hello'), 'Hello', true],
    'true and "1"' => [true, '1', true],
    'false and "0"' => [false, '0', true],
    'empty string, null and an empty list' => ['', [], true],
    'an object and an array' => [(object) ['a' => 1], ['a' => 1], true],
    'a number and its text' => [5, '5', true],
    'a decimal and its text' => [1.5, '1.50', true],
    'a code with a leading zero' => ['007', '7', false],
    'exponent notation stays text' => ['1e3', '1000', false],
    'different keys in another order' => [['b' => 1, 'a' => 2], ['a' => 2, 'b' => 1], true],
]);

it('compares text exactly when told to', function (): void {
    expect(ThreeWayMerge::same(5, '5', exact: true))->toBeFalse()
        ->and(ThreeWayMerge::changed(['a' => 1, 'b' => 2], ['a' => 1, 'b' => 3, 'c' => null]))->toBe(['b']);
});

it('compares rich content it cannot read as it is', function (): void {
    $custom = ['type' => 'doc', 'content' => [['type' => 'customBlock', 'attrs' => ['id' => 1]]]];

    expect(ThreeWayMerge::same($custom, $custom))->toBeTrue()
        ->and(ThreeWayMerge::same($custom, ['type' => 'doc', 'content' => []]))->toBeFalse();
});
