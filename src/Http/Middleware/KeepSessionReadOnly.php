<?php

declare(strict_types=1);

namespace SolutionForest\Collaboration\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Session\NullSessionHandler;
use Illuminate\Session\Store;
use SessionHandlerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sync runs several times a second next to saves and logouts. Writing the
 * session each time could restore a stale copy over either of them.
 */
final class KeepSessionReadOnly
{
    private const HANDLER = 'collaboration.session-handler';

    public function handle(Request $request, Closure $next): mixed
    {
        $session = $request->hasSession() ? $request->session() : null;

        if ($session instanceof Store) {
            $request->attributes->set(self::HANDLER, $session->getHandler());
            $session->setHandler(new NullSessionHandler);
        }

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $handler = $request->attributes->get(self::HANDLER);
        $session = $request->hasSession() ? $request->session() : null;

        if ($handler instanceof SessionHandlerInterface && $session instanceof Store) {
            $session->setHandler($handler);
        }
    }
}
