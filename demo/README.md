# Laravel Collaboration demo

One article that Alice, Bob and Chloe edit at the same time: from a Livewire page, an Inertia React page and an Inertia Vue page. The package is linked from the repository root, so changes to it show here at once.

```bash
composer install && npm install
cp .env.example .env && php artisan key:generate
touch database/database.sqlite && php artisan migrate --seed
npm run build && php artisan serve
```

Open <http://localhost:8000> and pick a person and a stack. Each browser keeps one session, so use a private window for each extra person.

| Where | What to read |
| --- | --- |
| `app/Models/Post.php` | The fields people edit together |
| `app/Livewire/EditPost.php`, `resources/views/livewire/edit-post.blade.php` | Livewire |
| `app/Http/Controllers/PostController.php` | The Inertia controller, shared by React and Vue |
| `resources/js/Pages/React/Posts/Edit.jsx` | React |
| `resources/js/Pages/Vue/Posts/Edit.vue` | Vue |
| `resources/js/livewire.js` | A TipTap editor in Livewire, with Alpine |
| `tests/browser/smoke.mjs` | Three browsers check that every stack sees every other |
| `tests/browser/media.mjs` | Records the screenshots and video in the main README |
