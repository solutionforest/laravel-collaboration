<?php

declare(strict_types=1);

namespace SolutionForest\Collaboration\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use SolutionForest\Collaboration\Http\AuthorizedRoom;
use SolutionForest\Collaboration\Support\Data;

/**
 * The record as it is saved now, for a form whose fields somebody else just
 * saved: it takes their values into the fields its person has not touched.
 */
final class ValuesController
{
    public function __invoke(Request $request): JsonResponse
    {
        $room = AuthorizedRoom::from($request);

        // As the record serialises itself, so hidden attributes stay hidden.
        return new JsonResponse([
            'values' => (object) Arr::only($room->record()->fresh()?->toArray() ?? [], Data::strings($request->input('fields'))),
        ]);
    }
}
