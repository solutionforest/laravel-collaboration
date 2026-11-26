import assert from 'node:assert/strict'
import { test } from 'node:test'
import { JSDOM } from 'jsdom'

const dom = new JSDOM('<!doctype html><head></head><body></body>')

Object.assign(globalThis, { window: dom.window, document: dom.window.document })

const { FormMemory } = await import('../../resources/js/core/form-memory.js')
const { listOps, newKey, toKeyed, toList } = await import('../../resources/js/core/items.js')
const { RecordPresence } = await import('../../resources/js/core/record-presence.js')
const { Comments } = await import('../../resources/js/core/comments.js')
const { addStyles, withoutStyles } = await import('../../resources/js/ui/styles.js')

/** A room that answers `call()` from a table, for what does not need a server. */
const roomAnswering = (answers, live = []) => ({
    config: { endpoints: Object.fromEntries(Object.keys(answers).map((name) => [name, `/${name}`])) },
    isLiveField: (field) => live.includes(field),
    calls: [],
    async call(name, payload) {
        this.calls.push([name, payload])

        return typeof answers[name] === 'function' ? answers[name](payload) : answers[name]
    },
})

test('a form takes saved values into fields its person left alone, and keeps the rest as conflicts', async () => {
    const room = roomAnswering({ values: { values: { category: 'sport', note: 'theirs', title: 'live' } } }, ['title'])
    const memory = new FormMemory(room, { title: 'Hi', category: 'news', note: 'base' })

    const { take, conflicts } = await memory.pull({ title: 'Hi there', category: 'news', note: 'mine' }, 'Bob')

    assert.deepEqual(room.calls[0], ['values', { fields: ['category', 'note'] }])
    assert.deepEqual(take, { category: 'sport' })
    assert.deepEqual(conflicts, [{ field: 'note', mine: 'mine', theirs: 'theirs', name: 'Bob' }])

    // Keeping mine: the base becomes theirs, so the next save overwrites knowingly.
    memory.keepMine('note')
    assert.equal(JSON.parse(memory.payload()).note, 'theirs')
    assert.deepEqual(memory.conflicts, [])
})

test('taking their version gives the value to put in the form', async () => {
    const memory = new FormMemory(roomAnswering({ values: { values: { note: 'theirs' } } }), { note: 'base' })

    await memory.pull({ note: 'mine' }, 'Bob')

    assert.equal(memory.useTheirs('note'), 'theirs')
})

test('repeater items convert between the form list and the draft keys', () => {
    const list = [{ key: 'a', title: 'One' }, { key: 'b', title: 'Two' }]

    assert.deepEqual(toKeyed(list), { a: { title: 'One' }, b: { title: 'Two' } })
    assert.deepEqual(toList(toKeyed(list)), list)
    assert.deepEqual(listOps.move(list, 'b', 0).map((item) => item.key), ['b', 'a'])
    assert.deepEqual(listOps.remove(list, 'a').map((item) => item.key), ['b'])
    assert.match(listOps.add(list, { title: 'Three' }).at(-1).key, /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/)
    assert.notEqual(newKey(), newKey())
})

test('a list asks who is on each record, and stops when its token is refused', async () => {
    let answers = 0
    const transport = {
        async send() {
            answers++

            if (answers > 1) throw Object.assign(new Error('expired'), { status: 410 })

            return { people: { 7: [{ key: 'k', name: 'Alice' }] } }
        },
    }
    const presence = new RecordPresence({ url: '/presence', csrf: '', token: 't', interval: 5, people: {} }, { transport })
    const seen = []

    presence.on('people', (people) => seen.push(Object.keys(people)))

    await new Promise((resolve) => setTimeout(resolve, 60))

    assert.deepEqual(presence.peopleOn(7).map((person) => person.name), ['Alice'])
    assert.deepEqual(seen, [['7']])
    assert.equal(presence.stopped, true)
})

test('comments are fetched while something shows them, and posted through the room', async () => {
    const room = roomAnswering({
        comments: { comments: [], mentionable: ['Bob'], canWrite: true },
        'comments.store': ({ body, field }) => ({ comments: [{ id: 1, body, field, resolved: false }], mentionable: ['Bob'], canWrite: true }),
    })
    const comments = new Comments(room)
    const stop = comments.on('change', () => {})

    await comments.post('Look, @Bob', 'title')

    assert.deepEqual(comments.about('title').map((comment) => comment.body), ['Look, @Bob'])
    assert.deepEqual(comments.mentionable, ['Bob'])

    stop()
    assert.equal(comments.timer, undefined)
})

test('an app that styles everything itself gets no stylesheet', () => {
    withoutStyles()
    addStyles()

    assert.equal(document.querySelector('style[data-collaboration]'), null)
})
