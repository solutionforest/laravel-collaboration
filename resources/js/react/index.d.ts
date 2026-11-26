import type { InertiaFormProps } from '@inertiajs/react'
import type { ReactElement } from 'react'
import type { CommentsState, Conflict, Person, RecordComment, RecordPerson, RecordPresenceConfig, Repeater, Room, RoomConfig, RoomStatus } from '../types/index.js'

export type { Person, Room, RoomConfig, RoomStatus }

/** The room, the people in it and its status: `form.collaboration`, or `useCollaboration()`. */
export interface Collaboration {
    room: Room | null
    people: Person[]
    status: RoomStatus
    peopleIn(field: string): Person[]
    isLive(field: string): boolean
    /** The colleague this page follows from field to field. */
    following: number | null
    /** Follow a colleague by client; again, or null, to stop. Clicking a face in `<Avatars>` does this. */
    follow(client: number | null): void
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

/** `form.collaboration`: the room, and the fields somebody else saved while you changed them too. */
export interface FormCollaboration extends Collaboration {
    conflicts: Conflict[]
    keepMine(field: string): void
    useTheirs(field: string): void
}

export type CollaborativeForm<TForm extends Record<string, any>> = InertiaFormProps<TForm> & {
    /** Props for the field's input. For a radio, or a checkbox in a group, pass the value it stands for. */
    field(name: keyof TForm & string, options?: { value?: string | number }): FieldProps
    /** A repeater: `form.items('lines')`, with props for each item's sub-field inputs. */
    items(name: keyof TForm & string): Repeater<FieldProps>
    collaboration: FormCollaboration
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

/** Fields somebody else saved while you changed them too, with "Keep mine" and "Use theirs". */
export function Conflicts(props: { form: { collaboration: FormCollaboration }, className?: string }): ReactElement | null

/** Who is on each record of a list: `useRecordPresence(props.presence)`. */
export function useRecordPresence(config: RecordPresenceConfig | null): { people: Record<string, RecordPerson[]>, peopleOn(key: string | number): RecordPerson[] }

/** The faces of everybody on one record of a list. */
export function RecordPeople(props: { presence: { peopleOn(key: string | number): RecordPerson[] }, record: string | number, limit?: number, className?: string }): ReactElement

/** A record's comments, for an app's own UI. */
export function useComments(source: { collaboration: { room: Room | null } } | { room: Room | null }): CommentsState<RecordComment[]>

/** A record's comments, with a box to write one; `field` shows one field's. */
export function Comments(props: { form: { collaboration: { room: Room | null } }, field?: string | null, className?: string }): ReactElement
