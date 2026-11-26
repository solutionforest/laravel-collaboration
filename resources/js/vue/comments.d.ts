import type { DefineComponent, Ref } from 'vue'
import type { CommentsState, RecordComment } from '../types/index.js'

/** A record's comments, for an app's own UI. */
export function useComments(form: { collaboration: { room: unknown } }): CommentsState<Ref<RecordComment[]>>

export const Comments: DefineComponent<{ form: { collaboration: unknown }, field?: string }>
