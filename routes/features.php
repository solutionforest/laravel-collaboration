<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use SolutionForest\Collaboration\Collaboration;
use SolutionForest\Collaboration\Features;
use SolutionForest\Collaboration\Http\Controllers\CommentController;
use SolutionForest\Collaboration\Http\Controllers\UploadController;
use SolutionForest\Collaboration\Http\Controllers\VersionController;
use SolutionForest\Collaboration\Support\Data;

// The optional parts' routes: only those of the features turned on.
$path = Data::string(Collaboration::config('path'), '_collaboration');
$middleware = Data::strings(Collaboration::config('middleware', ['web']));

Route::middleware([...$middleware, 'throttle:collaboration'])->prefix($path)->name('collaboration.')->group(function (): void {
    if (Collaboration::hasFeature(Features::COMMENTS)) {
        Route::post('comments', [CommentController::class, 'index'])->name('comments');
        Route::post('comments/store', [CommentController::class, 'store'])->name('comments.store');
        Route::post('comments/resolve', [CommentController::class, 'resolve'])->name('comments.resolve');
        Route::post('comments/reopen', [CommentController::class, 'reopen'])->name('comments.reopen');
    }

    if (Collaboration::hasFeature(Features::HISTORY)) {
        Route::post('versions', [VersionController::class, 'index'])->name('versions');
        Route::post('versions/restore', [VersionController::class, 'restore'])->name('versions.restore');
    }

    if (Collaboration::hasFeature(Features::UPLOADS)) {
        Route::post('uploads', UploadController::class)->name('uploads');
    }
});
