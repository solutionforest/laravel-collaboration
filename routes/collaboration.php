<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use SolutionForest\Collaboration\Http\Controllers\ScriptController;
use SolutionForest\Collaboration\Http\Controllers\SyncController;
use SolutionForest\Collaboration\Http\Middleware\KeepSessionReadOnly;

$path = config('collaboration.path', '_collaboration');

Route::post($path.'/sync', SyncController::class)
    ->middleware([...(array) config('collaboration.middleware', ['web']), KeepSessionReadOnly::class, 'throttle:collaboration'])
    ->name('collaboration.sync');

Route::get($path.'/livewire.js', ScriptController::class)->name('collaboration.livewire');
