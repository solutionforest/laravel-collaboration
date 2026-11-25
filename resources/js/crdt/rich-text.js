import { canonical, characters } from './id.js'
import { INLINE, TEXT_BLOCKS, markAttributes, nodeAttributes } from './schema.js'

/** TipTap document <-> flat sequence. Mirrors `src/Crdt/RichText.php`. */

const CONTINUES = 0
const STARTS = 1
const LEAF = 2

/**
 * A block's position in the tree: from the outermost ancestor to the block,
 * `[type, attributes or 0, flag]`.
 *
 * @typedef {Array<[string, Record<string, any> | null | 0, number]>} BlockPath
 */

/** @type {(token: Token) => boolean} */
export const isBlock = (token) => typeof token.value !== 'string' && 'b' in token.value

/** @type {(ancestors: BlockPath) => BlockPath} */
const continued = (ancestors) => ancestors.map(([type, attributes]) => [type, attributes, CONTINUES])

/**
 * @param {Partial<RichNode> | null | undefined} document
 * @returns {Token[]}
 */
export function toTokens(document) {
    /** @type {Token[]} */
    const tokens = []

    /** @param {BlockPath} path */
    const block = (path) =>
        tokens.push({
            value: { b: 1 },
            formatting: {
                b: canonical(path.map(([type, attributes, flag]) => [type, attributes ?? 0, flag])),
            },
        })

    /** @param {RichNode} node */
    const content = (node) => {
        const marks = (node.marks ?? []).map((mark) => {
            const attributes = markAttributes(mark.type, mark.attrs)

            return [`m:${mark.type}`, attributes ? canonical(attributes) : '1']
        })
        const formatting = Object.fromEntries(marks.sort(([a], [b]) => (a < b ? -1 : 1)))

        if (node.type === 'text') {
            for (const value of characters(node.text ?? '')) tokens.push({ value, formatting })

            return
        }

        if (!INLINE.has(node.type)) throw new Error(`Rich content node [${node.type}] cannot be shared live.`)

        const attributes = nodeAttributes(node.type, node.attrs)

        tokens.push({
            value: { n: canonical(attributes ? { attrs: attributes, type: node.type } : { type: node.type }) },
            formatting,
        })
    }

    /**
     * @param {RichNode[]} nodes
     * @param {BlockPath} ancestors
     */
    const children = (nodes, ancestors) => {
        let loose = false

        for (const node of nodes) {
            if (node.type === 'text' || INLINE.has(node.type)) {
                if (!loose) {
                    block([...ancestors, ['paragraph', null, CONTINUES]])
                    ancestors = continued(ancestors)
                    loose = true
                }

                content(node)
                continue
            }

            loose = false

            const attributes = nodeAttributes(node.type, node.attrs)

            if (TEXT_BLOCKS.has(node.type)) {
                block([...ancestors, [node.type, attributes, CONTINUES]])
                node.content?.forEach(content)
            } else if (!node.content?.length) {
                block([...ancestors, [node.type, attributes, LEAF]])
            } else {
                children(node.content, [...ancestors, [node.type, attributes, STARTS]])
            }

            ancestors = continued(ancestors)
        }
    }

    children(document?.content ?? [], [])

    if (!tokens.length) block([['paragraph', null, CONTINUES]])

    return tokens
}

/**
 * @param {string} type
 * @param {Record<string, any> | null | 0} attributes
 * @returns {RichNode}
 */
function createNode(type, attributes) {
    /** @type {RichNode} */
    const node = { type }

    if (attributes && Object.keys(attributes).length) node.attrs = attributes

    return node
}

/** @type {(parent: RichNode, child: RichNode) => void} */
const append = (parent, child) => (parent.content ??= []).push(child)

/**
 * @param {Token[]} tokens
 * @returns {RichNode}
 */
export function toDocument(tokens) {
    /** @type {RichNode} */
    const root = { type: 'doc' }
    /** @type {RichNode[]} */
    let open = []
    /** @type {RichNode | null} */
    let block = null

    for (const token of tokens) {
        if (!isBlock(token)) {
            if (!block) {
                block = { type: 'paragraph' }
                append(open.at(-1) ?? root, block)
            }

            appendInline(block, token)
            continue
        }

        /** @type {BlockPath} */
        const path = JSON.parse(token.formatting.b ?? '[["paragraph",0,0]]')
        const [leafType, leafAttributes, leafFlag] = path.at(-1) ?? ['paragraph', 0, CONTINUES]
        let depth = 0
        let parent = root

        for (const [type, attributes, flag] of path.slice(0, -1)) {
            const existing = open[depth]

            if (flag === CONTINUES && existing?.type === type) {
                parent = existing
            } else {
                const node = createNode(type, attributes)

                append(parent, node)
                open = [...open.slice(0, depth), node]
                parent = node
            }

            depth++
        }

        open = open.slice(0, depth)

        const node = createNode(leafType, leafAttributes)

        append(parent, node)
        block = leafFlag === LEAF ? null : node
    }

    root.content ??= [{ type: 'paragraph' }]

    return root
}

/**
 * @param {RichNode} block
 * @param {Token} token
 */
function appendInline(block, token) {
    const marks = Object.entries(token.formatting)
        .filter(([key]) => key.startsWith('m:'))
        .map(([key, value]) => createNode(key.slice(2), value === '1' ? null : JSON.parse(value)))

    /** @type {RichNode} */
    const node = typeof token.value === 'string' ? { type: 'text' } : JSON.parse(token.value.n ?? '{"type":"text"}')

    if (marks.length) node.marks = marks

    if (node.type === 'text') {
        const previous = block.content?.at(-1)

        if (previous?.type === 'text' && canonical(previous.marks ?? []) === canonical(marks)) {
            previous.text += String(token.value)

            return
        }

        node.text = String(token.value)
    }

    append(block, node)
}
