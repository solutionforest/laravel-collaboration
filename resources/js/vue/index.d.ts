import type { InertiaForm } from '@inertiajs/vue3'
import type { DefineComponent, Directive, Ref, ShallowRef } from 'vue'
import type { Conflict, Person, RecordPerson, RecordPresenceConfig, Room, RoomConfig, RoomStatus } from '../types/index.js'

export type { Person, Room, RoomConfig, RoomStatus }

/** What `useCollaboration()` returns. Inside a form, `form.collaboration` holds the same, unwrapped. */
export interface Collaboration {
    room: ShallowRef<Room | null>
    people: Ref<Person[]>
    status: Ref<RoomStatus>
    peopleIn(field: string): Person[]
    isLive(field: string): boolean
}

export interface CollaborationOptions {
    /** The page prop the room is in, reloaded when its token expires. `collaboration` by default. */
    prop?: string
}

/** What `v-collaborate` binds, from `form.field('title')`. */
export interface FieldReference {
    form: { collaboration: { room: Room | null } }
    name: string
}

export type CollaborativeForm<TForm extends Record<string, any>> = InertiaForm<TForm> & {
    /** For `v-collaborate="form.field('title')"`: only the form's own fields. */
    field(name: keyof TForm & string): FieldReference
    collaboration: {
        room: Room | null
        people: Person[]
        status: RoomStatus
        peopleIn(field: string): Person[]
        isLive(field: string): boolean
        conflicts: Conflict[]
        keepMine(field: string): void
        useTheirs(field: string): void
    }
}

export function useCollaboration(config: () => RoomConfig | null, options?: CollaborationOptions): Collaboration

export function useCollaborativeForm<TForm extends Record<string, any>>(config: () => RoomConfig | null, data: TForm, options?: CollaborationOptions): CollaborativeForm<TForm>

/** `v-collaborate:title="form"`, next to the input's `v-model`. */
export const vCollaborate: Directive<HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement, FieldReference | { collaboration: { room: Room | null } }>

type PresenceProps = { form?: { collaboration: unknown }, collaboration?: Collaboration }

export const Avatars: DefineComponent<PresenceProps & { limit?: number }>
export const FieldPeople: DefineComponent<PresenceProps & { field: string }>
export const Status: DefineComponent<PresenceProps>
export const Conflicts: DefineComponent<{ form: { collaboration: unknown } }>

/** Who is on each record of a list: `useRecordPresence(() => props.presence)`. */
export function useRecordPresence(config: () => RecordPresenceConfig | null): { people: Ref<Record<string, RecordPerson[]>>, peopleOn(key: string | number): RecordPerson[] }

export const RecordPeople: DefineComponent<{ presence: { peopleOn(key: string | number): RecordPerson[] }, record: string | number, limit?: number }>
