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

// APCA screen luminance of a #rrggbb colour: plain 2.4 power per channel, no linear toe
export function apcaLuminance(hex) {
    const channel = (component) => (parseInt(component, 16) / 255) ** 2.4;

    return 0.2126729 * channel(hex.substring(1, 3))
        + 0.7151522 * channel(hex.substring(3, 5))
        + 0.0721750 * channel(hex.substring(5, 7));
}

function softClampBlack(luminance) {
    return luminance > 0.022 ? luminance : luminance + (0.022 - luminance) ** 1.414;
}

// APCA lightness contrast Lc of text on a background (SAPC/APCA 0.0.98G-4g), both given as APCA luminance
export function apcaContrast(textLuminance, backgroundLuminance) {
    const text = softClampBlack(textLuminance);
    const background = softClampBlack(backgroundLuminance);

    if (Math.abs(background - text) < 0.0005) {
        return 0;
    }

    if (background > text) {
        const contrast = (background ** 0.56 - text ** 0.57) * 1.14;

        return contrast < 0.1 ? 0 : (contrast - 0.027) * 100;
    }

    const contrast = (background ** 0.65 - text ** 0.62) * 1.14;

    return contrast > -0.1 ? 0 : (contrast + 0.027) * 100;
}

// Black or white, whichever reads better on the background by APCA (the larger absolute Lc)
export function textColor(background) {
    const value = apcaLuminance(normalizeColor(background) ?? '#000000');

    return Math.abs(apcaContrast(0, value)) >= Math.abs(apcaContrast(1, value)) ? '#000000' : '#ffffff';
}
