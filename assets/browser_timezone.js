// The browser's IANA time zone (docs/features/player-timezone.md), or null when the browser does not tell.
// Shared by timezone_detect_controller.js (sign-up) and timezone_select_controller.js (profile settings).
// The server checks every value against its own list - this is only ever a suggestion.
export function browserTimeZone() {
    try {
        const zone = Intl.DateTimeFormat().resolvedOptions().timeZone;

        return typeof zone === 'string' && zone !== '' ? zone : null;
    } catch (error) {
        return null;
    }
}
