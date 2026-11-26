<?php

declare(strict_types=1);

namespace SolutionForest\Collaboration;

use BackedEnum;
use Closure;
use DateTimeInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use JsonSerializable;
use SolutionForest\Collaboration\Broadcasting\Audience;
use SolutionForest\Collaboration\Crdt\Html;
use SolutionForest\Collaboration\Documents\DocumentRepository;
use SolutionForest\Collaboration\Documents\EditTrail;
use SolutionForest\Collaboration\Documents\SharedField;
use SolutionForest\Collaboration\Documents\SharedUploads;
use SolutionForest\Collaboration\Enums\FieldKind;
use SolutionForest\Collaboration\Enums\PresenceMode;
use SolutionForest\Collaboration\Exceptions\SaveConflict;
use SolutionForest\Collaboration\Exceptions\UnknownFieldKind;
use SolutionForest\Collaboration\Merge\ThreeWayMerge;
use SolutionForest\Collaboration\Presence\Identity;
use SolutionForest\Collaboration\Rooms\RoomAuthorizer;
use SolutionForest\Collaboration\Rooms\RoomContext;
use SolutionForest\Collaboration\Rooms\RoomResolver;
use SolutionForest\Collaboration\Saving\LiveSave;
use SolutionForest\Collaboration\Support\Data;
use SolutionForest\Collaboration\Support\Database;
use SolutionForest\Collaboration\Support\Items;
use SolutionForest\Collaboration\Support\ModelFields;
use SolutionForest\Collaboration\Support\SaveLock;

/**
 * The room people share while they work on one record: which of its fields
 * are edited together, and how. Render it into the page, then save through it.
 *
 *     $room = Collaboration::room($post)->text('title')->rich('body');
 *
 *     return Inertia::render('Posts/Edit', ['collaboration' => $room]);
 *
 *     $room->update($request->validated());
 *
 * @implements Arrayable<string, mixed>
 */
final class Room implements Arrayable, JsonSerializable
{
    /** The name of the input a form sends its live edits in when it is saved. */
    public const INPUT = '_collaboration';

    /** And what its other fields held when it loaded or was last saved. */
    public const BASE = '_collaboration_base';

    /** @var array<string, FieldKind> */
    private array $fields = [];

    /** @var array<string, string> */
    private array $labels = [];

    /** @var array<string, list<string>> */
    private array $allowed = [];

    /** @var list<string> */
    private array $locks = [];

    /** @var array<string, list<string>> each list's sub-fields that merge by character */
    private array $texts = [];

    private PresenceMode $mode = PresenceMode::Editing;

    private ?string $locale = null;

    private ?string $guard = null;

    /** @var array<string, string|null> */
    private array $meta = [];

    private ?int $client = null;

    public function __construct(private readonly Model $record)
    {
        // The `Collaborative` trait says once, on the model, which fields every room has.
        if (method_exists($record, 'collaborativeFields')) {
            $this->fields(self::fieldListOf($record->collaborativeFields()));
        }
    }

    /** Fields that merge character by character, such as a title or a textarea. */
    public function text(string ...$fields): self
    {
        return $this->add(FieldKind::Text, array_values($fields));
    }

    /**
     * A repeater: a list of items people add, remove, reorder and edit
     * together. Sub-fields named in `text` merge by character; the others
     * take the latest change. Each item is saved with a `key`, so it keeps
     * its identity from one save to the next.
     *
     *     ->items('lines', text: ['description'])
     *
     * @param  list<string>  $text
     */
    public function items(string $field, array $text = []): self
    {
        $this->texts[$field] = array_values($text);

        return $this->add(FieldKind::Items, [$field]);
    }

