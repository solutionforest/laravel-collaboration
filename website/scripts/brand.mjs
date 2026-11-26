// Brand assets for the site, from brand/*.svg: the favicon set, the web manifest,
// and a 1200 × 630 social cover for the site and for every page.
// Run after changing the brand or the pages: npm run brand (needs `npm run sync` first,
// rsvg-convert, Python with Pillow, and the demo's Playwright). The output is committed.
import { execFileSync } from 'node:child_process'
import { copyFileSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const site = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..')
const pub = path.join(site, 'public')
const brand = (file) => path.join(site, 'brand', file)
const { chromium } = await import(path.join(site, '../demo/node_modules/playwright/index.mjs'))

mkdirSync(path.join(pub, 'covers'), { recursive: true })

// The mark as favicon and app icons; the logo for the navigation bar.
copyFileSync(brand('mark.svg'), path.join(pub, 'favicon.svg'))
copyFileSync(brand('mark.svg'), path.join(pub, 'icon.svg'))
copyFileSync(brand('logo.svg'), path.join(pub, 'logo.svg'))
copyFileSync(brand('logo-dark.svg'), path.join(pub, 'logo-dark.svg'))

for (const size of [16, 32, 48, 192, 512]) {
    execFileSync('rsvg-convert', ['-w', String(size), '-h', String(size), brand('mark.svg'), '-o', path.join(pub, size >= 192 ? `icon-${size}.png` : `favicon-${size}.png`)])
}

// Apple's icon has no transparency: the mark's amber fills the corners iOS rounds itself.
execFileSync('rsvg-convert', ['-w', '180', '-h', '180', '-b', '#f59e0b', brand('mark.svg'), '-o', path.join(pub, 'apple-touch-icon.png')])
execFileSync('python3', [
    '-c',
    `from PIL import Image; Image.open('${path.join(pub, 'favicon-48.png')}').save('${path.join(pub, 'favicon.ico')}', sizes=[(16, 16), (32, 32), (48, 48)])`,
])

writeFileSync(
    path.join(pub, 'site.webmanifest'),
    JSON.stringify(
        {
            name: 'Laravel Collaboration',
            short_name: 'Collaboration',
            description: 'Google Docs-style collaboration for any Laravel app',
            start_url: '/laravel-collaboration/',
            scope: '/laravel-collaboration/',
            display: 'standalone',
            background_color: '#ffffff',
            theme_color: '#f59e0b',
            icons: [
                { src: '/laravel-collaboration/icon-192.png', sizes: '192x192', type: 'image/png' },
                { src: '/laravel-collaboration/icon-512.png', sizes: '512x512', type: 'image/png' },
                { src: '/laravel-collaboration/icon.svg', sizes: 'any', type: 'image/svg+xml' },
            ],
        },
        null,
        4,
    ) + '\n',
)

// Covers: the same card, with each page's title and description.
const pages = JSON.parse(readFileSync(path.join(site, '.vitepress/pages.json'), 'utf8'))
const mark = readFileSync(brand('mark.svg'), 'utf8')
const screenshot = 'data:image/png;base64,' + readFileSync(path.join(site, '../docs/media/react.png')).toString('base64')
// Inter, as on the site: the one VitePress ships.
const inter = 'data:font/woff2;base64,' + readFileSync(path.join(site, 'node_modules/vitepress/dist/client/theme-default/fonts/inter-roman-latin.woff2')).toString('base64')
const escape = (text) => text.replace(/&/g, '&amp;').replace(/</g, '&lt;')
const html = ({ eyebrow, title, description }) => `<!doctype html><html><head><style>
    @font-face { font-family: Inter; src: url(${inter}) format('woff2'); font-weight: 100 900; }
    * { box-sizing: border-box; margin: 0; }
    body { width: 1200px; height: 630px; font-family: Inter, ui-sans-serif, system-ui, sans-serif; background: #fafafa; color: #18181b; position: relative; overflow: hidden;
        background-image: linear-gradient(rgb(24 24 27 / 5%) 1px, transparent 1px), linear-gradient(90deg, rgb(24 24 27 / 5%) 1px, transparent 1px); background-size: 48px 48px; }
    .card { position: absolute; inset: 40px; border: 1.5px solid rgb(24 24 27 / 14%); border-radius: 28px; background: rgb(255 255 255 / 92%); padding: 56px 60px 44px; display: flex; flex-direction: column; }
    .text { margin: auto 0; padding: 24px 0; max-width: 530px; }
    .brand { display: flex; align-items: center; gap: 18px; font-size: 26px; font-weight: 700; }
    .brand svg { width: 56px; height: 56px; }
    .brand small { display: block; font-size: 17px; font-weight: 600; color: #71717a; }
    .eyebrow { font-size: 20px; font-weight: 600; letter-spacing: 0.08em; text-transform: uppercase; color: #d97706; }
    h1 { margin-top: 14px; font-size: 52px; line-height: 1.05; letter-spacing: -0.03em; }
    p { margin-top: 20px; font-size: 21px; line-height: 1.45; color: #52525b; display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden; }
    /* The edit form from the React screenshot, inside the card with a margin on every side. */
    .shot { position: absolute; right: 56px; top: 50%; transform: translateY(-50%); width: 400px; height: 433px; overflow: hidden; background: #fff;
        border: 1.5px solid rgb(24 24 27 / 16%); border-radius: 16px; box-shadow: 0 24px 48px -24px rgb(0 0 0 / 35%); }
    .shot img { position: absolute; width: 520px; left: -8px; top: -44px; }
    .url { font-size: 18px; font-weight: 600; color: #a1a1aa; }
</style></head><body><div class="card">
    <div class="brand">${mark}<div><small>Laravel</small>Collaboration</div></div>
    <div class="text">
        <div class="eyebrow">${escape(eyebrow)}</div>
        <h1>${escape(title)}</h1>
        <p>${escape(description)}</p>
    </div>
    <span class="url">solutionforest.github.io/laravel-collaboration</span>
    <div class="shot"><img src="${screenshot}"></div>
</div></body></html>`

const browser = await chromium.launch()
const page = await browser.newPage({ viewport: { width: 1200, height: 630 } })
const covers = [
    { file: 'default', eyebrow: 'Livewire · React · Vue', title: 'Google Docs-style editing for Laravel', description: 'Live fields, presence, carets, comments and version history, for Livewire and Inertia apps. One trait, one hook.' },
    ...pages.map((entry) => ({
        file: entry.path.replace(/(\/index)?\.md$/, '').replace(/\//g, '-'),
        eyebrow: entry.path.startsWith('guide/') ? 'Usage guide' : 'Documentation',
        title: entry.title,
        description: entry.description,
    })),
]

for (const cover of covers) {
    await page.setContent(html(cover))
    await page.screenshot({ path: path.join(pub, 'covers', `${cover.file}.png`) })
}

await browser.close()
writeFileSync(path.join(pub, 'robots.txt'), 'User-agent: *\nAllow: /\n\nSitemap: https://solutionforest.github.io/laravel-collaboration/sitemap.xml\n')
console.log(`brand assets and ${covers.length} covers written to public/`)
