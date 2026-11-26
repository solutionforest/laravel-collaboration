<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use SolutionForest\Collaboration\Tests\Fixtures\Models\Post;
use SolutionForest\Collaboration\Tests\Fixtures\Models\User;
use SolutionForest\Collaboration\Tests\Fixtures\Policies\PostPolicy;
use SolutionForest\Collaboration\Tests\Support\Browser;

beforeEach(function (): void {
    Gate::policy(Post::class, PostPolicy::class);

    $this->post = Post::create(['title' => 'First', 'status' => 'draft']);
    $this->alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
});

it('lists the draft\'s versions, and brings one back for everybody', function (): void {
    $alice = new Browser($this, $this->alice, $this->post->collaboration());
    $alice->sync();

    // Two saves: each keeps a version.
    foreach (['Second', 'Third'] as $title) {
        $alice->type('title', $title);
        $this->actingAs($this->alice);
        $this->post->collaboration()->update([], $alice->payload());
        $alice->sync();
    }

    $versions = $this->postJson(route('collaboration.versions'), ['token' => $alice->config['token']])->assertOk()->json('versions');

    expect($versions[0])->toMatchArray(['reason' => 'save', 'name' => 'Alice', 'changed' => ['title']]);

    $this->postJson(route('collaboration.versions.restore'), ['token' => $alice->config['token'], 'id' => $versions[1]['id']])->assertOk();

    $alice->sync();

    expect($alice->value('title'))->toBe('Second');
});

it('lets only editors restore', function (): void {
    $viewer = User::create(['name' => 'Viewer', 'email' => 'viewer@example.com']);
    $browser = new Browser($this, $viewer, $this->post->collaboration()->viewing());

    $this->actingAs($viewer)->postJson(route('collaboration.versions'), ['token' => $browser->config['token']])->assertOk()->assertJsonPath('canRestore', false);
    $this->actingAs($viewer)->postJson(route('collaboration.versions.restore'), ['token' => $browser->config['token'], 'id' => 1])->assertForbidden();
});
