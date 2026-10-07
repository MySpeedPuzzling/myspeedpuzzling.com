/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/**
 * Rich text of a page section (page_section_form.html.twig). Quill 2 is loaded here only - it is heavy and only this
 * form needs it. The hidden textarea gets Quill's getSemanticHTML(): real <ul>/<ol> lists (Quill's own root.innerHTML
 * stores a bullet list as <ol><li data-list="bullet">, which the server's sanitizer would turn into a numbered list),
 * with ordinary spaces instead of the non-breaking ones Quill puts between words. The server sanitises it again on every
 * save with the same allow-list the toolbar offers (config/packages/html_sanitizer.php).
 *
 * Nothing of the editor is in English on another language's page: the toolbar is the template's (translated titles,
 * the header picker's options become its labels), and the link tooltip - whose texts Quill's snow theme writes as CSS
 * content - reads them from custom properties set here from the translated values (assets/styles/_page-sections.scss).
 */
export default class extends Controller {
    static targets = ['editor', 'input', 'toolbar'];

    static values = {
        visitUrl: String,
        enterLink: String,
        editLink: String,
        removeLink: String,
        saveLink: String,
    };

    async connect() {
        const [{ default: Quill }] = await Promise.all([
            import('quill'),
            import('quill/dist/quill.snow.css'),
        ]);

        if (!this.element.isConnected) {
            return;
        }

        this.applyTexts();

        this.quill = new Quill(this.editorTarget, {
            theme: 'snow',
            formats: ['header', 'bold', 'italic', 'underline', 'strike', 'list', 'blockquote', 'link', 'image'],
            modules: {
                toolbar: this.toolbarTarget,
            },
        });

        this.labelHeaderPicker();
        // No example address of Quill's own in the link field
        this.element.querySelector('.ql-tooltip input[type=text]')?.setAttribute('data-link', 'https://');
        this.toolbarTarget.hidden = false;

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

    applyTexts() {
        const texts = {
            '--ql-visit-url': this.visitUrlValue,
            '--ql-enter-link': this.enterLinkValue,
            '--ql-edit-link': this.editLinkValue,
            '--ql-remove-link': this.removeLinkValue,
            '--ql-save-link': this.saveLinkValue,
        };

        for (const [property, text] of Object.entries(texts)) {
            this.element.style.setProperty(property, cssString(text));
        }
    }

    /**
     * Quill hides the toolbar's <select> behind a picker of its own - the picker gets the select's translated name.
     */
    labelHeaderPicker() {
        const select = this.toolbarTarget.querySelector('select.ql-header');
        const label = this.toolbarTarget.querySelector('.ql-picker.ql-header .ql-picker-label');

        if (select && label) {
            label.setAttribute('aria-label', select.getAttribute('aria-label') ?? '');
            label.setAttribute('title', select.getAttribute('title') ?? '');
        }
    }
}

/**
 * A text as a CSS string value (for `content: var(--…)`).
 */
export function cssString(text) {
    return `"${String(text).replace(/\\/g, '\\\\').replace(/"/g, '\\"').replace(/[\r\n]+/g, ' ')}"`;
}
