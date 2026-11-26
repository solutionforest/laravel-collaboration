/** What `Collaboration::room()` renders for the browser: pass it to the client as it is. */
export interface RoomConfig {
    id: string
    url: string
    /** Where the page asks about its room besides syncing: comments, versions, values, uploads. */
    endpoints: Record<string, string>
    csrf: string
    token: string
    client: number
    mode: 'editing' | 'viewing'
    /** The live fields and how each merges. */
    fields: Record<string, FieldKind>
    /** What people see for each field: "Alice is in Title". */
    labels: Record<string, string>
    /** Fields only one person edits at a time: `->lock()`. */
    locks: string[]
    draft: string
    /** The input a save sends its unsynced edits in. */
    input: string
    interval: number
    channel: string | null
    avatarLimit: number
    reportErrors: boolean
    user: string
    strings: Record<string, string>
    /** Livewire only: where the fields are in the component, such as `form`. */
    statePath?: string | null
}

export type FieldKind = 'text' | 'rich' | 'value' | 'items'

export type RoomStatus = 'connecting' | 'synced' | 'syncing' | 'offline' | 'expired' | 'stopped' | 'denied' | 'deleted'

/** Somebody else in the room. */
export interface Person {
    client: number
    key: string
    name: string
    initials: string
    avatar: string | null
    color: string
    mode: 'editing' | 'viewing' | 'creating' | 'browsing'
    /** The field they are in, if you may see it. */
    field: string | null
    cursor: { anchor: Id | null, head: Id | null } | null
    idle: boolean
    /** Yourself, in another tab. */
    self: boolean
}

/** A character's id in the shared draft: `[clock, client]`. */
export type Id = [number, number]

/** A selection, as positions in a field: characters for text, the draft's positions for rich text. */
export interface Selection {
    anchor: number
    head: number
}

/** What an adapter tells the room about one field it shows. */
export interface FieldBinding {
    capture?(): void
    render?(): void
    cursor?(): Selection | null
    /** Brings the field into view, for somebody following a colleague. */
    reveal?(): void
    readonly isBusy?: boolean
}

export interface RoomEvents {
    ready: Room
    change: { field: string, value: unknown }
    people: Person[]
    status: RoomStatus
    saved: { name: string | null }
    expired: Room
    deleted: { name: string | null }
    following: number | null
}

/** One record's room in this tab: the shared draft, the people in it, and the sync loop. */
export class Room {
    constructor(config: RoomConfig, options?: { transport?: { send(config: RoomConfig, payload: Record<string, unknown>, options?: { keepalive?: boolean }): Promise<any> } })
    readonly config: RoomConfig
    readonly status: RoomStatus
    readonly isReady: boolean
    readonly isLive: boolean
    /** Everybody else here, one entry per person. */
    readonly people: Person[]
    isLiveField(field: string): boolean
    value(field: string): unknown
    label(field: string): string
    change(field: string, value: unknown, caret?: number | null): void
    focus(field: string | null): void
    blur(field: string): void
    cursorMoved(): void
    bind(field: string, binding: FieldBinding): () => void
    idAt(field: string, index: number): Id | null
    indexOf(field: string, id: Id | null): number | null
    carets(field: string): Array<{ person: Person, anchor: number, head: number }>
    peopleIn(field: string): Person[]
    /** Who holds a locked field right now, if somebody else does. */
    lockedBy(field: string): Person | null
    /** The colleague this page follows from field to field, by client. */
    readonly following: number | null
    /** Follow a colleague; again, or null, to stop. */
    follow(client: number | null): void
    /** Asks the server something about this room, such as `room.call('comments')`. */
    call<T = any>(endpoint: string, payload?: Record<string, unknown>): Promise<T>
    /** What a form sends when it is saved; `Room::save()` reads it. */
    payload(): string
    on<E extends keyof RoomEvents>(event: E, callback: (detail: RoomEvents[E]) => void): () => void
    renew(config: RoomConfig): void
    destroy(): void
}

export class SyncError extends Error {
    readonly status: number
    readonly code: string
    readonly body: Record<string, unknown> | null
}

export class HttpTransport {
    send(config: RoomConfig, payload: Record<string, unknown>, options?: { keepalive?: boolean }): Promise<any>
}

type Input = HTMLInputElement | HTMLTextAreaElement

