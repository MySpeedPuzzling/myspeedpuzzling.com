/**
 * The Help sheet's openers (help_opener_attrs() in src/Twig/HelpTwigExtension.php: the top bar's ? and Feedback, the
 * Menu's "Send feedback" row, the end-of-page line, the footer - docs/features/navigation README §4.5), one capture
 * click listener for all of them, before Turbo reads the link:
 * - data-help-opener="page": the ?url= the opener carries is the page as it was rendered; Live Components with URL props
 *   and history.replaceState change the address afterwards (a search, a filter). At the tap it becomes the page as it is
 *   now - location.pathname + location.search - so the feedback box and the e-mail name what the player is looking at
 *   (HELP review F4). The server validates it as before (HelpPageContextFactory); guest HTML stays as it was rendered.
 * - data-help-opener="private": a page whose URL holds a private token - the opener keeps the token-free path the server
 *   gave it (HELP review Q2), never the address.
 * - On the help page itself ([data-help-page]) an opener does not open the sheet over the page (its form and ids would
 *   be there twice - HELP review F5): an open layer (the Menu) closes first, then the page's own box is scrolled to and
 *   its field focused (guests: "Sign in to send feedback").
 * Modified clicks (a new tab) keep the browser's own handling; the middle button's auxclick only rewrites the address.
 * Plain functions over the objects handed in - tests/help-openers-harness.mjs runs them under node.
 */

export const OPENER_SELECTOR = 'a[data-help-opener]';
export const HELP_PAGE_SELECTOR = '[data-help-page]';

/**
 * The opener's href with url= set to the page as it is now; null when the href carries no url= (the bare help page)
 */
export function hrefForCurrentPage(href, page, base) {
    let url;

    try {
        url = new URL(href, base);
    } catch (e) {
        return null;
    }

    if (!url.searchParams.has('url') || typeof page !== 'string' || !page.startsWith('/')) {
        return null;
    }

    url.searchParams.set('url', page);

    return url.pathname + url.search + url.hash;
}

/**
 * What an opener on the help page leads to: the box's field, else the guest's sign-in link, else nothing
 */
export function ownHelpTarget(page) {
    if (!page || typeof page.querySelector !== 'function') {
        return null;
    }

    return page.querySelector('[data-help-sheet-target="message"]')
        || page.querySelector('[data-help-item="feedback_sign_in"] a')
        || null;
}

function isModified(event) {
    return event.button > 0 || event.altKey || event.ctrlKey || event.metaKey || event.shiftKey;
}

/**
 * Installs the listener; returns a function removing it (tests). `layers` is assets/layer_history.js (closeThen()).
 */
export function installHelpOpeners({ window, document, layers }) {
    const openerOf = (event) => {
        const target = event.target;

        return target && typeof target.closest === 'function' ? target.closest(OPENER_SELECTOR) : null;
    };

    const followCurrentPage = (opener) => {
        if (opener.getAttribute('data-help-opener') !== 'page') {
            return;
        }

        const href = hrefForCurrentPage(
            opener.getAttribute('href'),
            window.location.pathname + window.location.search,
            window.location.href,
        );

        if (href !== null) {
            opener.setAttribute('href', href);
        }
    };

    const onClick = (event) => {
        const opener = event.defaultPrevented ? null : openerOf(event);

        if (opener === null) {
            return;
        }

        const page = document.querySelector(HELP_PAGE_SELECTOR);

        if (page !== null && !page.contains(opener) && !isModified(event)) {
            const target = ownHelpTarget(page);

            event.preventDefault();

            const focus = () => {
                if (target === null) {
                    return;
                }

                if (typeof target.scrollIntoView === 'function') {
                    target.scrollIntoView({ block: 'center' });
                }

                target.focus({ preventScroll: true });
            };

            if (layers && typeof layers.closeThen === 'function') {
                layers.closeThen(focus);
            } else {
                focus();
            }

            return;
        }

        followCurrentPage(opener);
    };

    const onAuxClick = (event) => {
        const opener = openerOf(event);

        if (opener !== null) {
            followCurrentPage(opener);
        }
    };

    document.addEventListener('click', onClick, true);
    document.addEventListener('auxclick', onAuxClick, true);

    return () => {
        document.removeEventListener('click', onClick, true);
        document.removeEventListener('auxclick', onAuxClick, true);
    };
}
