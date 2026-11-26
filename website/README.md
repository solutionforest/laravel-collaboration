# Documentation website

The site at <https://solutionforest.github.io/laravel-collaboration/>, built with [VitePress](https://vitepress.dev).

Its pages come from the repository's own docs: edit `docs/`, `README.md` or `CHANGELOG.md`, not this folder. `scripts/sync.mjs` turns them into the site's pages on every build, splitting the guide into one page per section and rewriting links. Only the landing page, `pages/index.md`, is written here.

```bash
cd website
npm install
npm run dev       # http://localhost:5173/laravel-collaboration/
npm run build     # into .vitepress/dist
npm run check     # every link and anchor between pages resolves
npm run preview   # serve the build
```

After changing the brand (`brand/`) or adding a page, run `npm run sync && npm run brand` to regenerate the favicons, manifest and the 1200 × 630 social covers in `public/`, and commit them. It needs `rsvg-convert`, Python with Pillow, and the demo's Playwright (`cd ../demo && npm install`).

The **Docs** workflow builds the site and checks its links on every pull request that touches it, and publishes it to GitHub Pages from `main`. Pages needs the repository to be public, or a paid plan: until then the publish step is skipped. To turn Pages on, set **Settings → Pages → Source** to **GitHub Actions**.
