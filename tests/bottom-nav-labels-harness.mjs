// Runs assets/bottom_nav_labels.js (the bottom bar's label steps, NAV-02) through the cases
// tests/BottomNavLabelsScriptTest.php asks for and prints one JSON object {case: result}.
// A tab at 360 px has 70 px of room (72 px minus 2 × 1 px padding), at 320 px 62 px.

import { chooseStep, readStep, SAFETY_PX, STEP_10PX, STEP_11PX, STEP_CURRENT_ONLY } from '../assets/bottom_nav_labels.js';

const results = {
    constants: { STEP_11PX, STEP_10PX, STEP_CURRENT_ONLY, SAFETY_PX },

    // Widths measured in the step-1 font (11 px)
    'everything fits at 11 px': chooseStep({ cssStep: 1, room: 70, widths: [31, 41, 21, 62, 34] }),
    'the widest label is exactly at the room': chooseStep({ cssStep: 1, room: 62.5, widths: [62] }),
    'half a pixel too wide at 11 px → 10 px': chooseStep({ cssStep: 1, room: 62, widths: [61.6] }),
    'French Communauté at 360 → 10 px': chooseStep({ cssStep: 1, room: 70, widths: [39, 41, 40, 71.8, 28] }),
    'French Communauté at 320 → current label only': chooseStep({ cssStep: 1, room: 62, widths: [39, 41, 40, 71.8, 28] }),
    'a wider fallback font for Japanese at 360 → 10 px': chooseStep({ cssStep: 1, room: 70, widths: [33, 33, 22, 74, 55] }),

    // Widths measured in the step-2 font (10 px): CSS already chose step 2
    'CSS step 2 and the labels fit: stays 2': chooseStep({ cssStep: 2, room: 62, widths: [28, 37, 19, 56, 31] }),
    'CSS step 2 never goes back to 1, even with room to spare': chooseStep({ cssStep: 2, room: 100, widths: [20, 20, 20, 20, 20] }),
    'CSS step 2 but a label still overflows at 10 px → 3': chooseStep({ cssStep: 2, room: 62, widths: [28, 37, 19, 65.2, 31] }),

    // Step 3 is the last: nothing to raise
    'CSS step 3 stays 3': chooseStep({ cssStep: 3, room: 62, widths: [1, 1, 1, 65, 1] }),

    // Nothing to measure: CSS decides
    'no labels': chooseStep({ cssStep: 1, room: 70, widths: [] }),
    'no room (bar not laid out)': chooseStep({ cssStep: 2, room: 0, widths: [80] }),
    'junk widths are ignored': chooseStep({ cssStep: 1, room: 70, widths: [NaN, -1, 'x', null] }),
    'junk widths next to real ones': chooseStep({ cssStep: 1, room: 62, widths: [NaN, 65] }),
    'no argument at all': chooseStep(),
    'junk css step counts as 1': chooseStep({ cssStep: 'x', room: 62, widths: [61.6] }),

    readStep: ['1', ' 2', '3 ', '', '4', '0', 'x', null, undefined, 2].map(readStep),
};

process.stdout.write(JSON.stringify(results));
