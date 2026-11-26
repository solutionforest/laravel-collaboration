/**
 * Builds the site's pages from the README, its one source: each page is a
 * list of the README's sections. Run by `npm run dev` and `npm run build`.
 */
import { copyFileSync, mkdirSync, readFileSync, readdirSync, rmSync, writeFileSync } from 'node:fs'

const root = new URL('../../', import.meta.url).pathname
const site = new URL('../', import.meta.url).pathname
const readme = readFileSync(`${root}README.md`, 'utf8')

/** The README's sections by title, `##` and `###` alike, each with its body. */
const sections = new Map()
const lines = readme.split('\n')
let title = null
let body = []
let inCode = false

for (const line of lines) {
    if (line.startsWith('```')) inCode = !inCode

    const heading = !inCode && line.match(/^(##|###) (.+)$/)

    if (heading) {
        if (title) sections.set(title, body.join('\n').trim())

        title = heading[2]
        body = []
    } else if (title) {
        body.push(line)
    }
}

if (title) sections.set(title, body.join('\n').trim())

/** Each page: its file, its title, and the README sections it shows, in order. */
const pages = [
    ['guide/introduction', 'Introduction', ['What you get', 'Compared with filament-collaboration', 'Requirements']],
    ['guide/installation', 'Installation', ['Installation']],
    ['guide/quick-start', 'Quick start', ['1. Say which fields people edit together', '2. Use it from your stack']],
    ['guide/rich-text', 'Rich text', ['Rich text', 'Images in rich text']],
    ['guide/saving', 'Saving', ['Saving']],
    ['guide/permissions', 'Who may join', ['Who may join', 'Typos are caught']],
    ['guide/rooms', 'Describing a room', ['Describing a room in code', 'Repeaters', 'Create pages']],
    ['guide/presence', 'Presence', ['Following a colleague', 'Who is on each record of a list']],
    ['guide/comments-and-history', 'Comments and history', ['Comments and @mentions', 'Version history']],
    ['guide/configuration', 'Configuration', ['Configuration', 'Your own design', 'Pushing changes with Reverb']],
    ['guide/integrations', 'Building an integration', ['Building an integration']],
    ['guide/how-it-works', 'How it works', ['How it works']],
    ['guide/troubleshooting', 'Troubleshooting', ['Troubleshooting']],
    ['guide/demo-and-tests', 'Demo and tests', ['Run the demo', 'Testing']],
]

rmSync(`${site}guide`, { recursive: true, force: true })
mkdirSync(`${site}guide`, { recursive: true })

for (const [file, heading, names] of pages) {
    const parts = names.map((name) => {
        if (!sections.has(name)) throw new Error(`The README has no section "${name}" for ${file}.`)

        return names.length > 1 ? `## ${name.replace(/^\d\. /, '')}\n\n${sections.get(name)}` : sections.get(name)
    })

    // Links into the repository point at GitHub; images are served by the site.
    const text = `# ${heading}\n\n${parts.join('\n\n')}\n`
        .replaceAll('](docs/media/', '](/media/')
        .replace(/\]\((?!https?:|#|\/)([^)]+)\)/g, '](https://github.com/solutionforest/laravel-collaboration/blob/main/$1)')

    writeFileSync(`${site}${file}.md`, text)
}

mkdirSync(`${site}public/media`, { recursive: true })

for (const file of readdirSync(`${root}docs/media`)) copyFileSync(`${root}docs/media/${file}`, `${site}public/media/${file}`)

writeFileSync(`${site}.vitepress/pages.json`, JSON.stringify(pages.map(([file, heading]) => ({ text: heading, link: `/${file}` })), null, 4))

console.log(`Wrote ${pages.length} pages from the README.`)
