// Personal claim links in the organiser's tools (assets/participants_sheet/claim_links.js, participant-linking.md §7.1,
// §7.4): which actions a row offers, the endpoint call and its answers, the clipboard and the QR dialog (jsdom).
import assert from 'node:assert/strict';
import { setupDom } from '../participants-sheet-core/dom.mjs';
import { person } from '../participants-sheet-core/fixture.mjs';
import { COPY, COPY_ONLINE, QR, RENEW, claimLinkActions, copyText, openClaimLinkDialog, requestClaimLink } from '../../assets/participants_sheet/claim_links.js';

const linked = { id: 'pl-1', visible: true, name: 'Kim', code: 'kim', country: null, avatar: null, profileUrl: null };

function endpoint(answer) {
    const calls = [];

    return {
        calls,
        url: '/en/participants-sheet-api/c1/claim-link',
        csrfToken: 'csrf-1',
        request: async (url, options) => {
            calls.push({ url, ...options });

            return answer;
        },
    };
}

const LABELS = { title: 'claim_dialog_title', help: 'claim_dialog_help', copyLabel: 'copy', copiedLabel: 'copied', closeLabel: 'close' };

export default function (test) {
    test('a person on the list not linked to a profile gets Copy / QR / New link; linked and removed people nothing', () => {
        assert.deepEqual(claimLinkActions(person('a', 'Ana')), [COPY, QR, RENEW]);
        assert.deepEqual(claimLinkActions(person('a', 'Ana', { player: linked })), []);
        assert.deepEqual(claimLinkActions(person('a', 'Ana', { removedAt: '2026-10-01T10:00:00+00:00' })), []);
        assert.deepEqual(claimLinkActions(null), []);
    });

    test('an event with online timing: the personal link instead, when the person has one (§5.6)', () => {
        const withLink = person('a', 'Ana', { online: { link: 'https://example.test/en/compete/Tok' } });
        assert.deepEqual(claimLinkActions(withLink, { onlineLinks: true }), [COPY_ONLINE]);
        assert.deepEqual(claimLinkActions(person('a', 'Ana', { online: { link: null } }), { onlineLinks: true }), []);
        assert.deepEqual(claimLinkActions(person('a', 'Ana', { player: linked, online: { link: 'x' } }), { onlineLinks: true }), []);
    });

    test('the link is asked for with the person and renew, the CSRF token sent; its answer read', async () => {
        const server = endpoint({ kind: 'ok', status: 200, data: { participant: 'a', url: 'https://example.test/claim/Tok', qr: '<svg></svg>' } });

        assert.deepEqual(await requestClaimLink(server, 'a'), { ok: true, url: 'https://example.test/claim/Tok', qr: '<svg></svg>' });
        assert.deepEqual(server.calls[0], { url: '/en/participants-sheet-api/c1/claim-link', method: 'POST', body: { participant: 'a', renew: false }, csrfToken: 'csrf-1' });

        await requestClaimLink(server, 'a', { renew: true });
        assert.equal(server.calls[1].body.renew, true);
    });

    test('refusals come back with the server\'s reason and message; anything else as its kind', async () => {
        assert.deepEqual(
            await requestClaimLink(endpoint({ kind: 'client', status: 409, data: { error: 'already_linked', message: 'Linked already' } }), 'a'),
            { ok: false, reason: 'already_linked', message: 'Linked already' },
        );
        assert.deepEqual(await requestClaimLink(endpoint({ kind: 'forbidden', status: 403, data: { error: 'forbidden' } }), 'a'), { ok: false, reason: 'forbidden', message: null });
        assert.deepEqual(await requestClaimLink(endpoint({ kind: 'offline' }), 'a'), { ok: false, reason: 'offline', message: null });
        assert.deepEqual(await requestClaimLink(endpoint({ kind: 'auth', status: 401 }), 'a'), { ok: false, reason: 'auth', message: null });
        // A 200 without a link is no link
        assert.deepEqual(await requestClaimLink(endpoint({ kind: 'ok', status: 200, data: {} }), 'a'), { ok: false, reason: 'server', message: null });
    });

    test('the clipboard: written, refused or missing', async () => {
        const written = [];
        assert.equal(await copyText('x', { writeText: async (text) => written.push(text) }), true);
        assert.deepEqual(written, ['x']);
        assert.equal(await copyText('x', { writeText: async () => { throw new Error('NotAllowedError'); } }), false);
        assert.equal(await copyText('x', undefined), false);
        assert.equal(await copyText('x', {}), false);
    });

    test('the dialog shows the QR, the link to select and copies it; closed it is gone', async () => {
        setupDom();
        let closed = 0;
        const written = [];
        const dialog = openClaimLinkDialog(document.body, {
            name: 'Ana <Example>',
            url: 'https://example.test/claim/Tok',
            qr: '<svg data-test-qr></svg>',
            ...LABELS,
            clipboard: { writeText: async (text) => written.push(text) },
            onClose: () => closed++,
        });

        assert.ok(dialog.hasAttribute('open'));
        assert.equal(dialog.querySelector('[data-claim-link-name]').textContent, 'Ana <Example>');
        assert.ok(dialog.querySelector('[data-claim-link-qr] svg[data-test-qr]'));
        assert.equal(dialog.querySelector('[data-claim-link-url]').value, 'https://example.test/claim/Tok');

        dialog.querySelector('[data-claim-link-copy]').click();
        await new Promise((resolve) => setTimeout(resolve, 0));
        assert.deepEqual(written, ['https://example.test/claim/Tok']);
        assert.equal(dialog.querySelector('[data-claim-link-copy]').textContent, 'copied');

        dialog.querySelector('[data-claim-link-close]').click();
        assert.equal(document.querySelector('[data-claim-link-dialog]'), null);
        assert.equal(closed, 1);
    });

    test('the clipboard refusing: the link is selected for the system\'s own copy', async () => {
        setupDom();
        const dialog = openClaimLinkDialog(document.body, { name: 'Ana', url: 'https://example.test/claim/Tok', qr: '', ...LABELS, clipboard: undefined });
        const field = dialog.querySelector('[data-claim-link-url]');

        dialog.querySelector('[data-claim-link-copy]').click();
        await new Promise((resolve) => setTimeout(resolve, 0));
        assert.equal(document.activeElement, field);
        assert.equal(dialog.querySelector('[data-claim-link-copy]').textContent, 'copy');
    });
}
