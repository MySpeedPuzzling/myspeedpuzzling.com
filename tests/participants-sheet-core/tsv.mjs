// The clipboard parser and serializer (assets/participants_sheet/tsv.js) - the stage 0b spike's tests, plus the
// helpers the sheet added (rowsFromClipboard, readBoolean, trimCell).
import assert from 'node:assert/strict';
import { parseClipboardText, parseClipboardHtml, rowsFromClipboard, readBoolean, trimCell, toTsv, toHtmlTable } from '../../assets/participants_sheet/tsv.js';
import { TEXT_FIXTURES, HTML_FIXTURES } from './tsv_fixtures.mjs';

export default function (test) {
    for (const fixture of TEXT_FIXTURES) {
        test(fixture.name, () => assert.deepEqual(parseClipboardText(fixture.text), fixture.rows));
    }

    for (const fixture of HTML_FIXTURES) {
        test(fixture.name, () => assert.deepEqual(parseClipboardHtml(fixture.html), fixture.rows));
    }

    test('empty text is no rows', () => assert.deepEqual(parseClipboardText(''), []));
    test('a lone line end is one empty cell', () => assert.deepEqual(parseClipboardText('\r\n'), [['']]));
    test('a trailing tab is a trailing empty cell', () => assert.deepEqual(parseClipboardText('a\t'), [['a', '']]));
    test('only a tab is two empty cells', () => assert.deepEqual(parseClipboardText('\t'), [['', '']]));
    test('null and undefined are no rows', () => {
        assert.deepEqual(parseClipboardText(null), []);
        assert.deepEqual(parseClipboardText(undefined), []);
    });
    test('quoted cell then CR line end', () => assert.deepEqual(parseClipboardText('"a\nb"\rc'), [['a\nb'], ['c']]));
    test('quoted cell with CRLF inside becomes LF', () => assert.deepEqual(parseClipboardText('"a\r\nb"\r\n'), [['a\nb']]));
    test('closing quote followed by text = literal cell', () => assert.deepEqual(parseClipboardText('"a\nb"x\ty'), [['"a'], ['b"x', 'y']]));

    test('html without a table is null (Google Sheets one-cell copy is a <span>)', () => {
        assert.equal(parseClipboardHtml('<google-sheets-html-origin><span data-sheets-value="x">Wu Example 23</span></google-sheets-html-origin>'), null);
        assert.equal(parseClipboardHtml(''), null);
    });

    test('toTsv quotes only cells that need it', () => {
        assert.equal(
            toTsv([['plain', 'tab\there', 'line\nbreak', 'say "hi"', ''], ['x']]),
            'plain\t"tab\there"\t"line\nbreak"\t"say ""hi"""\t\nx',
        );
    });

    test('toTsv round-trips through the parser', () => {
        const rows = [
            ['plain', 'tab\there', 'line\nbreak', 'say "hi"', '', '"Speedy"', '"lead', 'trail"'],
            ['', '', 'Xia Example 24', '', '', '', '', ''],
            ['パズル', '🧩', ' nbsp ', '&<>', 'a""b', '"', '""', 'z'],
        ];
        assert.deepEqual(parseClipboardText(toTsv(rows)), rows);
    });

    test('toHtmlTable escapes and keeps line breaks, and our html parser reads it back', () => {
        const rows = [['<b>&', 'two\nlines'], ['"q"', '']];
        const html = toHtmlTable(rows);
        assert.equal(html, '<meta charset="utf-8"><table><tbody><tr><td>&lt;b&gt;&amp;</td><td>two<br>lines</td></tr><tr><td>&quot;q&quot;</td><td></td></tr></tbody></table>');
        assert.deepEqual(parseClipboardHtml(html), rows);
    });

    test('a large paste parses fast', () => {
        const rows = Array.from({ length: 2000 }, (_, r) => Array.from({ length: 10 }, (_, c) => (c === 3 ? `multi\nline ${r}` : `Yan Example ${r}-${c}`)));
        const text = toTsv(rows);
        const start = performance.now();
        const parsed = parseClipboardText(text);
        const took = performance.now() - start;
        assert.deepEqual(parsed, rows);
        assert.ok(took < 200, `took ${took} ms`);
    });

    test('text/plain wins, text/html only when there is no text', () => {
        assert.deepEqual(rowsFromClipboard('a\tb\r\n', '<table><tr><td>x</td></tr></table>'), [['a', 'b']]);
        assert.deepEqual(rowsFromClipboard('', '<table><tr><td>x</td><td>y</td></tr></table>'), [['x', 'y']]);
        assert.deepEqual(rowsFromClipboard(null, '<p>no table</p>'), []);
        assert.deepEqual(rowsFromClipboard(undefined, undefined), []);
    });

    test('a checkbox cell reads TRUE/FALSE, 1/0, x, yes/no in the site languages - anything else is not guessed', () => {
        for (const word of ['TRUE', 'true', '1', 'x', 'X', '✓', 'yes', 'Ano', 'WAHR', 'vrai', 'sí', 'はい', ' TRUE\u00A0']) {
            assert.equal(readBoolean(word), true, word);
        }
        for (const word of ['FALSE', '0', '', 'no', 'ne', 'FALSCH', 'faux', 'いいえ', '  ']) {
            assert.equal(readBoolean(word), false, word);
        }
        for (const word of ['maybe', '2', 'Kim Example']) {
            assert.equal(readBoolean(word), null, word);
        }
    });

    test('trimCell drops spaces, non-breaking spaces and BOMs around a cell, keeps them inside', () => {
        assert.equal(trimCell('\u00A0 Kim\u00A0Example \uFEFF'), 'Kim\u00A0Example');
        assert.equal(trimCell(null), '');
    });
}
