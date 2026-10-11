/**
 * The two textures of the Diamond "Masterpiece" finish, drawn as SVG data URIs once per page (deterministic, no
 * canvas, no network):
 *
 * - glitter: flakes in the brand prism hues + a few white four-point sparkles (the house decor shape), on transparent.
 *   Every flake diffracts one colour, so they stay visible on the light pastel art where white would not.
 * - etch: a die-cut jigsaw grid, white lines on transparent, used as a mask (the foil shows through the cuts only).
 *   Correct jigsaw geometry: every piece has exactly one connector per side - a round head on a short neck, centred,
 *   all identical - two knobs and two sockets per piece, so the 2 x 2 tile repeats without seams.
 *
 * Both tiles repeat; the CSS sizes them (assets/styles/_achievement-medal.scss).
 */

const TILE = 64;
const CELL = TILE / 2;

// Knob of an edge of length 1 from (0, 0) to (1, 0), protruding towards negative y: a head circle of radius HEAD on
// a neck made by two fillet circles of radius FILLET, tangent to the edge line and to the head. Head width 0.34,
// protrusion 0.35, neck/head 0.68 of the edge - the proportions of the site's jigsaw pieces.
const HEAD = 0.17;
const HEAD_CENTRE = 0.18;
const FILLET = 0.032;

function knobPath() {
    const reach = Math.sqrt((HEAD + FILLET) ** 2 - (HEAD_CENTRE - FILLET) ** 2);
    const f1 = 0.5 - reach;
    const f2 = 0.5 + reach;
    // Tangent points of the fillets on the head
    const k = FILLET / (HEAD + FILLET);
    const t1x = f1 + (0.5 - f1) * k;
    const t1y = -FILLET + (-HEAD_CENTRE + FILLET) * k;
    const t2x = 1 - t1x;
    const n = (v) => Number(v.toFixed(4));

    return `M0 0L${n(f1)} 0`
        + `A${FILLET} ${FILLET} 0 0 0 ${n(t1x)} ${n(t1y)}`
        + `A${HEAD} ${HEAD} 0 1 1 ${n(t2x)} ${n(t1y)}`
        + `A${FILLET} ${FILLET} 0 0 0 ${n(f2)} 0L1 0`;
}

function etchSvg() {
    const knob = knobPath();
    const edges = [];

    // One edge = the knob path placed on a cut; scale(CELL, ±CELL) points the knob up or down (a socket for the
    // neighbour). Columns alternate the horizontal cuts, rows the vertical ones: 2 knobs + 2 sockets per piece.
    for (let ox = -TILE; ox <= TILE; ox += TILE) {
        for (let oy = -TILE; oy <= TILE; oy += TILE) {
            for (let i = 0; i < 2; i++) {
                for (let j = 0; j < 2; j++) {
                    const x = ox + i * CELL;
                    const y = oy + j * CELL;
                    edges.push(`<use href="#k" transform="translate(${x} ${y}) scale(${CELL} ${i % 2 ? CELL : -CELL})"/>`);
                    edges.push(`<use href="#k" transform="translate(${x} ${y}) rotate(90) scale(${CELL} ${j % 2 ? CELL : -CELL})"/>`);
                }
            }
        }
    }

    return `<svg xmlns="http://www.w3.org/2000/svg" width="${TILE}" height="${TILE}" viewBox="0 0 ${TILE} ${TILE}">`
        + `<defs><path id="k" d="${knob}" fill="none" stroke="#fff" stroke-width="1.6" stroke-linecap="round" `
        + `stroke-linejoin="round" vector-effect="non-scaling-stroke"/></defs>${edges.join('')}</svg>`;
}

// mulberry32: a small deterministic generator, so the glitter is the same on every load
function generator(seed) {
    let state = seed >>> 0;

    return () => {
        state = (state + 0x6d2b79f5) >>> 0;
        let t = state;
        t = Math.imul(t ^ (t >>> 15), t | 1);
        t ^= t + Math.imul(t ^ (t >>> 7), t | 61);
        return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
}

const PRISM = ['#f0606a', '#f7a04f', '#f2c94c', '#3fcf9a', '#3cc0c8', '#4a9cf5', '#7b6cf0', '#e46fc0'];

function glitterSvg() {
    const size = 128;
    const random = generator(20261011);
    const shapes = [];
    const wrap = (draw) => {
        for (const dx of [-size, 0, size]) {
            for (const dy of [-size, 0, size]) {
                shapes.push(draw(dx, dy));
            }
        }
    };

    for (let k = 0; k < 46; k++) {
        const x = random() * size;
        const y = random() * size;
        const r = (1 + random() * 1.3).toFixed(2);
        const colour = PRISM[Math.floor(random() * PRISM.length)];
        const opacity = (0.5 + random() * 0.5).toFixed(2);
        wrap((dx, dy) => `<circle cx="${(x + dx).toFixed(1)}" cy="${(y + dy).toFixed(1)}" r="${r}" fill="${colour}" fill-opacity="${opacity}"/>`);
    }

    for (let k = 0; k < 9; k++) {
        const x = random() * size;
        const y = random() * size;
        const s = 2.5 + random() * 3;
        const w = s * 0.18;
        wrap((dx, dy) => {
            const cx = x + dx;
            const cy = y + dy;
            const p = (v) => v.toFixed(1);
            return `<path fill="#fff" fill-opacity=".9" d="M${p(cx)} ${p(cy - s)}Q${p(cx + w)} ${p(cy - w)} ${p(cx + s)} ${p(cy)}`
                + `Q${p(cx + w)} ${p(cy + w)} ${p(cx)} ${p(cy + s)}Q${p(cx - w)} ${p(cy + w)} ${p(cx - s)} ${p(cy)}`
                + `Q${p(cx - w)} ${p(cy - w)} ${p(cx)} ${p(cy - s)}Z"/>`;
        });
    }

    return `<svg xmlns="http://www.w3.org/2000/svg" width="${size}" height="${size}" viewBox="0 0 ${size} ${size}">${shapes.join('')}</svg>`;
}

let cached = null;

/**
 * @returns {{glitter: string, etch: string}} CSS url() values
 */
export function holoTextures() {
    if (cached === null) {
        const url = (svg) => `url("data:image/svg+xml,${encodeURIComponent(svg)}")`;
        cached = { glitter: url(glitterSvg()), etch: url(etchSvg()) };
    }

    return cached;
}
