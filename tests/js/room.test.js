import assert from 'node:assert/strict'
import { afterEach, test } from 'node:test'
import { JSDOM } from 'jsdom'
import { FakeServer } from './fake-server.js'

const dom = new JSDOM('<!doctype html><body></body>', { pretendToBeVisual: true })

Object.assign(globalThis, {
    window: dom.window,
    document: dom.window.document,
    HTMLInputElement: dom.window.HTMLInputElement,
    HTMLTextAreaElement: dom.window.HTMLTextAreaElement,
    HTMLSelectElement: dom.window.HTMLSelectElement,
    DOMRect: dom.window.DOMRect,
    requestAnimationFrame: (callback) => setTimeout(callback, 0),
    cancelAnimationFrame: (id) => clearTimeout(id),
    getComputedStyle: dom.window.getComputedStyle,
    AbortController: dom.window.AbortController,
})

const { Room } = await import('../../resources/js/core/room.js')
const { bindTextField } = await import('../../resources/js/bindings/text-field.js')
const { bindValueField } = await import('../../resources/js/bindings/value-field.js')

/** @type {Array<InstanceType<typeof Room>>} */
let rooms = []

afterEach(() => {
    rooms.forEach((room) => room.destroy())
    rooms = []
    document.body.replaceChildren()
})

const wait = (milliseconds) => new Promise((resolve) => setTimeout(resolve, milliseconds))

/** @param {() => boolean} condition */
async function until(condition, timeout = 3000) {
    const started = Date.now()

    while (!condition()) {
        if (Date.now() - started > timeout) throw new Error('Timed out')

        await wait(10)
    }
}

function server() {
    const fake = new FakeServer({ clock: 0, fields: { title: { k: 'text', r: [] }, status: { k: 'value', v: 'draft', at: [0, 0] } } })

    fake.seed('title', 'Hello')

    return fake
}

/** @param {FakeServer} fake @param {number} client @param {string} name */
async function join(fake, client, name) {
    const room = new Room(fake.config(client, name), { transport: fake.transport(name) })

    rooms.push(room)
    await until(() => room.isReady && room.value('title') === 'Hello')

    return room
}

test('two tabs converge on what both typed', async () => {
    const fake = server()
    const alice = await join(fake, 1, 'Alice')
    const bob = await join(fake, 2, 'Bob')

    alice.change('title', 'Hello world', 11)
    bob.change('title', 'Oh, Hello', 4)

    await until(() => alice.value('title') === 'Oh, Hello world' && bob.value('title') === 'Oh, Hello world')
})

test('announces others\' edits, and who is here', async () => {
    const fake = server()
    const alice = await join(fake, 1, 'Alice')
    const bob = await join(fake, 2, 'Bob')
    const changes = []

    alice.on('change', (change) => changes.push(change))
    bob.change('status', 'published')

    await until(() => changes.some((change) => change.field === 'status' && change.value === 'published'))
    await until(() => alice.people.some((person) => person.name === 'Bob'))
})

test('keeps the caret where it was while others type before it', async () => {
    const fake = server()
    const alice = await join(fake, 1, 'Alice')
    const bob = await join(fake, 2, 'Bob')
    const input = document.body.appendChild(document.createElement('input'))

    bindTextField(alice, 'title', input)
    assert.equal(input.value, 'Hello')

    input.focus()
    input.setSelectionRange(5, 5)
    bob.change('title', 'Oh, Hello', 4)

    await until(() => input.value === 'Oh, Hello')
    assert.equal(input.selectionStart, 9)
    assert.equal(input.selectionEnd, 9)
})

test('shares what is typed into a bound input', async () => {
    const fake = server()
    const alice = await join(fake, 1, 'Alice')
    const bob = await join(fake, 2, 'Bob')
    const input = document.body.appendChild(document.createElement('input'))

    bindTextField(alice, 'title', input)
    input.value = 'Hello there'
    input.setSelectionRange(11, 11)
    input.dispatchEvent(new window.Event('input'))

    await until(() => bob.value('title') === 'Hello there')
})

