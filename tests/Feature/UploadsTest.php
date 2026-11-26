<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use SolutionForest\Collaboration\Support\Database;
use SolutionForest\Collaboration\Tests\Fixtures\Models\Post;
use SolutionForest\Collaboration\Tests\Fixtures\Models\User;
use SolutionForest\Collaboration\Tests\Fixtures\Policies\PostPolicy;
use SolutionForest\Collaboration\Tests\Support\Browser;

beforeEach(function (): void {
    Gate::policy(Post::class, PostPolicy::class);
    Storage::fake('public');

    $this->post = Post::create(['title' => 'Hello', 'body' => '<p>Body</p>']);
    $this->alice = User::create(['name' => 'Alice', 'email' => 'alice@example.com']);
    $this->browser = new Browser($this, $this->alice, $this->post->collaboration());
});

it('stores an image for a rich text field, as the draft\'s until a save keeps it', function (): void {
    $url = $this->actingAs($this->alice)
        ->post(route('collaboration.uploads'), ['token' => $this->browser->config['token'], 'field' => 'body', 'file' => UploadedFile::fake()->image('photo.png')])
        ->assertOk()
        ->json('url');

    $path = Database::table('uploads')->value('path');

    expect($url)->toEndWith($path);
    Storage::disk('public')->assertExists($path);
});

it('takes images only, and only for rich text fields', function (): void {
    $this->actingAs($this->alice)
        ->post(route('collaboration.uploads'), ['token' => $this->browser->config['token'], 'field' => 'title', 'file' => UploadedFile::fake()->image('photo.png')])
        ->assertForbidden();

    $this->actingAs($this->alice)
        ->post(route('collaboration.uploads'), ['token' => $this->browser->config['token'], 'field' => 'body', 'file' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf')])
        ->assertSessionHasErrors('file');
});
