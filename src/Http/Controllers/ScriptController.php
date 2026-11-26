<?php

declare(strict_types=1);

namespace SolutionForest\Collaboration\Http\Controllers;

use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Serves the Livewire adapter's script, so a Livewire app needs no JavaScript build step. */
final class ScriptController
{
    public const PATH = __DIR__.'/../../../resources/dist/livewire.js';

    public const EDITOR = __DIR__.'/../../../resources/dist/livewire-editor.js';

    public function __invoke(): BinaryFileResponse
    {
        return self::serve(self::PATH);
    }

    /** The rich text editor's script, with TipTap: only pages with an editor load it. */
    public function editor(): BinaryFileResponse
    {
        return self::serve(self::EDITOR);
    }

    private static function serve(string $path): BinaryFileResponse
    {
        return response()->file($path, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }

    public static function version(string $path = self::PATH): string
    {
        return substr((string) @md5_file($path), 0, 8);
    }
}
