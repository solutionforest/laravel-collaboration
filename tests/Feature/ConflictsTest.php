<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use SolutionForest\Collaboration\Exceptions\SaveConflict;
use SolutionForest\Collaboration\Room;
use SolutionForest\Collaboration\Tests\Fixtures\Models\Post;
use SolutionForest\Collaboration\Tests\Fixtures\Models\User;
use SolutionForest\Collaboration\Tests\Fixtures\Policies\PostPolicy;
use SolutionForest\Collaboration\Tests\Support\Browser;

beforeEach(function (): void {
    Gate::policy(Post::class, PostPolicy::class);

    $this->post = Post::create(['title' => 'Hello', 'category' => 'news', 'status' => 'draft']);
    $this->alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
    $this->bob = User::create(['name' => 'Bob', 'email' => 'bob@example.com']);
});

/** Saves the post as a form would, with the category it loaded and the one it sends. */
function saveCategory(Post $post, User $user, string $category, string $loaded): void
{
    test()->actingAs($user);

    $post->collaboration()->update(['category' => $category], '', ['category' => $loaded]);
}

it('keeps what somebody else saved in a field this person left alone', function (): void {
    saveCategory($this->post, $this->alice, 'sport', loaded: 'news');

    // Bob's form still shows "news": he did not change it, so Alice's save stays.
    saveCategory($this->post->fresh(), $this->bob, 'news', loaded: 'news');

    expect($this->post->fresh()->category)->toBe('sport');
});

it('stops a save that would overwrite a field somebody else changed too', function (): void {
    $alice = new Browser($this, $this->alice, $this->post->collaboration());
    $alice->sync();
    $this->actingAs($this->alice);
    $this->post->collaboration()->update(['category' => 'sport'], $alice->payload(), ['category' => 'news']);

    $this->actingAs($this->bob);

    expect(fn () => $this->post->fresh()->collaboration()->update(['category' => 'tech'], '', ['category' => 'news']))
        ->toThrow(function (SaveConflict $conflict): void {
            expect($conflict->theirs)->toBe(['category' => 'sport'])
                ->and($conflict->name)->toBe('Alice')
                ->and($conflict->errors()['category'][0])->toContain('Alice also changed Category');
        });

    expect($this->post->fresh()->category)->toBe('sport');
});

it('saves the version chosen, once the base says theirs was seen', function (): void {
    saveCategory($this->post, $this->alice, 'sport', loaded: 'news');
    saveCategory($this->post->fresh(), $this->bob, 'tech', loaded: 'sport');

    expect($this->post->fresh()->category)->toBe('tech');
});

it('reads the base from the request', function (): void {
    saveCategory($this->post, $this->alice, 'sport', loaded: 'news');

    $this->app['router']->post('/posts/{post}', fn (Post $post) => $post->collaboration()->update(request()->only('category')))->middleware('web');

    $this->actingAs($this->bob)
        ->post('/posts/'.$this->post->id, ['category' => 'tech', Room::BASE => json_encode(['category' => 'news'])])
        ->assertSessionHasErrors('category');
});

it('gives a page the values its record has now', function (): void {
    $alice = new Browser($this, $this->alice, $this->post->collaboration());
    $this->post->update(['category' => 'sport']);

    $this->actingAs($this->alice)
        ->postJson(route('collaboration.values'), ['token' => $alice->config['token'], 'fields' => ['category', 'password']])
        ->assertOk()
        ->assertExactJson(['values' => ['category' => 'sport']]);
});
