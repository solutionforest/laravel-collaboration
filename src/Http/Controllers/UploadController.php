<?php

declare(strict_types=1);

namespace SolutionForest\Collaboration\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use SolutionForest\Collaboration\Collaboration;
use SolutionForest\Collaboration\Documents\SharedUploads;
use SolutionForest\Collaboration\Enums\FieldKind;
use SolutionForest\Collaboration\Http\AuthorizedRoom;
use SolutionForest\Collaboration\Support\Data;

/**
 * An image put into a rich text field while editing. It is stored at once,
 * so everybody sees it; until a save keeps it, it belongs to the draft, and
 * `collaboration:prune-uploads` deletes it if nobody does.
 */
final readonly class UploadController
{
    public function __construct(private SharedUploads $uploads) {}

    public function __invoke(Request $request): JsonResponse
    {
        $room = AuthorizedRoom::from($request);
        $field = Data::string($request->input('field'));
        $file = $request->file('file');

        abort_unless($room->isEditing() && ($room->context->fields[$field] ?? null) === FieldKind::RichText, 403);
        abort_unless($file instanceof UploadedFile && $file->isValid(), 422);

        $request->validate(['file' => ['image', 'max:'.Data::int(Collaboration::config('uploads_max_kb'), 5120)]]);

        $disk = Data::string(Collaboration::config('uploads_disk'), 'public');
        $path = $file->store('collaboration/'.substr($room->context->room, 0, 16), $disk);

        abort_if($path === false, 500);

        $this->uploads->track($room->context->draft(), $field, $disk, $path);

        return new JsonResponse(['url' => Storage::disk($disk)->url($path)]);
    }
}
