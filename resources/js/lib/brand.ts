/**
 * The product name, as one constant.
 *
 * Deliberately NOT a translation key: a brand name is the one string that must
 * survive every locale unchanged, and the i18n lint rule (which forbids literal
 * JSX text) is right to stop the alternative. Keeping it here means the wordmark
 * has a single source rather than a disable comment at every use site.
 */
export const BRAND_NAME = 'slot4u';
