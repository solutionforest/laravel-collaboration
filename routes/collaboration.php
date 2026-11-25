<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use SolutionForest\Collaboration\Collaboration;
use SolutionForest\Collaboration\Http\Controllers\ScriptController;
use SolutionForest\Collaboration\Http\Controllers\SyncController;
use SolutionForest\Collaboration\Http\Middleware\KeepSessionReadOnly;

$path = Collaboration::config('path', '_collaboration');

Route::post($path.'/sync', SyncController::class)
    ->middleware([...(array) Collaboration::config('middleware', ['web']), KeepSessionReadOnly::class, 'throttle:collaboration'])
    ->name('collaboration.sync');

Route::get($path.'/livewire.js', ScriptController::class)->name('collaboration.livewire');
