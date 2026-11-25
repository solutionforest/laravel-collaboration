import assert from 'node:assert/strict'
import { test } from 'node:test'
import { Document } from '../../resources/js/crdt/document.js'
import { toDocument, toTokens } from '../../resources/js/crdt/rich-text.js'
import { paragraph, simulate } from './simulation.js'

/**
 * Two replicas of a document with one field, holding `value`.
 *
 * @param {FieldKind} kind
 * @param {any} value
 * @param {string[]} texts
 * @returns {[Document, Document]}
 */
const pair = (kind, value, texts = []) => {
    const base = new Document({ fields: { field: { k: kind, r: [], m: {}, t: texts } } })
    const seed = base.changes('field', value, 0)
    const replicas = /** @type {[Document, Document]} */ ([1, 2].map(() => {
        const replica = new Document({ fields: { field: { k: kind, r: [], m: {}, t: texts } } })

        replica.apply(seed)

        return replica
    }))

    return replicas
}

/**
 * Both edit at once, then each receives the other's edit.
 *
 * @param {[Document, Document]} replicas
 * @param {{value: any, caret?: any}} aliceEdit
 * @param {{value: any, caret?: any}} bobEdit
 */
const concurrently = ([alice, bob], aliceEdit, bobEdit) => {
    const fromAlice = alice.changes('field', aliceEdit.value, 1, aliceEdit.caret)
    const fromBob = bob.changes('field', bobEdit.value, 2, bobEdit.caret)

    alice.apply([...fromAlice, ...fromBob])
    bob.apply([...fromBob, ...fromAlice])
    assert.deepEqual(alice.value('field'), bob.value('field'))

    return alice.value('field')
}

test('2,000 random sessions converge on every replica', () => {
    for (let seed = 1; seed <= 2000; seed++) {
        const { server, replicas } = simulate(seed * 7919)

        for (const { document, client } of replicas) {
            for (const field of ['title', 'body', 'status', 'items']) {
                assert.deepEqual(document.value(field), server.value(field), `seed ${seed}, editor ${client}, ${field}`)
            }
        }
    }
})

test('words typed at different places are both kept', () => {
    const result = concurrently(pair('text', 'the fox'), { value: 'the quick fox' }, { value: 'the fox jumps' })

    assert.equal(result, 'the quick fox jumps')
})

test('words typed at the same place do not interleave', () => {
    const result = concurrently(pair('text', ''), { value: 'hello' }, { value: 'world' })

    assert.ok(['helloworld', 'worldhello'].includes(result))
})

test('a caret says which of two equal letters is new', () => {
    const [alice] = pair('text', 'aa')
    const [operation] = alice.changes('field', 'aaa', 1, 1)

    assert.equal(operation.after, null)
})

test('emoji and CJK count as single items', () => {
    const result = concurrently(pair('text', '協作🙂'), { value: '即時協作🙂' }, { value: '協作🙂👍' })

    assert.equal(result, '即時協作🙂👍')
})

test('pressing Enter does not steal what someone types in the next paragraph', () => {
    const replicas = pair('rich', { content: [paragraph('A'), paragraph('B')] })
    const result = concurrently(
        replicas,
        // [¶ A ¶ | ¶ B]
        { value: { content: [paragraph('A'), paragraph(''), paragraph('B')] }, caret: 3 },
        { value: { content: [paragraph('A'), paragraph('>B')] }, caret: 4 },
    )

    assert.deepEqual(result.content, [paragraph('A'), paragraph(''), paragraph('>B')])
})

test('bold by one person and typing by another both survive', () => {
    const replicas = pair('rich', { content: [paragraph('hello world')] })
    const bold = { content: [{ type: 'paragraph', content: [{ type: 'text', text: 'hello', marks: [{ type: 'bold' }] }, { type: 'text', text: ' world' }] }] }
    const result = concurrently(replicas, { value: bold }, { value: { content: [paragraph('hello world!')] } })

    assert.equal(result.content[0].content[0].marks[0].type, 'bold')
    assert.equal(result.content[0].content[1].text, ' world!')
})

test('changing a paragraph into a heading keeps concurrent typing', () => {
    const result = concurrently(
        pair('rich', { content: [paragraph('Title')] }),
        { value: { content: [{ ...paragraph('Title'), type: 'heading', attrs: { level: 2 } }] } },
        { value: { content: [paragraph('Title two')] } },
    )

    assert.deepEqual(result.content[0], { type: 'heading', attrs: { level: 2 }, content: [{ type: 'text', text: 'Title two' }] })
})

