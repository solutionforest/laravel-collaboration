@props(['field' => null])

{{-- A record's comments, with a box to write one. Put it inside a Livewire component that renders <x-collaboration::room>. --}}
<div {{ $attributes }} wire:ignore data-collab-comments @if ($field) data-field="{{ $field }}" @endif></div>
