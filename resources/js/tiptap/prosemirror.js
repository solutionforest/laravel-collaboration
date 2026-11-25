import { characters } from '../crdt/id.js'

/**
 * Helpers for ProseMirror and TipTap editors: map between ProseMirror positions and the positions the
 * shared draft uses (one per character, block or embed), and turn the
 * editor's document into another touching as little as possible.
 *
 * Nothing is bundled: they work on the editor's own ProseMirror objects.
 */

/** @typedef {import('prosemirror-model').Node} ProseMirrorNode */
/** @typedef {import('prosemirror-model').Schema} ProseMirrorSchema */
/** @typedef {import('prosemirror-state').EditorState} EditorState */
/** @typedef {import('prosemirror-state').Transaction} Transaction */

/** @type {WeakMap<ProseMirrorNode, number[]>} */
const cache = new WeakMap()

/**
 * The ProseMirror position after each draft position, for one document.
 *
 * @param {ProseMirrorNode} doc
 * @returns {number[]}
 */
export function ends(doc) {
    const known = cache.get(doc)

    if (known) return known

    /** @type {number[]} */
    const result = []

    doc.descendants((node, position) => {
        if (!node.isTextblock) {
            if (node.childCount) return true

            result.push(position + node.nodeSize)

            return false
        }

        result.push(position + 1)

        node.forEach((child, offset) => {
            let end = position + 1 + offset

            if (child.isText) {
                for (const character of characters(child.text ?? '')) result.push((end += character.length))
            } else {
                result.push(end + child.nodeSize)
            }
        })

        return false
    })

    cache.set(doc, result)

    return result
}

/**
 * The draft position of a ProseMirror position: how many draft positions end at or before it.
 *
 * @param {ProseMirrorNode} doc
 * @param {number} position
 */
export function toDraft(doc, position) {
    const list = ends(doc)
    let low = 0
    let high = list.length

    while (low < high) {
        const middle = (low + high) >> 1

        if (/** @type {number} */ (list[middle]) <= position) low = middle + 1
        else high = middle
    }

    return low
}

/**
 * The ProseMirror position of a draft position.
 *
 * @param {ProseMirrorNode} doc
 * @param {number} index
 */
export function toEditor(doc, index) {
    const list = ends(doc)

    return Math.min(index > 0 ? (list[Math.min(index, list.length) - 1] ?? 1) : (list[0] ?? 1), doc.content.size)
}

/**
 * @param {ProseMirrorNode} doc
 * @param {number} position
 */
function splitsPair(doc, position) {
    if (position < 1 || position >= doc.content.size) return false

    const after = doc.textBetween(position, position + 1)
    const before = doc.textBetween(position - 1, position)

    return /^[\uD800-\uDBFF]$/.test(before) && /^[\uDC00-\uDFFF]$/.test(after)
}

/**
 * A transaction turning the editor's document into `next`, touching as little as it can,
 * so the caret, marks and undo history outside the change survive.
 *
 * @param {EditorState} state
 * @param {ProseMirrorNode} next
 * @returns {Transaction}
 */
export function replacement(state, next) {
    let start = state.doc.content.findDiffStart(next.content)
    const end = state.doc.content.findDiffEnd(next.content)

    if (start === null || !end) return state.tr

    let { a: oldEnd, b: newEnd } = end
    const overlap = start - Math.min(oldEnd, newEnd)

    if (overlap > 0) {
        oldEnd += overlap
        newEnd += overlap
    }

    // The diff counts UTF-16 units. Cutting an emoji in two would leave a caret inside it.
    if (splitsPair(state.doc, start)) start--

    if (splitsPair(state.doc, oldEnd)) {
        oldEnd++
        newEnd++
    }

    let transaction = state.tr

    try {
        transaction.replace(start, oldEnd, next.slice(start, newEnd))
    } catch {
        transaction = state.tr
    }

    return transaction.doc.eq(next) ? transaction : state.tr.replaceWith(0, state.doc.content.size, next.content)
}

/**
 * The editor's node for a draft document. Two people can together make a
 * structure neither could alone, such as a list item with no paragraph: the
 * text is kept, and as much of the shape as the schema allows.
 *
 * @param {ProseMirrorSchema} schema
 * @param {RichNode} target
 * @returns {ProseMirrorNode}
 */
export function buildDocument(schema, target) {
    const document = schema.nodeFromJSON(target)

    try {
        document.check()

        return document
    } catch {
        const paragraph = /** @type {import('prosemirror-model').NodeType} */ (schema.nodes.paragraph)

        /**
         * @param {ProseMirrorNode} node
         * @returns {ProseMirrorNode[]}
         */
        const paragraphs = (node) =>
            node.isTextblock
                ? [paragraph.create(null, node.content)]
                : Array.from({ length: node.childCount }, (_, index) => paragraphs(node.child(index))).flat()

        /** @type {ProseMirrorNode[]} */
        const blocks = []

        document.forEach((block) => {
            try {
                block.check()
                blocks.push(block)
            } catch {
                blocks.push(...paragraphs(block))
            }
        })

        return schema.topNodeType.createAndFill(null, blocks) ?? schema.topNodeType.createAndFill() ?? schema.topNodeType.create()
    }
}
