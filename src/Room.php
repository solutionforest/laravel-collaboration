<?php

declare(strict_types=1);

namespace SolutionForest\Collaboration;

use BackedEnum;
use Closure;
use DateTimeInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use JsonSerializable;
use SolutionForest\Collaboration\Broadcasting\Audience;
use SolutionForest\Collaboration\Concerns\Collaborative;
use SolutionForest\Collaboration\Crdt\Html;
use SolutionForest\Collaboration\Documents\DocumentRepository;
use SolutionForest\Collaboration\Documents\EditTrail;
use SolutionForest\Collaboration\Documents\SharedField;
use SolutionForest\Collaboration\Enums\FieldKind;
use SolutionForest\Collaboration\Enums\PresenceMode;
use SolutionForest\Collaboration\Presence\Identity;
use SolutionForest\Collaboration\Rooms\RoomAuthorizer;
use SolutionForest\Collaboration\Rooms\RoomContext;
use SolutionForest\Collaboration\Rooms\RoomResolver;
use SolutionForest\Collaboration\Saving\LiveSave;
use SolutionForest\Collaboration\Support\Data;
use SolutionForest\Collaboration\Support\SaveLock;

/**
 * The room people share while they work on one record: which of its fields
 * are edited together, and how. Render it into the page, then save through it.
 *
 *     $room = Collaboration::room($post)->text('title')->rich('body');
 *
 *     return Inertia::render('Posts/Edit', ['collaboration' => $room]);
 *
 *     $room->save($request->validated(), fn (array $values) => $post->update($values));
 *
 * @implements Arrayable<string, mixed>
 */
final class Room implements Arrayable, JsonSerializable
{
    /** The name of the input a form sends its live edits in when it is saved. */
    public const INPUT = '_collaboration';

    /** @var array<string, FieldKind> */
    private array $fields = [];

    /** @var array<string, string> */
    private array $labels = [];

    /** @var array<string, list<string>> */
    private array $allowed = [];

    private PresenceMode $mode = PresenceMode::Editing;

    private ?string $locale = null;

    private ?string $guard = null;

    /** @var array<string, string|null> */
    private array $meta = [];

    private ?int $client = null;

    public function __construct(private readonly Model $record)
    {
        if (in_array(Collaborative::class, class_uses_recursive($record), true)) {
            /** @var Model&Collaborative $record */
            $this->fields($record->collaborativeFields());
        }
    }

    /** Fields that merge character by character, such as a title or a textarea. */
    public function text(string ...$fields): self
    {
        return $this->add(FieldKind::Text, $fields);
    }

    /** Fields edited in a rich text editor such as TipTap, kept as HTML or as JSON. */
    public function rich(string ...$fields): self
    {
        return $this->add(FieldKind::RichText, $fields);
    }

    /**
     * Fields whose whole value is replaced by the latest change, such as a
     * select or a checkbox. Pass `allowed` to refuse any other value.
     *
     * @param  list<string|int|BackedEnum>|class-string<BackedEnum>|null  $allowed
     */
    public function value(string $field, array|string|null $allowed = null): self
    {
        $this->add(FieldKind::Value, [$field]);

        if ($allowed !== null) {
            $this->allowed[$field] = self::allowedValues($allowed);
        }

        return $this;
    }

    /**
     * Several fields at once: a name alone is text, or name => kind.
     *
     *     ->fields(['title', 'body' => 'rich', 'status' => 'value'])
     *
     * @param  array<int|string, string|FieldKind>  $fields
     */
    public function fields(array $fields): self
    {
        foreach ($fields as $name => $kind) {
            [$name, $kind] = is_int($name) ? [(string) $kind, FieldKind::Text] : [$name, $kind];

            $this->fields[$name] = $kind instanceof FieldKind ? $kind : FieldKind::from($kind);
        }

        return $this;
    }

    /**
     * What others see when this person is in a field: "Alice is in Title".
     *
     * @param  array<string, string>  $labels
     */
    public function labels(array $labels): self
    {
        $this->labels = [...$this->labels, ...$labels];

        return $this;
    }

    /** Join the room to watch, without editing. */
    public function viewing(): self
    {
        $this->mode = PresenceMode::Viewing;

        return $this;
    }

    /** A translated record has one draft per language. */
    public function locale(?string $locale): self
    {
        $this->locale = $locale;

        return $this;
    }

    /** The guard people are signed in with. The default guard otherwise. */
    public function guard(string $guard): self
    {
        $this->guard = $guard;

        return $this;
    }

    /**
     * Anything an integration must find again on every sync.
     *
     * @param  array<string, string|null>  $meta
     */
    public function meta(array $meta): self
    {
        $this->meta = [...$this->meta, ...$meta];

        return $this;
    }

