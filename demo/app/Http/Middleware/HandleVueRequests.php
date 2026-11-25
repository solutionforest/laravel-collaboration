<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

/** The Vue half of the demo: Inertia pages from `resources/js/Pages/Vue`. */
class HandleVueRequests extends Middleware
{
    protected $rootView = 'vue';

    public function share(Request $request): array
    {
        return [...parent::share($request), 'user' => $request->user()?->only('id', 'name')];
    }
}
