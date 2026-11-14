/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';

/**
 * "Bring the file" of the data import (docs/features/data-import/upload.md).
 *
 * The picked spreadsheet goes straight to object storage: the server answers where (a presigned PUT for a key, a
 * content type and a length it chose - StartImportUploadController), the browser sends the file there with a progress
 * line, then tells the server it is up (ConfirmImportUploadController) and opens the import's page. No byte goes
 * through PHP, so 25 MB from a phone on a slow connection never meets the proxy's timeout.
 *
 * Whatever can not work this way - the start can not be reached, object storage refuses or can not be reached from
 * this browser - sends the form as it is: the same file, the same limits, checked by the same code on the server
 * (BringImportFileController). When an upload was started before, the form carries its id and goes on with that
 * import - one file never makes two. A refusal with a reason (too large, not a spreadsheet, too many imports) is
 * shown and nothing is sent. Without this script the form is a plain upload form.
 *
 * When the file is up but the confirmation can not be reached, the page says so and links to the import's page,
 * whose "Finish the upload" button checks again - the player is never left with an import they can not see.
 */

const PUT_ATTEMPTS = 3;
const CONFIRM_ATTEMPTS = 4;
const RETRY_MS = [1000, 3000, 8000];
// No progress for this long = the connection is gone without saying so
const STALL_MS = 45000;

const wait = (ms) => new Promise((resolve) => window.setTimeout(resolve, ms));

export default class extends Controller {
    static targets = ['input', 'submit', 'progress', 'bar', 'status', 'error', 'importId', 'arrived', 'arrivedLink'];

    static values = {
        startUrl: String,
        csrfToken: String,
        maxBytes: Number,
        messages: Object,
        uploadingText: String,
        checkingText: String,
    };

    connect() {
        this.busy = false;
        this.sendWithForm = false;
    }

    async submit(event) {
        // The fallback: this very form, sent by the browser
        if (this.sendWithForm) {
            return;
        }

        event.preventDefault();

        if (this.busy) {
            return;
        }

        const file = this.inputTarget.files && this.inputTarget.files[0];

        if (!file) {
            this.refuse('no_file');
            return;
        }

        if (file.size > this.maxBytesValue) {
            this.refuse('too_large');
            return;
        }

        if (file.size === 0) {
            this.refuse('empty');
            return;
        }

        this.busy = true;
        this.showError(null);
        this.arrivedTarget.hidden = true;
        this.submitTarget.disabled = true;
        this.showProgress(0);

        try {
            await this.upload(file);
        } finally {
            this.busy = false;
        }
    }

    async upload(file) {
        let signed = await this.start(file, null);

        if (signed === null) {
            return;
        }

        let putDone = false;
        let resigned = false;

        for (let attempt = 0; attempt < PUT_ATTEMPTS && !putDone; attempt++) {
            if (attempt > 0) {
                await wait(RETRY_MS[Math.min(attempt - 1, RETRY_MS.length - 1)]);
            }

            const outcome = await this.put(file, signed);

            if (outcome.ok) {
                putDone = true;
            } else if (outcome.status === 403 && !resigned) {
                // The signed URL ran out (a long wait) - the same import signed again, once
                resigned = true;
                signed = await this.start(file, signed.importId);

                if (signed === null) {
                    return;
                }
            } else if (outcome.status >= 400 && outcome.status < 500) {
                // Object storage refuses what we signed - the form is the way then
                break;
            }
        }

        if (!putDone) {
            // The form goes on with the import started here - one file is one import (and one upload of the limit)
            this.fallBackToForm(signed.importId);
            return;
        }

        this.statusTarget.textContent = this.checkingTextValue;

        for (let attempt = 0; attempt < CONFIRM_ATTEMPTS; attempt++) {
            if (attempt > 0) {
                await wait(RETRY_MS[Math.min(attempt - 1, RETRY_MS.length - 1)]);
            }

            const answer = await this.post(signed.confirmUrl, null);

            if (answer.ok && answer.data && answer.data.status === 'uploaded') {
                window.location.assign(answer.data.importUrl || signed.importUrl);
                return;
            }

            // A reason from the server (the object is not there, another size): no retry changes it
            if (answer.status >= 400 && answer.status < 500) {
                this.refuse(answer.data && answer.data.error ? answer.data.error : 'storage', answer.data && answer.data.message);
                return;
            }
        }

        // The file is up but the check could not be reached: said so, with the way to the import's page - it has
        // the button that checks again. Never the form: the file would be sent a second time
        this.hideProgress();
        this.arrivedLinkTarget.href = signed.importUrl;
        this.arrivedTarget.hidden = false;
    }

