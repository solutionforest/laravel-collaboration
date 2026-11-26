// Builds the site's pages from the README and the changelog, so they never fork:
// the README is split into one page per section, and links and anchors are
// rewritten to where they now live.
// Run by `npm run dev` and `npm run build`; the output, src/, is not committed.
import { cpSync, existsSync, mkdirSync, readdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { slugify } from '../.vitepress/slugify.mjs'
import { diagramAlt, diagramName } from './mermaid.mjs'

const site = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..')
const repo = path.resolve(site, '..')
const out = path.join(site, 'src')
const read = (file) => readFileSync(path.join(repo, file), 'utf8')

rmSync(out, { recursive: true, force: true })
mkdirSync(out, { recursive: true })

/**
 * Splits markdown into its sections at headings of `level`, keeping the text before the first.
 *
 * @returns {{heading: string | null, body: string}[]}
 */
function sections(markdown, level) {
    const marker = '#'.repeat(level) + ' '
    const result = [{ heading: null, body: '' }]
    let fence = false

    for (const line of markdown.split('\n')) {
        if (line.startsWith('```')) fence = !fence

        if (!fence && line.startsWith(marker)) result.push({ heading: line.slice(marker.length).trim(), body: '' })
        else result.at(-1).body += line + '\n'
    }

    return result
}

const readme = sections(read('README.md'), 2)
const part = (name) => readme.find(({ heading }) => heading === name)?.body ?? ''

/** A `###` subsection of a README section, as a `##` section of its own page. */
const sub = (section, name) => {
    const found = sections(part(section), 3).find(({ heading }) => heading === name)

    if (!found) throw new Error(`The README has no "${name}" under "${section}".`)

    return `## ${name}\n${found.body}`
}

/** A README section without its `###` subsections, which have pages of their own. */
const lead = (section) => sections(part(section), 3)[0].body

/** A README section, its `###` subsections becoming the page's `##` sections. */
const promoted = (section) => part(section).replace(/^### /gm, '## ')

/**
 * Every page: where it goes, where it comes from (for "edit this page"), its title and description.
 *
 * @type {{path: string, source: string, title: string, description: string, body: string}[]}
 */
const pages = [
    {
        path: 'getting-started.md',
        source: 'README.md',
        title: 'Install and quick start',
        description: 'Install Laravel Collaboration, say which fields people edit together, and make a Livewire, React or Vue form collaborative.',
        body: `## Requirements\n${part('Requirements')}\n## Installation\n${part('Installation')}\n${promoted('Quick start')}`,
    },
    {
        path: 'guide/rich-text.md',
        source: 'README.md',
        title: 'Rich text',
        description: 'TipTap editors that merge as people type, in Livewire, React and Vue, with images uploaded while editing.',
        body: promoted('Rich text'),
    },
    {
        path: 'guide/saving.md',
        source: 'README.md',
        title: 'Saving',
        description: 'Saving everybody\'s edits, values from somebody else\'s save, and choosing between versions when two people change one field.',
        body: part('Saving'),
    },
    {
        path: 'guide/rooms.md',
        source: 'README.md',
        title: 'Describing a room',
        description: 'Fields and their kinds, locks, labels, languages, create pages, and the typos the package catches for you.',
        body: `${lead('Describing a room in code')}\n${sub('Describing a room in code', 'Create pages')}\n${sub('Describing a room in code', 'Typos are caught')}`,
    },
    {
        path: 'guide/repeaters.md',
        source: 'README.md',
        title: 'Repeaters',
        description: 'Lists of items people add, remove, reorder and edit together.',
        body: sub('Describing a room in code', 'Repeaters').replace(/^## Repeaters\n/, ''),
    },
    {
        path: 'guide/presence.md',
        source: 'README.md',
        title: 'Presence',
        description: 'Following a colleague from field to field, and who is on each record of a list or a table.',
        body: `${sub('Describing a room in code', 'Following a colleague')}\n${sub('Describing a room in code', 'Who is on each record of a list')}`,
    },
    {
        path: 'guide/comments-and-history.md',
        source: 'README.md',
        title: 'Comments and history',
        description: 'The optional features: comments with @mentions, version history with restore, and image uploads.',
        body: `${sub('Describing a room in code', 'Optional features')}\n${sub('Describing a room in code', 'Comments and @mentions')}\n${sub('Describing a room in code', 'Version history')}`,
    },
    {
        path: 'guide/permissions.md',
        source: 'README.md',
        title: 'Who may join',
        description: 'Policies, the collaborate ability, permission changes, and what other people can see.',
        body: part('Who may join'),
    },
    {
        path: 'configuration.md',
        source: 'README.md',
        title: 'Configuration',
        description: 'Behaviour set in code, deployment settings, your own design, and pushing changes with Reverb.',
        body: promoted('Configuration'),
    },
    {
        path: 'integrations.md',
        source: 'README.md',
        title: 'Building an integration',
        description: 'The hooks filament-collaboration builds on: users, config, settings, migrations, routes and access rules.',
        body: part('Building an integration'),
    },
    {
        path: 'comparison.md',
        source: 'README.md',
        title: 'Compared with filament-collaboration',
        description: 'Feature by feature: what this package and filament-collaboration each offer.',
        body: part('Compared with filament-collaboration'),
    },
    {
        path: 'how-it-works.md',
        source: 'README.md',
        title: 'How it works',
        description: 'The shared draft, the merge engine written in PHP and JavaScript, and how an edit travels.',
        body: part('How it works'),
    },
    {
        path: 'guide/troubleshooting.md',
        source: 'README.md',
        title: 'Troubleshooting',
        description: 'Common problems and their fixes.',
        body: part('Troubleshooting'),
    },
    {
        path: 'demo-and-tests.md',
        source: 'README.md',
        title: 'Demo and tests',
        description: 'Run the demo, where Livewire, React and Vue edit one article, and the test suites.',
        body: `## Run the demo\n${part('Run the demo')}\n## Testing\n${part('Testing')}`,
    },
    {
        path: 'changelog.md',
        source: 'CHANGELOG.md',
        title: 'Changelog',
        description: 'Every release of Laravel Collaboration, following Semantic Versioning.',
        body: read('CHANGELOG.md').replace(/^# .*\n/, ''),
    },
]

// Where every anchor now lives: each heading on its page, and each README section at its page.
const urlOf = (page) => '/' + page.path.replace(/(^|\/)index\.md$/, '$1').replace(/\.md$/, '')
/** @type {Map<string, string>} */
const anchors = new Map()

for (const page of pages) {
    for (const match of page.body.matchAll(/^#{2,4} (.+)$/gm)) anchors.set(slugify(match[1]), `${urlOf(page)}#${slugify(match[1])}`)
}

const sectionPages = {
    'What you get': '/',
    'Requirements': '/getting-started#requirements',
    'Installation': '/getting-started#installation',
    'Quick start': '/getting-started',
    'Rich text': '/guide/rich-text',
    'Saving': '/guide/saving',
    'Who may join': '/guide/permissions',
    'Describing a room in code': '/guide/rooms',
    'Configuration': '/configuration',
    'Building an integration': '/integrations',
    'How it works': '/how-it-works',
    'Troubleshooting': '/guide/troubleshooting',
    'Run the demo': '/demo-and-tests#run-the-demo',
    'Testing': '/demo-and-tests#testing',
    'Compared with filament-collaboration': '/comparison',
}

for (const [heading, url] of Object.entries(sectionPages)) anchors.set(slugify(heading), url)

const files = {
    'CHANGELOG.md': () => '/changelog',
    'README.md': (anchor) => anchors.get(anchor) ?? '/',
    'LICENSE': () => 'https://github.com/solutionforest/laravel-collaboration/blob/main/LICENSE',
    'SECURITY.md': () => 'https://github.com/solutionforest/laravel-collaboration/blob/main/SECURITY.md',
}

/** A PNG's width and height, from its header. */
function pngSize(file) {
    if (!existsSync(file)) return null

    const header = readFileSync(file).subarray(16, 24)

    return { width: header.readUInt32BE(0), height: header.readUInt32BE(4) }
}

/** Rewrites a link written relative to `source` for the site. */
function link(target, source) {
    if (/^(https?:|mailto:)/.test(target)) return target

    const [file, anchor] = target.split('#')

    if (file === '') return anchors.get(anchor) ?? `#${anchor}`

    const resolved = path.posix.normalize(path.posix.join(path.posix.dirname(source), file))

    if (resolved.startsWith('docs/media/')) return `/media/${path.posix.basename(resolved)}`
    if (files[resolved]) return files[resolved](anchor)

    // Anything else in the repository: on GitHub.
    return `https://github.com/solutionforest/laravel-collaboration/blob/main/${resolved}${anchor ? `#${anchor}` : ''}`
}

/** The SVG's own size, so the page does not shift as it loads. */
function svgSize(file) {
    const box = readFileSync(file, 'utf8').match(/viewBox="[\d.-]+ [\d.-]+ ([\d.]+) ([\d.]+)"/)

    return box ? { width: Math.round(Number(box[1])), height: Math.round(Number(box[2])) } : null
}

/** A mermaid block as the pictures `npm run diagrams` drew of it, light and dark. */
function diagram(code) {
    const name = diagramName(code)
    const file = path.join(site, 'public/diagrams', `${name}.svg`)

    if (!existsSync(file)) throw new Error(`A diagram changed: run npm run diagrams in website/ and commit public/diagrams/.`)

    const { width, height } = svgSize(file) ?? { width: 800, height: 400 }
    const alt = diagramAlt(code).replace(/"/g, '&quot;')

    return ['light', 'dark']
        .map((variant) => `<img class="diagram diagram-${variant}" src="/diagrams/${name}${variant === 'dark' ? '-dark' : ''}.svg" alt="${alt}" width="${width}" height="${height}" loading="lazy">`)
        .join('\n')
}

// Examples for Livewire, React and Vue become tabs: the README's <details> per stack,
// and runs of code blocks in a stack's language (blade, jsx, vue), each labelled by a first-line comment.
const stackOf = { Livewire: 'livewire', 'Inertia + React': 'react', 'Inertia + Vue': 'vue' }
const languages = { blade: 'livewire', jsx: 'react', tsx: 'react', vue: 'vue' }
const label = { livewire: 'Livewire', react: 'React', vue: 'Vue' }

const tabs = (panels) => [
    `<StackTabs :stacks="${JSON.stringify(panels.map(({ stack }) => stack)).replace(/"/g, "'")}">`,
    ...panels.map(({ stack, body }) => `<template #${stack}>\n\n${body.trim()}\n\n</template>`),
    '</StackTabs>',
].join('\n')

// "// React", "<!-- Vue -->", "{{-- Livewire, inside the component --}}": the stack's name goes, the rest stays.
function unlabelled(block, stack) {
    const [open, first, ...rest] = block.split('\n')
    const comment = first.match(/^(\/\/|<!--|\{\{--) (.*?)( -->| --\}\})?$/)

    if (!comment || !comment[2].startsWith(label[stack])) return block

    const remark = comment[2].slice(label[stack].length).replace(/^[,:]\s*/, '')
    const kept = remark ? [`${comment[1]} ${remark[0].toUpperCase()}${remark.slice(1)}${comment[3] ?? ''}`] : []

    return [open, ...kept, ...rest].join('\n')
}

function stackTabs(markdown) {
    return markdown
        .replace(/(?:<details(?: open)?>\n<summary><b>(?:Livewire|Inertia \+ React|Inertia \+ Vue)<\/b><\/summary>\n[\s\S]*?<\/details>\n*)+/g, (run) => {
            const panels = [...run.matchAll(/<summary><b>(.*?)<\/b><\/summary>\n([\s\S]*?)<\/details>/g)].map(([, name, body]) => ({ stack: stackOf[name], body }))

            return tabs(panels) + '\n\n'
        })
        .replace(/(?:^```(?:blade|jsx|tsx|vue)\n[\s\S]*?^```\n(?:\n|(?![\s\S])))+/gm, (run) => {
            const blocks = [...run.matchAll(/^```(\w+)\n[\s\S]*?^```$/gm)].map(([block, language]) => ({ stack: languages[language], block }))

            if (new Set(blocks.map(({ stack }) => stack)).size !== blocks.length || blocks.length < 2) return run

            return tabs(blocks.map(({ stack, block }) => ({ stack, body: unlabelled(block, stack) }))) + '\n\n'
        })
}

for (const page of pages) {
    let fence = false
    const body = stackTabs(page.body)
        .replace(/^```mermaid\n([\s\S]*?)^```$/gm, (match, code) => `<figure class="diagram">\n${diagram(code)}\n</figure>`)
        .split('\n')
        .map((line) => {
            if (line.startsWith('```')) fence = !fence

            if (fence || line.startsWith('```')) return line

            // Screenshots with their size, so the page does not shift as they load.
            const sized = line.replace(/!\[([^\]]*)\]\(([^)\s]+\.png)\)/g, (match, alt, target) => {
                const url = link(target, page.source)
                const size = url.startsWith('/media/') && pngSize(path.join(repo, 'docs/media', path.posix.basename(url)))

                return size ? `<img src="${url}" alt="${alt.replace(/"/g, '&quot;')}" width="${size.width}" height="${size.height}" loading="lazy">` : match
            })

            return sized.replace(/(!?\[[^\]]*\])\(([^)\s]+)\)/g, (_, text, target) => `${text}(${link(target, page.source)})`)
        })
        .join('\n')
        // Section numbers belong to the single guide file, not to separate pages.
        .replace(/^(#{2,4}) \d+\. /gm, '$1 ')
        // A guide section's subsections are the page's sections: ### becomes ##, under the page's title.
        .replace(/^#(#{2,5}) /gm, (heading, rest) => (page.promote ? `${rest} ` : heading))
    const front = ['---', `title: ${JSON.stringify(page.title)}`, `description: ${JSON.stringify(page.description)}`, `source: ${page.source}`, `cover: /covers/${page.path.replace(/(\/index)?\.md$/, '').replace(/\//g, '-')}.png`, '---', '']
    const file = path.join(out, page.path)

    mkdirSync(path.dirname(file), { recursive: true })
    writeFileSync(file, `${front.join('\n')}\n# ${page.title}\n\n${body.trim()}\n`)
}

// Hand-written pages (the landing page), brand assets, and the screenshots and video.
cpSync(path.join(site, 'pages'), out, { recursive: true })
mkdirSync(path.join(out, 'public', 'media'), { recursive: true })
cpSync(path.join(site, 'public'), path.join(out, 'public'), { recursive: true })

for (const file of readdirSync(path.join(repo, 'docs/media'))) cpSync(path.join(repo, 'docs/media', file), path.join(out, 'public/media', file))

writeFileSync(path.join(site, '.vitepress', 'pages.json'), JSON.stringify(pages.map(({ path: page, title, description, source }) => ({ path: page, title, description, source })), null, 2) + '\n')
console.log(`synced ${pages.length} pages from README.md and CHANGELOG.md`)
if (!existsSync(path.join(out, 'index.md'))) throw new Error('pages/index.md is missing')
