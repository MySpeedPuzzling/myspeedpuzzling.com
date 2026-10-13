/**
 * The round's personal links: the CSV the organiser's links page exports (`online_links_csv`: a BOM, a header, then
 * `name,link` per participant) or a plain list with one link or token per line. The token is the link's last path
 * segment - 22 characters of base58 (OnlineToken::looksValid()). Anything else on a line is ignored.
 */

const TOKEN = /^[1-9A-HJ-NP-Za-km-z]{22}$/;

/**
 * @param {string} text
 * @returns {string[]} the tokens, in file order, each once
 */
export function parseLinks(text) {
    const tokens = [];
    const seen = new Set();

    for (const rawLine of String(text).replace(/^﻿/, '').split(/\r?\n/)) {
        const line = rawLine.trim();

        if (line === '') {
            continue;
        }

        // The last field of a CSV line (a link never contains a comma), quotes taken off
        const field = line.slice(line.lastIndexOf(',') + 1).trim().replace(/^"|"$/g, '');
        const candidate = field.replace(/[?#].*$/, '').replace(/\/+$/, '').split('/').pop();

        if (TOKEN.test(candidate) && !seen.has(candidate)) {
            seen.add(candidate);
            tokens.push(candidate);
        }
    }

    return tokens;
}
