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
 * The example subdomain shown in landing illustrations.
 *
 * ⚠️ A constant rather than a translation key, for the same reason
 * {@see BRAND_NAME} is: it is not prose. Nothing about it changes per locale,
 * and putting it in `lang/hu` would invite somebody to "translate" a hostname.
 * Here it has one source, and the i18n lint rule — which is right to forbid
 * literal text in JSX — is satisfied without a disable comment.
 *
 * Deliberately NOT built from `tenancy.central_domain`: that is the real host,
 * and a made-up tenant on it would read as a link somebody could visit.
 */
export const EXAMPLE_HOST = 'sajat-nevem.slot4u.hu';

/*
 * ⚠️ The platform accent used to live here (SLO-170) — a teal, applied by
 * overriding `--primary` on the marketing and superadmin shells.
 *
 * It is gone, and its absence is the point (SLO-201, docs/21). The identity now
 * lives in `:root` itself: navy IS the product's primary colour, so a shell that
 * repaints the token is repainting it to the value it already had. Two places
 * deciding one colour is how they drift apart.
 *
 * The tenant override in PublicLayout stays exactly as it was, and is now the
 * ONLY override of `--primary` in the app: a tenant's booking page is their
 * brand, not ours (docs/19 §2).
 */
