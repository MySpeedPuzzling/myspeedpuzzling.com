/**
 * Link suggestions in the participants sheet (docs/features/competitions-management/participant-linking.md §7.2, D9,
 * R2, R7): who the people of the list without a profile may be, read from `participants_sheet_link_suggestions`
 * (GetParticipantLinkSuggestions - never part of the sheet's state) when the People tab's "Suggestions" filter is used.
 *
 * - requestSuggestions(endpoint): the call, resolving (never rejecting) to {ok: true, people} or {ok: false, reason};
 * - currentSuggestions(people, model): what is still true of an answer on the model now - people linked or removed
 *   meanwhile drop out, so do candidates linked to somebody else of the list meanwhile (a merge's self-joined row must
 *   still be there, linked to that player);
 * - rememberSuggested(model, people) / isSuggested(model, personId): the people the filter shows (kept per model, with
 *   their entries); suggestionFor(model, personId): one person's entry, still true now (the person editor - desktop
 *   panel and phone alike); onSuggested(model, listener): told when a new answer is remembered;
 * - loadSuggestions(model, endpoint): read + what is still true + remembered - the People views' one read per render;
 *   ensureSuggestions(model, endpoint): the same, only when this model has none yet and none is on its way (the person
 *   editor opened from a round tab - one read per page, never one per person);
 * - pretickedPlayer(entry): the one pre-tick rule (R2) - nothing name-based is ticked: only "Linked at another date"
 *   with a single candidate whose profile country is the person's, the earlier row named by its organiser (never a
 *   self-joined row's name - that is a free-text profile name);
 * - canMerge(candidate) / mergeRefusal(candidate): "Joined by themselves" merges the self-joined row into the
 *   organiser's row, unless that row holds what a merge would lose (R7 - "Merge by hand");
 * - suggestionAction(model, entries, selection): one group per ticked person (a refusal of one never stops the
 *   others): `player` (null → the player), or for a merge `[remove(selfJoinedRow), player(row, null → player)]` - one
 *   atomic group, in this order (§5.3). One undo step;
 * - openSuggestionsDialog(host, {...}): the dialog - per person name, country, the tier, the candidate (name, #CODE,
 *   country, signals) and a checkbox, several candidates = radios with none chosen; [Link N people].
 *
 * Pure except requestSuggestions() (network) and openSuggestionsDialog() (DOM) - pinned by
 * tests/participants-sheet-people/link-suggestions.mjs.
 */

import { officialResultsRequest } from '../official_results_api.js';
import { buildAction } from './sheet_changes.js';

export const JOINED_THEMSELVES = 'joined_themselves';
export const LINKED_BEFORE = 'linked_before';
export const NAME_COUNTRY = 'name_country';
export const TIERS = [JOINED_THEMSELVES, LINKED_BEFORE, NAME_COUNTRY];

// model → Map(participant id → the endpoint's entry), the listeners told of a new answer, the read on its way
const suggested = new WeakMap();
const listeners = new WeakMap();
const loading = new WeakMap();

/**
 * @param {{url: string, request?: function}} endpoint
 * @returns {Promise<{ok: true, people: Array<object>}|{ok: false, reason: string}>}
 */
export async function requestSuggestions({ url, request = officialResultsRequest }) {
    const answer = await request(url, { method: 'GET' });

    if (answer.kind === 'ok' && Array.isArray(answer.data?.people)) {
        return { ok: true, people: answer.data.people };
    }

    return { ok: false, reason: answer.kind === 'ok' ? 'server' : answer.kind };
}

function linkedTo(model, playerId) {
    return model.people().filter((person) => (person.player?.id ?? null) === playerId);
}

function stillTrue(model, candidate) {
    const playerId = candidate?.player?.id ?? null;

    if (playerId === null || !TIERS.includes(candidate.tier)) {
        return false;
    }

    const linked = linkedTo(model, playerId);

    if (candidate.tier === JOINED_THEMSELVES) {
        const selfId = candidate.selfJoined?.participant ?? null;

        // The self-joined row is still on the list, still theirs - and nobody else's row is
        return selfId !== null && linked.length === 1 && linked[0].id === selfId;
    }

    return linked.length === 0;
}

/**
 * @param {Array<object>} people the endpoint's `people`
 * @param {object} model
 * @returns {Array<object>} the same entries, only what is still true
 */