test('tells the framework about others\' edits to a select', async () => {
    const fake = server()
    const alice = await join(fake, 1, 'Alice')
    const bob = await join(fake, 2, 'Bob')
    const select = document.body.appendChild(document.createElement('select'))
    const seen = []

    select.innerHTML = '<option value="draft">Draft</option><option value="published">Published</option>'
    bindValueField(alice, 'status', select, { onRemoteChange: (value) => seen.push(value) })
    bob.change('status', 'published')

    await until(() => select.value === 'published')
    assert.deepEqual(seen, ['published'])
})

test('sends unsent edits with a save', async () => {
    const fake = server()
    const alice = await join(fake, 1, 'Alice')
    const input = document.body.appendChild(document.createElement('input'))

    bindTextField(alice, 'title', input)
    fake.failWith = { status: 503, code: 'failed' }
    alice.change('title', 'Hello!', 6)

    const payload = JSON.parse(alice.payload())

    assert.equal(payload.token, 'token-1')
    assert.deepEqual(payload.fields, ['title'])
    assert.equal(payload.batches.length, 1)
})

test('keeps edits while it waits for a new token, then carries on', async () => {
    const fake = server()
    const alice = await join(fake, 1, 'Alice')
    const bob = await join(fake, 2, 'Bob')
    let expired = false

    alice.on('expired', () => (expired = true))
    fake.failWith = { status: 410, code: 'expired' }
    alice.change('title', 'Hello again', 11)

    await until(() => expired)
    assert.equal(alice.status, 'expired')

    fake.failWith = null
    alice.renew({ ...alice.config, token: 'token-1-renewed' })

    await until(() => bob.value('title') === 'Hello again')
})

test('stops for good when access is gone', async () => {
    const fake = server()
    const alice = await join(fake, 1, 'Alice')

    fake.failWith = { status: 403, code: 'forbidden' }
    await until(() => alice.status === 'denied')

    alice.change('title', 'Ignored', 7)
    assert.equal(alice.value('title'), 'Hello')
})

test('shows where others\' carets are', async () => {
    const fake = server()
    const alice = await join(fake, 1, 'Alice')
    const bob = await join(fake, 2, 'Bob')
    const input = document.body.appendChild(document.createElement('input'))

    bindTextField(bob, 'title', input)
    input.focus()
    input.setSelectionRange(2, 4)
    input.dispatchEvent(new window.Event('select'))

    await until(() => alice.carets('title').length === 1)
    assert.deepEqual(alice.carets('title').map(({ anchor, head, person }) => [person.name, anchor, head]), [['Bob', 2, 4]])
    assert.deepEqual(alice.peopleIn('title').map((person) => person.name), ['Bob'])
})

/** @param {HTMLElement} element @param {string} key */
const press = (element, key, options = {}) => element.dispatchEvent(new window.KeyboardEvent('keydown', { key, ctrlKey: true, bubbles: true, cancelable: true, ...options }))

test('Ctrl+Z undoes your own typing, never a colleague\'s', async () => {
    const fake = server()
    const alice = await join(fake, 1, 'Alice')
    const bob = await join(fake, 2, 'Bob')
    const input = document.body.appendChild(document.createElement('input'))
    const seen = []

    bindTextField(alice, 'title', input, { onValueChange: (value) => seen.push(value) })
    input.focus()
    input.value = 'Hello world'
    input.setSelectionRange(11, 11)
    input.dispatchEvent(new window.Event('input'))

    await until(() => bob.value('title') === 'Hello world')
    bob.change('title', 'Oh, Hello world', 4)
    await until(() => input.value === 'Oh, Hello world')

    press(input, 'z')
    assert.equal(input.value, 'Oh, Hello')
    assert.equal(seen.at(-1), 'Oh, Hello')
    await until(() => bob.value('title') === 'Oh, Hello')

    press(input, 'z', { shiftKey: true })
    assert.equal(input.value, 'Oh, Hello world')
})

