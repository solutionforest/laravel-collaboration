import js from '@eslint/js'
import globals from 'globals'

/** Lint rules for the browser code and its tests: `npm run lint`. */
export default [
    {
        ignores: ['resources/dist/**', 'node_modules/**', 'vendor/**', 'demo/vendor/**', 'demo/node_modules/**', 'demo/public/**', 'website/node_modules/**', 'website/.vitepress/cache/**', 'website/.vitepress/dist/**'],
    },
    js.configs.recommended,
    {
        files: ['**/*.js', '**/*.mjs', '**/*.jsx'],
        languageOptions: {
            ecmaVersion: 2023,
            sourceType: 'module',
            globals: { ...globals.browser },
            parserOptions: { ecmaFeatures: { jsx: true } },
        },
        rules: {
            curly: ['error', 'multi-line'],
            eqeqeq: ['error', 'always'],
            'no-console': ['error', { allow: ['warn', 'error'] }],
            'no-shadow': 'error',
            'no-unused-vars': ['error', { argsIgnorePattern: '^_' }],
            'no-var': 'error',
            'object-shorthand': 'error',
            'prefer-const': 'error',
        },
    },
    {
        files: ['tests/js/**', 'demo/tests/**', 'demo/scripts/**', 'website/scripts/**', 'website/.vitepress/**', 'vite.config.js', 'vite.editor.config.js', 'eslint.config.js'],
        languageOptions: { globals: { ...globals.node, ...globals.browser } },
        rules: { 'no-console': 'off', 'no-shadow': 'off' },
    },
]
