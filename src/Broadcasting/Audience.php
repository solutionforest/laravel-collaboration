<?php

declare(strict_types=1);

namespace SolutionForest\Collaboration\Broadcasting;

use SolutionForest\Collaboration\Rooms\RoomContext;
use SolutionForest\Collaboration\Support\Data;

/**
 * Tabs that may read the same fields hear the same pushes. Pushed operations
 * and carets are filtered per audience, as a sync response is per tab.
 */
final readonly class Audience
{
    /**
     * @param  list<string>  $readable  fields whose content may be sent
     * @param  list<string>  $visible  fields whose names may be sent
     */
    public function __construct(
        public string $room,
        public array $readable,
        public array $visible,
        public ?string $locale = null,
    ) {}

    public static function of(RoomContext $context): self
    {
        $readable = array_map(strval(...), array_keys($context->fields));
        sort($readable);
        $visible = $context->visible;
        sort($visible);

        // Each language has its own draft, so its pushes go to its own tabs.
        return new self($context->room, $readable, $visible, $context->locale);
    }

    public function key(): string
    {
        return substr(hash('sha256', Data::encode([$this->room, $this->readable, $this->visible, ...($this->locale === null ? [] : [$this->locale])])), 0, 16);
    }

    public function channel(): string
    {
        return RoomChanged::channel($this->room).'.'.$this->key();
    }

    /** @return array{readable: list<string>, visible: list<string>} */
    public function toArray(): array
    {
        return ['readable' => $this->readable, 'visible' => $this->visible];
    }
}
