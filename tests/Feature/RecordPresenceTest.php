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

    $this->posts = collect([Post::create(['title' => 'One']), Post::create(['title' => 'Two'])]);
    $this->alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
    $this->bob = User::create(['name' => 'Bob', 'email' => 'bob@example.com']);
});

it('shows who is on each record of a list, leaving out the viewer', function (): void {
    (new Browser($this, $this->alice, $this->posts[1]->collaboration()))->sync();

    $this->actingAs($this->bob);
    $config = Collaboration::presence($this->posts)->toArray();

    expect((array) $config['people'])->toHaveKey((string) $this->posts[1]->id)
        ->and($config['people']->{$this->posts[1]->id}[0]['name'])->toBe('Alice')
        ->and((array) $config['people'])->not->toHaveKey((string) $this->posts[0]->id);

    $this->actingAs($this->alice);
    expect((array) Collaboration::presence($this->posts)->toArray()['people'])->toBe([]);
});

it('answers the page as people come and go', function (): void {
    $this->actingAs($this->bob);
    $config = Collaboration::presence($this->posts)->toArray();

    (new Browser($this, $this->alice, $this->posts[0]->collaboration()))->sync();

    $this->actingAs($this->bob)
        ->postJson(route('collaboration.presence'), ['token' => $config['token']])
        ->assertOk()
        ->assertJsonPath('people.'.$this->posts[0]->id.'.0.name', 'Alice');
});

it('refuses somebody else\'s list', function (): void {
    $this->actingAs($this->bob);
    $config = Collaboration::presence($this->posts)->toArray();

    $this->actingAs($this->alice)->postJson(route('collaboration.presence'), ['token' => $config['token']])->assertForbidden();
});
