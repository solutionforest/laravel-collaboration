import type { ReactElement } from 'react'
import type { Room, Version } from '../types/index.js'

/** The record's history, with Restore for editors. */
export function History(props: { form: { collaboration: { room: Room | null } }, className?: string }): ReactElement

/** The record's history, for an app's own UI. */
export function useVersions(form: { collaboration: { room: Room | null } }): { versions: Version[], canRestore: boolean, restore(id: number): Promise<unknown> | undefined }