test('a group of checkboxes shares the list of ticked values', async () => {
    const fake = new FakeServer({ clock: 0, fields: { title: { k: 'text', r: [] }, channels: { k: 'value', v: ['web'], at: [0, 0] } } })

    fake.seed('title', 'Hello')

    const alice = await join(fake, 1, 'Alice')
    const bob = await join(fake, 2, 'Bob')
    const boxes = ['web', 'email', 'sms'].map((value) => Object.assign(document.body.appendChild(document.createElement('input')), { type: 'checkbox', value }))

    bindValueField(alice, 'channels', boxes)
    assert.deepEqual(boxes.map((box) => box.checked), [true, false, false])

    boxes[2].checked = true
    boxes[2].dispatchEvent(new window.Event('change'))
    await until(() => JSON.stringify(bob.value('channels')) === '["web","sms"]')

    bob.change('channels', ['email'])
    await until(() => boxes.map((box) => box.checked).join() === 'false,true,false')
})

test('a locked field is read-only while somebody else is in it', async () => {
    const fake = server()

    fake.locks = ['status']

    const alice = await join(fake, 1, 'Alice')
    const bob = await join(fake, 2, 'Bob')
    const select = document.body.appendChild(document.createElement('select'))

    select.innerHTML = '<option value="draft">Draft</option><option value="published">Published</option>'
    bindValueField(alice, 'status', select)
    assert.equal(select.inert, false)

    bob.focus('status')
    await until(() => select.inert === true)
    assert.equal(select.title, 'Bob is editing this field')

    bob.focus(null)
    await until(() => select.inert === false)
})

test('a field the room does not have is reported once, as a likely typo', async () => {
    const fake = server()
    const alice = await join(fake, 1, 'Alice')
    const warnings = []
    const warn = console.warn

    console.warn = (message) => warnings.push(message)
    alice.bind('titel', {})
    alice.bind('titel', {})
    console.warn = warn

    assert.equal(warnings.length, 1)
    assert.match(warnings[0], /"titel" is not a field of this room/)
})

test('following a colleague brings the field they move to into view', async () => {
    const fake = server()
    const alice = await join(fake, 1, 'Alice')
    const bob = await join(fake, 2, 'Bob')
    const revealed = []

    alice.bind('title', { reveal: () => revealed.push('title') })
    await until(() => alice.people.length === 1)

    alice.follow(2)
    bob.focus('title')

    await until(() => revealed.length === 1)
    assert.equal(alice.following, 2)

    alice.follow(2)
    assert.equal(alice.following, null)
})

test('a repeater\'s item inputs share their typing, and a new item reaches everybody', async () => {
    const { bindItems } = await import('../../resources/js/bindings/items.js')
    const { listOps, toList } = await import('../../resources/js/core/items.js')
    const fake = new FakeServer({ clock: 0, fields: { title: { k: 'text', r: [] }, lines: { k: 'items', t: ['title'], r: [], m: {} } } })

    fake.seed('title', 'Hello')
    fake.seed('lines', { a: { title: 'One' } })

    const alice = await join(fake, 1, 'Alice')
    const bob = await join(fake, 2, 'Bob')
    const lists = []
    const items = bindItems(alice, 'lines', { onValueChange: (list) => lists.push(list) })
    const input = document.body.appendChild(document.createElement('input'))

    await until(() => toList(alice.value('lines')).length === 1)
    items.attach('a', 'title', input)
    assert.equal(input.value, 'One')

    input.value = 'One!'
    input.setSelectionRange(4, 4)
    input.dispatchEvent(new window.Event('input'))
    await until(() => bob.value('lines')?.a?.title === 'One!')

    items.change(listOps.add(toList(alice.value('lines')), { title: 'Two' }))
    await until(() => toList(bob.value('lines')).length === 2)
    assert.equal(toList(bob.value('lines'))[1].title, 'Two')
    assert.ok(lists.length > 0)
})
