import js from '@eslint/js';
import reactHooks from 'eslint-plugin-react-hooks';
import reactRefresh from 'eslint-plugin-react-refresh';
import tseslint from 'typescript-eslint';
import prettier from 'eslint-config-prettier';

export default tseslint.config(
    {
        ignores: [
            'public/**',
            'vendor/**',
            'node_modules/**',
            'bootstrap/ssr/**',
            'storage/**',
        ],
    },
    js.configs.recommended,
    ...tseslint.configs.recommended,
    {
        files: ['resources/js/**/*.{ts,tsx}'],
        plugins: {
            'react-hooks': reactHooks,
            'react-refresh': reactRefresh,
        },
        rules: {
            ...reactHooks.configs.recommended.rules,
            'react-refresh/only-export-components': [
                'warn',
                { allowConstantExport: true },
            ],
            // i18n guard (SLO-9): no hardcoded user-facing text in JSX — every
            // string must come from a lang file via the t() helper. Matches JSX
            // text with a 2+ letter word; punctuation/symbols (·, —, :) are fine.
            'no-restricted-syntax': [
                'error',
                {
                    selector:
                        'JSXText[value=/[A-Za-zÁÉÍÓÖŐÚÜŰáéíóöőúüű]{2,}/]',
                    message:
                        'Hardcoded UI text is not allowed — use the t() i18n helper (lang/hu/*.php).',
                },
            ],
        },
    },
    {
        // Primitive UI components (shadcn/ui) carry no user-facing copy; the
        // i18n guard does not apply to them.
        files: ['resources/js/components/ui/**/*.{ts,tsx}'],
        rules: {
            'react-refresh/only-export-components': 'off',
            'no-restricted-syntax': 'off',
        },
    },
    prettier,
);