test('nested structures survive a round trip', () => {
    /** @param {string} text */
    const cell = (text) => ({ type: 'tableCell', content: [paragraph(text)] })
    const document = {
        type: 'doc',
        content: [
            { type: 'table', content: [{ type: 'tableRow', content: [cell('1'), cell('2')] }, { type: 'tableRow', content: [cell('3'), cell('4')] }] },
            { type: 'bulletList', content: [{ type: 'listItem', content: [paragraph('a'), { type: 'bulletList', content: [{ type: 'listItem', content: [paragraph('b')] }] }] }] },
            { type: 'details', content: [{ type: 'detailsSummary', content: [{ type: 'text', text: 's' }] }, { type: 'detailsContent', content: [paragraph('c')] }] },
            { type: 'horizontalRule' },
        ],
    }

    assert.deepEqual(toDocument(toTokens(document)), document)
})

test('half an emoji never reaches the wire', () => {
    const [alice] = pair('text', 'Hi ')
    const [operation] = alice.changes('field', 'Hi \ud83d', 1)

    assert.equal(operation.v, '\ufffd')
    assert.equal(toTokens({ content: [paragraph('\udc4d')] })[1].value, '\ufffd')
})

test('unsafe attributes never enter the shared document', () => {
    for (const href of ['javascript:alert(1)', 'JAVA\tSCRIPT:alert(1)', ' javascript:alert(1)', 'data:text/html,x', 'java&colon;:x']) {
        const [, token] = toTokens({ content: [{ type: 'paragraph', content: [{ type: 'text', text: 'x', marks: [{ type: 'link', attrs: { href, onclick: 'x' } }] }] }] })

        assert.deepEqual(token.formatting, { 'm:link': '1' }, href)
    }
})

/** @type {(number: number) => string} */
const itemKey = (number) => `00000000-0000-4000-8000-${String(number).padStart(12, '0')}`

test('two people adding items at once both keep them', () => {
    const base = { [itemKey(1)]: { title: 'One' } }
    const result = concurrently(
        pair('items', base),
        { value: { ...base, [itemKey(2)]: { title: 'Alice' } } },
        { value: { ...base, [itemKey(3)]: { title: 'Bob' } } },
    )

    assert.deepEqual(Object.values(result).map((item) => item.title).sort(), ['Alice', 'Bob', 'One'])
})

test('edits to different sub-fields of one item merge', () => {
    const base = { [itemKey(1)]: { title: 'One', done: false } }
    const result = concurrently(
        pair('items', base),
        { value: { [itemKey(1)]: { title: 'Renamed', done: false } } },
        { value: { [itemKey(1)]: { title: 'One', done: true } } },
    )

    assert.deepEqual(result, { [itemKey(1)]: { done: true, title: 'Renamed' } })
})

test('moving an item keeps what someone typed in it', () => {
    const base = { [itemKey(1)]: { title: 'One' }, [itemKey(2)]: { title: 'Two' } }
    const result = concurrently(
        pair('items', base),
        { value: { [itemKey(2)]: { title: 'Two' }, [itemKey(1)]: { title: 'One' } } },
        { value: { [itemKey(1)]: { title: 'One!' }, [itemKey(2)]: { title: 'Two' } } },
    )

    assert.deepEqual(result, { [itemKey(2)]: { title: 'Two' }, [itemKey(1)]: { title: 'One!' } })
})

test('the same items loaded again under new keys change nothing', () => {
    const [alice] = pair('items', { [itemKey(1)]: { title: 'One' } })

    assert.deepEqual(alice.changes('field', { [itemKey(9)]: { title: 'One' } }, 1), [])
})

test('two people typing in one text sub-field of one item both keep their typing', () => {
    const base = { [itemKey(1)]: { title: 'the fox', done: false } }
    const result = concurrently(
        pair('items', base, ['title']),
        { value: { [itemKey(1)]: { title: 'the quick fox', done: false } }, caret: { key: itemKey(1), path: 'title', index: 10 } },
        { value: { [itemKey(1)]: { title: 'the fox jumps', done: true } } },
    )

    assert.deepEqual(result, { [itemKey(1)]: { title: 'the quick fox jumps', done: true } })
})

test('a text sub-field typed in by the server and a browser alike', () => {
    const [alice] = pair('items', { [itemKey(1)]: { title: 'One' } }, ['title'])
    const operations = alice.changes('field', { [itemKey(1)]: { title: 'One!' } }, 1)

    assert.deepEqual(operations.map(({ o, i, p, v }) => [o, i, p, v]), [['ins', itemKey(1), 'title', '!']])
    assert.deepEqual(alice.changes('field', { [itemKey(1)]: { title: '' } }, 1).map(({ o, p }) => [o, p]), [['del', 'title']])
})
