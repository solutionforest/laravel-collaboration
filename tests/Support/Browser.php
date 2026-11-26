<?php

declare(strict_types=1);

namespace SolutionForest\Collaboration\Tests\Support;

use Illuminate\Testing\TestResponse;
use SolutionForest\Collaboration\Crdt\Document;
use SolutionForest\Collaboration\Room;
use SolutionForest\Collaboration\Support\Data;
use SolutionForest\Collaboration\Tests\Fixtures\Models\User;
use SolutionForest\Collaboration\Tests\TestCase;
use Symfony\Component\HttpFoundation\Response;

/** One open tab, doing what the JavaScript client does. */
final class Browser
{
    public ?Document $document = null;

    public int $epoch = 0;

    public int $sequence = -1;

    /** @var list<array{n: int, ops: list<array<string, mixed>>}> */
    public array $outbox = [];

    private int $batch = 0;

    /** @var array<string, mixed> */
    public array $config;

    public function __construct(private readonly TestCase $test, public readonly User $user, Room $room)
    {
        $test->actingAs($user);
        $this->config = $room->toArray();
    }

    public function type(string $field, mixed $value): self
    {
        if ($this->document === null) {
            return $this;
        }

        $operations = $this->document->changes($field, $value, client: Data::int($this->config['client'] ?? null));

        if ($operations !== []) {
            $this->document->apply($operations);
            $this->outbox[] = ['n' => ++$this->batch, 'ops' => $operations];
        }

        return $this;
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return TestResponse<Response>
     */
    public function sync(array $extra = []): TestResponse
    {
        $this->test->actingAs($this->user);

        $response = $this->test->postJson(route('collaboration.sync'), [
            'token' => $this->config['token'],
            'epoch' => $this->epoch,
            'since' => $this->sequence,
            'batches' => $this->outbox,
            ...$extra,
        ]);

        if ($response->isOk() && $response->json('epoch') !== null) {
            $this->receive(Data::map($response->json()));
        }

        return $response;
    }

    public function value(string $field): mixed
    {
        return $this->document?->value($field);
    }

    /** What the form sends with its other fields when it is saved. */
    public function payload(): string
    {
        return json_encode(['token' => $this->config['token'], 'epoch' => $this->epoch, 'fields' => array_keys((array) $this->config['fields']), 'batches' => $this->outbox], JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $response */
    private function receive(array $response): void
    {
        $this->outbox = [];

        if ($response['state'] !== null) {
            $this->document = Document::from($response['state']);
        } elseif ($response['operations'] !== []) {
            $this->document?->apply(array_map(Data::map(...), Data::list($response['operations'])));
        }

        $this->epoch = Data::int($response['epoch'] ?? null);
        $this->sequence = Data::int($response['sequence'] ?? null);
    }
}
