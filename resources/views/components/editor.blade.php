@props(['field', 'statePath' => null, 'images' => false])

{{--
    A TipTap rich text editor for a Livewire component's field, collaborative:
    <x-collaboration::editor field="body" class="prose" />
    Put it inside a component that renders <x-collaboration::room>.
--}}
<div
    wire:ignore
    data-collab-editor="{{ $field }}"
    @if ($statePath) data-state-path="{{ $statePath }}" @endif
    data-images="{{ $images ? 'true' : 'false' }}"
    {{ $attributes }}
></div>

@assets
    <script src="{{ route('collaboration.livewire.editor', ['v' => \SolutionForest\Collaboration\Http\Controllers\ScriptController::version(\SolutionForest\Collaboration\Http\Controllers\ScriptController::EDITOR)]) }}" defer></script>
@endassets
