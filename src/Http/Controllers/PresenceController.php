<?php

declare(strict_types=1);

namespace SolutionForest\Collaboration\Http\Controllers;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use SolutionForest\Collaboration\Presence\Identity;
use SolutionForest\Collaboration\RecordPresence;
use SolutionForest\Collaboration\Support\Data;

/** Who is on each record of a list, for the page that shows it. */
final class PresenceController
{
    public function __invoke(Request $request): JsonResponse
    {
        try {
            $token = Data::decode(Crypt::decryptString(Data::string($request->input('token'))));
        } catch (DecryptException) {
            abort(403);
        }

        $user = auth()->guard(Data::string($token['g'] ?? null))->user();

        abort_if($user === null || Data::string($user->getAuthIdentifier()) !== Data::string($token['u'] ?? null), 403);
        abort_if(Data::int($token['e'] ?? null) < now()->getTimestamp(), 410);

        $rooms = array_map(Data::string(...), Data::map($token['r'] ?? null));

        return new JsonResponse(['people' => (object) RecordPresence::people($rooms, Identity::for($user))]);
    }
}
