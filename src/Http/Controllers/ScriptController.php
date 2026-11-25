<?php

declare(strict_types=1);

namespace SolutionForest\Collaboration\Http\Controllers;

use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Serves the Livewire adapter's script, so a Livewire app needs no JavaScript build step. */
final class ScriptController
{
    public const PATH = __DIR__.'/../../../resources/dist/livewire.js';

    public function __invoke(): BinaryFileResponse
    {
        return response()->file(self::PATH, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }

    public static function version(): string
    {
        return substr((string) @md5_file(self::PATH), 0, 8);
    }
}
