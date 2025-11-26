import { Controller } from '@hotwired/stimulus'

export default class extends Controller {
    static targets = ['text', 'toggle']
    static classes = ['truncated']

    connect() {
        // Hide toggle if text is not overflowing
        if (!this.isOverflowing()) {
            this.toggleTarget.style.display = 'none'
        }
    }

    isOverflowing() {
        const el = this.textTarget
        return el.scrollHeight > el.clientHeight
    }

    expand() {
        this.textTarget.classList.remove(this.truncatedClass)
        this.toggleTarget.style.display = 'none'
    }
}
