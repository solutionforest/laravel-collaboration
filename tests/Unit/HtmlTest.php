<?php

declare(strict_types=1);

use SolutionForest\Collaboration\Crdt\Html;
use SolutionForest\Collaboration\Crdt\RichText;

/** @param array<string, mixed> $document */
function sharedForm(array $document): array
{
    return RichText::document(RichText::tokens($document));
}

it('reads editors\' HTML exactly as the browser does', function (): void {
    $cases = json_decode((string) file_get_contents(__DIR__.'/../Fixtures/html.json'), true);

    expect($cases)->not->toBeEmpty();

    foreach ($cases as ['html' => $html, 'document' => $document]) {
        expect(sharedForm(Html::toDocument($html)))->toEqual($document, $html);
    }
});

it('writes HTML that reads back as the same document', function (): void {
    $cases = json_decode((string) file_get_contents(__DIR__.'/../Fixtures/html.json'), true);

    foreach ($cases as ['html' => $html, 'document' => $document]) {
        expect(sharedForm(Html::toDocument(Html::fromDocument($document))))->toEqual($document, $html);
    }
});

it('never lets markup outside the schema through', function (): void {
    $html = Html::fromDocument(sharedForm(Html::toDocument('<p onclick="x()">Hi<script>alert(1)</script> <a href="javascript:alert(1)">x</a></p><iframe src="https://evil.example"></iframe>')));

    expect($html)->not->toContain('onclick')
        ->and($html)->not->toContain('script')
        ->and($html)->not->toContain('javascript:')
        ->and($html)->not->toContain('iframe');
});
