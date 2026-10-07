/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import { chosenColor, textColor } from '../round_badge_color.js';

/**
 * The round form's badge preview: the round's name on the colour the event pages will show it in, with the text
 * colour they pick for contrast (RoundBadgeColor in PHP, assets/round_badge_color.js here). Without a colour of
 * its own the round gets its automatic one (`automaticColor`, worked out on the server from its place in the
 * schedule) and the "picked automatically" note shows. Sits on the form - it follows every input.
 */
export default class extends Controller {
    static targets = ['name', 'color', 'badge', 'automaticNote'];

    static values = {
        automaticColor: String,
        roundFormDefault: String,
        placeholder: String,
    };

    connect() {
        this.update();
    }

    update() {
        if (!this.hasBadgeTarget) {
            return;
        }

        const name = this.hasNameTarget ? this.nameTarget.value.trim() : '';
        const chosen = this.hasColorTarget ? chosenColor(this.colorTarget.value, this.roundFormDefaultValue) : null;
        const background = chosen ?? this.automaticColorValue;

        this.badgeTarget.textContent = name !== '' ? name : this.placeholderValue;
        this.badgeTarget.style.backgroundColor = background;
        this.badgeTarget.style.color = textColor(background);

        if (this.hasAutomaticNoteTarget) {
            this.automaticNoteTarget.hidden = chosen !== null;
        }
    }
}
