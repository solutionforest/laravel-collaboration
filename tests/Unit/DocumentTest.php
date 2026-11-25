<?php

declare(strict_types=1);

use SolutionForest\Collaboration\Crdt\Document;
use SolutionForest\Collaboration\Crdt\InvalidOperation;
use SolutionForest\Collaboration\Crdt\RichText;
use SolutionForest\Collaboration\Crdt\RichTextSchema;
use SolutionForest\Collaboration\Enums\FieldKind;
use SolutionForest\Collaboration\Support\Data;

const KINDS = ['title' => FieldKind::Text, 'body' => FieldKind::RichText, 'status' => FieldKind::Value, 'items' => FieldKind::Items];

const ITEM_PATHS = ['items' => ['title', 'done', 'meta.tag']];

/** @return array<string, mixed> */
function richParagraph(string $text, array $marks = []): array
{
    return ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text, ...($marks === [] ? [] : ['marks' => $marks])]]];
}

function sharedDocument(string $title = 'Shared title'): Document
{
    $document = new Document;

    foreach (KINDS as $field => $kind) {
        $document->create($field, $kind);
    }

    $document->apply($document->changes('title', $title));
    $document->apply($document->changes('body', ['type' => 'doc', 'content' => [richParagraph('Hello')]]));

    return $document;
}

it('replays sessions recorded by the browser implementation to the same result', function (): void {
    $cases = json_decode((string) file_get_contents(__DIR__.'/../Fixtures/convergence.json'), true);

    expect($cases)->toHaveCount(60);

    foreach ($cases as $case) {
        $document = new Document;
        $numbers = [];

        foreach (KINDS as $field => $kind) {
            $document->create($field, $kind, $field === 'items' ? ['title'] : []);
        }

        foreach ($case['batches'] as ['client' => $client, 'operations' => $operations]) {
            if ($client === Document::SERVER) {
                $document->apply($operations);
            } else {
                $numbers[$client] = ($numbers[$client] ?? 0) + 1;
                $document->receive($client, $numbers[$client], $operations, KINDS, ITEM_PATHS);
            }
        }

        $stored = Document::from(json_decode(Data::encode($document->toArray()), true));

        foreach ($case['expected'] as $field => $value) {
            expect($stored->value($field))->toEqual($value);
        }
    }
});

it('merges concurrent typing in either arrival order', function (): void {
    $base = sharedDocument('the fox');
    $alice = (clone $base)->changes('title', 'the quick fox', client: 1);
    $bob = (clone $base)->changes('title', 'the fox jumps', client: 2);

    $one = clone $base;
    $one->receive(1, 1, $alice, KINDS);
    $one->receive(2, 1, $bob, KINDS);

    $two = clone $base;
    $two->receive(2, 1, $bob, KINDS);
    $two->receive(1, 1, $alice, KINDS);

    expect($one->value('title'))->toBe('the quick fox jumps')
        ->and($two->value('title'))->toBe('the quick fox jumps')
        ->and($base->value('title'))->toBe('the fox');
});

it('applies a retried batch once', function (): void {
    $document = sharedDocument('a');
    $operations = $document->changes('title', 'ab', client: 1);

    expect($document->receive(1, 1, $operations, KINDS))->toHaveCount(1)
        ->and($document->receive(1, 1, $operations, KINDS))->toBe([])
        ->and($document->value('title'))->toBe('ab')
        ->and($document->lastBatch(1))->toBe(1);
});

it('counts emoji, CJK and combining marks as code points', function (string $value): void {
    $document = sharedDocument('協作🙂');
    $document->receive(1, 1, $document->changes('title', $value, client: 1), KINDS);

    expect($document->value('title'))->toBe($value);
})->with(['即時協作🙂', '協作🙂👍', "e\u{301}協作", "line\nbreak"]);

it('normalises line endings', function (): void {
    $document = sharedDocument('');
    $document->apply($document->changes('title', "a\r\nb"));

    expect($document->value('title'))->toBe("a\nb");
});

it('keeps the latest of two picked values', function (): void {
    $base = sharedDocument();
    $first = (clone $base)->changes('status', 'review', client: 1);
    $second = (clone $base)->changes('status', 'published', client: 2);

    $one = clone $base;
    $one->receive(1, 1, $first, KINDS);
    $one->receive(2, 1, $second, KINDS);

    $two = clone $base;
    $two->receive(2, 1, $second, KINDS);
    $two->receive(1, 1, $first, KINDS);

    expect($one->value('status'))->toBe('published')->and($two->value('status'))->toBe('published');
});

