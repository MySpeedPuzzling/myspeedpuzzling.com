import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
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
            swatches: [
                '#fe696a',
                '#ffffff',
                '#000000',
                '#0d6efd',
                '#198754',
                '#ffc107',
                '#dc3545',
                '#6c757d',
            ],
        });
    }
}
