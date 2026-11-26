{{-- The record's history, with Restore for editors. Put it inside a Livewire component that renders <x-collaboration::room>. --}}
<div {{ $attributes }} wire:ignore data-collab-history></div>

@assets
    <script src="{{ route('collaboration.livewire.features', ['v' => \SolutionForest\Collaboration\Http\Controllers\ScriptController::version(\SolutionForest\Collaboration\Http\Controllers\ScriptController::FEATURES)]) }}" defer></script>
@endassets
