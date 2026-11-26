<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use SolutionForest\Collaboration\Collaboration;
use SolutionForest\Collaboration\Tests\Fixtures\Models\Post;
use SolutionForest\Collaboration\Tests\Fixtures\Models\User;
use SolutionForest\Collaboration\Tests\Fixtures\Policies\PostPolicy;

beforeEach(function (): void {
    Gate::policy(Post::class, PostPolicy::class);

    $this->alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
    $this->bob = User::create(['name' => 'Bob', 'email' => 'bob@example.com']);
});

it('lets people creating a record see each other, with nothing to merge yet', function (): void {
    $this->actingAs($this->alice);
    $alice = Collaboration::creating(Post::class)->toArray();

    $this->actingAs($this->bob);
    $bob = Collaboration::creating(Post::class)->toArray();

    expect($alice['mode'])->toBe('creating')
        ->and((array) $alice['fields'])->toBe([]);

    $this->actingAs($this->alice)->postJson(route('collaboration.sync'), ['token' => $alice['token'], 'since' => -1])->assertOk();

    $people = $this->actingAs($this->bob)->postJson(route('collaboration.sync'), ['token' => $bob['token'], 'since' => -1])->assertOk()->json('people');

    expect(array_column($people, 'name'))->toBe(['Alice'])
        ->and($people[0]['mode'])->toBe('creating');
});

it('asks the policy whether this person may create one', function (): void {
    $this->actingAs(User::create(['name' => 'Viewer', 'email' => 'viewer@example.com']));

    expect(Collaboration::creating(Post::class)->toArray())->toBe([]);
});
