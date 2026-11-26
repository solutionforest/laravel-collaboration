<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use SolutionForest\Collaboration\Support\Database;
use SolutionForest\Collaboration\Tests\Fixtures\Models\Post;
use SolutionForest\Collaboration\Tests\Fixtures\Models\User;
use SolutionForest\Collaboration\Tests\Fixtures\Policies\PostPolicy;

it('registers no routes and no table for features that are off', function (): void {
    expect(Route::has('collaboration.comments'))->toBeFalse()
        ->and(Route::has('collaboration.versions'))->toBeFalse()
        ->and(Route::has('collaboration.uploads'))->toBeFalse()
        ->and(Route::has('collaboration.sync'))->toBeTrue()
        ->and(Schema::hasTable(Database::tableName('comments')))->toBeFalse();
});

it('tells the browser only of the endpoints that exist', function (): void {
    Gate::policy(Post::class, PostPolicy::class);
    $this->actingAs(User::create(['name' => 'Alice', 'email' => 'alice@example.com']));

    expect(array_keys(Post::create(['title' => 'Hello'])->collaboration()->toArray()['endpoints']))->toBe(['values']);
});

it('deletes a record without touching the comments table', function (): void {
    Post::create(['title' => 'Hello'])->delete();

    expect(Post::count())->toBe(0);
});
