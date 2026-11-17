/**
 * The results desk's qualification helpers (docs/features/competitions-management/results-desk.md). Qualification is
 * always the organiser's explicit decision: these functions only PRE-SELECT entries for review, and the desk applies
 * the reviewed selection as ordinary `qualified` changes (from → to), so a mark somebody else changed meanwhile comes
 * back as a conflict. Pinned by tests/OfficialResultsDeskHelpersTest.php.
 *
 * Entries are the desk's ranked entries: {ref, rank (null = unranked), qualified, countries: [code, ...]}. Only ranked
 * entries (finished or unfinished) are ever proposed - did not start and no result yet never are.
 */

function ranked(entries) {
    return entries
        .filter((entry) => Number.isInteger(entry.rank))
        .sort((a, b) => a.rank - b.rank);
}

/**
 * The best `count` of a list in rank order - plus everybody sharing the last place that fits. When that shared
 * place takes the list past `count`, those entries are "tied at the cut": proposed, but highlighted for the organiser.
 */
function cut(list, count) {
    if (count < 1 || list.length === 0) {
        return { selected: [], tiedAtCut: [] };
    }

    if (list.length <= count) {
        return { selected: list, tiedAtCut: [] };
    }

    const cutRank = list[count - 1].rank;
    const selected = list.filter((entry) => entry.rank <= cutRank);
    const tiedAtCut = selected.length > count ? selected.filter((entry) => entry.rank === cutRank) : [];

    return { selected, tiedAtCut };
}

/**
 * "Top N" by the computed rank.
 *
 * @returns {{selected: string[], tiedAtCut: string[]}} refs
 */
export function topN(entries, count) {
    const { selected, tiedAtCut } = cut(ranked(entries), Math.floor(Number(count) || 0));

    return {
        selected: selected.map((entry) => entry.ref),
        tiedAtCut: tiedAtCut.map((entry) => entry.ref),
    };
}

/**
 * "Best of each country": the best `perCountry` entries of every country among entries with a ranked result. A
 * pair/team counts for each of its members' countries (`countries`). Entries without any country are listed apart
 * for the organiser to decide - never proposed.
 *
 * @returns {{countries: Array<{country: string, selected: string[], tiedAtCut: string[]}>, selected: string[],
 *            tiedAtCut: string[], withoutCountry: string[]}} countries ordered by their best entry, then code
 */
export function bestOfEachCountry(entries, perCountry) {
    const count = Math.floor(Number(perCountry) || 0);
    const byCountry = new Map();
    const withoutCountry = [];

    for (const entry of ranked(entries)) {
        const countries = [...new Set((entry.countries ?? []).filter((country) => typeof country === 'string' && country !== ''))];

        if (countries.length === 0) {
            withoutCountry.push(entry.ref);
            continue;
        }

        for (const country of countries) {
            if (!byCountry.has(country)) {
                byCountry.set(country, []);
            }

            byCountry.get(country).push(entry);
        }
    }

    const perCountryResults = [...byCountry.entries()]
        .map(([country, list]) => ({ country, best: list[0].rank, ...cut(list, count) }))
        .sort((a, b) => a.best - b.best || (a.country < b.country ? -1 : (a.country > b.country ? 1 : 0)))
        .map(({ country, selected, tiedAtCut }) => ({
            country,
            selected: selected.map((entry) => entry.ref),
            tiedAtCut: tiedAtCut.map((entry) => entry.ref),
        }));

    const unique = (lists) => [...new Set(lists.flat())];

    return {
        countries: perCountryResults,
        selected: unique(perCountryResults.map((result) => result.selected)),
        tiedAtCut: unique(perCountryResults.map((result) => result.tiedAtCut)),
        withoutCountry,
    };
}

/**
 * What applying a selection changes: entries to mark, and - unless the organiser keeps the other current marks -
 * qualified entries outside the selection to unmark. `isQualified(entry)` = what the desk shows now (incl. its own
 * unsaved changes).
 *
 * @param {Iterable<string>} selectedRefs
 * @returns {{mark: string[], unmark: string[]}}
 */
export function qualificationDiff(entries, selectedRefs, keepOthers, isQualified = (entry) => entry.qualified === true) {
    const selected = new Set(selectedRefs);
    const mark = [];
    const unmark = [];

    for (const entry of entries) {
        const qualified = isQualified(entry);

        if (selected.has(entry.ref) && !qualified) {
            mark.push(entry.ref);
        } else if (!selected.has(entry.ref) && qualified && !keepOthers) {
            unmark.push(entry.ref);
        }
    }

    return { mark, unmark };
}