    /** Fields edited in a rich text editor such as TipTap, kept as HTML or as JSON. */
    public function rich(string ...$fields): self
    {
        return $this->add(FieldKind::RichText, array_values($fields));
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
     * Several fields at once, as name => kind. A name alone takes its kind
     * from the model's casts: a boolean, number, date or enum is a value,
     * anything else text.
     *
     *     ->fields(['title', 'body' => FieldKind::RichText, 'status' => 'value'])
     *
     * @param  array<int|string, string|FieldKind>  $fields
     *
     * @throws UnknownFieldKind
     */
    public function fields(array $fields): self
    {
        foreach ($fields as $name => $kind) {
            if (is_int($name)) {
                $name = is_string($kind) ? $kind : throw new InvalidArgumentException("A field of kind [{$kind->value}] needs a name: ['body' => FieldKind::{$kind->name}].");
                $kind = ModelFields::kindOf($this->record, $name);
            }

            $this->fields[$name] = $kind instanceof FieldKind ? $kind : (FieldKind::tryFrom($kind) ?? throw UnknownFieldKind::named($kind, $name));
        }

        return $this;
    }

    /**
     * Fields only one person edits at a time: while somebody is in one, it
     * is read-only for everybody else, and says who is in it. For fields
     * where a merge makes no sense, such as a price or a slug.
     */
    public function lock(string ...$fields): self
    {
        $this->locks = array_values(array_unique([...$this->locks, ...$fields]));

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

    /** A record's room; before it is saved, the room of everybody creating one. */
    public function id(): string
    {
        return $this->isCreating()
            ? app(RoomResolver::class)->forScope($this->record->getMorphClass())
            : app(RoomResolver::class)->forRecord($this->record);
    }

    /** Whether this is a new record, not saved yet: its room only shows who else is creating one. */
    public function isCreating(): bool
    {
        return ! $this->record->exists;
    }

    public function draft(): string
    {
        return RoomContext::draftFor($this->id(), $this->locale);
    }

    /** What the browser starts the room from. Empty when nobody may join it. */
    public function toArray(): array
    {
        // A typo in a field name fails here, in development, not silently in the browser.
        if (! $this->isCreating() && config('app.debug')) {
            ModelFields::assertExist($this->record, [...array_keys($this->fields), ...$this->locks]);
        }

        $user = $this->user();

        if ($user === null || ! RoomAuthorizer::allows($user, $this->isCreating() ? $this->record::class : $this->record, $this->ability())) {
            return [];
        }

        // A new record has no draft yet: people creating one see each other, and nothing more.
        $live = $this->isEditing() && ! $this->isCreating() && Permissions::mayCollaborate($this->record, $user) ? $this->fields : [];

        if ($live !== []) {
            $this->reconcile();
        }

        $context = $this->context($user, $live);

        return [
            'id' => substr($context->room, 0, 16).'-'.$context->client,
            'url' => route('collaboration.sync'),
            'endpoints' => self::endpoints(),
            'csrf' => csrf_token(),
            'token' => $context->toToken(),
            'client' => $context->client,
            'mode' => $this->mode()->value,
            'fields' => (object) array_map(static fn (FieldKind $kind): string => $kind->value, $live),
            'labels' => (object) $this->resolvedLabels(),
            'locks' => array_values(array_intersect($this->locks, array_keys($this->fields))),
            'draft' => $context->draft(),
            'input' => self::INPUT,
            'interval' => Collaboration::$pollingInterval,
            'channel' => Collaboration::broadcasts() ? Audience::of($context)->channel() : null,
            'avatarLimit' => Collaboration::$avatarLimit,
            'styles' => Collaboration::$styles,
            'reportErrors' => $this->isEditing() && Collaboration::reportsEditorErrors(),
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
     * @param  array<string, mixed>|null  $base  what the form's other fields held when it loaded; read from the request when null
     * @return TResult
     *
     * @throws SaveConflict when somebody else saved a field this person changed too
     */
    public function save(array $values, Closure $persist, ?string $sync = null, ?array $base = null): mixed
    {
        $sync ??= Data::string(request()->input(self::INPUT));
        $base ??= Data::decode(request()->input(self::BASE));

        return app(SaveLock::class)->block($this->id(), function () use ($values, $persist, $sync, $base): mixed {
            $documents = app(DocumentRepository::class);
            $context = $this->contextFromSync($sync);
            $live = new LiveSave;

            if ($context !== null && $context->fields !== []) {
                $documents->reconcile($context->draft(), $this->currentFields());
                $live = LiveSave::prepare($documents, $context->draft(), $context->client, $sync, $context->fields, $context->allowed, $context->currentUser());
            }

            $values = $this->mergeOtherFields($values, $base, $live->fields);
            $mine = $this->toShared($values);
            $published = $live->valuesToPublish($mine, $this->currentValues());
            $result = $persist([...$values, ...$this->fromShared($published)]);

            // A new record saved through its create room has no draft to bring up to date.
            if ($this->isCreating()) {
                return $result;
            }

            $this->record->refresh();
            $this->markSaved($context, $live, $mine);

            // Images the record now holds are the record's, no longer the draft's.
            app(SharedUploads::class)->release($this->draft());

            return $result;
        });
    }

    /**
     * Updates the record with the form's values and everybody's latest edits.
     *
     *     $post->collaboration()->update($request->validated());
     *
     * @param  array<string, mixed>  $values  what the form submitted
     * @param  string|null  $sync  the form's `_collaboration` input; read from the request when null
     */
    public function update(array $values, ?string $sync = null, ?array $base = null): bool
    {
        return $this->save($values, fn (array $merged): bool => $this->record->update($merged), $sync, $base);
    }

    /**
     * Fields that are not live, merged three ways against what the form
     * loaded: one this person left alone keeps what somebody else saved
     * meanwhile, and one both changed stops the save until they choose.
     *
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $base
     * @param  list<string>  $live
     * @return array<string, mixed>
     *
     * @throws SaveConflict
     */
    private function mergeOtherFields(array $values, array $base, array $live): array
    {
        if ($base === [] || ! $this->record->exists) {
            return $values;
        }

        $saved = $this->record->fresh()?->toArray() ?? [];
        $conflicts = [];

        foreach ($values as $field => $mine) {
            if (in_array($field, $live, true) || ! array_key_exists($field, $base) || ! array_key_exists($field, $saved)) {
                continue;
            }

            $theirs = $saved[$field];

            if (ThreeWayMerge::same($theirs, $base[$field])) {
                continue;
            }

            if (ThreeWayMerge::same($mine, $base[$field])) {
                $values[$field] = $this->record->getAttribute($field);
            } elseif (! ThreeWayMerge::same($mine, $theirs)) {
                $conflicts[$field] = $theirs;
            }
        }

        if ($conflicts !== []) {
            throw SaveConflict::on($conflicts, $this->resolvedLabels(), $this->lastSavedBy());
        }

        return $values;
    }

    /** Who saved the record last, through its room. */
    private function lastSavedBy(): string
    {
        $savedBy = Data::decode(Database::table('documents')->where('room', $this->draft())->value('saved_by'));

        return Data::nullableString($savedBy['name'] ?? null) ?? Data::string(__('collaboration::collaboration.someone'));
    }

    /**
     * The current values of the room's fields, as the shared draft holds them.
     *
     * @return array<string, mixed>
     */
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
            static fn (string $field, FieldKind $kind): SharedField => new SharedField($field, $kind, $values[$field], $this->texts[$field] ?? []),
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

        if (Collaboration::audits()) {
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
            scope: $this->isCreating() ? $this->id() : null,
            mode: $this->mode(),
            client: $this->client,
            guard: $this->guardName(),
            user: Data::string($user->getAuthIdentifier()),
            fields: $live,
            visible: array_keys($this->fields),
            model: $this->record::class,
            key: $this->isCreating() ? null : Data::string($this->record->getKey()),
            expiresAt: now()->getTimestamp() + max(1, Data::int(Collaboration::config('token_lifetime'), 900)),
            allowed: array_intersect_key($this->allowedValuesOfFields(), $live),
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
                $values[$field] = Html::fromDocument(Data::map($value));
            }

            if ($this->fields[$field] === FieldKind::Items) {
                $values[$field] = Items::asList($value);
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
            $kind === FieldKind::Items => Items::byKey($value),
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

        return array_map(static fn (string|int|BackedEnum $value): string => (string) ($value instanceof BackedEnum ? $value->value : $value), $values);
    }

    /**
     * What a model's `$collaborative` holds, checked: names, and kinds as strings or cases.
     *
     * @return array<int|string, string|FieldKind>
     */
    private static function fieldListOf(mixed $fields): array
    {
        $list = [];

        foreach (Data::map($fields) as $name => $kind) {
            $list[$name] = $kind instanceof FieldKind ? $kind : Data::string($kind);
        }

        return $list;
    }

    /**
     * The values each picked field may take: what `value()` was given, or an enum cast's cases.
     *
     * @return array<string, list<string>>
     */
    private function allowedValuesOfFields(): array
    {
        $allowed = $this->allowed;

        foreach ($this->fields as $field => $kind) {
            $enum = $kind === FieldKind::Value ? ModelFields::enumOf($this->record, $field) : null;

            if (! isset($allowed[$field]) && $enum !== null) {
                $allowed[$field] = self::allowedValues($enum);
            }
        }

        return $allowed;
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

    /**
     * Where the page asks about its room besides syncing. An integration
     * that registers its own routes keeps the names.
     *
     * @return array<string, string>
     */
    private static function endpoints(): array
    {
        $endpoints = [];

        foreach (['values', 'comments', 'comments.store', 'comments.resolve', 'comments.reopen', 'versions', 'versions.restore', 'uploads'] as $name) {
            if (app('router')->has("collaboration.{$name}")) {
                $endpoints[$name] = route("collaboration.{$name}");
            }
        }

        return $endpoints;
    }

    private function isEditing(): bool
    {
        return $this->mode === PresenceMode::Editing;
    }

    private function mode(): PresenceMode
    {
        return $this->isCreating() && $this->isEditing() ? PresenceMode::Creating : $this->mode;
    }

    /** What the policy is asked: `create`, `update`, or `view` for a room people only watch. */
    private function ability(): string
    {
        return match (true) {
            $this->isCreating() => 'create',
            $this->isEditing() => 'update',
            default => 'view',
        };
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
