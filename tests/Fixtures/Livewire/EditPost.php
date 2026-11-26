<?php

declare(strict_types=1);

namespace SolutionForest\Collaboration\Tests\Fixtures\Livewire;

use Livewire\Component;
use SolutionForest\Collaboration\Livewire\WithCollaboration;
use SolutionForest\Collaboration\Room;
use SolutionForest\Collaboration\Tests\Fixtures\Models\Post;

final class EditPost extends Component
{
    use WithCollaboration;

    public Post $post;

    public string $title = '';

    public string $status = '';

    public ?string $category = null;

    public function mount(Post $post): void
    {
        $this->post = $post;
        $this->title = $post->title;
        $this->status = $post->status;
        $this->category = $post->category;
    }

    public function room(): Room
    {
        return $this->post->collaboration();
    }

    public function save(): void
    {
        $this->saveCollaboratively();
    }

    public function render(): string
    {
        return <<<'BLADE'
            <form wire:submit="save">
                <x-collaboration::room :room="$this->collaborationRoom()" />
                <input wire:model="title">
                <select wire:model="status"><option>draft</option><option>published</option></select>
            </form>
            BLADE;
    }
}
