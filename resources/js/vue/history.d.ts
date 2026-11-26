import type { DefineComponent, Ref } from 'vue'
import type { Version } from '../types/index.js'

export const History: DefineComponent<{ form: { collaboration: unknown } }>

/** The record's history, for an app's own UI. */
export function useVersions(form: { collaboration: { room: unknown } }): { versions: Ref<Version[]>, canRestore: Ref<boolean>, restore(id: number): Promise<unknown> | undefined }
