import { Controller } from '@hotwired/stimulus';

/**
 * Colour picker on a text input (the round form's badge colour). With `clearLabel` it gets a button emptying the
 * input - an empty badge colour means "pick one automatically" (RoundBadgeColor).
 */
export default class extends Controller {
    static values = {
        clearLabel: String,
    };

    async connect() {
        const [{ default: Coloris }] = await Promise.all([
            import('@melloware/coloris'),
            import('@melloware/coloris/dist/coloris.css'),
        ]);

        Coloris.init();
        Coloris({
            el: '#' + this.element.id,
            format: 'hex',
            alpha: false,
            clearButton: this.clearLabelValue !== '',
            clearLabel: this.clearLabelValue,
            // No #fe696a: rounds stored with the old form default get their automatic colour (RoundBadgeColor),
            // so picking it would look like it did nothing. No white: a white badge disappears on the white page.
            swatches: [
                '#000000',
                '#0d6efd',
                '#198754',
                '#ffc107',
                '#dc3545',
                '#6f42c1',
                '#fd7e14',
                '#6c757d',
            ],
        });
    }
}