    /**
     * The tab this room is rendered for. Rendering it again for the same tab,
     * to renew its token, must keep its client.
     */
    public function client(?int $client): self
    {
        $this->client = $client !== null && $client > 0 ? $client : null;

        return $this;
    }

    public function record(): Model
    {
        return $this->record;
    }

    /** @return array<string, FieldKind> */
    public function sharedFields(): array
    {
        return $this->fields;
    }

    public function id(): string
    {
        return app(RoomResolver::class)->forRecord($this->record);
    }

    public function draft(): string
    {
        return RoomContext::draftFor($this->id(), $this->locale);
    }

    /** What the browser starts the room from. Empty when nobody may join it. */
    public function toArray(): array
    {
        $user = $this->user();

        if ($user === null || ! RoomAuthorizer::allows($user, $this->record, $this->isEditing() ? 'update' : 'view')) {
            return [];
        }

        $live = $this->isEditing() && Permissions::mayCollaborate($this->record, $user) ? $this->fields : [];

        if ($live !== []) {
            $this->reconcile();
        }

        $context = $this->context($user, $live);

        return [
            'id' => substr($context->room, 0, 16).'-'.$context->client,
            'url' => route('collaboration.sync'),
            'csrf' => csrf_token(),
            'token' => $context->toToken(),
            'client' => $context->client,
            'mode' => $this->mode->value,
            'fields' => (object) array_map(static fn (FieldKind $kind): string => $kind->value, $live),
            'labels' => (object) $this->resolvedLabels(),
            'draft' => $context->draft(),
            'input' => self::INPUT,
            'interval' => Collaboration::$pollingInterval,
            'channel' => Collaboration::$broadcasts ? Audience::of($context)->channel() : null,
            'avatarLimit' => Collaboration::$avatarLimit,
            'reportErrors' => $this->isEditing() && Collaboration::$reportsEditorErrors,
            'user' => Identity::for($user)->key,
            'strings' => trans('collaboration::collaboration.client'),
        ];
    }

    public function jsonSerialize(): mixed
    {
        $config = $this->toArray();

        return $config === [] ? null : $config;
    }

    /**
     * Saves the record with everybody's latest edits in its live fields. The
     * browser sends what it typed since its last sync with the form, so nothing
     * is lost to a save that overtakes it.
     *
     * @template TResult
     *
     * @param  array<string, mixed>  $values  what the form submitted
     * @param  Closure(array<string, mixed>): TResult  $persist  writes the values to the database
     * @param  string|null  $sync  the form's `_collaboration` input; read from the request when null
     * @return TResult
     */
    public function save(array $values, Closure $persist, ?string $sync = null): mixed
    {
        $sync ??= Data::string(request()->input(self::INPUT));

        return app(SaveLock::class)->block($this->id(), function () use ($values, $persist, $sync): mixed {
            $documents = app(DocumentRepository::class);
            $context = $this->contextFromSync($sync);
            $live = new LiveSave;

            if ($context !== null && $context->fields !== []) {
                $documents->reconcile($context->draft(), $this->currentFields());
                $live = LiveSave::prepare($documents, $context->draft(), $context->client, $sync, $context->fields, $context->allowed, $context->currentUser());
            }

            $mine = $this->toShared($values);
            $published = $live->valuesToPublish($mine, $this->currentValues());
            $result = $persist([...$values, ...$this->fromShared($published)]);

            $this->record->refresh();
            $this->markSaved($context, $live, $mine);

            return $result;
        });
    }

    /** The current values of the room's fields, as the shared draft holds them. */
    public function currentValues(): array
    {
        $values = [];

        foreach ($this->fields as $field => $kind) {
            $values[$field] = self::toSharedValue($kind, data_get($this->record, $field));
        }

        return $values;
    }

    /** @return list<SharedField> */
    public function currentFields(): array
    {
        $values = $this->currentValues();

        return array_map(
            static fn (string $field, FieldKind $kind): SharedField => new SharedField($field, $kind, $values[$field]),
            array_keys($this->fields),
            $this->fields,
        );
    }

    /** Brings the shared draft in line with the database, unless somebody is saving right now. */
    private function reconcile(): void
    {
        $documents = app(DocumentRepository::class);
        $reconcile = function () use ($documents): void {
            $documents->reconcile($this->draft(), $this->currentFields());
            $documents->link($this->draft(), $this->id());
        };

        if (! app(SaveLock::class)->attempt($this->id(), $reconcile) && ! $documents->exists($this->draft())) {
            rescue(fn () => app(SaveLock::class)->block($this->id(), $reconcile));
        }
    }