/**
 * "Seat them now" after advancing: the advanced entries of one target round get the lowest free table numbers, in the
 * plan's seed order (fastest first, or slowest first on request). Entries the round had before keep their numbers;
 * an advanced entry that has a number already (somebody seated it meanwhile) keeps it.
 *
 * @param {Array<{ref: string, tableNumber: number|null}>} targetEntries the target round as it is now
 * @param {string[]} refsInSeedOrder the plan's created entries of this round, fastest first
 * @returns {Array<{entry: string, from: null, number: number}>} `from` = no table, as the target round has it now
 */
export function seatAdvanced(targetEntries, refsInSeedOrder, slowestFirst = false) {
    const byRef = new Map(targetEntries.map((entry) => [entry.ref, entry]));
    const taken = new Set(targetEntries
        .map((entry) => entry.tableNumber)
        .filter((number) => Number.isInteger(number)));

    const order = slowestFirst ? [...refsInSeedOrder].reverse() : refsInSeedOrder;
    const assignments = [];
    let next = 1;

    for (const ref of order) {
        const entry = byRef.get(ref);

        if (entry === undefined || Number.isInteger(entry.tableNumber)) {
            continue;
        }

        while (taken.has(next)) {
            next++;
        }

        if (next > 9999) {
            break;
        }

        assignments.push({ entry: ref, from: null, number: next });
        taken.add(next);
    }

    return assignments;
}

/**
 * "Mark top N overall" of an advancement step (competition toolkit A8): the server's answer
 * (official_results_advancement_step_preset - AdvancementSeeding over all the step's source rounds, saved results)
 * narrowed to this desk's round: only its own ranked entries are proposed, the ties at the cut highlighted like Top N.
 *
 * @param {{selected?: Array<{ref: string}>, tiedAtCut?: string[]}|null} answer
 * @returns {{selected: string[], tiedAtCut: string[]}} refs
 */
export function overallForRound(answer, entries) {
    const own = new Set(ranked(entries).map((entry) => entry.ref));
    const selected = (Array.isArray(answer?.selected) ? answer.selected : [])
        .map((item) => item?.ref)
        .filter((ref) => typeof ref === 'string' && own.has(ref));
    const tiedAtCut = (Array.isArray(answer?.tiedAtCut) ? answer.tiedAtCut : [])
        .filter((ref) => typeof ref === 'string' && own.has(ref));

    return { selected: [...new Set(selected)], tiedAtCut: [...new Set(tiedAtCut)] };
}

const DISTRIBUTIONS = ['single', 'balanced', 'by_source'];

/**
 * What the Advance dialog starts with for a declared advancement step (competition toolkit A8) - the step's settings
 * checked against the rounds the dialog knows (a round gone, or of another category now, is left out; a distribution
 * that no longer fits the targets is left for the organiser to choose). The dry run, plan hash and apply work as for
 * a choice made by hand.
 *
 * @param {object} step AdvancementStepOverview's JSON
 * @param {Array<{id: string, category: string}>} rounds
 * @returns {{sources: string[], targets: string[], distribution: string|null, targetBySource: Object<string, string>,
 *            countryRule: number|null}|null} null = nothing usable (no source or no target left)
 */
export function prefillFromStep(step, rounds) {
    if (step === null || typeof step !== 'object' || !Array.isArray(rounds)) {
        return null;
    }

    const known = new Map(rounds.map((round) => [String(round.id).toLowerCase(), round]));
    const ids = (list) => (Array.isArray(list) ? [...new Set(list
        .filter((id) => typeof id === 'string')
        .map((id) => id.toLowerCase())
        .filter((id) => known.has(id)))] : []);

    const sourceIds = ids(step.sourceRoundIds);
    const category = sourceIds.length > 0 ? known.get(sourceIds[0]).category : null;
    const sources = sourceIds.filter((id) => known.get(id).category === category);
    const targets = ids(step.targetRoundIds).filter((id) => known.get(id).category === category && !sources.includes(id));

    if (sources.length === 0 || targets.length === 0) {
        return null;
    }

    let distribution = DISTRIBUTIONS.includes(step.distribution) ? step.distribution : null;

    if ((distribution === 'single' && targets.length !== 1) || (distribution === 'balanced' && targets.length < 2)) {
        distribution = null;
    }

    const original = (id) => known.get(id).id;
    const targetBySource = {};

    if (distribution === 'by_source' && step.targetBySource !== null && typeof step.targetBySource === 'object') {
        const map = Object.fromEntries(Object.entries(step.targetBySource).map(([source, target]) => [source.toLowerCase(), String(target).toLowerCase()]));

        for (const source of sources) {
            if (targets.includes(map[source])) {
                targetBySource[original(source)] = original(map[source]);
            }
        }
    }

    const country = Number.isInteger(step.bestOfEachCountry) && step.bestOfEachCountry >= 1 ? Math.min(99, step.bestOfEachCountry) : null;

    return {
        sources: sources.map(original),
        targets: targets.map(original),
        distribution,
        targetBySource,
        countryRule: country,
    };
}