/** Makes an input or textarea collaborative. Returns what makes it an ordinary input again. */
export function bindTextField(room: Room, field: string, element: Input, options?: BindingOptions<string>): () => void

export interface BindingOptions<TValue> {
    /** Called with every value the binding writes into the element: others' edits, undo and redo. */
    onValueChange?: (value: TValue) => void
    /** @deprecated use `onValueChange` */
    onRemoteChange?: (value: TValue) => void
}

/** Makes a select, a checkbox or a group of radios collaborative. */
export function bindValueField(room: Room, field: string, target: HTMLInputElement | HTMLSelectElement | HTMLInputElement[], options?: BindingOptions<unknown>): () => void

/** Makes a locked field read-only while somebody else is in it. */
export function lockWhileEditing(room: Room, field: string, element: HTMLElement): () => void

/** Whether an input edits text with a caret, rather than a date, number or email. */
export function isTextInput(element: Element | null): boolean

export function renderAvatars(people: Person[], options?: { limit?: number, strings?: Record<string, string>, labels?: Record<string, string> }): HTMLElement

/** "Alice · In Title", for a tooltip. */
export function describe(person: Person, strings?: Record<string, string>, labels?: Record<string, string>): string

export function peopleIn(people: Person[], field: string): Person[]

export function avatarsOf(people: Person[], limit?: number): { shown: Person[], more: number }

/** An input's change event as a form value; anything else as it is. */
export function valueOf(event: unknown): unknown

/** A field somebody else saved while this person changed it too. */
export interface Conflict {
    field: string
    mine: unknown
    theirs: unknown
    name: string | null
}

/** What a form's other fields held when it loaded or was last saved. */
export class FormMemory {
    constructor(room: Room, values: Record<string, unknown>)
    readonly conflicts: Conflict[]
    saved(values: Record<string, unknown>): void
    pull(current: Record<string, unknown>, name?: string | null): Promise<{ take: Record<string, unknown>, conflicts: Conflict[] }>
    keepMine(field: string): void
    useTheirs(field: string): unknown
    payload(): string
}

/** What `Collaboration::presence($records)` renders. */
export interface RecordPresenceConfig {
    url: string
    csrf: string
    token: string
    interval: number
    people: Record<string, RecordPerson[]>
    strings: Record<string, string>
}

/** Somebody on a record of a list. */
export interface RecordPerson {
    key: string
    name: string
    initials: string
    avatar: string | null
    color: string
    mode: string
    idle: boolean
}

/** Who is on each record of a list, asked again every few seconds. */
export class RecordPresence {
    constructor(config: RecordPresenceConfig)
    readonly people: Record<string, RecordPerson[]>
    peopleOn(key: string | number): RecordPerson[]
    on(event: 'people', callback: (people: Record<string, RecordPerson[]>) => void): () => void
    destroy(): void
}

/** One item of a repeater, as a form holds it. */
export type Item = Record<string, any> & { key: string }

/** A repeater's list, and what changes it. */
export interface Repeater<TFieldProps> {
    readonly list: Item[]
    add(item?: Record<string, any>): void
    remove(key: string): void
    move(key: string, to: number): void
    /** What binds one item's sub-field input. */
    field(key: string, path: string): TFieldProps
}

/** A comment on a record, or on one of its fields. */
export interface RecordComment {
    id: number
    field: string | null
    body: string
    /** The body, escaped, with mentions marked. */
    html: string
    author: string
    mine: boolean
    mentions: string[]
    at: string
    resolved: boolean
    resolvedBy: string | null
}

/** What a comments hook gives an app's own UI. */
export interface CommentsState<TList> {
    comments: TList
    mentionable: string[] | { value: string[] }
    canWrite: boolean | { value: boolean }
    /** Mention people as `@Name`. */
    post(body: string, field?: string | null): Promise<RecordComment[]> | undefined
    resolve(id: number): Promise<RecordComment[]> | undefined
    reopen(id: number): Promise<RecordComment[]> | undefined
}

/** Draws a record's comments into an element; returns what removes them. */
export function renderComments(element: HTMLElement, room: Room, options?: { field?: string | null }): () => void

/** One version of the shared draft. */
export interface Version {
    id: number
    /** `edit`, `save`, `before`, `restore` or `discard`. */
    reason: string
    name: string | null
    at: string
    changed: string[]
}

/** Draws the record's history into an element; returns what removes it. */
export function renderHistory(element: HTMLElement, room: Room): () => void
