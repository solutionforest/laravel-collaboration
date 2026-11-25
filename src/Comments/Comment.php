<?php

declare(strict_types=1);

namespace SolutionForest\Collaboration\Comments;

use Carbon\CarbonImmutable;
use SolutionForest\Collaboration\Support\Data;

/** A comment on a record, or on one of its fields. */
final readonly class Comment
{
    /**
     * @param  list<Mention>  $mentions
     */
    public function __construct(
        public int $id,
        public ?string $field,
        public string $body,
        public string $authorType,
        public string $authorId,
        public string $authorName,
        public array $mentions,
        public CarbonImmutable $at,
        public ?string $resolvedBy = null,
        public ?CarbonImmutable $resolvedAt = null,
    ) {}

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $resolved = isset($row['resolved_at']) ? Data::int($row['resolved_at']) : null;

        return new self(
            id: Data::int($row['id'] ?? null),
            field: Data::nullableString($row['field'] ?? null),
            body: Data::string($row['body'] ?? null),
            authorType: Data::string($row['user_type'] ?? null),
            authorId: Data::string($row['user_id'] ?? null),
            authorName: Data::string($row['user_name'] ?? null),
            mentions: array_values(array_filter(array_map(
                static fn (mixed $mention): ?Mention => Mention::from(Data::map($mention)),
                Data::list(Data::decode($row['mentions'] ?? null)),
            ))),
            at: CarbonImmutable::createFromTimestamp(Data::int($row['created_at'] ?? null)),
            resolvedBy: Data::nullableString($row['resolved_by'] ?? null),
            resolvedAt: $resolved === null ? null : CarbonImmutable::createFromTimestamp($resolved),
        );
    }

    public function isResolved(): bool
    {
        return $this->resolvedAt !== null;
    }

    /**
     * The body as HTML: escaped, with each mention highlighted.
     */
    public function html(): string
    {
        $html = e($this->body);

        foreach ($this->mentions as $mention) {
            $name = e('@'.$mention->name);
            $html = str_replace($name, '<span class="fi-collab-mention">'.$name.'</span>', $html);
        }

        return nl2br($html, false);
    }
}