export function currentSuggestions(people, model) {
    const result = [];

    for (const entry of people ?? []) {
        const person = model.person(entry.participant);

        if (person === null || (person.removedAt ?? null) !== null || (person.player ?? null) !== null) {
            continue;
        }

        const candidates = (entry.candidates ?? []).filter((candidate) => stillTrue(model, candidate));

        if (candidates.length > 0) {
            result.push({ ...entry, name: person.name, country: person.country ?? null, candidates });
        }
    }

    return result;
}

/** The people the "Suggestions" filter shows - kept for this model, with their entries; the listeners are told. */
export function rememberSuggested(model, people) {
    suggested.set(model, new Map((people ?? []).map((entry) => [entry.participant, entry])));

    for (const listener of [...(listeners.get(model) ?? [])]) {
        listener();
    }
}

export function isSuggested(model, personId) {
    return suggested.get(model)?.has(personId) ?? false;
}

/** This model has an answer remembered (an empty one too). */
export function suggestionsKnown(model) {
    return suggested.has(model);
}

/**
 * One person's suggestion as it is true now (people linked meanwhile, candidates linked to somebody else of the list
 * meanwhile drop out - currentSuggestions()), else null.
 */
export function suggestionFor(model, personId) {
    const entry = suggested.get(model)?.get(personId) ?? null;

    return entry === null ? null : (currentSuggestions([entry], model)[0] ?? null);
}

/** `listener()` is called whenever an answer is remembered for this model; returns the unsubscribe. */
export function onSuggested(model, listener) {
    if (!listeners.has(model)) {
        listeners.set(model, new Set());
    }

    listeners.get(model).add(listener);

    return () => listeners.get(model)?.delete(listener);
}

/**
 * Read, keep what is still true, remember it. The read on its way is known to ensureSuggestions() (no second one).
 *
 * @returns {Promise<{ok: true, entries: Array<object>}|{ok: false, reason: string}>}
 */
export function loadSuggestions(model, endpoint) {
    const promise = requestSuggestions(endpoint).then((result) => {
        if (!result.ok) {
            return result;
        }

        const entries = currentSuggestions(result.people, model);
        rememberSuggested(model, entries);

        return { ok: true, entries };
    }).finally(() => {
        if (loading.get(model) === promise) {
            loading.delete(model);
        }
    });
    loading.set(model, promise);

    return promise;
}

/**
 * The remembered answer, else the read on its way, else loadSuggestions() - the person editor's one read per page.
 */
export function ensureSuggestions(model, endpoint) {
    if (suggestionsKnown(model)) {
        return Promise.resolve({ ok: true, entries: [...suggested.get(model).values()] });
    }

    return loading.get(model) ?? loadSuggestions(model, endpoint);
}

export function mergeRefusal(candidate) {
    return candidate?.tier === JOINED_THEMSELVES ? (candidate.selfJoined?.mergeRefusal ?? null) : null;
}

/** A candidate that may be ticked: anything but a merge the self-joined row refuses (R7). */
export function canMerge(candidate) {
    return candidate?.tier !== JOINED_THEMSELVES || (candidate.selfJoined?.participant != null && mergeRefusal(candidate) === null);
}

/**
 * R2: nothing name-based is pre-ticked - a self-joined row's name is free text too. Only "Linked at another date",
 * the only candidate, and the person's country is the profile's.
 *
 * @returns {string|null} the player id ticked when the dialog opens
 */
export function pretickedPlayer(entry) {
    const candidates = entry?.candidates ?? [];

    if (candidates.length !== 1) {
        return null;
    }

    const [candidate] = candidates;

    // The earlier row's name must be the organiser's: a self-joined row copied it from a free-text profile name, and a
    // renamed profile must never come pre-ticked
    return candidate.tier === LINKED_BEFORE && candidate.countryMatches === true && candidate.nameFromOrganiser === true ? candidate.player.id : null;
}

/** participant id → player id: the pre-ticked choices of a dialog. */
export function initialSelection(entries) {
    const selection = new Map();

    for (const entry of entries) {
        const playerId = pretickedPlayer(entry);

        if (playerId !== null) {
            selection.set(entry.participant, playerId);
        }
    }

    return selection;
}

/**
 * The changes of one accepted suggestion: a merge = remove the self-joined row, then link the organiser's row.
 */
