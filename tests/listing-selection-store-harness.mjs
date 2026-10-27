// Runs assets/listing_selection_store.js for tests/ListingSelectionStoreScriptTest.php: reads a list of steps as JSON on
// stdin, prints what the store answered after each one. A fake storage (an object, or one that always throws) and a
// clock the steps move stand in for the browser.
import { readFileSync } from 'node:fs';
import { createListingSelectionStore } from '../assets/listing_selection_store.js';

const steps = JSON.parse(readFileSync(0, 'utf8'));

let now = 1_000_000_000_000;
const data = {};
const workingStorage = {
    getItem: (key) => (key in data ? data[key] : null),
    setItem: (key, value) => { data[key] = String(value); },
    removeItem: (key) => { delete data[key]; },
};
const throwingStorage = {
    getItem: () => { throw new Error('denied'); },
    setItem: () => { throw new Error('denied'); },
    removeItem: () => { throw new Error('denied'); },
};

const stores = {};
const results = [];

for (const step of steps) {
    const name = step.store ?? 'a';

    if (step.op === 'open') {
        stores[name] = createListingSelectionStore(step.throwing ? throwingStorage : workingStorage, step.key ?? 'k', () => now);
    } else if (step.op === 'advance') {
        now += step.ms;
    } else if (step.op === 'add') {
        results.push(stores[name].add(step.id, step.seller));
        continue;
    } else if (step.op === 'remove') {
        stores[name].remove(step.id);
    } else if (step.op === 'clear') {
        stores[name].clear();
    }

    if (step.op !== 'advance') {
        results.push({ ids: stores[name].ids(), sellers: stores[name].sellerCount() });
    }
}

process.stdout.write(JSON.stringify(results));
