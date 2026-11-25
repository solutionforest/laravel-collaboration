import { Document } from '../../resources/js/crdt/document.js'

/**
 * Editors changing one record concurrently, through a server that orders
 * operations by arrival, as the PHP endpoint does.
 *
 * @param {number} seed
 * @param {{editors?: number, steps?: number}} options
 */
export function simulate(seed, { editors = 4, steps = 90 } = {}) {
    let state = seed
    const random = () => ((state = (state * 16807) % 2147483647) - 1) / 2147483646
    /** @type {<T>(items: T[]) => T} */
    const pick = (items) => /** @type {any} */ (items[Math.floor(random() * items.length)])

    /** @type {DocumentState} */
    const initial = { clock: 0, fields: { title: { k: 'text', r: [] }, body: { k: 'rich', r: [] }, status: { k: 'value', v: null, at: [0, 0] }, items: { k: 'items', r: [], m: {}, t: ['title'] } } }
    const server = new Document(initial)
    /** @type {Array<{client: number, operations: Operation[]}>} */
    const log = []
    /** @type {Array<{client: number, operations: Operation[]}>} */
    const batches = []

    /**
     * @param {number} client
     * @param {Operation[]} operations
     */
    const push = (client, operations) => {
        server.apply(operations)
        log.push({ client, operations })
    }

    for (const [field, value] of /** @type {Array<[string, any]>} */ ([
        ['title', 'Shared title'],
        ['status', 'draft'],
        ['body', { type: 'doc', content: [paragraph('First paragraph'), paragraph('Second paragraph')] }],
        ['items', { [key(1)]: { title: 'One', done: false }, [key(2)]: { title: 'Two', meta: { tag: 'a' } } }],
    ])) {
        const operations = server.changes(field, value, 0)

        push(0, operations)
        batches.push({ client: 0, operations })
    }

    /** @typedef {{client: number, document: Document, cursor: number, queue: Operation[][]}} Replica */

    /** @type {Replica[]} */
    const replicas = Array.from({ length: editors }, (_, index) => ({
        client: index + 1,
        document: new Document(initial),
        cursor: 0,
        queue: [],
    }))

    /** @param {Replica} replica */
    const pull = (replica) => {
        for (; replica.cursor < log.length; replica.cursor++) {
            const entry = log[replica.cursor]

            if (entry.client !== replica.client) replica.document.apply(entry.operations)
        }
    }

    /** @param {Replica} replica */
    const flush = (replica) => {
        for (const operations of replica.queue.splice(0)) {
            push(replica.client, operations)
            batches.push({ client: replica.client, operations })
        }
    }

    replicas.forEach(pull)

    for (let step = 0; step < steps; step++) {
        const replica = pick(replicas)
        const action = random()

        if (action < 0.17) {
            pull(replica)
        } else if (action < 0.32) {
            flush(replica)
        } else {
            const field = pick(['title', 'body', 'body', 'status', 'items', 'items'])
            const value = mutate(field, replica.document.value(field), random, pick)
            const operations = replica.document.changes(field, value, replica.client)

            if (!operations.length) continue

            replica.document.apply(operations)
            replica.queue.push(operations)
        }
    }

    replicas.forEach(flush)
    replicas.forEach(pull)

    return { server, replicas, batches }
}

/**
 * @param {string} [text]
 * @returns {RichNode}
 */
export function paragraph(text) {
    return { type: 'paragraph', ...(text ? { content: [{ type: 'text', text }] } : {}) }
}

/** @type {(number: number) => string} */
const key = (number) => `00000000-0000-4000-8000-${String(number).padStart(12, '0')}`
let nextKey = 100

/**
 * @param {Record<string, any>} items
 * @param {() => number} random
 * @param {<T>(items: T[]) => T} pick
 */
function mutateItems(items, random, pick) {
    const entries = Object.entries(structuredClone(items))
    const action = Math.floor(random() * 5)
    const at = Math.floor(random() * (entries.length + 1))

    if (action === 0 || !entries.length) entries.splice(at, 0, [key(nextKey++ + Math.floor(random() * 1e6)), { title: pick(['new', 'x', '協作']), done: random() < 0.5 }])
    else if (action === 1) entries.splice(Math.min(at, entries.length - 1), 1)
    else if (action === 2) entries.splice(Math.floor(random() * entries.length), 0, ...entries.splice(Math.min(at, entries.length - 1), 1))
    else {
        const [, item] = pick(entries)

        if (action === 3 && random() < 0.6) {
            // Typing in a text sub-field.
            const characters = Array.from(item.title ?? '')

            characters.splice(Math.floor(random() * (characters.length + 1)), random() < 0.3 ? 1 : 0, ...(random() < 0.8 ? Array.from(pick(['a', 'bc', ' ', '協作', '🙂'])) : []))
            item.title = characters.join('') || null
        } else if (action === 3) item.title = pick(['a', 'b', 'c', null, '🙂', 7])
        else item.done = !item.done
    }

    return Object.fromEntries(entries)
}

/**
 * A random edit, as a person would make it.
 *
 * @param {string} field
 * @param {any} value
 * @param {() => number} random
 * @param {<T>(items: T[]) => T} pick
 * @returns {any}
 */
function mutate(field, value, random, pick) {
    if (field === 'items') return mutateItems(value, random, pick)

    const piece = () => pick(['a', 'bc', ' ', '協作', '🙂', 'hello', 'é', ' ', '/', '"'])

    if (field === 'status') return pick(['draft', 'review', 'published', null, ['a', 'b'], true, 3])

    if (field === 'title') {
        const items = Array.from(value)
        const at = Math.floor(random() * (items.length + 1))

        items.splice(at, random() < 0.4 ? Math.ceil(random() * 4) : 0, ...(random() < 0.8 ? Array.from(piece()) : []))

        return items.join('')
    }

    const document = structuredClone(value)
    const blocks = document.content
    const index = Math.floor(random() * blocks.length)
    const block = blocks[index]
    const action = Math.floor(random() * 10)
    const isText = ['paragraph', 'heading'].includes(block.type)

    if (action <= 2 && isText) {
        block.content ??= []

        const last = block.content.at(-1)

        if (last?.type === 'text' && random() < 0.6) {
            const items = Array.from(last.text)

            items.splice(Math.floor(random() * items.length), random() < 0.3 ? 1 : 0, piece())
            last.text = items.join('')
        } else {
            block.content.push({ type: 'text', text: piece(), ...(random() < 0.5 ? { marks: [{ type: pick(['bold', 'italic']) }] } : {}) })
        }
    } else if (action === 3 && isText) {
        block.type = 'heading'
        block.attrs = { level: pick([2, 3]) }
    } else if (action === 4) {
        blocks.splice(index + 1, 0, paragraph(piece()))
    } else if (action === 5 && blocks.length > 1) {
        blocks.splice(index, 1)
    } else if (action === 6 && block.type === 'paragraph') {
        blocks.splice(index, 1, { type: 'bulletList', content: [{ type: 'listItem', content: [block] }] })
    } else if (action === 7) {
        blocks.splice(index + 1, 0, { type: 'horizontalRule' })
    } else if (action === 8 && isText) {
        for (const node of block.content ?? []) {
            node.marks = random() < 0.5 ? [{ type: 'link', attrs: { href: 'https://example.com/a?b=1&c=é', target: '_blank' } }] : []
        }
    } else if (action === 9 && isText) {
        ;(block.content ??= []).push({ type: 'image', attrs: { src: 'https://example.com/i.png', alt: piece() } })
    }

    return document
}