    /** @param array<string, mixed> $mine */
    private function markSaved(?RoomContext $context, LiveSave $live, array $mine): void
    {
        $user = $this->user();
        $client = $context === null ? 0 : $context->client;

        // A form that was not live overwrote whatever it changed.
        $overwritten = array_values(array_diff(array_keys(array_intersect_key($mine, $this->fields)), $live->fields));

        $contributors = app(DocumentRepository::class)->markSaved(
            $this->draft(),
            $this->currentFields(),
            $live->snapshot,
            $live->fields,
            $overwritten,
            $user === null ? Data::string(__('collaboration::collaboration.someone')) : Identity::for($user)->name,
            $client,
            $user,
        );

        if (Collaboration::$audits) {
            foreach ($contributors as $field => $people) {
                foreach ($people as $contributor) {
                    app(EditTrail::class)->record($this->id(), $this->record, $contributor, [(string) $field]);
                }
            }
        }
    }

    /** The token a saving form sent back, if it is this user's, for this room. */
    private function contextFromSync(string $sync): ?RoomContext
    {
        $context = RoomContext::fromToken(Data::string(Data::decode($sync)['token'] ?? null));

        if ($context === null || $context->room !== $this->id() || ! $context->isEditing() || ! $context->belongsTo($context->currentUser())) {
            return null;
        }

        return $context;
    }

    /** @param array<string, FieldKind> $live */
    private function context(Authenticatable $user, array $live): RoomContext
    {
        $this->client ??= self::clientFromRequest() ?? random_int(1, 2 ** 31 - 1);

        return new RoomContext(
            room: $this->id(),
            scope: null,
            mode: $this->mode,
            client: $this->client,
            guard: $this->guardName(),
            user: Data::string($user->getAuthIdentifier()),
            fields: $live,
            visible: array_keys($this->fields),
            model: $this->record::class,
            key: Data::string($this->record->getKey()),
            expiresAt: now()->getTimestamp() + max(1, Data::int(config('collaboration.token_lifetime'), 900)),
            allowed: array_intersect_key($this->allowed, $live),
            permissions: Permissions::version($this->id()),
            locale: $this->locale,
            meta: $this->meta,
        );
    }

    /** An Inertia page reloading its room sends the client it already has. */
    private static function clientFromRequest(): ?int
    {
        $client = request()->header('X-Collaboration-Client');

        return is_string($client) && ctype_digit($client) && (int) $client > 0 && (int) $client < 2 ** 31 ? (int) $client : null;
    }

    /**
     * Form values in the shape the draft keeps them in.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function toShared(array $values): array
    {
        $shared = [];

        foreach (array_intersect_key($values, $this->fields) as $field => $value) {
            $shared[$field] = self::toSharedValue($this->fields[$field], $value);
        }

        return $shared;
    }

    /**
     * Values from the draft in the shape the record keeps them in: a rich
     * text column that held HTML gets HTML back.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function fromShared(array $values): array
    {
        foreach ($values as $field => $value) {
            if ($this->fields[$field] === FieldKind::RichText && is_array($value) && ! $this->keepsJson($field)) {
                $values[$field] = Html::fromDocument($value);
            }
        }

        return $values;
    }

    private function keepsJson(string $field): bool
    {
        return is_array(data_get($this->record, $field)) || $this->record->hasCast($field, ['array', 'json', 'object', 'collection']);
    }

    private static function toSharedValue(FieldKind $kind, mixed $value): mixed
    {
        return match (true) {
            $value instanceof BackedEnum => $value->value,
            $value instanceof DateTimeInterface => $value->format('Y-m-d H:i:s'),
            $kind === FieldKind::RichText && is_string($value) => Html::toDocument($value),
            $kind === FieldKind::RichText && $value === null => ['type' => 'doc', 'content' => [['type' => 'paragraph']]],
            $kind === FieldKind::Text && $value !== null && ! is_string($value) => is_scalar($value) ? (string) $value : $value,
            default => $value,
        };
    }

    /**
     * @param  list<string|int|BackedEnum>|class-string<BackedEnum>  $allowed
     * @return list<string>
     */
    private static function allowedValues(array|string $allowed): array
    {
        $values = is_string($allowed) ? $allowed::cases() : $allowed;

        return array_values(array_map(static fn (mixed $value): string => (string) ($value instanceof BackedEnum ? $value->value : $value), $values));
    }

    /** @param list<string> $fields */
    private function add(FieldKind $kind, array $fields): self
    {
        foreach ($fields as $field) {
            $this->fields[$field] = $kind;
        }

        return $this;
    }

    /** @return array<string, string> */
    private function resolvedLabels(): array
    {
        $labels = [];

        foreach (array_keys($this->fields) as $field) {
            $labels[$field] = $this->labels[$field] ?? str($field)->afterLast('.')->headline()->toString();
        }

        return $labels;
    }

    private function isEditing(): bool
    {
        return $this->mode === PresenceMode::Editing;
    }

    private function user(): ?Authenticatable
    {
        return auth()->guard($this->guardName())->user();
    }

    private function guardName(): string
    {
        return $this->guard ?? Data::string(config('auth.defaults.guard'), 'web');
    }
}
