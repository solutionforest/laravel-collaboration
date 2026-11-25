<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use SolutionForest\Collaboration\Rooms\RoomContext;
use SolutionForest\Collaboration\Tests\Fixtures\Livewire\EditPost;
use SolutionForest\Collaboration\Tests\Fixtures\Models\Post;
use SolutionForest\Collaboration\Tests\Fixtures\Models\User;
use SolutionForest\Collaboration\Tests\Fixtures\Policies\PostPolicy;
use SolutionForest\Collaboration\Tests\Support\Browser;

beforeEach(function (): void {
    Gate::policy(Post::class, PostPolicy::class);

    $this->post = Post::create(['title' => 'Hello', 'body' => '<p>Body</p>', 'status' => 'draft']);
    $this->alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
    $this->bob = User::create(['name' => 'Bob', 'email' => 'bob@example.com']);
});

it('renders the room, and the script that joins it', function (): void {
    $this->actingAs($this->alice);

    Livewire::test(EditPost::class, ['post' => $this->post])
        ->assertSeeHtml('data-collaboration=')
        ->assertSeeHtml('data-collab-presence');
});

it('renders nothing for somebody who may not edit', function (): void {
    $this->actingAs(User::create(['name' => 'Viewer', 'email' => 'viewer@example.com']));

    Livewire::test(EditPost::class, ['post' => $this->post])->assertDontSeeHtml('data-collaboration=');
});

it('keeps its client across renders, so a renewed token names the same tab', function (): void {
    $this->actingAs($this->alice);

    $component = Livewire::test(EditPost::class, ['post' => $this->post]);
    $first = $component->get('collaborationClient');

    $component->call('$refresh');

    $room = $component->instance()->collaborationRoom()->toArray();

    expect($component->get('collaborationClient'))->toBe($first)
        ->and(RoomContext::fromToken($room['token'])?->client)->toBe($first);
});

it('saves with everybody\'s edits', function (): void {
    $alice = new Browser($this, $this->alice, $this->post->collaboration());
    $bob = new Browser($this, $this->bob, $this->post->collaboration());
    $alice->sync();
    $bob->sync();
    $alice->type('title', 'Hello from Alice')->sync();
    $bob->type('status', 'published');

    $this->actingAs($this->bob);

    Livewire::test(EditPost::class, ['post' => $this->post])
        ->set('status', 'published')
        ->set('collaborationSync', $bob->payload())
        ->call('save');

    expect($this->post->fresh())
        ->title->toBe('Hello from Alice')
        ->status->toBe('published');
});

it('serialises for Inertia as the room\'s config, or null', function (): void {
    expect(json_encode($this->post->collaboration()))->toBe('null');

    $this->actingAs($this->alice);

    expect(json_decode((string) json_encode(['collaboration' => $this->post->collaboration()]), true)['collaboration'])
        ->toHaveKeys(['token', 'fields', 'labels', 'url']);
});

it('renders the room again for the tab that asks with its client', function (): void {
    $this->actingAs($this->alice);

    request()->headers->set('X-Collaboration-Client', '12345');

    expect($this->post->collaboration()->toArray()['client'])->toBe(12345);
});
