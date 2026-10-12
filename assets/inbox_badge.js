// The Inbox badge in the browser (docs/features/navigation INB-02, README §4.4 / D3): a realtime push is the
// `inbox_badge` Turbo Stream (templates/inbox/_badge_stream.html.twig) with the numbers only - for-you,
// conversations, requests, at. This applies them to every .inbox-badge instance and its enclosing Inbox link
// (templates/inbox/_link.html.twig: the target tab's href, the accessible name in the page's locale, data-bar-count).
// inboxBadgeState() is the twin of SpeedPuzzling\Web\Value\InboxBadgeParts (total, target, labelKind, labelCount) -
// pinned by tests/Value/InboxBadgeScriptTest.php.

import { chooseTranslation } from './translation_choice.js';

const DISPLAY_CAP = 99;

let lastPush = null;

export function inboxBadgeState({ forYou, conversations, requests }) {
    const messages = conversations + requests;
    const total = forYou + messages;

    let target = 'for_you';
    if (forYou === 0 && messages > 0) {
        target = conversations === 0 ? 'requests' : 'messages';
    }

    let labelKind = 'mixed';
    if (total === 0) {
        labelKind = 'none';
    } else if (forYou === 0) {
        labelKind = 'messages';
    } else if (messages === 0) {
        labelKind = 'for_you';
    }

    const labelCount = labelKind === 'messages' ? messages : (labelKind === 'for_you' ? forYou : total);

    return { parts: { total, for_you: forYou, messages }, target, labelKind, labelCount };
}

export function displayCount(count) {
    return count > DISPLAY_CAP ? `${DISPLAY_CAP}+` : String(count);
}

// The parts of a stream element's attributes; null when one is not a number (an unknown or broken push changes nothing)
export function partsFromAttributes(element) {
    const read = (name) => {
        const value = Number.parseInt(element.getAttribute(name) ?? '', 10);

        return Number.isInteger(value) && value >= 0 ? value : null;
    };

    const parts = { forYou: read('for-you'), conversations: read('conversations'), requests: read('requests') };

    if (parts.forYou === null || parts.conversations === null || parts.requests === null) {
        return null;
    }

    return { ...parts, at: read('at') ?? 0 };
}

export function applyInboxBadge(badges, parts) {
    const state = inboxBadgeState(parts);
    const links = new Set();

    for (const badge of badges) {
        const count = state.parts[badge.dataset.inboxPart ?? 'total'];

        if (count !== undefined) {
            badge.textContent = displayCount(count);
            badge.hidden = count === 0;
        }

        const link = badge.closest('a[data-inbox-link]');
        if (link !== null) {
            links.add(link);
        }
    }

    for (const link of links) {
        applyToLink(link, state);
    }
}

// StreamActions.inbox_badge: `this` is the <turbo-stream>
export function inboxBadgeStreamAction(stream) {
    const parts = partsFromAttributes(stream);

    if (parts === null) {
        return;
    }

    lastPush = parts;
    applyInboxBadge(stream.targetElements, parts);
}

// A page Turbo restored from a snapshot shows the numbers it was left with: re-apply the last push when it is newer
// than the page (both server clocks: the push's `at`, the page's msp-rendered-at). A page rendered after the push
// already has fresher numbers - the notifications page marks everything read without a push.
export function reapplyAfterRestore() {
    if (lastPush === null) {
        return;
    }

    const meta = document.querySelector('meta[name="msp-rendered-at"]');
    const renderedAt = meta === null ? Number.NaN : Number.parseInt(meta.getAttribute('content') ?? '', 10);

    if (Number.isInteger(renderedAt) && lastPush.at > renderedAt) {
        applyInboxBadge(document.querySelectorAll('.inbox-badge'), lastPush);
    }
}

function applyToLink(link, state) {
    const hrefs = readJson(link.dataset.inboxHrefs);
    const labels = readJson(link.dataset.inboxLabels);
    const href = hrefs?.[state.target];

    if (typeof href === 'string') {
        link.setAttribute('href', href);
    }

    const label = labels?.[state.labelKind];
    const text = label ? chooseTranslation(label.message, state.labelCount, label.locale) : null;

    if (typeof text === 'string') {
        link.setAttribute('aria-label', text);
    }

    link.dataset.barCount = String(state.parts.total);
}

function readJson(value) {
    if (typeof value !== 'string') {
        return null;
    }

    try {
        return JSON.parse(value);
    } catch {
        return null;
    }
}
