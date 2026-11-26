import { createHash } from 'node:crypto'

/** The mermaid blocks of a markdown file. */
export function mermaidBlocks(markdown) {
    return [...markdown.matchAll(/^```mermaid\n([\s\S]*?)^```$/gm)].map((match) => match[1])
}

/** The file name of a diagram: the same code always draws to the same file. */
export const diagramName = (code) => createHash('sha256').update(code).digest('hex').slice(0, 16)

/** The diagram's description, from its first line when that is a `%%` comment. */
export const diagramAlt = (code) => code.match(/^%%\s*(.+)$/m)?.[1].trim() ?? 'Diagram'