    /**
     * @returns {Promise<null|{importId: string, url: string, headers: Object, confirmUrl: string, importUrl: string}>}
     *          null = settled here (a refusal shown, or the form sent instead)
     */
    async start(file, importId) {
        const answer = await this.post(this.startUrlValue, { name: file.name, bytes: file.size, ...(importId ? { importId } : {}) });

        if (answer.ok && answer.data && typeof answer.data.url === 'string' && typeof answer.data.importId === 'string') {
            return answer.data;
        }

        // 422 = this file is not taken, 429 = too many uploads: said to the player, the form would hear the same
        if ((answer.status === 422 || answer.status === 429) && answer.data && answer.data.error) {
            this.refuse(answer.data.error, answer.data.message);
            return null;
        }

        // Signed out, a stale page, the server unreachable: the form finds out (and shows the sign-in page if needed)
        this.fallBackToForm(importId);

        return null;
    }

    async post(url, body) {
        try {
            const response = await fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': this.csrfTokenValue,
                },
                body: JSON.stringify(body || {}),
            });

            let data = null;

            try {
                data = await response.json();
            } catch (error) {
                data = null;
            }

            return { ok: response.ok, status: response.status, data };
        } catch (error) {
            return { ok: false, status: 0, data: null };
        }
    }

    put(file, signed) {
        return new Promise((resolve) => {
            const xhr = new XMLHttpRequest();
            let lastProgressAt = Date.now();

            const watchdog = window.setInterval(() => {
                if (Date.now() - lastProgressAt > STALL_MS) {
                    xhr.abort();
                }
            }, 2000);

            const settle = (outcome) => {
                window.clearInterval(watchdog);
                resolve(outcome);
            };

            xhr.open('PUT', signed.url);

            for (const [name, value] of Object.entries(signed.headers || {})) {
                // The browser sets the length itself from the file - it is signed, and it is the file's size
                if (name.toLowerCase() !== 'content-length') {
                    xhr.setRequestHeader(name, value);
                }
            }

            xhr.upload.onprogress = (event) => {
                lastProgressAt = Date.now();

                if (event.lengthComputable && event.total > 0) {
                    this.showProgress(Math.min(99, Math.floor((event.loaded / event.total) * 100)));
                }
            };

            xhr.onload = () => {
                if (xhr.status >= 200 && xhr.status < 300) {
                    this.showProgress(100);
                    settle({ ok: true, status: xhr.status });
                    return;
                }

                settle({ ok: false, status: xhr.status });
            };

            xhr.onerror = () => settle({ ok: false, status: 0 });
            xhr.onabort = () => settle({ ok: false, status: 0 });

            // A Blob with the signed type: some browsers would otherwise add their own guess
            xhr.send(file.slice(0, file.size, (signed.headers || {})['Content-Type'] || ''));
        });
    }

    fallBackToForm(importId = null) {
        this.sendWithForm = true;
        this.importIdTarget.value = importId || '';
        this.hideProgress();
        this.submitTarget.disabled = false;

        if (typeof this.element.requestSubmit === 'function') {
            this.element.requestSubmit(this.submitTarget);
        } else {
            this.element.submit();
        }
    }

    refuse(reason, message) {
        this.hideProgress();
        this.submitTarget.disabled = false;
        this.showError(message || this.messagesValue[reason] || this.messagesValue.storage || '');
    }

    showError(message) {
        this.errorTarget.textContent = message || '';
        this.errorTarget.hidden = !message;
    }

    showProgress(percent) {
        this.progressTarget.hidden = false;
        this.barTarget.style.width = `${percent}%`;
        this.barTarget.setAttribute('aria-valuenow', String(percent));
        this.statusTarget.textContent = this.uploadingTextValue.replace('%percent%', String(percent));
    }

    hideProgress() {
        this.progressTarget.hidden = true;
    }
}
