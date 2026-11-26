@props(['field'])

{{-- Who is in a field right now, as React's and Vue's <FieldPeople>: put it next to the field's label. --}}
<span {{ $attributes->class('collab-field-people') }} wire:ignore data-collab-people="{{ $field }}"></span>
