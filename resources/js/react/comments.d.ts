import type { ReactElement } from 'react'
import type { CommentsState, RecordComment, Room } from '../types/index.js'

/** A record's comments, for an app's own UI. */
export function useComments(source: { collaboration: { room: Room | null } } | { room: Room | null }): CommentsState<RecordComment[]>

/** A record's comments, with a box to write one; `field` shows one field's. */
export function Comments(props: { form: { collaboration: { room: Room | null } }, field?: string | null, className?: string }): ReactElement
