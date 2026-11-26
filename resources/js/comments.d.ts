import type { RecordComment, Room } from './types/index.js'

export type { RecordComment }

/** Draws a record's comments into an element; returns what removes them. */
export function renderComments(element: HTMLElement, room: Room, options?: { field?: string | null }): () => void

/** A record's comments, kept up to date while something shows them. */
export class Comments {
    constructor(room: Room)
    readonly list: RecordComment[]
    readonly mentionable: string[]
    readonly canWrite: boolean
    about(field: string | null): RecordComment[]
    on(event: 'change', callback: () => void): () => void
    post(body: string, field?: string | null): Promise<RecordComment[]>
    resolve(id: number): Promise<RecordComment[]>
    reopen(id: number): Promise<RecordComment[]>
}

/** The one `Comments` of a room. */
export function commentsOf(room: Room): Comments
