import type { InertiaFormProps } from '@inertiajs/react'
import type { ReactElement } from 'react'
import type { Person, Room, RoomConfig, RoomStatus } from '../types/index.js'

export type { Person, Room, RoomConfig, RoomStatus }

/** The room, the people in it and its status: `form.collaboration`, or `useCollaboration()`. */
export interface Collaboration {
    room: Room | null
    people: Person[]
    status: RoomStatus
    peopleIn(field: string): Person[]
    isLive(field: string): boolean
}

export interface CollaborationOptions {
    /** The page prop the room is in, reloaded when its token expires. `collaboration` by default. */
    prop?: string
}

/** Props to spread on an input, textarea, select, checkbox or radio. */
export interface FieldProps {
    name: string
    ref: (element: HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement | null) => void
    onChange: (eventOrValue: unknown) => void
    onFocus: () => void
    onBlur: () => void
    value?: any
    /** Text that merges by character: the binding writes it, not React. */
    defaultValue?: any
    checked?: boolean
}

export type CollaborativeForm<TForm extends Record<string, any>> = InertiaFormProps<TForm> & {
    /** Props for the field's input. For a radio, or a checkbox in a group, pass the value it stands for. */
    field(name: keyof TForm & string, options?: { value?: string | number }): FieldProps
    collaboration: Collaboration
}

export function useCollaboration(config: RoomConfig | null, options?: CollaborationOptions): Collaboration

export function useCollaborativeForm<TForm extends Record<string, any>>(config: RoomConfig | null, data: TForm, options?: CollaborationOptions): CollaborativeForm<TForm>

interface PresenceProps {
    form?: { collaboration: Collaboration }
    collaboration?: Collaboration
    className?: string
}

export function Avatars(props: PresenceProps & { limit?: number }): ReactElement | null

/** With a form, `field` must be one of its fields. */
export function FieldPeople<TForm extends Record<string, any>>(props: { form: CollaborativeForm<TForm>, field: keyof TForm & string, className?: string }): ReactElement
export function FieldPeople(props: { collaboration: Collaboration, field: string, className?: string }): ReactElement

export function Status(props: PresenceProps): ReactElement
