// Runs assets/round_badge_color.js (the browser half of RoundBadgeColor) for the colours
// tests/RoundBadgeColorParityTest.php hands over on stdin, and prints the results as JSON.

import { readFileSync } from 'node:fs';
import { chosenColor, textColor } from '../assets/round_badge_color.js';

const { colors, roundFormDefault } = JSON.parse(readFileSync(0, 'utf8'));

process.stdout.write(JSON.stringify(colors.map((color) => ({
    chosen: chosenColor(color, roundFormDefault),
    text: textColor(color),
}))));
