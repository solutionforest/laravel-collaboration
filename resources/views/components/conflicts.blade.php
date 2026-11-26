@props(['conflicts' => []])

{{-- Fields somebody else saved while you changed them too. Put it inside a Livewire component using WithCollaboration. --}}
@if ($conflicts !== [])
    <div {{ $attributes->class('collab-conflicts') }} role="alert">
        @foreach ($conflicts as $field => $conflict)
            <div class="collab-conflict" wire:key="collab-conflict-{{ $field }}">
                <p>{{ __('collaboration::collaboration.conflict', ['name' => $conflict['name'], 'field' => str($field)->headline()]) }}</p>
                <p class="collab-conflict-theirs">{{ is_scalar($conflict['theirs']) ? $conflict['theirs'] : json_encode($conflict['theirs']) }}</p>
                <button type="button" wire:click="keepMyVersion('{{ $field }}')">{{ __('collaboration::collaboration.keep_mine') }}</button>
                <button type="button" wire:click="useTheirVersion('{{ $field }}')">{{ __('collaboration::collaboration.use_theirs', ['name' => $conflict['name']]) }}</button>
            </div>
        @endforeach
    </div>
@endif
