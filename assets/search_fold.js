// The browser half of SearchText::fold() (docs/features/puzzle-names/README.md, "Search"): the server renders a
// puzzle's stored search keys - every name and code, folded once in PHP - into `data-search`, the browser folds only
// what was typed, so the two read text the same way. Pinned by tests/SearchFoldParityTest.php, which runs this module
// under node against the PHP fold over a corpus and every Latin letter.

// ICU `Latin-ASCII` for the Latin letters it does not reduce by dropping their accents (Ł, ß, ø, æ, đ, þ...),
// grouped by what they become - lower case is enough, the fold lower-cases afterwards. Every other Latin letter loses
// its combining marks (é → e), like ICU does.
const LATIN_TO_ASCII = {
    a: 'Ⱥᴀᶏⱥ',
    aa: 'Ꜳꜳ',
    ae: 'ÆæǢǣǼǽᴁᴭ𐞃',
    ao: 'Ꜵꜵ',
    au: 'Ꜷꜷ',
    av: 'ꜸꜹꜺꜻ',
    ay: 'Ꜽꜽ',
    b: 'ƀƁƂƃɃɓʙᴃᵬᶀ𐞄𐞅',
    c: 'ƇƈȻȼɕᴄᶝꞒꞓ',
    d: 'ÐðĐđƉƊƋƌȡɖɗᴅᴆᵭᶁᶑᶞꝱꝹꝺ𐞋𐞌𐞍',
    db: 'ȸ',
    dz: 'ʣʥ𐞇𐞉',
    e: 'ƐɆɇɛᴇᵋᶒᶓⱸ',
    f: 'ƑƒᵮᶂꜰꝻꝼ',
    g: 'ƓǤǥɠɡɢʛᶃᶢꞠꞡ𐞒𐞓𐞔',
    h: 'ĦħɦɧʜʱⱧⱨꞪꟸ𐞕𐞖𐞗',
    hv: 'ƕ',
    i: 'ıƖƗɨɪᵻᶖᶤᶦᶧ',
    j: 'ȷɈɉɟʝᴊᶡᶨ',
    k: 'ƘƙᴋᶄⱩⱪꝀꝁꝂꝃꝄꝅꞢꞣ',
    l: 'ŁłƚȴȽɫɬɭʟᴌᶅᶩᶪᶫⱠⱡⱢꝆꝇꝈꝉꝲꭞ𐞛',
    ll: 'Ỻỻ',
    ls: 'ʪ𐞙',
    lz: 'ʫ𐞚',
    m: 'ɱᴍᵯᶆᶬⱮꝳ',
    n: 'ŊŋƝƞȵɲɳɴᵑᵰᶇᶮᶯᶰꝴꞐꞑꞤꞥ',
    o: 'ØøǾǿᴏⱺꝊꝋꝌꝍ𐞢',
    oe: 'Œœɶꟹ𐞣',
    oi: 'Ƣƣ',
    oo: 'Ꝏꝏ',
    p: 'ƤƥᴘᵱᵽᶈⱣꝐꝑꝒꝓꝔꝕ',
    q: 'ĸʠꝖꝗꝘꝙ',
    qp: 'ȹ',
    r: 'ɌɍɼɽɾʀᵲᵳᶉⱤꝵꝶꞦꞧ𐞨𐞩𐞪',
    s: 'ȿʂᵴᶊᶳẜẝⱾꜱꞨꞩ',
    ss: 'ßẞ',
    t: 'ŦŧƫƬƭƮȶȾʈᴛᵵᶵⱦꝷꞆꞇ𐞯',
    th: 'ÞþᵺꝤꝥꝦꝧ',
    ts: 'ʦ𐞬',
    u: 'Ʉʉᴜᵾᶙᶶᶸ',
    ue: 'ᵫ',
    v: 'ƲʋᴠᶌᶹỼỽⱱⱴꝞꝟ𐞰',
    vy: 'Ꝡꝡ',
    w: 'ᴡⱲⱳ',
    x: 'ᶍ',
    y: 'ƳƴɎɏʏỾỿ𐞲',
    z: 'ƵƶȤȥɀʐʑᴢᵶᶎᶼᶽⱫⱬⱿ',
};

const LATIN_EXCEPTIONS = new Map(
    Object.entries(LATIN_TO_ASCII).flatMap(([ascii, letters]) => [...letters].map((letter) => [letter, ascii])),
);

const latinToAscii = (letter) => LATIN_EXCEPTIONS.get(letter) ?? letter.normalize('NFD').replace(/\p{M}+/gu, '');

// Apostrophes and what is typed for one: ' ` ´ ʹ ʼ ‘ ’ ‛ ′ and the full-width ＇
const APOSTROPHES = /['`\u00B4\u02B9\u02BC\u2018\u2019\u201B\u2032\uFF07]+/g;

/**
 * SearchText::fold(): apostrophes removed ("where's" = "where´s" = "wheres") before NFKC and after it, NFKC (full-width
 * `％４Ａ` become ASCII, ligatures split), Latin letters to ASCII, lower case, NFC, every whitespace run one space
 * (PCRE's Unicode `\s` is White_Space, U+0085 included, and still the old space U+180E, plus the separators), control
 * and format characters removed, a combining mark with no letter before it removed (NFKC turns `˘` into a space and
 * the mark), trimmed.
 */
export function foldSearchText(text) {
    return String(text ?? '')
        .replace(APOSTROPHES, '')
        .normalize('NFKC')
        .replace(/\p{Script=Latin}/gu, latinToAscii)
        .toLowerCase()
        .normalize('NFC')
        .replace(APOSTROPHES, '')
        .replace(/[\p{White_Space}\p{Z}\u180E]+/gu, ' ')
        .replace(/[\p{Cc}\p{Cf}]+/gu, '')
        .replace(/(^| )\p{M}+/gu, '$1')
        .replace(/ {2,}/g, ' ')
        .replace(/^ | $/g, '');
}

/**
 * Whether a puzzle's search keys (`data-search`: "\nname\nother name\n\ne:4005556147090\nc:rb1000001\n", folded by
 * PHP) hold what was typed. The keys store codes the way they are compared: a barcode without leading zeros and
 * separators, a brand code as letters and digits only - so a typed "04005556-147090" also looks for 4005556147090,
 * a typed "RB-1000" also for rb1000 in the codes. Nothing typed matches everything.
 */
export function searchKeyMatcher(query) {
    const folded = foldSearchText(query);

    if (folded === '') {
        return () => true;
    }

    const terms = [folded];

    if (/^[0-9 .-]+$/.test(folded)) {
        const number = folded.replace(/[^0-9]/g, '').replace(/^0+/, '');

        if (number !== '' && number !== folded) {
            terms.push(number);
        }
    }

    // Letters and digits never need escaping in a pattern
    const code = folded.replace(/[^\p{L}\p{N}]+/gu, '');
    const codeLine = code !== '' && code !== folded ? new RegExp(`\\n[ce]:[^\\n]*${code}`, 'u') : null;

    return (key) => {
        const text = String(key ?? '');

        return terms.some((term) => text.includes(term)) || (codeLine !== null && codeLine.test(text));
    };
}
