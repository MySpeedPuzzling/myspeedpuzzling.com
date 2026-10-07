/**
 * Clipboard blocks of the participants spreadsheet - one parser for what Excel, Google Sheets, Numbers and
 * LibreOffice put on the clipboard, one serializer for what we put there
 * (docs/features/competitions-management/participants-spreadsheet.md §3, §7; "Client architecture (as built)").
 * Pure - no DOM: runs the same under node (tests/participants-sheet-core-harness.mjs).
 *
 * text/plain is the source of truth: every spreadsheet writes it. text/html is read only when text/plain is missing.
 *
 * - Cells are separated by a tab, rows by "\r\n", "\n" or a lone "\r" (old Excel for Mac).
 * - ONE trailing line end is ignored: Excel always appends one, Google Sheets does not.
 * - A cell is quoted RFC 4180 style ("a ""b"" c", "line1\nline2") only when the producer had to: the quoted text
 *   holds a tab, a line break or a doubled quote, and the closing quote is followed by a tab, a line end or the end.
 *   Anything else starting with a quote is the cell's literal text ("Speedy" team, "Speedy"), as is a quote in the
 *   middle of a cell.
 * - Line breaks inside a cell become "\n". A UTF-8 BOM at the start is dropped. Non-breaking spaces are kept - the
 *   caller trims.
 * - Rows may be ragged; the caller decides what a missing cell means.
 */

const BOM = '﻿';

/**
 * The rows of a clipboard item: text/plain when there is any (every spreadsheet writes it), else the first table of
 * text/html, else nothing.
 *
 * @param {string|null|undefined} text
 * @param {string|null|undefined} html
 * @returns {string[][]}
 */
export function rowsFromClipboard(text, html) {
    if (typeof text === 'string' && text !== '') {
        return parseClipboardText(text);
    }

    return parseClipboardHtml(html ?? '') ?? [];
}

// What a checkbox column reads as ticked / not ticked: Excel's TRUE/FALSE (also in the site's languages), 1/0, x,
// check marks, yes/no
const TRUE_WORDS = new Set(['1', 'true', 'yes', 'y', 'x', '✓', '✔', '☑', 'ano', 'pravda', 'ja', 'wahr', 'oui', 'vrai', 'sí', 'si', 'verdadero', 'はい', 'true()', 'in']);
const FALSE_WORDS = new Set(['0', 'false', 'no', 'n', '', '✗', '✘', '☐', '-', '–', 'ne', 'nepravda', 'nein', 'falsch', 'non', 'faux', 'falso', 'いいえ', 'false()', 'out']);

/**
 * A pasted cell as a checkbox value: true, false, or null when it reads as neither (the caller lists it, never guesses).
 *
 * @param {string} cell
 * @returns {boolean|null}
 */
export function readBoolean(cell) {
    const word = String(cell ?? '').replace(/^[\p{White_Space}\uFEFF]+|[\p{White_Space}\uFEFF]+$/gu, '').toLowerCase();

    if (TRUE_WORDS.has(word)) {
        return true;
    }

    return FALSE_WORDS.has(word) ? false : null;
}

/**
 * A pasted cell's text without the spaces around it (non-breaking spaces and BOMs too - web pages and Excel leave them).
 *
 * @param {string} cell
 */
export function trimCell(cell) {
    return String(cell ?? '').replace(/^[\p{White_Space}\uFEFF]+|[\p{White_Space}\uFEFF]+$/gu, '');
}

/**
 * @param {string} text
 * @returns {string[][]}
 */
export function parseClipboardText(text) {
    let source = String(text ?? '');

    if (source.startsWith(BOM)) {
        source = source.slice(1);
    }

    const rows = [];
    const length = source.length;
    let row = [];
    let i = 0;
    let rowStarted = false;

    while (i < length) {
        let cell;
        rowStarted = true;

        if (source[i] === '"') {
            const quoted = readQuotedCell(source, i);

            if (quoted !== null) {
                cell = quoted.value;
                i = quoted.end;
            }
        }

        if (cell === undefined) {
            let end = i;

            while (end < length && source[end] !== '\t' && source[end] !== '\n' && source[end] !== '\r') {
                end++;
            }

            cell = source.slice(i, end);
            i = end;
        }

        row.push(cell);

        if (i >= length) {
            break;
        }

        const separator = source[i];

        if (separator === '\t') {
            i++;

            if (i >= length) {
                row.push('');
            }

            continue;
        }

        // a line end: "\r\n", "\n" or a lone "\r"
        i += separator === '\r' && source[i + 1] === '\n' ? 2 : 1;
        rows.push(row);
        row = [];
        rowStarted = false;
    }

    if (rowStarted) {
        rows.push(row);
    }

    return rows;
}

/**
 * A quoted cell starting at `start` (which holds a quote), or null when the quote is the cell's literal text.
 *
 * @returns {{value: string, end: number} | null}
 */
