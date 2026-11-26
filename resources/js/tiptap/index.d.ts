import type { Extension } from '@tiptap/core'
import type { Room } from '../types/index.js'

/** Makes a TipTap editor collaborative: `Collaborative.configure({ room, field: 'body' })`. */
export const Collaborative: Extension<{ room: Room | null, field: string, /** Upload images pasted or dropped in; needs TipTap's Image extension. */ images?: boolean }>
