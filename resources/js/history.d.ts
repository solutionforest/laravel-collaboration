import type { Room, Version } from './types/index.js'

export type { Version }

/** Draws the record's history into an element; returns what removes it. */
export function renderHistory(element: HTMLElement, room: Room): () => void
