// Draws every ```mermaid block in docs/, the README and the changelog as SVG, in a
// light and a dark version, into public/diagrams/. The site shows these pictures, so
// readers download no diagram library; GitHub draws the same blocks by itself.
// Run after changing a diagram: npm run diagrams (needs the demo's Playwright). The output is committed.
import { mkdirSync, readdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { diagramName, mermaidBlocks } from './mermaid.mjs'

const site = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..')
const repo = path.resolve(site, '..')
const out = path.join(site, 'public/diagrams')
const { chromium } = await import(path.join(repo, 'demo/node_modules/playwright/index.mjs'))

const sources = [...readdirSync(path.join(repo, 'docs')).filter((file) => file.endsWith('.md')).map((file) => `docs/${file}`), 'README.md', 'CHANGELOG.md']
const codes = [...new Set(sources.flatMap((file) => mermaidBlocks(readFileSync(path.join(repo, file), 'utf8'))))]

// A font every reader has, as an <img> cannot use the site's web font.
const fontFamily = 'ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif'
const themes = {
    light: {
        theme: 'base',
        themeVariables: {
            fontFamily,
            primaryColor: '#fef3c7',
            primaryBorderColor: '#d97706',
            primaryTextColor: '#18181b',
            lineColor: '#52525b',
            clusterBkg: '#fafafa',
            clusterBorder: '#d4d4d8',
            actorBkg: '#fef3c7',
            actorBorder: '#d97706',
            signalColor: '#3f3f46',
            noteBkgColor: '#f4f4f5',
            edgeLabelBackground: '#ffffff',
        },
    },
    dark: {
        theme: 'dark',
        themeVariables: { fontFamily, primaryColor: '#3f2a0d', primaryBorderColor: '#f59e0b', primaryTextColor: '#fafafa', lineColor: '#a1a1aa', clusterBkg: '#1f1f23', edgeLabelBackground: '#18181b' },
    },
}

rmSync(out, { recursive: true, force: true })
mkdirSync(out, { recursive: true })

const browser = await chromium.launch()
const page = await browser.newPage()

await page.setContent('<!doctype html><html><body></body></html>')
await page.addScriptTag({ path: path.join(site, 'node_modules/mermaid/dist/mermaid.min.js') })

for (const code of codes) {
    for (const [variant, options] of Object.entries(themes)) {
        const svg = await page.evaluate(
            async ({ code, options }) => {
                window.mermaid.initialize({ startOnLoad: false, securityLevel: 'strict', htmlLabels: false, flowchart: { htmlLabels: false, wrappingWidth: 340 }, ...options })

                const { svg } = await window.mermaid.render(`diagram${Math.random().toString(36).slice(2)}`, code)

                // Shown as an <img>, so it must be valid XML, with no HTML inside.
                if (new DOMParser().parseFromString(svg, 'image/svg+xml').querySelector('parsererror') || svg.includes('<foreignObject')) {
                    throw new Error(`This diagram does not draw as a plain SVG:\n${code}`)
                }

                return svg
            },
            { code, options },
        )

        writeFileSync(path.join(out, `${diagramName(code)}${variant === 'dark' ? '-dark' : ''}.svg`), svg)
    }
}

await browser.close()
console.log(`${codes.length} diagrams drawn to public/diagrams/`)
