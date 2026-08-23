/**
 * The product name, as one constant.
 *
 * Deliberately NOT a translation key: a brand name is the one string that must
 * survive every locale unchanged, and the i18n lint rule (which forbids literal
 * JSX text) is right to stop the alternative. Keeping it here means the wordmark
 * has a single source rather than a disable comment at every use site.
 */
export const BRAND_NAME = 'slot4u';

/**
 * The platform's own accent, taken from the sloth's branch (SLO-170).
 *
 * ⚠️ Applied to the slot4u marketing surface ONLY, by overriding `--primary` on
 * the layout — the same mechanism a tenant's own brand colour uses on its public
 * pages. `TenantBranding::DEFAULT_PRIMARY_COLOR` stays indigo on purpose: a
 * tenant's booking page is THEIR brand, not ours, and repainting every tenant
 * who never chose a colour would be the platform helping itself to their shop
 * window. Same line the data-controller split follows (docs/19 §2).
 */
export const PLATFORM_ACCENT = '#22DECB';

/** Readable against the accent — it is a light teal, so the text on it is dark. */
export const PLATFORM_ACCENT_FOREGROUND = '#091020';
