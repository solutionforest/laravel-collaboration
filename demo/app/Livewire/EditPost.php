<?php

namespace App\Livewire;

use App\Models\Post;
use Livewire\Attributes\Layout;
use Livewire\Component;
use SolutionForest\Collaboration\Livewire\WithCollaboration;
use SolutionForest\Collaboration\Room;

#[Layout('layouts.app', ['stack' => 'Livewire'])]
class EditPost extends Component
{
    use WithCollaboration;

    public Post $post;

    public string $title = '';

    public ?string $summary = '';

    /** HTML from the database; the editor's document once someone types. */
    public array|string|null $body = null;

    public string $status = '';

    public bool $featured = false;

    public bool $saved = false;

    public function mount(Post $post): void
    {
        $this->fill($post->only('title', 'summary', 'body', 'status', 'featured'));
    }

    public function room(): Room
    {
        return $this->post->collaboration();
    }

    public function save(): void
    {
        $this->validate(['title' => ['required', 'string', 'max:255'], 'status' => ['required', 'in:'.implode(',', array_keys(Post::STATUSES))]]);

        $this->saveCollaboratively();

        $this->saved = true;
    }

    public function render()
    {
        return view('livewire.edit-post', ['statuses' => Post::STATUSES]);
    }
}
