// Every link between the site's pages, anchors included, must land somewhere.
// VitePress checks that pages exist; this also checks the #anchors. Run after the build.
import { readdirSync, readFileSync } from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const dist = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../.vitepress/dist')
const base = '/laravel-collaboration/'
const files = readdirSync(dist, { recursive: true }).filter((file) => file.endsWith('.html') && file !== '404.html')
const urlOf = (file) => base + file.replace(/(^|\/)index\.html$/, '$1').replace(/\.html$/, '')
const ids = new Map(files.map((file) => [urlOf(file), new Set([...readFileSync(path.join(dist, file), 'utf8').matchAll(/id="([^"]+)"/g)].map((match) => match[1]))]))
const broken = []

for (const file of files) {
    for (const [, href] of readFileSync(path.join(dist, file), 'utf8').matchAll(/href="([^"]*#[^"]+)"/g)) {
        if (/^[a-z]+:/.test(href)) continue

        const [page, anchor] = href.split('#')
        const target = page === '' ? urlOf(file) : page.replace(/\.html$/, '')

        if (!ids.has(target)) broken.push(`${file}: ${href} (no such page)`)
        else if (!ids.get(target).has(decodeURIComponent(anchor))) broken.push(`${file}: ${href} (no such anchor)`)
    }
}

if (broken.length) {
    console.error(broken.join('\n'))
    process.exit(1)
}

console.log(`${files.length} pages, every internal link and anchor resolves`)
