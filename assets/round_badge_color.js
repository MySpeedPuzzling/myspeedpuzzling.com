// The browser half of src/Value/RoundBadgeColor.php, for the round form's live badge preview - keep the two in step
// (tests/RoundBadgeColorParityTest.php runs both).

// Lowercase #rrggbb, or null for anything that is not a hex colour
export function normalizeColor(color) {
    const match = /^#?([0-9a-f]{3}|[0-9a-f]{6})$/i.exec(String(color ?? '').trim());

    if (match === null) {
        return null;
    }

    let hex = match[1].toLowerCase();

    if (hex.length === 3) {
        hex = hex[0] + hex[0] + hex[1] + hex[1] + hex[2] + hex[2];
    }

    return '#' + hex;
}

// The organiser's colour, null when there is none (nothing, not a hex colour, or the old form default)
export function chosenColor(color, roundFormDefault) {
    const chosen = normalizeColor(color);

    return chosen === normalizeColor(roundFormDefault) ? null : chosen;
}

function luminance(hex) {
    const channel = (component) => {
        const value = parseInt(component, 16) / 255;

        return value <= 0.03928 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4;
    };

    return 0.2126 * channel(hex.substring(1, 3))
        + 0.7152 * channel(hex.substring(3, 5))
        + 0.0722 * channel(hex.substring(5, 7));
}

// Black or white, whichever contrasts more with the background (WCAG relative luminance)
export function textColor(background) {
    const value = luminance(normalizeColor(background) ?? '#000000');

    const contrastWithBlack = (value + 0.05) / 0.05;
    const contrastWithWhite = 1.05 / (value + 0.05);

    return contrastWithBlack >= contrastWithWhite ? '#000000' : '#ffffff';
}
