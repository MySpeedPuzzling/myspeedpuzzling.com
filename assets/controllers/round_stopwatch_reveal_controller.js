/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/**
 * "Reveal the puzzles" on the round stopwatch's control page (templates/manage_round_stopwatch.html.twig,
 * docs/features/competitions-management/toolkit/A-round-formats.md §4.3): one checkbox, unticked on every page load,
 * for every start form of the page (`data-stopwatch-reveal-start`: Start, Start in 10 s, Start at). Ticked, a start
 * asks once (confirm) and sends `reveal=1` + the list it confirmed (`reveal_hash`); the start buttons say they reveal.
 * Without this script the box does nothing - a start then reveals nothing, the safe side.
 */
export default class extends Controller {
    static targets = ['checkbox'];

    static values = {
        hash: String,
        confirm: String,
        suffix: String,
    };

    connect() {
        // Never ticked by the browser restoring a form (back button, a reload keeping the form state)
        this.checkboxTarget.checked = false;
        this.toggle();
    }

    toggle() {
        const ticked = this.checkboxTarget.checked;

        this.element.querySelectorAll('form[data-stopwatch-reveal-start] button[type="submit"]').forEach((button) => {
            let suffix = button.querySelector('[data-stopwatch-reveal-suffix]');

            if (ticked && suffix === null) {
                suffix = document.createElement('span');
                suffix.dataset.stopwatchRevealSuffix = '';
                suffix.textContent = ` + ${this.suffixValue}`;
                button.append(suffix);
            } else if (!ticked && suffix !== null) {
                suffix.remove();
            }
        });
    }

    submit(event) {
        const form = event.target;

        if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-stopwatch-reveal-start')) {
            return;
        }

        form.querySelectorAll('input[data-stopwatch-reveal-field]').forEach((input) => input.remove());

        if (!this.checkboxTarget.checked) {
            return;
        }

        if (!window.confirm(this.confirmValue)) {
            event.preventDefault();

            return;
        }

        form.append(this.hidden('reveal', '1'), this.hidden('reveal_hash', this.hashValue));
    }

    hidden(name, value) {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = value;
        input.dataset.stopwatchRevealField = '';

        return input;
    }
}
