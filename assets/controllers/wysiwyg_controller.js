import { Controller } from '@hotwired/stimulus';

/**
 * User-friendly rich text editor for competition page sections.
 * Content is synced into a hidden textarea; the server re-sanitizes on every
 * write with a strict allow-list, so the toolbar mirrors what survives.
 *
 * Quill is imported dynamically — it is heavy and only editor pages need it.
 */
export default class extends Controller {
    static targets = ['editor', 'input'];

    async connect() {
        const [{ default: Quill }] = await Promise.all([
            import('quill'),
            import('quill/dist/quill.snow.css'),
        ]);

        this.quill = new Quill(this.editorTarget, {
            theme: 'snow',
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

        this.quill.on('text-change', () => {
            this.inputTarget.value = this.quill.root.innerHTML;
        });
    }

    disconnect() {
        this.quill = null;
    }
}
