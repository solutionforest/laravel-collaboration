<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use SolutionForest\Collaboration\Collaboration;
use SolutionForest\Collaboration\Events\CommentPosted;
use SolutionForest\Collaboration\Tests\Fixtures\Models\Post;
use SolutionForest\Collaboration\Tests\Fixtures\Models\User;
use SolutionForest\Collaboration\Tests\Fixtures\Policies\PostPolicy;
use SolutionForest\Collaboration\Tests\Support\Browser;

beforeEach(function (): void {
    Gate::policy(Post::class, PostPolicy::class);

    $this->post = Post::create(['title' => 'Hello']);
    $this->alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
    $this->bob = User::create(['name' => 'Bob Lee', 'email' => 'bob@example.com']);

    Collaboration::mentionUsersUsing(fn () => User::all());
});

it('posts a comment on a field, with the people it mentions', function (): void {
    Event::fake([CommentPosted::class]);
    $alice = new Browser($this, $this->alice, $this->post->collaboration());

    $comments = $this->actingAs($this->alice)
        ->postJson(route('collaboration.comments.store'), ['token' => $alice->config['token'], 'body' => 'Can you check this, @Bob Lee?', 'field' => 'title'])
        ->assertOk()
        ->json('comments');

    expect($comments)->toHaveCount(1)
        ->and($comments[0])->toMatchArray(['field' => 'title', 'author' => 'Alice', 'mine' => true, 'mentions' => ['Bob Lee'], 'resolved' => false])
        ->and($comments[0]['html'])->toContain('<span class="collab-mention">@Bob Lee</span>');

    Event::assertDispatched(CommentPosted::class, fn (CommentPosted $event): bool => $event->comment->mentions[0]->name === 'Bob Lee');
});

it('resolves and reopens comments', function (): void {
    $alice = new Browser($this, $this->alice, $this->post->collaboration());
    $this->actingAs($this->alice);
    $id = $this->postJson(route('collaboration.comments.store'), ['token' => $alice->config['token'], 'body' => 'Fix the title'])->json('comments.0.id');

    $this->postJson(route('collaboration.comments.resolve'), ['token' => $alice->config['token'], 'id' => $id])->assertJsonPath('comments.0.resolvedBy', 'Alice');
    $this->postJson(route('collaboration.comments.reopen'), ['token' => $alice->config['token'], 'id' => $id])->assertJsonPath('comments.0.resolved', false);
});

it('lets people who only view read comments, not write them', function (): void {
    $viewer = User::create(['name' => 'Viewer', 'email' => 'viewer@example.com']);
    $browser = new Browser($this, $viewer, $this->post->collaboration()->viewing());

    $this->actingAs($viewer)->postJson(route('collaboration.comments'), ['token' => $browser->config['token']])->assertOk()->assertJsonPath('canWrite', false);
    $this->actingAs($viewer)->postJson(route('collaboration.comments.store'), ['token' => $browser->config['token'], 'body' => 'Hi'])->assertStatus(422);
});

it('refuses a comment on a field this person cannot see', function (): void {
    $alice = new Browser($this, $this->alice, $this->post->collaboration());

    $this->actingAs($this->alice)->postJson(route('collaboration.comments.store'), ['token' => $alice->config['token'], 'body' => 'Hi', 'field' => 'password'])->assertStatus(422);
});
