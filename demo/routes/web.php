<?php

use App\Http\Controllers\PostController;
use App\Http\Middleware\HandleReactRequests;
use App\Http\Middleware\HandleVueRequests;
use App\Livewire\EditPost;
use App\Models\Post;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => view('welcome', ['users' => User::all(), 'post' => Post::first()]))->name('home');

// The demo signs you in with one click, so several browsers can be different people.
Route::get('/as/{user}', function (User $user) {
    Auth::login($user);

    return redirect()->to(request('to', '/'));
})->name('sign-in');

Route::middleware('auth')->group(function () {
    Route::get('/livewire/posts/{post}', EditPost::class)->name('livewire.posts.edit');

    Route::middleware(HandleReactRequests::class)->prefix('react')->name('react.')->group(function () {
        Route::get('/posts/{post}', [PostController::class, 'edit'])->name('posts.edit');
        Route::put('/posts/{post}', [PostController::class, 'update'])->name('posts.update');
    });

    Route::middleware(HandleVueRequests::class)->prefix('vue')->name('vue.')->group(function () {
        Route::get('/posts/{post}', [PostController::class, 'edit'])->name('posts.edit');
        Route::put('/posts/{post}', [PostController::class, 'update'])->name('posts.update');
    });
});
