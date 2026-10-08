/**
 * Clipboard samples as spreadsheets write them, with the rows we expect to read (from the stage 0b spike) - used by
 * tsv.mjs and by the browser check of the grid (a paste of an Excel CRLF block).
 *
 * Source of each sample: written from knowledge of how the apps behave, NOT captured from a real clipboard in this
 * spike. "verified" below means documented behaviour widely relied on by other parsers (e.g. Excel's CRLF + trailing
 * CRLF + quoted multi-line cells); "unverified" means an assumption to check with a real copy before the build.
 * All names are invented.
 */

export const TEXT_FIXTURES = [
    {
        name: 'Excel for Windows (365): CRLF rows, trailing CRLF, Alt+Enter cell quoted with LF inside',
        source: 'verified (documented behaviour): CRLF + trailing CRLF; quoted multi-line cell with LF inside',
        text: 'Table\tTeam name\tMember 1\tMember 2\r\n'
            + '1\t"Puzzle\nPals"\tAlex Example 1\tBo Example 2\r\n'
            + '2\tQuick Corners\tCy Example 3\t\r\n',
        rows: [
            ['Table', 'Team name', 'Member 1', 'Member 2'],
            ['1', 'Puzzle\nPals', 'Alex Example 1', 'Bo Example 2'],
            ['2', 'Quick Corners', 'Cy Example 3', ''],
        ],
    },
    {
        name: 'Excel for Windows: a cell with quotes only is NOT quoted, a cell with a quote and a line break is',
        source: 'unverified: Excel quotes only cells with a line break (or tab); quotes inside are doubled then',
        text: 'Team "Fast"\t"Say ""hi""\nthere"\r\n',
        rows: [['Team "Fast"', 'Say "hi"\nthere']],
    },
    {
        name: 'Excel for Windows: empty cells, trailing empty cells keep their tabs, non-breaking space kept',
        source: 'verified for trailing tabs (selection width), NBSP from pasted web text: synthetic',
        text: 'Alex\u00A0Example 4\t\t\t\r\n\t\tBo Example 5\t\r\n',
        rows: [['Alex\u00A0Example 4', '', '', ''], ['', '', 'Bo Example 5', '']],
    },
    {
        name: 'Excel for Mac 2011 / classic Mac line ends: lone CR rows, trailing CR',
        source: 'unverified: old Excel for Mac wrote CR; current Excel 16 for Mac is believed to write CRLF or LF',
        text: '3\tCorner Club\tDee Example 6\tEd Example 7\r4\tEdge Folk\tFi Example 8\tGus Example 9\r',
        rows: [
            ['3', 'Corner Club', 'Dee Example 6', 'Ed Example 7'],
            ['4', 'Edge Folk', 'Fi Example 8', 'Gus Example 9'],
        ],
    },
    {
        name: 'Excel 16 for Mac: CRLF like Windows, quoted multi-line cell with CR inside',
        source: 'unverified: line break inside the cell may be CR on Mac - normalised to LF',
        text: '5\t"Two\rLines"\tHal Example 10\r\n',
        rows: [['5', 'Two\nLines', 'Hal Example 10']],
    },
    {
        name: 'Google Sheets: LF rows, NO trailing line end, multi-line cell quoted',
        source: 'verified (documented behaviour): LF, no trailing newline, quoted multi-line cells',
        text: 'Table\tTeam name\tMember 1\n6\t"Night\nOwls"\tIvy Example 11\n7\tSky Pieces\tJo Example 12',
        rows: [
            ['Table', 'Team name', 'Member 1'],
            ['6', 'Night\nOwls', 'Ivy Example 11'],
            ['7', 'Sky Pieces', 'Jo Example 12'],
        ],
    },
    {
        name: 'Google Sheets: a cell with quotes, if Sheets quotes it',
        source: 'unverified: whether Sheets quotes a cell that holds a quote but no line break - both forms read the same',
        text: '"Team ""Fast"""\tKai Example 13',
        rows: [['Team "Fast"', 'Kai Example 13']],
    },
    {
        name: 'Google Sheets: one cell, no line end',
        source: 'verified',
        text: 'Lu Example 14',
        rows: [['Lu Example 14']],
    },
    {
        name: 'Apple Numbers: LF rows, trailing LF, quoted multi-line cell',
        source: 'unverified: Numbers believed to write LF and quote multi-line cells like Sheets',
        text: '8\tTiny Tiles\tMo Example 15\tNia Example 16\n9\t"Big\nBoxes"\tOz Example 17\t\n',
        rows: [
            ['8', 'Tiny Tiles', 'Mo Example 15', 'Nia Example 16'],
            ['9', 'Big\nBoxes', 'Oz Example 17', ''],
        ],
    },
    {
        name: 'LibreOffice Calc (macOS/Linux): LF rows, trailing LF, ragged rows',
        source: 'unverified: LibreOffice writes LF (CRLF on Windows) with a trailing line end',
        text: '10\tFlat Edges\n11\n12\tLast Piece\tPia Example 18\n',
        rows: [['10', 'Flat Edges'], ['11'], ['12', 'Last Piece', 'Pia Example 18']],
    },
    {
        name: 'UTF-8 BOM at the start is dropped',
        source: 'synthetic: text copied out of a file editor',
        text: '\uFEFFQi Example 19\tRo Example 20\n',
        rows: [['Qi Example 19', 'Ro Example 20']],
    },
    {
        name: 'Literal quotes: in the middle, at the start of an unquoted cell, a whole quoted word',
        source: 'synthetic: what a person types; the producers do not quote these',
        text: 'Al"x Example\t"Speedy" team\t"Speedy"\t"unterminated',
        rows: [['Al"x Example', '"Speedy" team', '"Speedy"', '"unterminated']],
    },
    {
        name: 'A quoted cell with a tab inside',
        source: 'verified (RFC 4180 style, how all producers escape a tab)',
        text: '"a\tb"\tc\r\n',
        rows: [['a\tb', 'c']],
    },
    {
        name: 'Two trailing line ends = the last row is one empty cell (only ONE line end is ignored)',
        source: 'synthetic: Excel copying a block whose last row is empty',
        text: 'Su Example 21\r\n\r\n',
        rows: [['Su Example 21'], ['']],
    },
    {
        name: 'Japanese and emoji text survive',
        source: 'synthetic',
        text: 'パズル班\t🧩 Team\n',
        rows: [['パズル班', '🧩 Team']],
    },
];

