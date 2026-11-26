<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use SolutionForest\Collaboration\Collaboration;
use SolutionForest\Collaboration\Tests\Fixtures\Models\Post;
use SolutionForest\Collaboration\Tests\Fixtures\Models\User;
use SolutionForest\Collaboration\Tests\Fixtures\Policies\PostPolicy;
use SolutionForest\Collaboration\Tests\Support\Browser;

beforeEach(function (): void {
    Gate::policy(Post::class, PostPolicy::class);

    $this->post = Post::create(['title' => 'Hello', 'status' => 'draft', 'lines' => [['key' => 'a', 'description' => 'Coffee', 'quantity' => 2], ['description' => 'Tea', 'quantity' => 1]]]);
    $this->alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
    $this->bob = User::create(['name' => 'Bob', 'email' => 'bob@example.com']);
    $this->room = fn () => Collaboration::room($this->post)->items('lines', text: ['description']);
});

it('keeps a list\'s items by key in the draft, giving keys to items without one', function (): void {
    $alice = new Browser($this, $this->alice, ($this->room)());
    $alice->sync();

    expect($alice->value('lines'))->toBe([
        'a' => ['description' => 'Coffee', 'quantity' => 2],
        'i1' => ['description' => 'Tea', 'quantity' => 1],
    ]);
});

it('merges what two people type into the same item', function (): void {
    $alice = new Browser($this, $this->alice, ($this->room)());
    $bob = new Browser($this, $this->bob, ($this->room)());
    $alice->sync();
    $bob->sync();

    $lines = $alice->value('lines');
    $alice->type('lines', ['a' => ['description' => 'Coffee, black', 'quantity' => 2], 'i1' => $lines['i1']])->sync();
    $bob->type('lines', ['a' => ['description' => 'Iced Coffee', 'quantity' => 3], 'i1' => $lines['i1']])->sync();
    $alice->sync();

    expect($alice->value('lines')['a'])->toBe(['description' => 'Iced Coffee, black', 'quantity' => 3]);
});

it('saves the list with each item\'s key, new items with the UUID they were given', function (): void {
    $alice = new Browser($this, $this->alice, ($this->room)());
    $alice->sync();
    $alice->type('lines', [...$alice->value('lines'), '7d1f4c3e-2b8a-4f6d-9c1e-5a2b3c4d5e6f' => ['description' => 'Cake', 'quantity' => 1]]);

    $this->actingAs($this->alice);
    ($this->room)()->update([], $alice->payload());

    expect($this->post->fresh()->lines)->toBe([
        ['key' => 'a', 'description' => 'Coffee', 'quantity' => 2],
        ['key' => 'i1', 'description' => 'Tea', 'quantity' => 1],
        ['key' => '7d1f4c3e-2b8a-4f6d-9c1e-5a2b3c4d5e6f', 'description' => 'Cake', 'quantity' => 1],
    ]);
});