it('refuses a forged operation', function (Closure $forge): void {
    $document = sharedDocument();
    $clock = $document->clock() + 1;
    $block = fn (array $path): array => ['o' => 'ins', 'f' => 'body', 'id' => [$clock, 7], 'after' => null, 'v' => ['b' => 1], 'a' => ['b' => Data::encode($path)]];
    $embed = fn (array $node): array => ['o' => 'ins', 'f' => 'body', 'id' => [$clock, 7], 'after' => null, 'v' => ['n' => Data::encode($node)]];

    expect(fn () => $document->receive(7, 1, [$forge($clock, $block, $embed)], KINDS))->toThrow(InvalidOperation::class);
})->with([
    'another editor\'s id' => [fn (int $c) => ['o' => 'ins', 'f' => 'title', 'id' => [$c, 8], 'after' => null, 'v' => 'x']],
    'the server\'s id' => [fn (int $c) => ['o' => 'ins', 'f' => 'title', 'id' => [$c, 0], 'after' => null, 'v' => 'x']],
    'a field that is not writable' => [fn (int $c) => ['o' => 'ins', 'f' => 'password', 'id' => [$c, 7], 'after' => null, 'v' => 'x']],
    'a runaway clock' => [fn (int $c) => ['o' => 'ins', 'f' => 'title', 'id' => [$c + 9_000_000, 7], 'after' => null, 'v' => 'x']],
    'a position that does not exist' => [fn (int $c) => ['o' => 'ins', 'f' => 'title', 'id' => [$c, 7], 'after' => [2, 999], 'v' => 'x']],
    'a range beyond the document' => [fn () => ['o' => 'del', 'f' => 'title', 'ids' => [[1, 0, 400000]]]],
    'formatting in plain text' => [fn (int $c) => ['o' => 'ins', 'f' => 'title', 'id' => [$c, 7], 'after' => null, 'v' => 'x', 'a' => ['m:bold' => '1']]],
    'an object as a picked value' => [fn (int $c) => ['o' => 'set', 'f' => 'status', 'id' => [$c, 7], 'v' => ['html' => '<img>']]],
    'text written as a value' => [fn (int $c) => ['o' => 'set', 'f' => 'title', 'id' => [$c, 7], 'v' => 'x']],
    'an unknown operation' => [fn (int $c) => ['o' => 'eval', 'f' => 'title', 'id' => [$c, 7]]],
    'a script link' => [fn (int $c) => ['o' => 'ins', 'f' => 'body', 'id' => [$c, 7], 'after' => null, 'v' => 'x', 'a' => ['m:link' => '{"href":"javascript:alert(1)"}']]],
    'an unknown mark' => [fn (int $c) => ['o' => 'ins', 'f' => 'body', 'id' => [$c, 7], 'after' => null, 'v' => 'x', 'a' => ['m:script' => '1']]],
    'a custom block with preview HTML' => [fn (int $c, Closure $block) => $block([['customBlock', ['id' => 'x', 'preview' => 'PHNjcmlwdD4='], 2]])],
    'an event handler attribute' => [fn (int $c, Closure $block) => $block([['paragraph', ['onmouseover' => 'alert(1)'], 0]])],
    'text inside a container' => [fn (int $c, Closure $block) => $block([['bulletList', 0, 0]])],
    'a mention with extra attributes' => [fn (int $c, Closure $block, Closure $embed) => $embed(['attrs' => ['extra' => ['onclick' => 'x']], 'type' => 'mention'])],
    'a script image' => [fn (int $c, Closure $block, Closure $embed) => $embed(['attrs' => ['src' => 'javascript:alert(1)'], 'type' => 'image'])],
]);

it('refuses batches out of order', function (): void {
    $document = sharedDocument();

    expect(fn () => $document->receive(1, 2, $document->changes('title', 'x', client: 1), KINDS))->toThrow(InvalidOperation::class);
});

it('round-trips nested rich content', function (): void {
    $content = ['type' => 'doc', 'content' => [
        ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => 'Heading']]],
        ['type' => 'bulletList', 'content' => [
            ['type' => 'listItem', 'content' => [richParagraph('one')]],
            ['type' => 'listItem', 'content' => [richParagraph('two', [['type' => 'bold']])]],
        ]],
        ['type' => 'table', 'content' => [['type' => 'tableRow', 'content' => [['type' => 'tableCell', 'content' => [richParagraph('cell')]]]]]],
        ['type' => 'horizontalRule'],
        ['type' => 'paragraph', 'content' => [['type' => 'image', 'attrs' => ['src' => 'https://example.com/a.png']]]],
    ]];

    expect(RichText::document(RichText::tokens($content)))->toEqual($content);
});

it('treats default attributes as no change', function (): void {
    $document = sharedDocument();

    $fromBrowser = ['type' => 'doc', 'content' => [[...richParagraph('Hello'), 'attrs' => ['textAlign' => 'start']]]];

    expect($document->changes('body', $fromBrowser))->toBe([]);
});