export const HTML_FIXTURES = [
    {
        name: 'Google Sheets text/html',
        source: 'shape from knowledge (google-sheets-html-origin wrapper, data-sheets-* attributes); unverified in detail',
        html: '<meta charset=\'utf-8\'><google-sheets-html-origin><style type="text/css"><!--td {border: 1px solid #cccccc;}br {mso-data-placement:same-cell;}--></style>'
            + '<table xmlns="http://www.w3.org/1999/xhtml" cellspacing="0" cellpadding="0" dir="ltr" border="1" data-sheets-root="1">'
            + '<colgroup><col width="100"/><col width="120"/></colgroup><tbody>'
            + '<tr style="height:21px;"><td style="overflow:hidden;" data-sheets-value="{&quot;1&quot;:3,&quot;3&quot;:6}">6</td>'
            + '<td data-sheets-value="{&quot;1&quot;:2,&quot;2&quot;:&quot;Night\\nOwls&quot;}">Night<br>Owls</td></tr>'
            + '<tr style="height:21px;"><td>7</td><td>Sky &amp; Pieces&nbsp;</td></tr>'
            + '</tbody></table></google-sheets-html-origin>',
        rows: [['6', 'Night\nOwls'], ['7', 'Sky & Pieces\u00A0']],
    },
    {
        name: 'Excel for Windows text/html (CF_HTML body): conditional comments, mso styles, wrapped source, colspan',
        source: 'shape from knowledge (xmlns:x, <!--[if gte mso 9]>, x:str, mso-data-placement br); unverified in detail',
        html: '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel">\r\n'
            + '<head><meta http-equiv=Content-Type content="text/html; charset=utf-8"><style>\r\n<!--table\r\n\t{mso-displayed-decimal-separator:"\\.";}\r\n.xl65 {mso-number-format:"\\@";}\r\n-->\r\n</style></head>\r\n'
            + '<body link="#0563C1" vlink="#954F72">\r\n<!--StartFragment-->\r\n'
            + '<table border=0 cellpadding=0 cellspacing=0 width=256 style=\'border-collapse:collapse;width:192pt\'>\r\n'
            + ' <col width=64 span=4 style=\'width:48pt\'>\r\n'
            + ' <tr height=20 style=\'height:15.0pt\'>\r\n'
            + '  <td height=20 class=xl65 width=64 style=\'height:15.0pt;width:48pt\'>1</td>\r\n'
            + '  <td width=64 style=\'width:48pt\'>Puzzle<br style=\'mso-data-placement:same-cell\'>\r\n  Pals</td>\r\n'
            + '  <td colspan=2 x:str>Alex Example 1</td>\r\n'
            + ' </tr>\r\n'
            + ' <tr height=20 style=\'height:15.0pt\'>\r\n'
            + '  <td height=20>2</td>\r\n  <td>Quick\r\n  Corners</td>\r\n  <td></td>\r\n  <td>Bo Example 2</td>\r\n'
            + ' </tr>\r\n'
            + '<!--EndFragment-->\r\n</table>\r\n</body>\r\n</html>',
        rows: [['1', 'Puzzle\nPals', 'Alex Example 1', ''], ['2', 'Quick Corners', '', 'Bo Example 2']],
    },
    {
        name: 'Numbers / LibreOffice-like plain table with <th> and numeric entities',
        source: 'synthetic',
        html: '<table><thead><tr><th>Name</th><th>Country</th></tr></thead><tbody><tr><td>Vi&#x20;Example&#160;22</td><td>CZ</td></tr></tbody></table>',
        rows: [['Name', 'Country'], ['Vi Example\u00A022', 'CZ']],
    },
];
