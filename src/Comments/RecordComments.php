<?php

declare(strict_types=1);

namespace SolutionForest\Collaboration\Comments;

use Illuminate\Contracts\Auth\Authenticatable;
use LogicException;
use SolutionForest\Collaboration\Presence\Identity;
use SolutionForest\Collaboration\Support\Data;
use SolutionForest\Collaboration\Support\Database;

/** Comments on a record, kept by its room in `collaboration_comments`. */
final class RecordComments
{
    public const MAX_LENGTH = 5000;

    /**
     * Open comments first, then resolved ones; oldest first within each.
     *
     * @return list<Comment>
     */
    public function list(string $room, int $limit = 200): array
    {
        $rows = Database::table('comments')
            ->where('room', $room)
            ->orderByRaw('resolved_at is not null')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        return array_values(array_map(static fn (mixed $row): Comment => Comment::fromRow(Data::map($row)), $rows->all()));
    }

    /** @return array<string, int> open comments per field, `''` for the record itself */
    public function openCounts(string $room): array
    {
        $counts = [];

        foreach (Database::table('comments')->where('room', $room)->whereNull('resolved_at')->selectRaw('field, count(*) as aggregate')->groupBy('field')->get() as $row) {
            $row = Data::map($row);
            $counts[Data::string($row['field'] ?? null)] = Data::int($row['aggregate'] ?? null);
        }

        return $counts;
    }

    /** @param list<Mention> $mentions */
    public function post(string $room, ?string $field, string $body, Authenticatable $author, array $mentions): Comment
    {
        $id = Database::table('comments')->insertGetId([
            'room' => $room,
            'field' => $field,
            'body' => $body,
            'user_type' => $author::class,
            'user_id' => Data::string($author->getAuthIdentifier()),
            'user_name' => Identity::for($author)->name,
            'mentions' => Data::encode(array_map(static fn (Mention $mention): array => $mention->toArray(), $mentions)),
            'created_at' => now()->getTimestamp(),
        ]);

        return $this->find($room, (int) $id) ?? throw new LogicException('The comment was not stored.');
    }

    public function find(string $room, int $id): ?Comment
    {
        $row = Database::table('comments')->where('room', $room)->where('id', $id)->first();

        return $row === null ? null : Comment::fromRow(Data::map($row));
    }

    public function resolve(string $room, int $id, string $by): void
    {
        Database::table('comments')->where('room', $room)->where('id', $id)->whereNull('resolved_at')->update([
            'resolved_by' => $by,
            'resolved_at' => now()->getTimestamp(),
        ]);
    }

    public function reopen(string $room, int $id): void
    {
        Database::table('comments')->where('room', $room)->where('id', $id)->update(['resolved_by' => null, 'resolved_at' => null]);
    }

    /** A record that is gone for good takes its comments with it. */
    public function forget(string $room): void
    {
        Database::table('comments')->where('room', $room)->delete();
    }

    /**
     * Who `@Name` in the body means, among the people who may be mentioned.
     * Longer names win, so `@Ann Lee` is not read as `@Ann`.
     *
     * @param  iterable<mixed>  $people
     * @return list<Mention>
     */
    public static function mentionsIn(string $body, iterable $people): array
    {
        $candidates = [];

        foreach ($people as $person) {
            if ($person instanceof Authenticatable) {
                $candidates[] = Mention::of($person);
            }
        }

        usort($candidates, static fn (Mention $a, Mention $b): int => mb_strlen($b->name) <=> mb_strlen($a->name));

        $found = [];

        foreach ($candidates as $mention) {
            if ($mention->name === '') {
                continue;
            }

            $pattern = '/(?<![\p{L}\p{N}_])@'.preg_quote($mention->name, '/').'(?![\p{L}\p{N}_])/iu';

            if (preg_match($pattern, $body) === 1) {
                $found[$mention->type.':'.$mention->id] = $mention;
                $body = (string) preg_replace($pattern, '', $body);
            }
        }

        return array_values($found);
    }
}
