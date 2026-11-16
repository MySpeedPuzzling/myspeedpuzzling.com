import { Controller } from '@hotwired/stimulus';

// A person's messages this close together form one group: one avatar, one time (same rule as _message_list.html.twig)
const GROUP_SECONDS = 300;
// Further than this from the newest message = "reading history": nothing pulls the view down then
const AT_BOTTOM_PX = 40;
// Scrolled up this far, the "jump to the newest message" button shows even without new messages
const JUMP_SHOWN_PX = 300;

export default class extends Controller {
    static targets = ['messages', 'textarea', 'loadOlder', 'loadOlderSpinner', 'jump', 'jumpCount'];

    static values = {
        today: String,
        todayLabel: String,
    };

    connect() {
        this._atBottom = true;
        this._unseen = 0;

        this.layout();
        this.markSeen();

        // Delay scroll to ensure DOM layout is fully computed
        requestAnimationFrame(() => {
            this.scrollToBottom();
            // Only once the view sits on the newest message - the button at the top is in view before that
            requestAnimationFrame(() => this.observeLoadOlder());
        });

        // For modals: also scroll after the modal transition completes
        this.modalElement = this.element.closest('.modal');
        if (this.modalElement) {
            this._modalShownHandler = () => this.scrollToBottom();
            this.modalElement.addEventListener('shown.bs.modal', this._modalShownHandler);
        }

        this.observer = new MutationObserver(() => this.messagesChanged());
        this.observer.observe(this.messagesTarget, { childList: true });

        this._scrollHandler = () => this.scrolled();
        this.messagesTarget.addEventListener('scroll', this._scrollHandler, { passive: true });

        // The full-height chat page resizes the list after the first paint (styles and fonts
        // settling, the phone keyboard opening, the input growing): stay on the newest message
        // unless the reader scrolled up to read older ones
        if (typeof ResizeObserver !== 'undefined') {
            this.resizeObserver = new ResizeObserver(() => {
                if (this._atBottom) this.scrollToBottom();
            });
            this.resizeObserver.observe(this.messagesTarget);
        }
    }

    disconnect() {
        this.observer?.disconnect();
        this.resizeObserver?.disconnect();
        this.loadOlderObserver?.disconnect();
        this.messagesTarget.removeEventListener('scroll', this._scrollHandler);
        if (this.modalElement && this._modalShownHandler) {
            this.modalElement.removeEventListener('shown.bs.modal', this._modalShownHandler);
        }
    }

    submitOnEnter(event) {
        if (event.key !== 'Enter' || event.shiftKey || event.isComposing) return;

        // Phones and tablets without a mouse or trackpad: Enter is a new line, the button sends
        // (like every chat app there - the on-screen keyboard has no Shift+Enter)
        if (window.matchMedia && !window.matchMedia('(any-pointer: fine)').matches) return;

        event.preventDefault();
        const form = event.target.closest('form');
        if (form && event.target.value.trim() !== '') {
            form.requestSubmit();
        }
    }

    autoGrow() {
        if (!this.hasTextareaTarget) return;

        const textarea = this.textareaTarget;
        // The CSS max-height caps it; past that the field scrolls
        textarea.style.height = 'auto';
        textarea.style.height = `${textarea.scrollHeight + textarea.offsetHeight - textarea.clientHeight}px`;
    }

    onSubmitEnd(event) {
        if (event.detail.success && this.hasTextareaTarget) {
            this.textareaTarget.value = '';
            this.textareaTarget.style.height = '';
            this.textareaTarget.focus();
        }

        // Re-enable submit button
        const button = this.element.querySelector('form button[type=submit]');
        if (button) button.disabled = false;
    }

    scrollToBottom() {
        // Prepending older history must not yank the view to the bottom
        if (this._suppressAutoScroll) return;
        this.messagesTarget.scrollTop = this.messagesTarget.scrollHeight;
        this._atBottom = true;
        this._unseen = 0;
        this.updateJump(0);
    }

    jumpToLatest() {
        const reduceMotion = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
        this.messagesTarget.scrollTo({ top: this.messagesTarget.scrollHeight, behavior: reduceMotion ? 'auto' : 'smooth' });
    }

    scrolled() {
        const el = this.messagesTarget;
        const distance = el.scrollHeight - el.scrollTop - el.clientHeight;
        this._atBottom = distance < AT_BOTTOM_PX;
        if (this._atBottom) this._unseen = 0;
        this.updateJump(distance);
    }

    updateJump(distance) {
        if (!this.hasJumpTarget) return;

        this.jumpTarget.hidden = !(this._unseen > 0 || distance > JUMP_SHOWN_PX);
        if (this.hasJumpCountTarget) {
            this.jumpCountTarget.hidden = this._unseen === 0;
            this.jumpCountTarget.textContent = this._unseen > 99 ? '99+' : String(this._unseen);
        }
    }

    messagesChanged() {
        // Grouping and day lines first: it adds lines, which re-runs this once more with nothing new
        this.layout();

        const fresh = this.markSeen();
        if (fresh.length === 0 || this._suppressAutoScroll) return;

        // Own message sent: always show it. Somebody else's: only pull down a reader who is already there
        if (fresh.some((row) => row.dataset.own === '1') || this._atBottom) {
            this.scrollToBottom();
            return;
        }

        this._unseen += fresh.length;
        const el = this.messagesTarget;
        this.updateJump(el.scrollHeight - el.scrollTop - el.clientHeight);
    }

