<?php

declare(strict_types=1);

namespace SolutionForest\Collaboration\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use SolutionForest\Collaboration\Documents\DocumentRepository;
use SolutionForest\Collaboration\Documents\DraftVersions;
use SolutionForest\Collaboration\Http\AuthorizedRoom;
use SolutionForest\Collaboration\Support\Data;
use SolutionForest\Collaboration\Support\SaveLock;

/**
 * The shared draft as it was: while people edit, every few minutes, and at
 * each save. Editors can bring any version back, for everybody.
 */
final readonly class VersionController
{
    public function __construct(private DraftVersions $versions, private DocumentRepository $documents) {}

    public function index(Request $request): JsonResponse
    {
        $room = AuthorizedRoom::from($request);

        return $this->respond($room);
    }

    public function restore(Request $request): JsonResponse
    {
        $room = AuthorizedRoom::from($request);
        $draft = $room->context->draft();
        $values = $this->versions->values($draft, Data::int($request->input('id')));

        abort_unless($room->isEditing() && $room->context->fields !== [], 403);
        abort_if($values === null, 404);

        // The restored version becomes the draft's latest, under the save lock, as a save would.
        app(SaveLock::class)->block($room->context->room, fn () => $this->documents->restore($draft, $values, array_keys($room->context->fields), $room->identity->name));

        return $this->respond($room);
    }

    private function respond(AuthorizedRoom $room): JsonResponse
    {
        $versions = $this->versions->list($room->context->draft(), array_fill_keys($room->context->visible, ''));

        return new JsonResponse([
            'versions' => array_map(static fn (array $version): array => [...$version, 'at' => $version['at']->toIso8601String()], $versions),
            'canRestore' => $room->isEditing() && $room->context->fields !== [],
        ]);
    }
}
