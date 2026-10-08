// Runs assets/round_badge_color.js (the browser half of RoundBadgeColor) for the colours
// tests/RoundBadgeColorParityTest.php hands over on stdin, and prints the results as JSON.

import { readFileSync } from 'node:fs';
import { apcaContrast, apcaLuminance, chosenColor, textColor } from '../assets/round_badge_color.js';

const { colors, roundFormDefault } = JSON.parse(readFileSync(0, 'utf8'));

process.stdout.write(JSON.stringify(colors.map((color) => {
    const chosen = chosenColor(color, roundFormDefault);
    const luminance = chosen !== null ? apcaLuminance(chosen) : null;

    return {
        chosen,
        text: textColor(color),
        lc: luminance !== null ? [apcaContrast(0, luminance), apcaContrast(1, luminance)] : null,
    };
})));
