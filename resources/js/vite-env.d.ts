/// <reference types="vite/client" />

interface ImportMetaEnv {
    readonly VITE_REVERB_APP_KEY: string;
    readonly VITE_REVERB_HOST: string;
    readonly VITE_REVERB_PORT: string;
    readonly VITE_REVERB_SCHEME: string;
    // Browser error reporting (SLO-153). Empty in dev/CI — nothing is loaded.
    readonly VITE_SENTRY_DSN: string;
    readonly VITE_SENTRY_ENVIRONMENT: string;
    readonly VITE_APP_RELEASE: string;
}

interface ImportMeta {
    readonly env: ImportMetaEnv;
}
