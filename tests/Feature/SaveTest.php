<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use SolutionForest\Collaboration\Collaboration;
use SolutionForest\Collaboration\Room;
use SolutionForest\Collaboration\Tests\Fixtures\Models\Post;
use SolutionForest\Collaboration\Tests\Fixtures\Models\User;
use SolutionForest\Collaboration\Tests\Fixtures\Policies\PostPolicy;
use SolutionForest\Collaboration\Tests\Support\Browser;

beforeEach(function (): void {
    Gate::policy(Post::class, PostPolicy::class);

    $this->post = Post::create(['title' => 'Hello', 'summary' => 'Summary', 'body' => '<p>Body</p>', 'status' => 'draft']);
    $this->alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
    $this->bob = User::create(['name' => 'Bob', 'email' => 'bob@example.com']);
});

it('saves everybody\'s edits, not only the saving person\'s', function (): void {
    $alice = new Browser($this, $this->alice, $this->post->collaboration());
    $bob = new Browser($this, $this->bob, $this->post->collaboration());
    $alice->sync();
    $bob->sync();

    $alice->type('title', 'Hello world')->sync();
    $bob->type('status', 'published');

    // Bob's form still shows the old title: the draft has the new one.
    $this->actingAs($this->bob);
    $this->post->collaboration()->save(['title' => 'Hello', 'status' => 'published'], fn (array $values) => $this->post->update($values), $bob->payload());

    expect($this->post->fresh())
        ->title->toBe('Hello world')
        ->status->toBe('published');
});

it('includes what was typed but not yet sent when the save overtakes the sync', function (): void {
    $alice = new Browser($this, $this->alice, $this->post->collaboration());
    $alice->sync();

    $alice->type('summary', 'Summary, typed just before saving');

    $this->actingAs($this->alice);
    $this->post->collaboration()->save(['summary' => 'Summary'], fn (array $values) => $this->post->update($values), $alice->payload());

    expect($this->post->fresh()->summary)->toBe('Summary, typed just before saving');
});

it('keeps rich text as HTML when the column holds HTML', function (): void {
    $alice = new Browser($this, $this->alice, $this->post->collaboration());
    $alice->sync();

    $alice->type('body', ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'New ', 'marks' => [['type' => 'bold']]], ['type' => 'text', 'text' => 'body']]]]]);

    $this->actingAs($this->alice);
    $this->post->collaboration()->save([], fn (array $values) => $this->post->update($values), $alice->payload());

    expect($this->post->fresh()->body)->toBe('<p><strong>New </strong>body</p>');
});

it('keeps rich text as JSON when the column is cast to an array', function (): void {
    $room = Collaboration::room($this->post)->rich('content');
    $alice = new Browser($this, $this->alice, $room);
    $alice->sync();

    $document = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Kept as JSON']]]]];
    $alice->type('content', $document);

    $this->actingAs($this->alice);
    $room->save([], fn (array $values) => $this->post->update($values), $alice->payload());

    expect($this->post->fresh()->content)->toBe($document);
});

it('reads the live edits from the request by default', function (): void {
    $alice = new Browser($this, $this->alice, $this->post->collaboration());
    $alice->sync();
    $alice->type('title', 'From the request');

    $this->app['router']->post('/posts/{post}', fn (Post $post) => $post->collaboration()->save(request()->only('title'), fn (array $values) => $post->update($values)))
        ->middleware('web');

    $this->actingAs($this->alice)->post('/posts/'.$this->post->id, ['title' => 'Hello', Room::INPUT => $alice->payload()])->assertOk();

    expect($this->post->fresh()->title)->toBe('From the request');
});

it('lets the next sync know the record was saved', function (): void {
    $alice = new Browser($this, $this->alice, $this->post->collaboration());
    $bob = new Browser($this, $this->bob, $this->post->collaboration());
    $alice->sync();
    $bob->sync();

    $alice->type('title', 'Saved');

    $this->actingAs($this->alice);
    $this->post->collaboration()->save(['title' => 'Saved'], fn (array $values) => $this->post->update($values), $alice->payload());

    expect($bob->sync()->json('saved'))->toMatchArray(['name' => 'Alice', 'own' => false])
        ->and($bob->value('title'))->toBe('Saved');
});

it('takes the draft over what a live form submitted', function (): void {
    $alice = new Browser($this, $this->alice, $this->post->collaboration());
    $alice->sync();

    $this->actingAs($this->alice);
    $this->post->collaboration()->save(['title' => 'Never typed'], fn (array $values) => $this->post->update($values), $alice->payload());

    expect($this->post->fresh()->title)->toBe('Hello');
});

it('treats a save from a page that was not live as the latest change', function (): void {
    $alice = new Browser($this, $this->alice, $this->post->collaboration());
    $alice->sync();

    $this->actingAs($this->bob);
    $this->post->collaboration()->save(['title' => 'Overwritten'], fn (array $values) => $this->post->update($values), '');

    expect($this->post->fresh()->title)->toBe('Overwritten')
        ->and($alice->sync()->json('operations'))->not->toBeEmpty()
        ->and($alice->value('title'))->toBe('Overwritten');
});