    // Rows rendered before or added since the last call; each counted once
    markSeen() {
        const fresh = [...this.messagesTarget.querySelectorAll('[data-message-id]:not([data-seen])')];
        fresh.forEach((row) => { row.dataset.seen = '1'; });

        return fresh;
    }

    /**
     * Day lines and grouping for everything in the list - messages that arrived live or a loaded batch
     * of older ones meet the rendered ones here. Changes only what differs, so running it twice is a no-op.
     */
    layout() {
        // Day lines: one before the first message of every day, never two for one day
        let currentDay = null;
        for (const item of this.messagesTarget.querySelectorAll('[data-message-id][data-day], .chat-day')) {
            if (item.classList.contains('chat-day')) {
                if (item.dataset.day === currentDay) {
                    item.remove();
                } else {
                    currentDay = item.dataset.day;
                }
            } else if (item.dataset.day !== currentDay) {
                item.before(this.daySeparator(item.dataset.day));
                currentDay = item.dataset.day;
            }
        }

        const rows = [...this.messagesTarget.querySelectorAll('[data-message-id][data-day]')];
        rows.forEach((row, i) => {
            this.toggleClass(row, 'chat-row--joined-prev', this.joins(rows[i - 1], row));
            this.toggleClass(row, 'chat-row--joined-next', this.joins(row, rows[i + 1]));
        });
    }

    joins(earlier, later) {
        return earlier !== undefined && later !== undefined
            && earlier.dataset.sender !== ''
            && earlier.dataset.sender === later.dataset.sender
            && earlier.dataset.day === later.dataset.day
            && Number(later.dataset.ts) - Number(earlier.dataset.ts) < GROUP_SECONDS;
    }

    toggleClass(element, name, on) {
        if (element.classList.contains(name) !== on) {
            element.classList.toggle(name, on);
        }
    }

    daySeparator(day) {
        const separator = document.createElement('div');
        separator.className = 'chat-day';
        separator.dataset.day = day;
        separator.setAttribute('role', 'separator');

        const label = document.createElement('span');
        if (day === this.todayValue && this.todayLabelValue) {
            label.textContent = this.todayLabelValue;
        } else {
            const [year, month, date] = day.split('-').map(Number);
            label.textContent = new Intl.DateTimeFormat(document.documentElement.lang || undefined, {
                weekday: 'long', day: 'numeric', month: 'long',
            }).format(new Date(year, month - 1, date));
        }
        separator.append(label);

        return separator;
    }

    // Older messages load by themselves once the reader scrolls near the top; the button stays for keyboards
    observeLoadOlder() {
        if (!this.hasLoadOlderTarget || typeof IntersectionObserver === 'undefined') return;

        const button = this.loadOlderTarget.querySelector('button');
        if (!button) return;

        this.loadOlderObserver = new IntersectionObserver((entries) => {
            if (entries.some((entry) => entry.isIntersecting) && !this.loadOlderTarget.classList.contains('d-none')) {
                this.loadOlderMessages({ currentTarget: button });
            }
        }, { root: this.messagesTarget, rootMargin: '300px 0px 0px 0px' });
        this.loadOlderObserver.observe(button);
    }

    async loadOlderMessages(event) {
        if (this._loadingOlder) return;

        const button = event.currentTarget;
        const url = button.dataset.url;
        const before = this._oldestMessageId || button.dataset.oldestId;
        if (!url || !before) return;

        this._loadingOlder = true;
        button.disabled = true;
        if (this.hasLoadOlderSpinnerTarget) {
            this.loadOlderSpinnerTarget.classList.remove('d-none');
        }

        try {
            const response = await fetch(`${url}?before=${encodeURIComponent(before)}`, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (!response.ok) return;

            const template = document.createElement('template');
            template.innerHTML = (await response.text()).trim();
            const batch = template.content.firstElementChild;
            if (!batch) return;

            const container = this.messagesTarget;
            const prevScrollHeight = container.scrollHeight;
            const prevScrollTop = container.scrollTop;

            // Insert right below the button: each batch is older than the previous one
            this._suppressAutoScroll = true;
            this.loadOlderTarget.insertAdjacentElement('afterend', batch);
            // Day lines and grouping where the batch meets the rows below, before measuring
            this.layout();
            this.markSeen();
            container.scrollTop = prevScrollTop + (container.scrollHeight - prevScrollHeight);
            requestAnimationFrame(() => {
                this._suppressAutoScroll = false;
            });

            this._oldestMessageId = batch.dataset.oldestId || before;
            if (batch.dataset.hasOlder !== '1') {
                this.loadOlderTarget.classList.add('d-none');
                this.loadOlderObserver?.disconnect();
            }
        } catch {
            // Network error - keep the button enabled so the user can retry
        } finally {
            this._loadingOlder = false;
            button.disabled = false;
            if (this.hasLoadOlderSpinnerTarget) {
                this.loadOlderSpinnerTarget.classList.add('d-none');
            }
            // Still near the top after a short batch: observing again reports it at once
            if (this.loadOlderObserver && !this.loadOlderTarget.classList.contains('d-none')) {
                this.loadOlderObserver.unobserve(button);
                this.loadOlderObserver.observe(button);
            }
        }
    }
}
