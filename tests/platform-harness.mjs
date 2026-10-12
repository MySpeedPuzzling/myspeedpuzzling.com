// Runs assets/platform.js (the one reader of the platform flags on <html>) for the cases tests/PlatformModuleTest.php
// hands over on stdin and prints what each function answered as JSON. A case is the attributes and classes of a fake
// <html> root, or `root: null` (nothing to read), or `noDocument: true` (the module's own default under node, where
// there is no document).

import { readFileSync } from 'node:fs';
import { isNativeApp, isStandalone, nativePlatform, os } from '../assets/platform.js';

const cases = JSON.parse(readFileSync(0, 'utf8'));

function fakeRoot(attributes = {}, classes = []) {
    return {
        getAttribute: (name) => (Object.prototype.hasOwnProperty.call(attributes, name) ? attributes[name] : null),
        classList: { contains: (name) => classes.includes(name) },
    };
}

function answers(root) {
    const call = (fn) => {
        try {
            return { value: root === undefined ? fn() : fn(root) };
        } catch (error) {
            return { threw: String(error) };
        }
    };

    return {
        isNativeApp: call(isNativeApp),
        nativePlatform: call(nativePlatform),
        os: call(os),
        isStandalone: call(isStandalone),
    };
}

const results = cases.map((testCase) => {
    let root;

    if (testCase.noDocument) {
        root = undefined;
    } else if (testCase.root === null) {
        root = null;
    } else {
        root = fakeRoot(testCase.attributes || {}, testCase.classes || []);
    }

    return { name: testCase.name, ...answers(root) };
});

process.stdout.write(JSON.stringify(results));
