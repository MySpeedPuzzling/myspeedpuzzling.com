/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/**
 * Rich text of a page section (page_section_form.html.twig). Quill 2 is loaded here only - it is heavy and only this
 * form needs it. The hidden textarea gets Quill's getSemanticHTML(): real <ul>/<ol> lists (Quill's own root.innerHTML
 * stores a bullet list as <ol><li data-list="bullet">, which the server's sanitizer would turn into a numbered list),
 * with ordinary spaces instead of the non-breaking ones Quill puts between words. The server sanitises it again on every
 * save with the same allow-list the toolbar offers (config/packages/html_sanitizer.php).
 */
export default class extends Controller {
    static targets = ['editor', 'input'];

    async connect() {
        const [{ default: Quill }] = await Promise.all([
            import('quill'),
            import('quill/dist/quill.snow.css'),
        ]);

        if (!this.element.isConnected) {
            return;
        }

        this.quill = new Quill(this.editorTarget, {
            theme: 'snow',
            formats: ['header', 'bold', 'italic', 'underline', 'strike', 'list', 'blockquote', 'link', 'image'],
            modules: {
                toolbar: [
                    [{ header: [2, 3, 4, false] }],
                    ['bold', 'italic', 'underline', 'strike'],
                    [{ list: 'ordered' }, { list: 'bullet' }],
                    ['blockquote', 'link'],
                    ['clean'],
                ],
            },
        });

        this.quill.on('text-change', () => this.sync());

        this.form = this.element.closest('form');
        this.syncOnSubmit = () => this.sync();
        this.form?.addEventListener('submit', this.syncOnSubmit);
    }

    disconnect() {
        this.form?.removeEventListener('submit', this.syncOnSubmit);
        this.quill = null;
    }

    sync() {
        if (!this.quill) {
            return;
        }

        // An empty editor has length 1 (its trailing newline)
        const html = this.quill.getLength() <= 1 ? '' : this.quill.getSemanticHTML();
        this.inputTarget.value = html.replaceAll('&nbsp;', ' ');
    }
}
