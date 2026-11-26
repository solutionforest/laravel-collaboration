<?php

declare(strict_types=1);

namespace SolutionForest\Collaboration\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Inertia\Inertia;
use Livewire\Livewire;
use SolutionForest\Collaboration\Support\Data;

/** Sets the package up: config, tables, and what the app's stack needs next. */
final class InstallCommand extends Command
{
    protected $signature = 'collaboration:install {--migrate : Create the tables without asking} {--npm : Install the browser side for Inertia without asking}';

    protected $description = 'Set up Laravel Collaboration: config, tables, and the next steps for Livewire, React or Vue';

    public function handle(): int
    {
        $this->callSilently('vendor:publish', ['--tag' => 'collaboration-config']);
        $this->components->info('Published config/collaboration.php.');

        if ($this->option('migrate') || $this->confirm('Create the tables now?', true)) {
            $this->call('migrate');
        }

        $stacks = $this->stacks();

        if (array_intersect($stacks, ['React', 'Vue']) !== [] && ($this->option('npm') || $this->confirm('Install the browser side for Inertia with npm?', true))) {
            Process::path(base_path())->run('npm install ./vendor/solutionforest/laravel-collaboration')->throw();
            $this->components->info('Installed @solutionforest/laravel-collaboration.');
        }

        $this->components->info('Next: add the Collaborative trait and the fields people edit together to a model:');
        $this->line("    use Collaborative;\n    protected array \$collaborative = ['title', 'body' => FieldKind::RichText];\n");

        foreach ($stacks as $stack) {
            $this->components->twoColumnDetail("<fg=green>{$stack}</>", match ($stack) {
                'Livewire' => 'use WithCollaboration, and put <x-collaboration::room :room="$this->collaborationRoom()" /> in the view',
                'React' => "useCollaborativeForm() from '@solutionforest/laravel-collaboration/react'",
                'Vue' => "useCollaborativeForm() and v-collaborate from '@solutionforest/laravel-collaboration/vue'",
                default => '',
            });
        }

        if ($stacks === []) {
            $this->components->warn('Neither Livewire nor Inertia is installed: see the README for the framework-free client.');
        }

        return self::SUCCESS;
    }

    /** @return list<string> the stacks this app uses */
    private function stacks(): array
    {
        $package = Data::decode((string) @file_get_contents(base_path('package.json')));
        $npm = [...Data::map($package['dependencies'] ?? null), ...Data::map($package['devDependencies'] ?? null)];

        return array_values(array_filter([
            class_exists(Livewire::class) ? 'Livewire' : null,
            class_exists(Inertia::class) && isset($npm['@inertiajs/react']) ? 'React' : null,
            class_exists(Inertia::class) && isset($npm['@inertiajs/vue3']) ? 'Vue' : null,
        ]));
    }
}
