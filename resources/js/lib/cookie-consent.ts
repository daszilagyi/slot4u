/**
 * How the footer link reaches the cookie settings dialog across two unrelated
 * subtrees (SLO-165).
 *
 * A context provider would be the textbook answer and three files of ceremony
 * for one boolean; a window event is the same thing without the wiring. Lives
 * here rather than beside the component so that file exports only components
 * (fast refresh).
 */
export const COOKIE_SETTINGS_EVENT = 'slot4u:cookie-settings';

export function openCookieSettings(): void {
    window.dispatchEvent(new CustomEvent(COOKIE_SETTINGS_EVENT));
}
