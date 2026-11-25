<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use SolutionForest\Collaboration\Collaboration;
use SolutionForest\Collaboration\Permissions;
use SolutionForest\Collaboration\Presence\Identity;
use SolutionForest\Collaboration\Tests\Fixtures\Models\Post;
use SolutionForest\Collaboration\Tests\Fixtures\Models\User;
use SolutionForest\Collaboration\Tests\Fixtures\Policies\PostPolicy;
use SolutionForest\Collaboration\Tests\Support\Browser;

beforeEach(function (): void {
    Gate::policy(Post::class, PostPolicy::class);

    $this->post = Post::create(['title' => 'Hello', 'summary' => 'A short summary', 'body' => '<p>Body</p>', 'status' => 'draft']);
    $this->alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
    $this->bob = User::create(['name' => 'Bob', 'email' => 'bob@example.com']);
});

it('gives the browser everything it needs to join the room', function (): void {
    $this->actingAs($this->alice);

    $config = Collaboration::room($this->post)->toArray();

    expect($config)
        ->toHaveKeys(['token', 'url', 'client', 'csrf'])
        ->and((array) $config['fields'])->toBe(['title' => 'text', 'summary' => 'text', 'body' => 'rich', 'status' => 'value'])
        ->and((array) $config['labels'])->toMatchArray(['title' => 'Title', 'status' => 'Status']);
});

it('lets nobody join without access to the record', function (): void {
    expect(Collaboration::room($this->post)->toArray())->toBe([]);

    $this->actingAs(User::create(['name' => 'Viewer', 'email' => 'viewer@example.com']));

    expect(Collaboration::room($this->post)->toArray())->toBe([])
        ->and(Collaboration::room($this->post)->viewing()->toArray())->toHaveKey('token');
});

it('starts the draft from what the database holds', function (): void {
    $alice = new Browser($this, $this->alice, $this->post->collaboration());

    $alice->sync()->assertOk();

    expect($alice->value('title'))->toBe('Hello')
        ->and($alice->value('status'))->toBe('draft')
        ->and($alice->value('body'))->toBe(['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Body']]]]]);
});

it('merges what two people type at the same time', function (): void {
    $alice = new Browser($this, $this->alice, $this->post->collaboration());
    $bob = new Browser($this, $this->bob, $this->post->collaboration());
    $alice->sync();
    $bob->sync();

    $alice->type('title', 'Hello world');
    $bob->type('title', 'Oh, Hello');

    $alice->sync()->assertOk();
    $bob->sync()->assertOk();
    $alice->sync()->assertOk();

    expect($alice->value('title'))->toBe('Oh, Hello world')
        ->and($bob->value('title'))->toBe('Oh, Hello world');
});

it('shows who is here, and in which field', function (): void {
    $alice = new Browser($this, $this->alice, $this->post->collaboration());
    $bob = new Browser($this, $this->bob, $this->post->collaboration());
    $alice->sync();

    $alice->sync(['field' => 'title']);
    $people = $bob->sync()->json('people');

    expect($people)->toHaveCount(1)
        ->and($people[0])->toMatchArray(['name' => 'Alice', 'initials' => 'A', 'field' => 'title', 'mode' => 'editing', 'self' => false]);
});

it('refuses somebody else\'s token', function (): void {
    $alice = new Browser($this, $this->alice, $this->post->collaboration());

    $this->actingAs($this->bob)
        ->postJson(route('collaboration.sync'), ['token' => $alice->config['token']])
        ->assertForbidden();
});

it('asks for a new token once permissions change', function (): void {
    $alice = new Browser($this, $this->alice, $this->post->collaboration());
    $alice->sync()->assertOk();

    Permissions::changed($this->post);

    $alice->sync()->assertStatus(410)->assertJson(['error' => 'expired']);
});

it('stops a person who lost access at their next sync', function (): void {
    $alice = new Browser($this, $this->alice, $this->post->collaboration());
    $alice->sync()->assertOk();

    $this->alice->update(['name' => 'Viewer']);

    $alice->sync()->assertNotFound()->assertJson(['error' => 'not_found']);
});

it('tells everybody when the record is deleted', function (): void {
    $alice = new Browser($this, $this->alice, $this->post->collaboration());
    $alice->sync()->assertOk();

    $this->post->delete();

    $alice->sync()->assertNotFound()->assertJson(['error' => 'deleted', 'restorable' => false]);
});

it('answers a malformed request without touching the draft', function (): void {
    $this->actingAs($this->alice)->postJson(route('collaboration.sync'), ['token' => 42])->assertBadRequest();
    $this->actingAs($this->alice)->postJson(route('collaboration.sync'), ['token' => 'nonsense'])->assertForbidden();
});

it('refuses a value a picked field may not take', function (): void {
    $alice = new Browser($this, $this->alice, Collaboration::room($this->post)->value('status', allowed: ['draft', 'published']));
    $alice->sync();

    $alice->type('status', 'deleted');

    $alice->sync()->assertStatus(409)->assertJson(['error' => 'rejected']);
});

it('finds the user the way the app says', function (): void {
    Collaboration::resolveUsersUsing(fn () => $this->bob);

    expect(Identity::current()?->name)->toBe('Bob');
});
