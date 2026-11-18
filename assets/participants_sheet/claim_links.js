/**
 * Personal claim links in the organiser's tools (docs/features/competitions-management/participant-linking.md §7.1,
 * §7.4): the participants sheet's row actions (Copy claim link / Show claim QR / New link) and the check-in desk's
 * "Show QR" - one endpoint, POST `participants_sheet_claim_link` {participant, renew}, answering the link without a
 * language and its QR as inline SVG (made on demand, replaced on "New link").
 *
 * - claimLinkActions(person, {onlineLinks}): which actions a person's row offers - a claim link only for somebody on the
 *   list who is not linked to a profile; on an event with online timing the existing personal link instead (§5.6);
 * - requestClaimLink(endpoint, participantId, {renew}): the call, resolving (never rejecting) to
 *   {ok: true, url, qr} or {ok: false, reason, message};
 * - copyText(text, clipboard): the Clipboard API's write, resolving to true / false (refused, missing);
 * - openClaimLinkDialog(host, {...}): the dialog with the QR (for the desk: shown on the organiser's phone), the link
 *   in a read-only field to select and a Copy button.
 *
 * Pure except requestClaimLink() (network through official_results_api.js) and openClaimLinkDialog() (DOM) - pinned by
 * tests/participants-sheet-people/claim-links.mjs.
 */

import { officialResultsRequest } from '../official_results_api.js';

export const COPY = 'claim_copy';
export const QR = 'claim_qr';
export const RENEW = 'claim_renew';
export const COPY_ONLINE = 'online_copy';

/**
 * @param {object} person a state row
 * @param {{onlineLinks?: boolean}} [options] onlineLinks = a round of the event uses online timing (the people come with
 *        their personal links)
 * @returns {string[]}
 */
export function claimLinkActions(person, { onlineLinks = false } = {}) {
    if (!person || (person.removedAt ?? null) !== null || (person.player ?? null) !== null) {
        return [];
    }

    if (onlineLinks) {
        return typeof person.online?.link === 'string' && person.online.link !== '' ? [COPY_ONLINE] : [];
    }

    return [COPY, QR, RENEW];
}

/**
 * @param {{url: string, csrfToken: string, request?: function}} endpoint
 * @param {string} participantId
 * @param {{renew?: boolean}} [options]
 * @returns {Promise<{ok: true, url: string, qr: string}|{ok: false, reason: string, message: string|null}>}
 */
export async function requestClaimLink({ url, csrfToken, request = officialResultsRequest }, participantId, { renew = false } = {}) {
    const answer = await request(url, { method: 'POST', body: { participant: participantId, renew }, csrfToken });

    if (answer.kind === 'ok') {
        const data = answer.data ?? {};

        if (typeof data.url === 'string' && data.url !== '' && typeof data.qr === 'string') {
            return { ok: true, url: data.url, qr: data.qr };
        }

        return { ok: false, reason: 'server', message: null };
    }

    if (answer.kind === 'client' || answer.kind === 'forbidden') {
        return { ok: false, reason: typeof answer.data?.error === 'string' ? answer.data.error : answer.kind, message: typeof answer.data?.message === 'string' ? answer.data.message : null };
    }

    return { ok: false, reason: answer.kind, message: null };
}

/**
 * @param {string} text
 * @param {{writeText?: function}|undefined} clipboard navigator.clipboard
 * @returns {Promise<boolean>}
 */
export async function copyText(text, clipboard = globalThis.navigator?.clipboard) {
    if (!clipboard || typeof clipboard.writeText !== 'function') {
        return false;
    }

    try {
        await clipboard.writeText(text);

        return true;
    } catch (e) {
        return false;
    }
}

function escape(text) {
    return String(text ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#39;');
}

/**
 * The dialog of one person's claim link: the QR (the server's SVG of the link), the link in a read-only field and a
 * Copy button. Closed by its Close button, Esc or a click on the backdrop; removed from the page when closed.
 *
 * @param {HTMLElement} host
 * @param {{name: string, url: string, qr: string, title: string, help: string, copyLabel: string, copiedLabel: string,
 *          closeLabel: string, clipboard?: object, onClose?: function}} options
 * @returns {HTMLDialogElement}
 */
export function openClaimLinkDialog(host, { name, url, qr, title, help, copyLabel, copiedLabel, closeLabel, clipboard = undefined, onClose = () => {} }) {
    const dialog = host.ownerDocument.createElement('dialog');
    dialog.className = 'claim-link-dialog';
    dialog.setAttribute('aria-label', title);
    dialog.dataset.claimLinkDialog = '';
    dialog.innerHTML = `
        <div class="claim-link-dialog__body">
            <h2 class="h5 mb-1">${escape(title)}</h2>
            <p class="fw-semibold mb-2" data-claim-link-name>${escape(name)}</p>
            <div class="claim-link-dialog__qr mx-auto mb-2" data-claim-link-qr>${qr}</div>
            <p class="small text-muted mb-2">${escape(help)}</p>
            <div class="input-group input-group-sm mb-3">
                <input type="text" class="form-control" readonly value="${escape(url)}" aria-label="${escape(title)}" data-claim-link-url>
                <button type="button" class="btn btn-outline-primary" data-claim-link-copy>${escape(copyLabel)}</button>
            </div>
            <div class="text-end"><button type="button" class="btn btn-secondary btn-sm" data-claim-link-close>${escape(closeLabel)}</button></div>
        </div>`;
    host.append(dialog);

    const close = () => {
        if (dialog.open && typeof dialog.close === 'function') {
            dialog.close();
        }

        dialog.remove();
        onClose();
    };
    const field = dialog.querySelector('[data-claim-link-url]');
    const copy = dialog.querySelector('[data-claim-link-copy]');

    copy.addEventListener('click', async () => {
        if (await copyText(url, clipboard)) {
            copy.textContent = copiedLabel;
        } else {
            // Refused or missing: the field selected, the system's own copy menu takes over
            field.focus();
            field.select();
        }
    });
    field.addEventListener('focus', () => field.select());
    dialog.querySelector('[data-claim-link-close]').addEventListener('click', close);
    dialog.addEventListener('cancel', (event) => {
        event.preventDefault();
        close();
    });
    dialog.addEventListener('click', (event) => {
        if (event.target === dialog) {
            close();
        }
    });

    if (typeof dialog.showModal === 'function') {
        dialog.showModal();
    } else {
        dialog.setAttribute('open', '');
    }

    return dialog;
}
