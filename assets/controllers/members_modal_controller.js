/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import { MEMBERS_MODAL_EVENT, MEMBERS_PLACEMENT_PATTERN } from '../nav_instrumentation.js';

/**
 * The members modal (#membersExclusiveModal, base.html.twig) passes on which teaser opened it (docs/features/navigation
 * README §6 rule 5, TEASE-01). A teaser's lock carries data-members-placement (MembersPlacement: card.tier,
 * profile.tier); every time the modal opens:
 * - the free-trial form's hidden `placement` field (membership/_free_trial_start_form.html.twig) gets it - the form's
 *   `source` stays members_modal, StartFreeTrialController logs the placement once on the `navigation` channel;
 * - the membership links (`link` targets) get ?placement=<id> (none: the parameter is removed);
 * - one `msp:members-modal` {placement} on the document (assets/nav_instrumentation.js sends the `members_modal`
 *   beacon) - 'none' when the lock that opened it has no placement.
 * Measured, never stored (implementation plan decision 12). Lazy: the modal is on every page, the code loads apart from
 * the page's bundle. Bootstrap's show.bs.modal carries the clicked lock as relatedTarget.
 */
export default class extends Controller {
    static targets = ['placement', 'link'];

    connect() {
        this.element.addEventListener('show.bs.modal', this.show);
    }

    disconnect() {
        this.element.removeEventListener('show.bs.modal', this.show);
    }

    show = (event) => {
        const trigger = event.relatedTarget;
        const value = trigger && typeof trigger.getAttribute === 'function' ? trigger.getAttribute('data-members-placement') : null;
        const placement = typeof value === 'string' && MEMBERS_PLACEMENT_PATTERN.test(value) ? value : null;

        this.placementTargets.forEach((input) => {
            input.value = placement || '';
        });

        this.linkTargets.forEach((link) => {
            const href = link.getAttribute('href');

            if (!href) {
                return;
            }

            try {
                const url = new URL(href, window.location.href);

                if (placement) {
                    url.searchParams.set('placement', placement);
                } else {
                    url.searchParams.delete('placement');
                }

                link.setAttribute('href', url.pathname + url.search + url.hash);
            } catch (e) {
                // the link stays as rendered
            }
        });

        try {
            document.dispatchEvent(new CustomEvent(MEMBERS_MODAL_EVENT, { detail: { placement: placement || 'none' } }));
        } catch (e) {
            // a lost count, never a broken modal
        }
    };
}
