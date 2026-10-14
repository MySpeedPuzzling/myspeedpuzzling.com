// Runs assets/pull_indicator.js (the installed app's pull indicator per platform, docs/features/navigation README §4.13
// G17, GEST-19) through the cases tests/PullIndicatorScriptTest.php asks for and prints one JSON object {case: answer}.

import {
    DISC,
    INDICATOR_OVERSHOOT,
    INDICATOR_SIZE,
    indicatorClass,
    indicatorMarkup,
    IOS_CLASS,
    lookFor,
    pullFrame,
    SPINNER,
    SPINNER_TURN_DEGREES,
} from '../assets/pull_indicator.js';

function run(name, fn) {
    try {
        return [name, fn()];
    } catch (error) {
        return [name, { threw: String(error && error.stack ? error.stack : error) }];
    }
}

// Finger travel: nothing yet, a quarter, just short of the threshold (≈ 128 px), at it, far past it
const DISTANCES = [0, 32, 120, 128, 136, 300];

const results = Object.fromEntries([
    run('constants', () => ({ INDICATOR_SIZE, INDICATOR_OVERSHOOT, SPINNER_TURN_DEGREES, DISC, SPINNER, IOS_CLASS })),
    run('lookFor', () => ({ ios: lookFor('ios'), android: lookFor('android'), other: lookFor('other'), junk: lookFor(undefined) })),
    run('indicatorClass', () => ({ disc: indicatorClass(DISC), spinner: indicatorClass(SPINNER) })),
    run('markup', () => {
        const disc = indicatorMarkup(DISC);
        const spinner = indicatorMarkup(SPINNER);
        const count = (html, needle) => html.split(needle).length - 1;

        return {
            disc: { arrow: count(disc, 'class="pwa-ptr-arrow"'), spinner: count(disc, 'class="pwa-ptr-spinner"'), ios: count(disc, 'pwa-ptr-ios-spinner') },
            spinner: { arrow: count(spinner, 'pwa-ptr-arrow'), spinner: count(spinner, 'class="pwa-ptr-spinner"'), ios: count(spinner, 'class="pwa-ptr-ios-spinner"'), spokes: count(spinner, '<line ') },
            spokeOpacities: [...spinner.matchAll(/opacity="([\d.]+)"/g)].map((match) => Number(match[1])),
        };
    }),
    run('disc frames', () => Object.fromEntries(DISTANCES.map((distance) => [distance, pullFrame(DISC, distance)]))),
    run('spinner frames', () => Object.fromEntries(DISTANCES.map((distance) => [distance, pullFrame(SPINNER, distance)]))),
]);

process.stdout.write(JSON.stringify(results));
