import { Controller } from '@hotwired/stimulus';

/**
 * The conversation page is exactly as high as what the visitor sees (_conversation-page.scss). `100dvh` covers
 * Safari's toolbars but not the on-screen keyboard: iPhone Safari and Android Chrome lay the page out as if the
 * keyboard were not there and pan the page up to the input, so the chat header slid off the top. The visual
 * viewport is what is left above the keyboard - the page takes its height, and the composer sits on the keyboard.
 */
export default class extends Controller {
    connect() {
        this.viewport = window.visualViewport;
        if (!this.viewport) return;

        this.update = () => {
            // Pinch zoom shrinks the visual viewport too - the page must not shrink with it
            if (Math.abs(this.viewport.scale - 1) > 0.01) return;

            document.documentElement.style.setProperty('--chat-viewport-height', `${Math.round(this.viewport.height)}px`);

            // Safari panned the page to show the input; it fits above the keyboard now, so pan back
            if (window.scrollY > 0 && document.documentElement.scrollHeight <= Math.ceil(this.viewport.height) + 1) {
                window.scrollTo(0, 0);
            }
        };

        this.viewport.addEventListener('resize', this.update);
        this.viewport.addEventListener('scroll', this.update);
        this.update();
    }

    disconnect() {
        if (!this.viewport) return;

        this.viewport.removeEventListener('resize', this.update);
        this.viewport.removeEventListener('scroll', this.update);
        document.documentElement.style.removeProperty('--chat-viewport-height');
    }
}
