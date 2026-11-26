import { chromium } from 'playwright'

const base = process.env.APP_URL ?? 'http://127.0.0.1:8123'
const browser = await chromium.launch()
let failed = false

async function person(id, path) {
    const context = await browser.newContext({ viewport: { width: 1100, height: 900 } })
    const page = await context.newPage()

    page.on('pageerror', (error) => (failed = true, console.log(`[${id}] pageerror:`, error.message)))
    page.on('console', (message) => message.type() === 'error' && (failed = true, console.log(`[${id}] console:`, message.text().slice(0, 300))))
    await page.goto(`${base}/as/${id}?to=${encodeURIComponent(path)}`)

    return page
}

const people = { alice: await person(1, '/react/posts/1'), bob: await person(2, '/vue/posts/1'), chloe: await person(3, '/livewire/posts/1') }
const all = Object.entries(people)
const title = (page) => page.locator('input').first()

await people.alice.waitForTimeout(2500)

async function expectEverywhere(label, read, expected) {
    for (let attempt = 0; attempt < 40; attempt++) {
        const values = await Promise.all(all.map(([, page]) => read(page)))

        if (values.every((value) => value === expected)) return console.log(`ok   ${label}`)

        await people.alice.waitForTimeout(150)
    }

    failed = true
    console.log(`FAIL ${label}:`, JSON.stringify(await Promise.all(all.map(async ([name, page]) => [name, await read(page)]))))
}

// Each one types at the end of the title, from a different stack.
for (const [name, page] of all) {
    await title(page).click()
    await page.keyboard.press('End')
    await page.keyboard.type(` ${name}`)
}

// Typed at the same place at the same time, their words may land in any order, but the same order everywhere.
await expectEverywhere('text typed in React, Vue and Livewire merges', (page) => title(page).inputValue().then((value) => ['alice', 'bob', 'chloe'].every((name) => value.includes(name))), true)
await expectEverywhere('and reads the same everywhere', (page) => title(page).inputValue(), await title(people.alice).inputValue())

// Typing at human speed in React and Vue at the same moment loses no letter.
await title(people.alice).click()
await people.alice.keyboard.press('End')
await title(people.bob).click()
await people.bob.keyboard.press('Home')
await Promise.all([people.alice.keyboard.type(' this spring', { delay: 90 }), people.bob.keyboard.type('Now open: ', { delay: 90 })])
await expectEverywhere('typing at the same moment in React and Vue keeps every letter', (page) => title(page).inputValue().then((value) => value.startsWith('Now open: ') && value.endsWith(' this spring')), true)

await people.bob.locator('select').selectOption('review')
await people.bob.locator('select').blur()
await expectEverywhere('a select changed in Vue shows everywhere', (page) => page.locator('select').inputValue(), 'review')

await people.chloe.locator('input[type=checkbox]').first().check()
await expectEverywhere('a checkbox ticked in Livewire shows everywhere', (page) => page.locator('input[type=checkbox]').first().isChecked(), true)

const channels = (page) => page.locator('input[type=checkbox][value]:checked').evaluateAll((boxes) => boxes.map((box) => box.value).filter((value) => value !== 'on').join())

await people.bob.locator('input[type=checkbox][value=email]').check()
await expectEverywhere('a checkbox group ticked in Vue shows everywhere', channels, 'web,email')

await people.chloe.locator('input[type=checkbox][value=sms]').check()
await expectEverywhere('and in Livewire', channels, 'web,email,sms')

await people.alice.locator('input[type=checkbox][value=web]').uncheck()
await expectEverywhere('and in React', channels, 'email,sms')

// The status is locked: while Alice is in it, nobody else can change it.
await people.alice.locator('select').focus()
await expectEverywhere('a locked field is read-only for the others', async (page) => page === people.alice ? true : page.locator('select').evaluate((select) => select.closest('[inert]') !== null), true)
await people.alice.locator('select').blur()
await expectEverywhere('and free again once she leaves it', (page) => page.locator('select').evaluate((select) => select.closest('[inert]') === null), true)

// Ctrl+Z undoes only your own typing: Bob's words go, Chloe's stay.
const summary = (page) => page.locator('textarea').inputValue()

await people.bob.locator('textarea').click()
await people.bob.keyboard.press('End')
await people.bob.keyboard.type(' Bob wrote this.')
await expectEverywhere('Bob\'s typing arrives', (page) => summary(page).then((value) => value.endsWith('Bob wrote this.')), true)
await people.chloe.locator('textarea').click()
await people.chloe.keyboard.press('Control+Home')
await people.chloe.keyboard.type('Chloe: ')
await expectEverywhere('then Chloe\'s', (page) => summary(page).then((value) => value.startsWith('Chloe: ')), true)
await people.bob.locator('textarea').focus()
await people.bob.keyboard.press('Control+z')
await expectEverywhere('Ctrl+Z undoes Bob\'s words only', summary, 'Chloe: What changes for customers, and when.')

await people.alice.locator('.ProseMirror').click()
await people.alice.keyboard.press('Control+End')
await people.alice.keyboard.type(' Rich text from React.')
await expectEverywhere('rich text typed in React shows everywhere', (page) => page.locator('.ProseMirror').innerText().then((text) => text.includes('Rich text from React.')), true)

await people.chloe.locator('.ProseMirror li').first().click()
await people.chloe.keyboard.press('End')
await people.chloe.keyboard.type(', from Livewire')
await expectEverywhere('rich text typed in Livewire shows everywhere', (page) => page.locator('.ProseMirror').innerText().then((text) => text.includes(', from Livewire')), true)

await expectEverywhere('everybody sees two others', (page) => page.locator('.collab-avatar').count(), 2)

// Bob saves from Vue; everybody's edits are saved, and a fresh page shows them.
await people.bob.getByRole('button', { name: 'Save' }).click()
await people.bob.waitForTimeout(1500)

const fresh = await (await browser.newContext()).newPage()

await fresh.goto(`${base}/as/1?to=${encodeURIComponent('/react/posts/1')}`)
await fresh.waitForTimeout(1500)

const saved = await fresh.evaluate(() => JSON.parse(document.querySelector('script[data-page]')?.textContent ?? '{}').props?.post ?? null)

if (saved && ['alice', 'bob', 'chloe'].every((name) => saved.title.includes(name)) && saved.status === 'review' && saved.featured && saved.body.includes('from Livewire') && saved.body.includes('Rich text from React.')) {
    console.log('ok   saving from Vue stores everybody\'s edits, body as HTML')
} else {
    failed = true
    console.log('FAIL saving from Vue:', JSON.stringify(saved))
}

await browser.close()
process.exit(failed ? 1 : 0)
