/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/**
 * A scanned barcode (`barcode-scanner:scanned`) into a code list of one input per code (puzzle/_code_inputs.html.twig,
 * optional_rows_controller.js): a code already listed only gets focus, otherwise it goes into the first empty input,
 * or into a new row when every input holds a code.
 */
export default class extends Controller {
    static targets = ['codes'];

    fill(event) {
        const code = event.detail?.code;

        if (!code || !this.hasCodesTarget) {
            return;
        }

        const inputs = () => Array.from(this.codesTarget.querySelectorAll('[data-optional-rows-target="row"] input'));
        const listed = inputs().find((input) => this.normalize(input.value) === this.normalize(code));

        if (listed) {
            listed.focus();
            return;
        }

        let input = inputs().find((candidate) => candidate.value.trim() === '');

        if (!input) {
            const rows = this.application.getControllerForElementAndIdentifier(this.codesTarget, 'optional-rows');
            input = rows?.appendRow()?.querySelector('input') ?? null;
            rows?.dispatch('change');
        }

        if (!input) {
            return;
        }

        input.value = code;
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
        input.focus();
    }

    // A barcode is stored without its leading zeros (EanList) - "0045..." and "45..." are the same code. Anything else
    // in an EAN input (a catalogue number, letters) is kept as typed, so it is compared as typed.
    normalize(value) {
        const trimmed = value.trim();

        return /^\d+$/.test(trimmed) ? trimmed.replace(/^0+/, '') : trimmed;
    }
}
