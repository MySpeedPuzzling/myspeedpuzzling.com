// Runs the inline data-os head script (assets/head/platform_os.js, handed over by tests/PlatformOsScriptTest.php
// exactly as a page rendered it) once per case in a fake browser - a navigator with the case's user agent, UA-CH
// platform and touch points, a CSS.supports() that knows -webkit-touch-callout or not (or no CSS at all) - and prints
// the data-os value it set per case as JSON (null = it set nothing; `threw` = the script threw).

const fs = require('fs');
const vm = require('vm');

const { script, cases } = JSON.parse(fs.readFileSync(0, 'utf8'));

const results = cases.map((testCase) => {
    const attributes = {};
    const navigator = { userAgent: testCase.ua, maxTouchPoints: testCase.maxTouchPoints || 0 };

    if (testCase.uaCh !== null && testCase.uaCh !== undefined) {
        navigator.userAgentData = { platform: testCase.uaCh, mobile: false, brands: [] };
    }

    const sandbox = {
        document: {
            documentElement: {
                setAttribute: (name, value) => { attributes[name] = String(value); },
                getAttribute: (name) => (name in attributes ? attributes[name] : null),
            },
        },
    };

    if (testCase.brokenNavigator) {
        Object.defineProperty(sandbox, 'navigator', { get() { throw new Error('navigator is not available'); } });
    } else {
        sandbox.navigator = navigator;
    }

    if (testCase.css !== 'none') {
        sandbox.CSS = {
            supports: (property, value) => testCase.css === 'touch-callout' && property === '-webkit-touch-callout' && value === 'none',
        };
    }

    sandbox.window = sandbox;

    try {
        vm.runInNewContext(script, sandbox);

        return { name: testCase.name, os: 'data-os' in attributes ? attributes['data-os'] : null };
    } catch (error) {
        return { name: testCase.name, os: null, threw: String(error) };
    }
});

process.stdout.write(JSON.stringify(results));
