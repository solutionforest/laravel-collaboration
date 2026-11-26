<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use SolutionForest\Collaboration\Collaboration;
use SolutionForest\Collaboration\Http\Controllers\CommentController;
use SolutionForest\Collaboration\Http\Controllers\PresenceController;
use SolutionForest\Collaboration\Http\Controllers\ScriptController;
use SolutionForest\Collaboration\Http\Controllers\SyncController;
use SolutionForest\Collaboration\Http\Controllers\ValuesController;
use SolutionForest\Collaboration\Http\Controllers\VersionController;
use SolutionForest\Collaboration\Http\Middleware\KeepSessionReadOnly;
use SolutionForest\Collaboration\Support\Data;

$path = Data::string(Collaboration::config('path'), '_collaboration');
$middleware = Data::strings(Collaboration::config('middleware', ['web']));

Route::post($path.'/sync', SyncController::class)
    ->middleware([...$middleware, KeepSessionReadOnly::class, 'throttle:collaboration'])
    ->name('collaboration.sync');

Route::get($path.'/livewire.js', ScriptController::class)->name('collaboration.livewire');

// What a page asks about its room besides syncing. Each request carries the room's token.
Route::middleware([...$middleware, 'throttle:collaboration'])->prefix($path)->name('collaboration.')->group(function (): void {
    Route::post('values', ValuesController::class)->name('values');
    Route::post('presence', PresenceController::class)->name('presence');
    Route::post('comments', [CommentController::class, 'index'])->name('comments');
    Route::post('comments/store', [CommentController::class, 'store'])->name('comments.store');
    Route::post('comments/resolve', [CommentController::class, 'resolve'])->name('comments.resolve');
    Route::post('comments/reopen', [CommentController::class, 'reopen'])->name('comments.reopen');
    Route::post('versions', [VersionController::class, 'index'])->name('versions');
    Route::post('versions/restore', [VersionController::class, 'restore'])->name('versions.restore');
});
