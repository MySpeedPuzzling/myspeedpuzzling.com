/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/**
 * The round form's "Members per team" (CompetitionRound::$teamSize, participants-spreadsheet.md D5): only team rounds
 * have it, so the field shows while the category "Team" is picked - hidden, its input is disabled too, so a number
 * typed before switching to another category is not sent. Sits on the element around the category radios and the
 * field; the server renders the field hidden for another category already (no flash before this loads), and ignores
 * a value sent for a solo or pair round.
 */
export default class extends Controller {
    static targets = ['field'];

    static values = {
        category: { type: String, default: 'team' },
    };

    connect() {
        this.toggle();
    }

    toggle() {
        const picked = this.element.querySelector('input[type="radio"]:checked');
        const isTeamRound = picked !== null && picked.value === this.categoryValue;

        this.fieldTargets.forEach((field) => {
            field.hidden = !isTeamRound;
            field.querySelectorAll('input').forEach((input) => {
                input.disabled = !isTeamRound;
            });
        });
    }
}
