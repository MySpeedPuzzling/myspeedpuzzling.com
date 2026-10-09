/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/**
 * The add event / series form (templates/add_competition.html.twig, docs/features/organizations/README.md "Approval"):
 * under an approved organization a series or event is approved at once - then the "reviewed by an admin" sentence
 * gives way to its "no review needed" sibling and the button reads "Add event" / "Add series" instead of "Submit for
 * Approval". The server renders the state of the submitted form; this follows the organization select (its options
 * carry data-approved-at-once) and the "Recurring" box.
 */
export default class extends Controller {
    static targets = ['organization', 'recurring', 'review', 'direct', 'submit'];

    connect() {
        this.update();
    }

    update() {
        const option = this.hasOrganizationTarget ? this.organizationTarget.selectedOptions[0] : undefined;
        const atOnce = option !== undefined && option.dataset.approvedAtOnce === '1';

        this.reviewTargets.forEach((element) => {
            element.hidden = atOnce;
        });
        this.directTargets.forEach((element) => {
            element.hidden = !atOnce;
        });

        if (this.hasSubmitTarget) {
            const recurring = this.hasRecurringTarget && this.recurringTarget.checked;
            const label = atOnce
                ? (recurring ? this.submitTarget.dataset.seriesLabel : this.submitTarget.dataset.eventLabel)
                : this.submitTarget.dataset.reviewLabel;

            if (label) {
                this.submitTarget.textContent = label;
            }
        }
    }
}