function readQuotedCell(source, start) {
    const length = source.length;
    let i = start + 1;
    let value = '';
    let needsQuoting = false;

    while (i < length) {
        const char = source[i];

        if (char === '"') {
            if (source[i + 1] === '"') {
                value += '"';
                needsQuoting = true;
                i += 2;
                continue;
            }

            const next = source[i + 1];
            const closesCell = next === undefined || next === '\t' || next === '\n' || next === '\r';

            if (!closesCell || !needsQuoting) {
                return null;
            }

            return { value: value.replace(/\r\n?/g, '\n'), end: i + 1 };
        }

        if (char === '\t' || char === '\n' || char === '\r') {
            needsQuoting = true;
        }

        value += char;
        i++;
    }

    // no closing quote: the quote was literal
    return null;
}

/**
 * The first <table> of a text/html clipboard item, or null when there is none. A fallback only - see the file comment.
 * Works on the markup as a string (no DOM), so it runs the same in the browser and under node.
 *
 * - <td>/<th> cells, colspan padded with empty cells (rowspan is not expanded).
 * - <br> is a line break inside the cell; other whitespace in the markup collapses (Excel wraps its HTML source).
 * - <style>, comments and conditional comments (Excel's <!--[if ...]>) are dropped, entities decoded.
 *
 * @param {string} html
 * @returns {string[][] | null}
 */
export function parseClipboardHtml(html) {
    const source = String(html ?? '')
        .replace(/<style[\s\S]*?<\/style\s*>/gi, '')
        .replace(/<script[\s\S]*?<\/script\s*>/gi, '')
        .replace(/<!--[\s\S]*?-->/g, '')
        .replace(/<!\[if[\s\S]*?<!\[endif\]>/gi, '');

    const tableStart = source.search(/<table[\s>]/i);

    if (tableStart === -1) {
        return null;
    }

    const tableEndMatch = /<\/table\s*>/i.exec(source.slice(tableStart));
    const table = tableEndMatch === null
        ? source.slice(tableStart)
        : source.slice(tableStart, tableStart + tableEndMatch.index);

    const rows = [];
    const rowPattern = /<tr[\s>][\s\S]*?(?=<tr[\s>]|$)/gi;
    const cellPattern = /<(td|th)(\s[^>]*)?>([\s\S]*?)(?=<\/t[dh]\s*>|<t[dh][\s>]|<\/tr\s*>|$)/gi;

    for (const rowMatch of table.matchAll(rowPattern)) {
        const row = [];

        for (const cellMatch of rowMatch[0].matchAll(cellPattern)) {
            row.push(htmlCellText(cellMatch[3]));

            const colspan = /\bcolspan\s*=\s*["']?(\d+)/i.exec(cellMatch[2] ?? '');
            const span = colspan === null ? 1 : Math.min(parseInt(colspan[1], 10), 1000);

            for (let extra = 1; extra < span; extra++) {
                row.push('');
            }
        }

        rows.push(row);
    }

    return rows;
}

function htmlCellText(markup) {
    const withBreaks = markup
        .replace(/[ \t\r\n\f]+/g, ' ')
        .replace(/ ?<br\b[^>]*> ?/gi, '\n')
        .replace(/<\/(p|div)\s*> ?(?=<(p|div)[\s>])/gi, '\n')
        .replace(/<[^>]*>/g, '');

    return decodeEntities(withBreaks).replace(/^[ \t]+|[ \t]+$/g, '').replace(/[ \t]*\n[ \t]*/g, '\n');
}

const NAMED_ENTITIES = { amp: '&', lt: '<', gt: '>', quot: '"', apos: "'", nbsp: ' ' };

function decodeEntities(text) {
    return text.replace(/&(#x[0-9a-f]+|#\d+|[a-z]+);/gi, (entity, name) => {
        if (name[0] === '#') {
            const code = name[1] === 'x' || name[1] === 'X' ? parseInt(name.slice(2), 16) : parseInt(name.slice(1), 10);

            return code > 0 && code <= 0x10FFFF ? String.fromCodePoint(code) : entity;
        }

        return NAMED_ENTITIES[name.toLowerCase()] ?? entity;
    });
}

/**
 * text/plain for the clipboard: tab-separated, "\n" between rows, no trailing line end; a cell holding a tab, a line
 * break or a quote is quoted with its quotes doubled (Excel and Sheets read that back as one cell).
 *
 * @param {string[][]} rows
 * @returns {string}
 */
export function toTsv(rows) {
    return rows
        .map((row) => row.map((cell) => {
            const text = String(cell ?? '');

            return /[\t\n\r"]/.test(text) ? `"${text.replace(/"/g, '""')}"` : text;
        }).join('\t'))
        .join('\n');
}

/**
 * text/html for the clipboard, so a paste into Excel/Sheets/Numbers keeps line breaks inside cells.
 *
 * @param {string[][]} rows
 * @returns {string}
 */
export function toHtmlTable(rows) {
    const body = rows
        .map((row) => `<tr>${row.map((cell) => `<td>${escapeHtml(String(cell ?? '')).replace(/\r\n?|\n/g, '<br>')}</td>`).join('')}</tr>`)
        .join('');

    return `<meta charset="utf-8"><table><tbody>${body}</tbody></table>`;
}

function escapeHtml(text) {
    return text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}
