<?php

declare(strict_types=1);

namespace SolutionForest\Collaboration\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use SolutionForest\Collaboration\Collaboration;
use SolutionForest\Collaboration\CollaborationServiceProvider;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [LivewireServiceProvider::class, CollaborationServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('auth.providers.users.model', Fixtures\Models\User::class);
    }

    protected function defineDatabaseMigrations(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->string('password')->default('');
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('posts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable();
            $table->string('title')->default('');
            $table->text('summary')->nullable();
            $table->text('body')->nullable();
            $table->json('content')->nullable();
            $table->string('status')->default('draft');
            $table->boolean('featured')->default(false);
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Collaboration::flush();

        parent::tearDown();
    }
}
