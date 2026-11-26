<?php

declare(strict_types=1);

afterEach(function (): void {
    @unlink(config_path('collaboration.php'));
});

it('publishes the config, creates the tables, and says what comes next', function (): void {
    $this->artisan('collaboration:install', ['--migrate' => true])
        ->expectsOutputToContain('Published config/collaboration.php')
        ->expectsOutputToContain('Livewire')
        ->assertSuccessful();

    expect(config_path('collaboration.php'))->toBeFile();
});