export function suggestionChanges(entry, candidate) {
    const link = { op: 'player', participant: entry.participant, from: null, to: candidate.player.id, _player: { ...candidate.player } };

    if (candidate.tier === JOINED_THEMSELVES) {
        return [{ op: 'remove', participant: candidate.selfJoined.participant }, link];
    }

    return [link];
}

/**
 * @param {object} model
 * @param {Array<object>} entries currentSuggestions()
 * @param {Map<string, string>} selection participant id → the chosen player id
 * @param {object} [options] buildAction's (newId, countries)
 */
export function suggestionAction(model, entries, selection, options = {}) {
    const groups = [];

    for (const entry of entries) {
        const playerId = selection.get(entry.participant) ?? null;
        const candidate = entry.candidates.find((item) => item.player.id === playerId) ?? null;

        if (candidate !== null && canMerge(candidate)) {
            groups.push(suggestionChanges(entry, candidate));
        }
    }

    return buildAction(model, groups, { ...options, label: { key: 'link_suggestions', count: groups.length } });
}

function escape(text) {
    return String(text ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#39;');
}

function fill(text, params) {
    return Object.entries(params).reduce((result, [key, value]) => result.replaceAll(`%${key}%`, String(value ?? '')), String(text ?? ''));
}

/**
 * The candidate's line: MSP name, #CODE, country and the signals.
 *
 * @param {object} candidate
 * @param {object} labels see openSuggestionsDialog()
 */
export function candidateHtml(candidate, labels) {
    const player = candidate.player ?? {};
    const who = player.visible === true
        ? `${escape(player.name ?? `#${String(player.code ?? '').toUpperCase()}`)}${player.code ? ` <span class="text-muted">#${escape(String(player.code).toUpperCase())}</span>` : ''}${player.country ? ` <span class="text-muted">· ${escape(labels.country(player.country))}</span>` : ''}`
        : escape(labels.hiddenProfile);
    const signals = [];

    if (candidate.joinedAt) {
        signals.push(fill(labels.joinedAt, { date: labels.date(candidate.joinedAt) }));
    }

    if (candidate.linkedAt) {
        signals.push(fill(labels.linkedAt, { event: candidate.linkedAt.name, date: candidate.linkedAt.date ? labels.date(candidate.linkedAt.date) : '' }));
    }

    if (candidate.follows) {
        signals.push(labels.follows);
    }

    const refusal = mergeRefusal(candidate);

    return `<span class="link-suggestion__who">${who}</span>`
        + (signals.length > 0 ? `<span class="link-suggestion__signals small text-muted d-block">${signals.map(escape).join(' · ')}</span>` : '')
        + (refusal !== null ? `<span class="link-suggestion__refusal small text-warning-emphasis d-block" data-merge-refusal="${escape(refusal)}">${escape(labels.mergeByHand)}: ${escape(labels.refusal[refusal] ?? refusal)}</span>` : '');
}

/**
 * The dialog's rows: per person a checkbox (one candidate) or radios (several - none chosen, "Not now" is).
 */
export function suggestionsHtml(entries, selection, labels) {
    return entries.map((entry) => {
        const name = `${escape(entry.name)}${entry.country ? ` <span class="text-muted small">· ${escape(labels.country(entry.country))}</span>` : ''}`;
        const field = `link-suggestion-${escape(entry.participant)}`;

        if (entry.candidates.length === 1) {
            const [candidate] = entry.candidates;
            const checked = selection.get(entry.participant) === candidate.player.id;
            const disabled = !canMerge(candidate);

            return `<li class="link-suggestion list-group-item" data-suggestion="${escape(entry.participant)}">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="${field}" data-suggestion-choice value="${escape(candidate.player.id)}"${checked ? ' checked' : ''}${disabled ? ' disabled' : ''}>
                    <label class="form-check-label" for="${field}">
                        <span class="fw-semibold">${name}</span> <span class="badge text-bg-light" data-tier="${escape(candidate.tier)}">${escape(labels.tier[candidate.tier] ?? candidate.tier)}</span>
                        <span class="d-block">→ ${candidateHtml(candidate, labels)}</span>
                    </label>
                </div>
            </li>`;
        }

        const options = entry.candidates.map((candidate, index) => {
            const id = `${field}-${index}`;
            const checked = selection.get(entry.participant) === candidate.player.id;

            return `<div class="form-check">
                <input class="form-check-input" type="radio" name="${field}" id="${id}" data-suggestion-choice value="${escape(candidate.player.id)}"${checked ? ' checked' : ''}${canMerge(candidate) ? '' : ' disabled'}>
                <label class="form-check-label" for="${id}"><span class="badge text-bg-light" data-tier="${escape(candidate.tier)}">${escape(labels.tier[candidate.tier] ?? candidate.tier)}</span> ${candidateHtml(candidate, labels)}</label>
            </div>`;
        }).join('');
        const none = !selection.has(entry.participant);

        return `<li class="link-suggestion list-group-item" data-suggestion="${escape(entry.participant)}">
            <div class="fw-semibold mb-1">${name} <span class="small text-muted">${escape(labels.several)}</span></div>
            ${options}
            <div class="form-check">
                <input class="form-check-input" type="radio" name="${field}" id="${field}-none" data-suggestion-none value=""${none ? ' checked' : ''}>
                <label class="form-check-label text-muted" for="${field}-none">${escape(labels.notNow)}</label>
            </div>
        </li>`;
    }).join('');
}

/**
 * The suggestions dialog. `labels` = {title, help, empty, submit (with %count%), cancel, several, notNow, follows,
 * joinedAt (%date%), linkedAt (%event%, %date%), hiddenProfile, mergeByHand, tier: {tier: text}, refusal: {reason:
 * text}, country(code), date(iso)}. `onSubmit(selection)` gets participant id → player id of the ticked people; the
 * dialog closes first. Closed by Cancel, Esc or a click on the backdrop; removed from the page when closed.
 *
 * @returns {HTMLDialogElement}
 */
export function openSuggestionsDialog(host, { entries, labels, onSubmit = () => {}, onClose = () => {} }) {
    const selection = initialSelection(entries);
    const dialog = host.ownerDocument.createElement('dialog');
    dialog.className = 'link-suggestions-dialog';
    dialog.setAttribute('aria-label', labels.title);
    dialog.dataset.linkSuggestionsDialog = '';
    dialog.innerHTML = `
        <form method="dialog" class="link-suggestions-dialog__body">
            <h2 class="h5 mb-1">${escape(labels.title)}</h2>
            <p class="small text-muted mb-2">${escape(entries.length > 0 ? labels.help : labels.empty)}</p>
            <ul class="list-group mb-3" data-suggestions-list>${suggestionsHtml(entries, selection, labels)}</ul>
            <div class="d-flex justify-content-end gap-2">
                <button type="button" class="btn btn-secondary btn-sm" data-suggestions-cancel>${escape(labels.cancel)}</button>
                <button type="button" class="btn btn-primary btn-sm" data-suggestions-submit></button>
            </div>
        </form>`;
    host.append(dialog);

    const submit = dialog.querySelector('[data-suggestions-submit]');
    const sync = () => {
        submit.textContent = fill(labels.submit, { count: selection.size });
        submit.disabled = selection.size === 0;
    };
    let closed = false;
    const close = () => {
        if (closed) {
            return;
        }

        closed = true;

        if (dialog.open && typeof dialog.close === 'function') {
            dialog.close();
        }

        dialog.remove();
        onClose();
    };

    dialog.querySelector('[data-suggestions-list]').addEventListener('change', (event) => {
        const row = event.target.closest('[data-suggestion]');

        if (!row) {
            return;
        }

        const participantId = row.dataset.suggestion;

        if (event.target.matches('[data-suggestion-none]') || (event.target.type === 'checkbox' && !event.target.checked)) {
            selection.delete(participantId);
        } else if (event.target.matches('[data-suggestion-choice]') && event.target.checked) {
            selection.set(participantId, event.target.value);
        }

        sync();
    });
    submit.addEventListener('click', () => {
        const chosen = new Map(selection);
        close();
        onSubmit(chosen);
    });
    dialog.querySelector('[data-suggestions-cancel]').addEventListener('click', close);
    dialog.addEventListener('cancel', (event) => {
        event.preventDefault();
        close();
    });
    dialog.addEventListener('click', (event) => {
        if (event.target === dialog) {
            close();
        }
    });
    sync();

    if (typeof dialog.showModal === 'function') {
        dialog.showModal();
    } else {
        dialog.setAttribute('open', '');
    }

    return dialog;
}