it('only shares rich content it can keep intact', function (array $node, bool $shareable): void {
    expect(RichTextSchema::canShareLosslessly(['type' => 'doc', 'content' => [$node]]))->toBe($shareable);
})->with([
    'a paragraph' => [['type' => 'paragraph'], true],
    'a custom block' => [['type' => 'customBlock', 'attrs' => ['id' => 'hero']], false],
    'a link with a class' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'x', 'marks' => [['type' => 'link', 'attrs' => ['href' => '/x', 'class' => 'cta']]]]]], false],
    'a base64 image' => [['type' => 'image', 'attrs' => ['src' => 'data:image/png;base64,AAAA']], false],
]);

it('refuses a picked value outside the options this editor was given', function (): void {
    $document = sharedDocument();
    $allowed = ['status' => ['draft', 'review']];

    $document->receive(1, 1, $document->changes('status', 'review', client: 1), KINDS, $allowed);

    expect($document->value('status'))->toBe('review')
        ->and(fn () => $document->receive(1, 2, $document->changes('status', 'published', client: 1), KINDS, $allowed))
        ->toThrow(InvalidOperation::class);
});

it('refuses a forged item operation', function (array $operation): void {
    $document = sharedDocument();
    $document->apply($document->changes('items', ['00000000-0000-4000-8000-000000000001' => ['title' => 'One']]));
    $operation['id'] = [$document->clock() + 1, 7];

    expect(fn () => $document->receive(7, 1, [['f' => 'items', ...$operation]], KINDS, ITEM_PATHS))->toThrow(InvalidOperation::class);
})->with([
    'an invented record key' => [['o' => 'ins', 'after' => null, 'v' => ['i' => 'record-99']]],
    'a sub-field the form does not have' => [['o' => 'put', 'i' => '00000000-0000-4000-8000-000000000001', 'p' => 'is_admin', 'v' => true]],
    'an item that does not exist' => [['o' => 'put', 'i' => '00000000-0000-4000-8000-000000000002', 'p' => 'title', 'v' => 'x']],
    'an object as a value' => [['o' => 'put', 'i' => '00000000-0000-4000-8000-000000000001', 'p' => 'title', 'v' => ['a' => 1]]],
]);

const ITEM = '00000000-0000-4000-8000-000000000001';

function textItems(): Document
{
    $document = new Document;
    $document->create('items', FieldKind::Items, ['title']);
    $document->apply($document->changes('items', [ITEM => ['title' => 'the fox', 'done' => false]]));

    return $document;
}

it('merges typing in one text sub-field of one item, in either arrival order', function (): void {
    $base = textItems();
    $alice = (clone $base)->changes('items', [ITEM => ['title' => 'the quick fox', 'done' => false]], client: 1);
    $bob = (clone $base)->changes('items', [ITEM => ['title' => 'the fox jumps', 'done' => true]], client: 2);

    $one = clone $base;
    $one->receive(1, 1, $alice, KINDS, ITEM_PATHS);
    $one->receive(2, 1, $bob, KINDS, ITEM_PATHS);

    $two = clone $base;
    $two->receive(2, 1, $bob, KINDS, ITEM_PATHS);
    $two->receive(1, 1, $alice, KINDS, ITEM_PATHS);

    expect($one->value('items'))->toBe([ITEM => ['title' => 'the quick fox jumps', 'done' => true]])
        ->and($two->value('items'))->toBe($one->value('items'))
        ->and(Document::from($one->toArray())->value('items'))->toBe($one->value('items'))
        ->and(Document::from($one->toArray())->texts('items'))->toBe(['title']);
});

it('refuses a forged text sub-field operation', function (array $operation): void {
    $document = textItems();
    $operation['id'] = [$document->clock() + 1, 7];

    expect(fn () => $document->receive(7, 1, [['f' => 'items', 'i' => ITEM, ...$operation]], KINDS, ITEM_PATHS))->toThrow(InvalidOperation::class);
})->with([
    'a whole value for a text sub-field' => [['o' => 'put', 'p' => 'title', 'v' => 'replaced']],
    'typing in a sub-field that is not text' => [['o' => 'ins', 'p' => 'done', 'after' => null, 'v' => 'x']],
    'a sub-field the form does not have' => [['o' => 'ins', 'p' => 'is_admin', 'after' => null, 'v' => 'x']],
    'an item as text' => [['o' => 'ins', 'p' => 'title', 'after' => null, 'v' => ['i' => 'x']]],
    'empty text' => [['o' => 'ins', 'p' => 'title', 'after' => null, 'v' => '']],
    'formatting' => [['o' => 'fmt', 'p' => 'title', 'ids' => [[1, 0, 1]], 'k' => 'm:bold', 'v' => '1']],
]);
