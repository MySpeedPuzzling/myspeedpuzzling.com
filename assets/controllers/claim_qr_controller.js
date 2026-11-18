/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import { openClaimLinkDialog, requestClaimLink } from '../participants_sheet/claim_links.js';

/**
 * "Show QR" at the check-in desk (docs/features/competitions-management/participant-linking.md §7.4): a person on the
 * list not linked to a profile gets their personal claim link as a QR on the organiser's phone - scanned on the spot,
 * it links them. The link is made on demand through the participants sheet's endpoint (participants_sheet_claim_link,
 * claim_links.js). Values: url (the endpoint), csrf-token (`official_results`), texts (the dialog's words). Buttons:
 * `data-action="claim-qr#show"` with `data-participant-id` and `data-name` - inside the CompetitionCheckIn component,
 * which re-renders without touching this controller's element.
 */
export default class extends Controller {
    static values = {
        url: String,
        csrfToken: String,
        texts: Object,
    };

    async show(event) {
        const button = event.currentTarget;
        const participantId = button.dataset.participantId ?? '';
        const name = button.dataset.name ?? '';
        const texts = this.textsValue;

        button.disabled = true;

        try {
            const result = await requestClaimLink({ url: this.urlValue, csrfToken: this.csrfTokenValue }, participantId);

            if (!result.ok) {
                window.alert(result.message ?? texts.failed ?? '');

                return;
            }

            openClaimLinkDialog(document.body, {
                name,
                url: result.url,
                qr: result.qr,
                title: texts.title ?? '',
                help: texts.help ?? '',
                copyLabel: texts.copy ?? '',
                copiedLabel: texts.copied ?? '',
                closeLabel: texts.close ?? '',
                onClose: () => button.focus(),
            });
        } finally {
            button.disabled = false;
        }
    }
}
