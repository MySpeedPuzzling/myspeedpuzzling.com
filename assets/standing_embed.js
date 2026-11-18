// The embeddable standing (templates/standing/embed.html.twig, real events L3, standings.md "Embeddable standing") -
// the Encore entry `standing_embed`: no Stimulus, no Turbo, nothing of the site. The thin DOM adapter of
// assets/standing_live.js (the public standing page's live logic, its harness tests/standing-live-harness.mjs): on the
// event's data-free standings signal (or every 60 s ±20 % without a stream) it refetches this very page (an ETag makes
// an unchanged one a 304) and swaps the table part only when it differs - no scroll jump, no flicker.

import 'bootstrap-icons/font/bootstrap-icons.min.css';
import './styles/standing_embed.scss';
import { StandingLive } from './standing_live.js';

const FETCH_TIMEOUT_MS = 10000;

function start() {
    const root = document.getElementById('standing-embed');
    const table = root?.querySelector('[data-standing-embed-table]');

    if (!root || !table) {
        return;
    }

    const status = document.querySelector('[data-standing-embed-status]');

    const fetchTable = async () => {
        const controller = typeof AbortController === 'undefined' ? null : new AbortController();
        const timer = setTimeout(() => controller?.abort(), FETCH_TIMEOUT_MS);

        try {
            const response = await fetch(root.dataset.url, {
                headers: { Accept: 'text/html' },
                credentials: 'omit',
                // Revalidated every time: the ETag answers 304 while nothing changed
                cache: 'no-cache',
                signal: controller?.signal,
            });

            if (response.status === 404) {
                return { status: 'gone' };
            }

            if (!response.ok) {
                return { status: 'error' };
            }

            return { status: 'ok', html: await response.text() };
        } catch (e) {
            return { status: 'error' };
        } finally {
            clearTimeout(timer);
        }
    };

    const apply = (html) => {
        const next = new DOMParser().parseFromString(html, 'text/html').querySelector('[data-standing-embed-table]');

        if (next === null || next.innerHTML.trim() === table.innerHTML.trim()) {
            return false;
        }

        table.innerHTML = next.innerHTML;

        return true;
    };

    const updated = () => {
        if (!status) {
            return;
        }

        const label = status.dataset.updated ?? '';
        const time = new Date().toLocaleTimeString(document.documentElement.lang || undefined, { hour: '2-digit', minute: '2-digit' });
        status.textContent = label.replace('%time%', time);
    };

    const live = new StandingLive({
        hubUrl: root.dataset.hub ?? '',
        topic: root.dataset.topic ?? '',
        fetchTable,
        apply,
        updated,
        isVisible: () => document.visibilityState !== 'hidden',
    });

    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') {
            live.onVisible();
        }
    });

    live.start();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
} else {
    start();
}
