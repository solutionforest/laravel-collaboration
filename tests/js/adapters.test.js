import assert from 'node:assert/strict'
import { test } from 'node:test'
import { JSDOM } from 'jsdom'

const dom = new JSDOM('<!doctype html><body><div id="app"></div></body>', { pretendToBeVisual: true, url: 'http://localhost/' })

// Inertia reads the navigation type when it loads; jsdom has no timeline.
dom.window.performance.getEntriesByType = () => []

Object.assign(globalThis, {
    window: dom.window,
    document: dom.window.document,
    HTMLElement: dom.window.HTMLElement,
    HTMLInputElement: dom.window.HTMLInputElement,
    HTMLTextAreaElement: dom.window.HTMLTextAreaElement,
    HTMLSelectElement: dom.window.HTMLSelectElement,
    Node: dom.window.Node,
    DOMRect: dom.window.DOMRect,
    AbortController: dom.window.AbortController,
    getComputedStyle: dom.window.getComputedStyle,
    requestAnimationFrame: (callback) => setTimeout(callback, 0),
    cancelAnimationFrame: (id) => clearTimeout(id),
    IS_REACT_ACT_ENVIRONMENT: true,
    // Nothing answers: the room stays offline, which is all these tests need.
    fetch: () => new Promise(() => {}),
})

const { avatarsOf, peopleIn, valueOf } = await import('../../resources/js/ui/people.js')
const React = await import('react')
const { act } = React
const { createRoot } = await import('react-dom/client')
const { useCollaborativeForm } = await import('../../resources/js/react/index.js')
const { router } = await import('@inertiajs/react')

const config = {
    id: 'room', url: '/sync', csrf: 'csrf', token: 'token', client: 7, mode: 'editing',
    fields: { title: 'text', email: 'text', status: 'value', channels: 'value' }, labels: {}, draft: 'draft', input: '_collaboration',
    interval: 1000, channel: null, avatarLimit: 5, reportErrors: false, user: 'me', strings: {},
}

const person = (client, field = null, idle = false) => ({ client, key: `k${client}`, name: `P${client}`, initials: 'P', avatar: null, color: '#000', mode: 'editing', field, cursor: null, idle, self: false })

test('helpers read inputs and people the same way everywhere', () => {
    assert.equal(valueOf({ target: { type: 'checkbox', checked: true, value: 'on' } }), true)
    assert.equal(valueOf({ target: { type: 'text', value: 'Hi' } }), 'Hi')
    assert.deepEqual(valueOf({ target: { multiple: true, value: 'a', selectedOptions: [{ value: 'a' }, { value: 'b' }] } }), ['a', 'b'])
    assert.equal(valueOf('custom'), 'custom')

    assert.deepEqual(peopleIn([person(1, 'title'), person(2, 'title', true), person(3, 'body')], 'title').map((p) => p.client), [1])
    assert.deepEqual(avatarsOf([person(1), person(2), person(3)], 2).more, 1)
})

test('React keeps each input bound across renders, and keeps the app\'s own transform', async () => {
    /** @type {any} */
    let form

    function Edit() {
        form = useCollaborativeForm(config, { title: 'Hello', email: 'a@example.com', status: 'draft', channels: ['web'] })

        return React.createElement('div', null,
            React.createElement('input', { id: 'title', ...form.field('title') }),
            React.createElement('input', { id: 'draft', type: 'radio', ...form.field('status', { value: 'draft' }) }),
            React.createElement('input', { id: 'published', type: 'radio', ...form.field('status', { value: 'published' }) }),
            React.createElement('input', { id: 'email', type: 'email', ...form.field('email') }),
            ...['web', 'sms'].map((channel) => React.createElement('input', { key: channel, id: channel, type: 'checkbox', ...form.field('channels', { value: channel }) })),
        )
    }

    const root = createRoot(document.getElementById('app'))

    await act(async () => root.render(React.createElement(Edit)))

    const room = form.collaboration.room
    const binding = room.bindings.get('title')

    assert.ok(binding, 'the text input is bound once the room has joined')

    // Typing re-renders the form; the input must keep the same binding.
    for (const title of ['Hello!', 'Hello!!']) await act(async () => form.setData('title', title))

    assert.equal(room.bindings.get('title'), binding)

    // Radios: each says whether it is the chosen one.
    assert.equal(document.getElementById('draft').checked, true)
    assert.equal(document.getElementById('published').checked, false)

    // An email has no caret to keep: it is shared as a whole value, not bound as text.
    assert.equal(room.bindings.get('email')?.render, undefined)

    // A checkbox in a group adds its value to the list, or takes it out.
    assert.equal(document.getElementById('web').checked, true)
    await act(async () => document.getElementById('sms').click())
    assert.deepEqual(form.data.channels, ['web', 'sms'])
    await act(async () => document.getElementById('web').click())
    assert.deepEqual(form.data.channels, ['sms'])

    // A save runs the app's transform, then adds the edits the server has not seen.
    let sent = null

    router.put = (_url, data) => (sent = data)
    form.transform((data) => ({ ...data, title: data.title.toUpperCase() }))
    form.put('/posts/1')

    assert.equal(sent.title, 'HELLO!!')
    assert.equal(sent.status, 'draft')
    assert.equal(typeof sent._collaboration, 'string')

    await act(async () => root.unmount())
    assert.equal(room.destroyed, true)
})
