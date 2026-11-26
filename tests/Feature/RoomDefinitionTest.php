<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use SolutionForest\Collaboration\Collaboration;
use SolutionForest\Collaboration\Enums\FieldKind;
use SolutionForest\Collaboration\Exceptions\UnknownField;
use SolutionForest\Collaboration\Exceptions\UnknownFieldKind;
use SolutionForest\Collaboration\Rooms\RoomContext;
use SolutionForest\Collaboration\Tests\Fixtures\Enums\PostStatus;
use SolutionForest\Collaboration\Tests\Fixtures\Models\TypedPost;
use SolutionForest\Collaboration\Tests\Fixtures\Models\User;
use SolutionForest\Collaboration\Tests\Fixtures\Policies\PostPolicy;

beforeEach(function (): void {
    Gate::policy(TypedPost::class, PostPolicy::class);

    // No `Collaborative` trait: each test describes its own room.
    $this->post = TypedPost::create(['title' => 'Hello', 'status' => PostStatus::Draft]);
    $this->actingAs(User::create(['name' => 'Alice', 'email' => 'alice@example.com']));
});

it('takes a field\'s kind from the model\'s casts', function (): void {
    $room = Collaboration::room($this->post)->fields(['title', 'status', 'created_at']);

    expect($room->sharedFields())->toBe([
        'title' => FieldKind::Text,
        'status' => FieldKind::Value,
        'created_at' => FieldKind::Value,
    ]);
});

it('accepts kinds as FieldKind cases', function (): void {
    expect(Collaboration::room($this->post)->fields(['body' => FieldKind::RichText])->sharedFields())
        ->toBe(['body' => FieldKind::RichText]);
});

it('says which kinds exist when given one that does not', function (): void {
    Collaboration::room($this->post)->fields(['body' => 'richtext']);
})->throws(UnknownFieldKind::class, '[richtext] is not a kind of field, for [body]. Use text, rich, value, items, or a FieldKind case. Did you mean [rich]?');

it('catches a misspelt field name while developing', function (): void {
    config(['app.debug' => true]);

    Collaboration::room($this->post)->text('titel')->toArray();
})->throws(UnknownField::class, '[TypedPost] has no attribute [titel] to collaborate on. Did you mean [title]?');

it('lets a picked field take only the cases of its enum cast', function (): void {
    $context = RoomContext::fromToken(Collaboration::room($this->post)->value('status')->toArray()['token']);

    expect($context?->allowed)->toBe(['status' => ['draft', 'published']]);
});

it('tells the browser which fields are locked', function (): void {
    expect(Collaboration::room($this->post)->text('title')->value('status')->lock('status', 'nothing')->toArray()['locks'])
        ->toBe(['status']);
});
