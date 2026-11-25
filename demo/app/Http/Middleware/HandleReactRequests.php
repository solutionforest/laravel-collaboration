<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

/** The React half of the demo: Inertia pages from `resources/js/Pages/React`. */
class HandleReactRequests extends Middleware
{
    protected $rootView = 'react';

    public function share(Request $request): array
    {
        return [...parent::share($request), 'user' => $request->user()?->only('id', 'name')];
    }
}
