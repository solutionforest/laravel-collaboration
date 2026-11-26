import type { Extension } from '@tiptap/core'
import type { Room } from '../types/index.js'

/** Makes a TipTap editor collaborative: `Collaborative.configure({ room, field: 'body' })`. */
export const Collaborative: Extension<{ room: Room | null, field: string }>
