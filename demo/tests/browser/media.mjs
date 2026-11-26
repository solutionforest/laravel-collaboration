/**
 * Records the README's screenshots and video from a real session: three
 * people on one article, from Livewire, React and Vue.
 *
 *     php artisan migrate:fresh --seed && php artisan serve --port=8123
 *     node tests/browser/media.mjs
 */
import { chromium } from 'playwright'
import { execFileSync } from 'node:child_process'
import { mkdirSync, readdirSync, renameSync, rmSync } from 'node:fs'

const base = process.env.APP_URL ?? 'http://127.0.0.1:8123'
const out = new URL('../../../docs/media/', import.meta.url).pathname
const raw = `${out}raw/`
const size = { width: 760, height: 980 }

rmSync(raw, { recursive: true, force: true })
mkdirSync(raw, { recursive: true })

const browser = await chromium.launch()

async function person(id, stack) {
    const context = await browser.newContext({ viewport: size, deviceScaleFactor: 1, recordVideo: { dir: `${raw}${stack}`, size } })
    const page = await context.newPage()

    await page.goto(`${base}/as/${id}?to=${encodeURIComponent(`/${stack}/posts/1`)}`)

    return { page, context, stack }
}

const alice = await person(1, 'react')
const bob = await person(2, 'vue')
const chloe = await person(3, 'livewire')
const pause = (milliseconds) => alice.page.waitForTimeout(milliseconds)
const type = (page, text) => page.keyboard.type(text, { delay: 90 })

await pause(2500)

// Alice and Bob type into the title at the same time.
await alice.page.locator('input').first().click()
await alice.page.keyboard.press('End')
await bob.page.locator('input').first().click()
await bob.page.keyboard.press('Home')
await Promise.all([type(alice.page, ' this spring'), type(bob.page, 'Now open: ')])
await pause(800)

// Chloe writes in the body while Alice changes the summary.
await chloe.page.locator('.ProseMirror li').last().click()
await chloe.page.keyboard.press('End')
await alice.page.locator('textarea').click()
await alice.page.keyboard.press('End')
await Promise.all([type(chloe.page, ' and email'), type(alice.page, ' Live from April.')])
await chloe.page.keyboard.press('Enter')
await type(chloe.page, 'Pay online when you book')
await pause(600)

// Everybody is somewhere: take the pictures.
await bob.page.locator('.ProseMirror p').first().click()
await bob.page.keyboard.press('End')
await type(bob.page, ' It took a year.')
await pause(1200)

for (const { page, stack } of [alice, bob, chloe]) await page.screenshot({ path: `${raw}${stack}.png` })

// Bob sets the status, Chloe adds the newsletter, Alice saves everybody's work.
await bob.page.locator('select').selectOption('review')
await bob.page.locator('select').blur()
await pause(700)
await chloe.page.locator('input[type=checkbox][value=email]').check()
await pause(900)
await alice.page.getByRole('button', { name: 'Save' }).click()
await pause(2500)

for (const { context } of [alice, bob, chloe]) await context.close()

await browser.close()

// One picture and one video, the three browsers side by side.
const videos = ['react', 'vue', 'livewire'].map((stack) => {
    const [file] = readdirSync(`${raw}${stack}`)
    const target = `${raw}${stack}.webm`

    renameSync(`${raw}${stack}/${file}`, target)

    return target
})

const label = (text) => `drawtext=text='${text}':x=(w-text_w)/2:y=h-48:fontsize=26:fontcolor=white:box=1:boxcolor=0x0f172a@0.85:boxborderw=12`
const labels = ['Alice · React', 'Bob · Vue', 'Chloe · Livewire']
const ffmpeg = (...args) => execFileSync('ffmpeg', ['-y', '-loglevel', 'error', ...args])

ffmpeg(
    ...['react', 'vue', 'livewire'].flatMap((stack) => ['-i', `${raw}${stack}.png`]),
    '-filter_complex', `[0]${label(labels[0])}[a];[1]${label(labels[1])}[b];[2]${label(labels[2])}[c];[a][b][c]hstack=inputs=3,scale=1800:-1`,
    `${out}three-stacks.png`,
)

ffmpeg(
    ...videos.flatMap((video) => ['-i', video]),
    '-filter_complex', `[0]${label(labels[0])}[a];[1]${label(labels[1])}[b];[2]${label(labels[2])}[c];[a][b][c]hstack=inputs=3,scale=1800:-2,fps=24`,
    '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-crf', '28', '-movflags', '+faststart',
    `${out}demo.mp4`,
)

ffmpeg(
    '-i', `${out}demo.mp4`,
    '-vf', 'fps=10,scale=1200:-1:flags=lanczos,split[s0][s1];[s0]palettegen=max_colors=96[p];[s1][p]paletteuse=dither=bayer:bayer_scale=5',
    `${out}demo.gif`,
)

for (const stack of ['react', 'vue', 'livewire']) renameSync(`${raw}${stack}.png`, `${out}${stack}.png`)

rmSync(raw, { recursive: true, force: true })
console.log('Written to', out)
