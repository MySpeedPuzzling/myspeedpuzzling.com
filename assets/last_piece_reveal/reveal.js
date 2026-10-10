/*
 * The last-piece reveal (badge lab PoC P2, docs/features/xp-levels/): the earned picture arrives as its own 3x3
 * jigsaw. The 8 frame pieces fly in and snap down (corners first, then the edges - the way speed puzzlers build a
 * frame), the centre piece with the icon hovers over its empty slot and clicks in last (~1 s after the tap). The seams
 * flash and fuse, the puzzle card dissolves, the badge pops; confetti and sound from the outside (confetti.js, sfx.js).
 *
 * - The cut is geometry.json (badge lab P2 `geometry.py`, D06 `knob_template`): the 9 pieces tile the square exactly,
 *   every interior side carries exactly one centred connector of the same size (round head on a short neck).
 * - Only transform and opacity are animated, with Web Animations: the compositor runs them, so they keep going while
 *   the main thread is busy. Every animation of a reveal shares one clock (keyframes at absolute times), side effects
 *   (confetti, label, end) are cues on the same clock. At the end every animation is cancelled and plain CSS holds
 *   the final state (`.is-final`) - nothing keeps running.
 * - The unrevealed look (the empty tray with its "?") is plain CSS (`.lpr-tray`, assets/img/last-piece-reveal-tray.svg),
 *   so a page full of unrevealed badges costs nothing and nothing pops in when this lazy module arrives; the pieces
 *   are built on the first play.
 * - The picture is either a badge image (`{kind: 'image', url}`) or an inline SVG emblem drawn in the 300-unit square
 *   (`{kind: 'emblem', element}` - the level medallion of the XP launch reveal).
 */

const SVGNS = 'http://www.w3.org/2000/svg';
const BADGE_BOX = { x: 7, y: 7, w: 286 };                 // where a badge image sits in the puzzle picture (95.3 %)
const ORDER = [0, 2, 8, 6, 1, 5, 7, 3];                    // TL, TR, BR, BL corners, then T, R, B, L edges
const CENTRE = 4;                                          // the icon piece
const SPIN_MARGIN = 1.25;                                  // a flying piece's box: scale 1.1 + rotation, rounded up

let instanceCount = 0;

/** One rAF loop per controller: the cues of every reveal + the confetti; stops itself when nothing is left. */
export class Ticker {
    constructor() {
        this.fns = new Set();
        this.raf = 0;
        this.step = this.step.bind(this);
    }

    add(fn) {
        this.fns.add(fn);

        if (!this.raf) {
            this.raf = requestAnimationFrame(this.step);
        }
    }

    remove(fn) {
        this.fns.delete(fn);
    }

    step(now) {
        this.raf = 0;

        for (const fn of [...this.fns]) {
            if (fn(now) === false) {
                this.fns.delete(fn);
            }
        }

        if (this.fns.size > 0) {
            this.raf = requestAnimationFrame(this.step);
        }
    }

    stop() {
        if (this.raf) {
            cancelAnimationFrame(this.raf);
        }

        this.raf = 0;
        this.fns.clear();
    }
}

function setAttrs(element, attrs) {
    for (const key in attrs || {}) {
        if (key === 'text') {
            element.textContent = attrs[key];
        } else if (key === 'style') {
            element.style.cssText = attrs[key];
        } else {
            element.setAttribute(key, attrs[key]);
        }
    }
}

/** HTML part of the reveal: spans only - the stage is a <button>, which may hold phrasing content only. */
function span(attrs, parent) {
    const element = document.createElement('span');
    setAttrs(element, attrs);

    if (parent) {
        parent.appendChild(element);
    }

    return element;
}

function sv(tag, attrs, parent) {
    const element = document.createElementNS(SVGNS, tag);
    setAttrs(element, attrs);

    if (parent) {
        parent.appendChild(element);
    }

    return element;
}

function mulberry32(seed) {
    let a = seed;

    return () => {
        a |= 0;
        a = (a + 0x6d2b79f5) | 0;
        let t = Math.imul(a ^ (a >>> 15), 1 | a);
        t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
        return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
}

const tf = (x, y, r, s) => `translate(${x.toFixed(2)}px, ${y.toFixed(2)}px) rotate(${r.toFixed(2)}deg) scale(${s})`;

/*
 * One Web Animations track per element: frames at ABSOLUTE reveal times (ms) become keyframes with offsets and no
 * delay, so every animation of a reveal shares one clock. The first and the last keyframe carry every animated
 * property (WebKit needs them there).
 */
function track(element, frames) {
    const end = Math.max(1, frames[frames.length - 1].t);
    const pick = (frame) => {
        const out = {};

        if (frame.transform !== undefined) {
            out.transform = frame.transform;
        }

        if (frame.opacity !== undefined) {
            out.opacity = frame.opacity;
        }

        return out;
    };
    const keyframes = [];

    if (frames[0].t > 0) {
        keyframes.push({ offset: 0, ...pick(frames[0]), easing: 'linear' });
    }

    for (const frame of frames) {
        keyframes.push({ offset: frame.t / end, ...pick(frame), easing: frame.easing || 'linear' });
    }

    for (const key of ['transform', 'opacity']) {
        const defined = frames.filter((frame) => frame[key] !== undefined);

        if (defined.length === 0) {
            continue;
        }

        if (keyframes[0][key] === undefined) {
            keyframes[0][key] = defined[0][key];
        }

        if (keyframes[keyframes.length - 1][key] === undefined) {
            keyframes[keyframes.length - 1][key] = defined[defined.length - 1][key];
        }
    }

    return element.animate(keyframes, { duration: end, fill: 'both' });
}

/** JS side effects at reveal times (confetti, label, end), run by the ticker on the same clock as the animations. */
class Cues {
    constructor(t0) {
        this.t0 = t0;
        this.list = [];
    }

    at(t, fn, skippable = false) {
        this.list.push({ t, fn, skippable, fired: false });
        this.list.sort((a, b) => a.t - b.t);

        return this;
    }

    tick(now) {
        const t = now - this.t0;
        let pending = false;

        for (const cue of this.list) {
            if (cue.fired) {
                continue;
            }

            if (t >= cue.t) {
                cue.fired = true;
                cue.fn();
            } else {
                pending = true;
            }
        }

        return pending;
    }

    /** a skip: run what is left at once, except the skippable cues (confetti that has not started) */
    flush() {
        for (const cue of this.list) {
            if (!cue.fired) {
                cue.fired = true;

                if (!cue.skippable) {
                    cue.fn();
                }
            }
        }
    }
}

export class LastPieceReveal {
    /**
     * @param {HTMLElement} stage a square element with a `.lpr-tray` child (the unrevealed look)
     * @param {object} options
     *   geometry: the cut (geometry.json); ticker: Ticker; sfx: PieceSfx; confetti: PieceConfetti
     *   picture: () => ({kind: 'image', url} | {kind: 'emblem', element}) - asked on every play
     *   clipRect: () => DOMRect|null - the frame pieces start inside it horizontally (nothing clipped, no overflow)
     *   onReveal(look, {instant}) - the badge is complete (label, counters); onState(state)
     */
    constructor(stage, options) {
        this.stage = stage;
        this.o = options;
        this.geo = options.geometry;
        this.uid = `lpr${++instanceCount}`;
        this.anims = [];
        this.state = 'idle';
        this.built = false;
        this.cues = null;
        this.bus = null;
        this.look = null;
        this.tray = stage.querySelector('.lpr-tray') || span({ class: 'lpr-tray', 'aria-hidden': 'true' }, stage);
        this.cuesTicker = (now) => (this.cues ? this.cues.tick(now) : false);
    }

    id(name) {
        return `${this.uid}-${name}`;
    }

    build() {
        if (this.built) {
            return;
        }

        this.built = true;
        const g = this.geo;
        const S = g.size;
        const stage = this.stage;

        // definitions: the puzzle picture behind the badge (a soft tier-tinted card with a faint sunburst) + the cut
        const defsSvg = sv('svg', { class: 'lpr-defs', 'aria-hidden': 'true', focusable: 'false' });
        const defs = sv('defs', {}, defsSvg);
        const cardGradient = sv('radialGradient', {
            id: this.id('card'), gradientUnits: 'userSpaceOnUse', cx: 150, cy: 142, r: 215,
        }, defs);
        this.cardStops = [0, 0.55, 1].map((offset) => sv('stop', { offset }, cardGradient));
        const art = sv('g', { id: this.id('art') }, defs);
        sv('rect', { x: 0, y: 0, width: S, height: S, fill: `url(#${this.id('card')})` }, art);
        let rays = '';

        for (let i = 0; i < 18; i++) {
            const a0 = (i / 18) * Math.PI * 2 - Math.PI / 2;
            const a1 = a0 + Math.PI / 18;
            rays += `M150 142L${(150 + 260 * Math.cos(a0)).toFixed(1)} ${(142 + 260 * Math.sin(a0)).toFixed(1)}`
                + `L${(150 + 260 * Math.cos(a1)).toFixed(1)} ${(142 + 260 * Math.sin(a1)).toFixed(1)}Z`;
        }

        sv('path', { d: rays, fill: '#ffffff', 'fill-opacity': 0.55 }, art);
        g.pieces.forEach((piece, i) => {
            sv('path', { d: piece.d }, sv('clipPath', { id: this.id(`clip${i}`), clipPathUnits: 'userSpaceOnUse' }, defs));
        });
        sv('path', { d: g.outer }, sv('clipPath', { id: this.id('outer'), clipPathUnits: 'userSpaceOnUse' }, defs));

        // the "lock" burst sits BEHIND the puzzle: a ring + 12 short strokes peek out around it, never over the icon
        const burst = sv('svg', { class: 'lpr-burst', viewBox: '-225 -225 450 450', 'aria-hidden': 'true' });
        this.burstRing = sv('circle', { r: 150, fill: 'none', 'stroke-width': 4 }, burst);
        this.burstRays = [];

        for (let i = 0; i < 12; i++) {
            const a = (i / 12) * Math.PI * 2 + Math.PI / 12;
            this.burstRays.push(sv('line', {
                x1: (166 * Math.cos(a)).toFixed(1),
                y1: (166 * Math.sin(a)).toFixed(1),
                x2: (188 * Math.cos(a)).toFixed(1),
                y2: (188 * Math.sin(a)).toFixed(1),
                'stroke-width': 5,
                'stroke-linecap': 'round',
            }, burst));
        }

        // the board (thunks when the last piece locks) holds the tray, the pieces, the final picture and the flash
        const board = span({ class: 'lpr-board' });
        stage.insertBefore(defsSvg, stage.firstChild);
        this.defsSvg = defsSvg;
        stage.insertBefore(burst, this.tray);
        stage.insertBefore(board, this.tray);
        board.appendChild(this.tray);
        this.burst = burst;
        this.board = board;

        // pieces in flight order (corners, edges, then the icon piece); each shadow sits right under its piece
        const picture = this.o.picture();
        this.emblem = picture.kind === 'emblem' ? picture.element : null;

        if (this.emblem) {
            const emblemArt = this.emblem.querySelector('.lpr-emblem-art') || this.emblem.firstElementChild;

            if (!emblemArt.id) {
                emblemArt.id = this.id('emblem');
            }

            this.emblemHref = `#${emblemArt.id}`;
        }

        const pieces = span({ class: 'lpr-layer lpr-pieces' }, board);
        this.piecesLayer = pieces;
        this.pieceEls = [];
        this.shadowEls = [];
        this.pieceImages = [];

        for (const i of [...ORDER, CENTRE]) {
            const piece = g.pieces[i];
            const [bx, by, bw, bh] = piece.bbox;
            const box = `left:${(bx / S) * 100}%;top:${(by / S) * 100}%;width:${(bw / S) * 100}%;height:${(bh / S) * 100}%;`
                + `transform-origin:${((piece.centroid[0] - bx) / bw) * 100}% ${((piece.centroid[1] - by) / bh) * 100}%`;
            const viewBox = `${bx} ${by} ${bw} ${bh}`;
            const shadow = span({ class: i === CENTRE ? 'lpr-shadow is-hero' : 'lpr-shadow', style: box }, pieces);
            sv('path', { d: piece.d }, sv('svg', { viewBox, 'aria-hidden': 'true', focusable: 'false' }, shadow));
            const pieceEl = span({ class: 'lpr-piece', style: box }, pieces);
            const pieceSvg = sv('svg', { viewBox, 'aria-hidden': 'true', focusable: 'false' }, pieceEl);
            const clipped = sv('g', { 'clip-path': `url(#${this.id(`clip${i}`)})` }, pieceSvg);
            sv('use', { href: `#${this.id('art')}` }, clipped);

            if (this.emblem) {
                sv('use', { href: this.emblemHref }, clipped);
            } else {
                this.pieceImages.push(sv('image', {
                    x: BADGE_BOX.x, y: BADGE_BOX.y, width: BADGE_BOX.w, height: BADGE_BOX.w,
                    preserveAspectRatio: 'xMidYMid meet',
                }, clipped));
            }

            sv('path', { d: piece.d, class: 'lpr-edge' }, pieceSvg);
            this.pieceEls[i] = pieceEl;
            this.shadowEls[i] = shadow;
        }

        // the finished picture (no seams): the card dissolves, the badge stays
        const final = span({ class: 'lpr-layer lpr-final' }, board);
        this.final = final;
        const finalCard = sv('svg', { class: 'lpr-final-card', viewBox: `0 0 ${S} ${S}`, 'aria-hidden': 'true', focusable: 'false' }, final);
        sv('use', { href: `#${this.id('art')}` }, sv('g', { 'clip-path': `url(#${this.id('outer')})` }, finalCard));
        sv('path', { d: g.outer, class: 'lpr-edge' }, finalCard);
        this.finalCard = finalCard;

        if (this.emblem) {
            this.emblem.classList.add('lpr-final-emblem');
            final.appendChild(this.emblem);
            this.finalBadge = this.emblem;
        } else {
            const img = document.createElement('img');
            img.className = 'lpr-final-badge';
            img.alt = '';
            img.draggable = false;
            img.decoding = 'async';
            final.appendChild(img);
            this.finalBadge = img;
        }

        this.shine = span({ class: this.emblem ? 'lpr-shine is-emblem' : 'lpr-shine', 'aria-hidden': 'true' }, final);
        this.bands = [
            span({ class: 'lpr-band lpr-band-white' }, this.shine),
            span({ class: 'lpr-band lpr-band-prism' }, this.shine),
        ];

        // the seams light up white for a moment when the last piece locks, then the picture is one piece
        const flash = sv('svg', { class: 'lpr-flash', viewBox: `0 0 ${S} ${S}`, 'aria-hidden': 'true', focusable: 'false' }, board);
        const flashSeams = sv('g', {
            fill: 'none', stroke: '#ffffff', 'stroke-width': 4.6, 'stroke-linecap': 'round', 'stroke-linejoin': 'round',
        }, flash);
        g.seams.forEach((d) => sv('path', { d }, flashSeams));
        this.flash = flash;
    }

    setLook(look) {
        this.cardStops.forEach((stop, i) => stop.setAttribute('stop-color', look.card[i]));
        this.burstRing.setAttribute('stroke', look.ring);
        this.burstRays.forEach((ray, i) => ray.setAttribute('stroke', look.rays[i % look.rays.length]));
    }

    setPicture() {
        const picture = this.o.picture();

        if (picture.kind !== 'image') {
            return;
        }

        for (const image of this.pieceImages) {
            image.setAttribute('href', picture.url);
        }

        if (this.finalBadge.getAttribute('src') !== picture.url) {
            this.finalBadge.src = picture.url;
        }
    }

    /** CSS mask of the glint/shimmer bands (the badge without its navy ink); null = the badge itself */
    setShineMask(maskUrl) {
        const value = maskUrl ? `url("${maskUrl}")` : 'none';
        this.shine.style.webkitMaskImage = value;
        this.shine.style.maskImage = value;
    }

    cancel() {
        for (const animation of this.anims) {
            animation.cancel();
        }

        this.anims = [];

        if (this.cues) {
            this.o.ticker.remove(this.cuesTicker);
            this.cues = null;
        }

        if (this.bus) {
            this.o.sfx.stop(this.bus);
            this.bus = null;
        }
    }

    /** disconnect: nothing keeps running and the stage gets its server-rendered children back (a reconnect builds anew) */
    destroy() {
        this.cancel();

        if (this.built) {
            this.stage.appendChild(this.tray);

            if (this.emblem) {
                this.emblem.classList.remove('lpr-final-emblem');
                this.stage.appendChild(this.emblem);
            }

            this.defsSvg.remove();
            this.burst.remove();
            this.board.remove();
            this.built = false;
        }

        this.stage.classList.remove('is-running', 'is-final');
        this.state = 'destroyed';
    }

    setState(state) {
        this.state = state;
        this.stage.classList.toggle('is-running', state === 'running');

        if (this.o.onState) {
            this.o.onState(state);
        }
    }

    /** reduced motion: straight to the end state (the chime still plays when the sound is on) */
    showInstant(look, { sound = true } = {}) {
        this.build();
        this.cancel();
        this.look = look;
        this.setLook(look);
        this.setPicture();
        this.stage.classList.add('is-final');
        this.setState('done');
        this.bus = sound ? this.o.sfx.play([{ t: 0, type: 'chime', arg: look.chime }]) : null;

        if (this.o.onReveal) {
            this.o.onReveal(look, { instant: true });
        }
    }

    play(look, { reduced = false, seed = 1, sound = true } = {}) {
        this.build();

        if (reduced) {
            this.showInstant(look, { sound });
            return;
        }

        const wasDone = this.state === 'done';
        this.cancel();
        this.stage.classList.remove('is-final');
        this.look = look;
        this.setLook(look);
        this.setPicture();
        const S = this.geo.size;
        const u = this.stage.clientWidth / S;                  // px per unit
        const random = mulberry32(7919 * seed + 17);
        const A = (element, frames) => {
            const animation = track(element, frames);
            this.anims.push(animation);

            return animation;
        };
        const cues = new Cues(performance.now());
        this.cues = cues;
        const sounds = [];
        this.setState('running');

        // ------------------------------------------------------------------ timing (ms)
        const PRE = wasDone ? 170 : 0;                        // a replay fades the finished badge out first
        const STAGGER = 62;
        const FLY = 380;                                       // 8 frame pieces: one every 62 ms, 380 ms flight
        const tStart = (k) => PRE + 30 + k * STAGGER;
        const tLand = (k) => tStart(k) + FLY * 0.86;
        const T_IN = PRE + 560;                                // the icon piece comes in from above ...
        const T_HOVER = T_IN + 180;                            // ... hovers clear of its empty slot ("?" visible) ...
        const T_DROP = PRE + 860;
        const CLICK = T_DROP + 120;                            // ... and clicks in last, ~1 s after the tap

        // ------------------------------------------------------------------ layers
        A(this.tray, [{ t: 0, opacity: wasDone ? 0 : 1 }, { t: PRE, opacity: 1 }, { t: CLICK + 205, opacity: 1 }, { t: CLICK + 206, opacity: 0 }]);
        A(this.piecesLayer, [{ t: 0, opacity: 1 }, { t: CLICK + 205, opacity: 1 }, { t: CLICK + 206, opacity: 0 }]);
        A(this.final, [
            { t: 0, opacity: wasDone ? 1 : 0 },
            { t: PRE, opacity: 0 },
            { t: CLICK + 40, opacity: 0, easing: 'ease-out' },
            { t: CLICK + 200, opacity: 1 },
        ]);
        A(this.finalCard, [
            { t: 0, opacity: wasDone ? 0 : 1 },
            { t: PRE, opacity: 1 },
            { t: CLICK + 220, opacity: 1, easing: 'cubic-bezier(.4,0,.6,1)' },
            { t: CLICK + 520, opacity: 0 },
        ]);

        // ------------------------------------------------------------------ 8 frame pieces: corners first, then edges
        const room = this.room(u);

        ORDER.forEach((i, k) => {
            const piece = this.geo.pieces[i];
            const dx = piece.slot[0] - 150;
            const dy = piece.slot[1] - 150;
            const length = Math.hypot(dx, dy);
            const nx = dx / length;                            // outward direction of the slot
            const ny = dy / length;
            const side = random() < 0.5 ? -1 : 1;
            const dist = (120 + random() * 40) * u;
            const tangent = side * (12 + random() * 26) * u;
            const x0 = room.clampX(piece, nx * dist - ny * tangent);
            const y0 = ny * dist + nx * tangent;
            const r0 = side * (16 + random() * 26);
            const xn = nx * 6 * u;                             // "near": hovering just outside the slot, lifted
            const yn = ny * 6 * u;
            const ts = tStart(k);
            const tl = tLand(k);
            const te = ts + FLY;
            const frames = [
                { t: ts, transform: tf(x0, y0, r0, 1.1), opacity: 0, easing: 'cubic-bezier(.16,.78,.32,1)' },
                { t: ts + FLY * 0.18, opacity: 1 },
                { t: ts + FLY * 0.64, transform: tf(xn, yn, r0 * 0.12, 1.09), easing: 'cubic-bezier(.55,0,.9,.55)' },
                { t: tl, transform: tf(0, 0, 0, 0.965), easing: 'cubic-bezier(.3,1.7,.55,1)' },
                { t: te, transform: tf(0, 0, 0, 1), opacity: 1 },
            ];
            A(this.pieceEls[i], frames);
            const lift = 11 * u;
            A(this.shadowEls[i], [
                { t: ts, transform: `translate(0px, ${lift}px) ` + tf(x0, y0, r0, 1.1), opacity: 0, easing: frames[0].easing },
                { t: ts + FLY * 0.18, opacity: 0.9 },
                { t: ts + FLY * 0.64, transform: `translate(0px, ${lift * 0.8}px) ` + tf(xn, yn, r0 * 0.12, 1.09), opacity: 0.9, easing: frames[2].easing },
                { t: tl, transform: `translate(0px, ${u}px) ` + tf(0, 0, 0, 0.965), opacity: 0 },
            ]);
            sounds.push({ t: tl, type: 'tick', arg: k });
        });

        // ------------------------------------------------------------------ the icon piece: hovers, then clicks in last
        const HY = -108 * u;                                  // above the top row, its own slot stays visible
        const hero = [
            { t: T_IN, transform: tf(0, -190 * u, -10, 1.2), opacity: 0, easing: 'cubic-bezier(.2,.8,.3,1)' },
            { t: T_IN + 70, opacity: 1 },
            { t: T_HOVER, transform: tf(0, HY, -3, 1.12), easing: 'ease-in-out' },
            { t: T_DROP - 45, transform: tf(0, HY - 3.5 * u, -2, 1.13), easing: 'ease-out' },  // anticipation
            { t: T_DROP, transform: tf(0, HY - 3 * u, -2, 1.13), easing: 'cubic-bezier(.62,0,1,.6)' },
            { t: CLICK, transform: tf(0, 0, 0, 0.95), easing: 'cubic-bezier(.25,1.75,.5,1)' },
            { t: CLICK + 210, transform: tf(0, 0, 0, 1), opacity: 1 },
        ];
        A(this.pieceEls[CENTRE], hero);
        const heroLift = 24 * u;
        A(this.shadowEls[CENTRE], [
            { t: T_IN, transform: `translate(0px, ${heroLift * 1.4}px) ` + tf(0, -190 * u, -10, 1.2), opacity: 0, easing: hero[0].easing },
            { t: T_IN + 70, opacity: 0.75 },
            { t: T_HOVER, transform: `translate(0px, ${heroLift}px) ` + tf(0, HY, -3, 1.12), easing: 'ease-in-out' },
            { t: T_DROP - 45, transform: `translate(0px, ${heroLift * 1.08}px) ` + tf(0, HY - 3.5 * u, -2, 1.13), easing: 'ease-out' },
            { t: T_DROP, transform: `translate(0px, ${heroLift * 1.07}px) ` + tf(0, HY - 3 * u, -2, 1.13), opacity: 0.85, easing: hero[4].easing },
            { t: CLICK, transform: `translate(0px, ${u}px) ` + tf(0, 0, 0, 0.95), opacity: 0 },
        ]);
        sounds.push({ t: CLICK, type: 'clunk' });
        sounds.push({ t: CLICK + 60, type: 'chime', arg: look.chime });

        if (look.shimmer) {
            sounds.push({ t: CLICK + 320, type: 'glitter' });
        }

        // ------------------------------------------------------------------ the settle: thunk, lock burst, badge pop
        A(this.board, [
            { t: CLICK, transform: 'scale(1)', easing: 'cubic-bezier(.3,0,.6,1)' },
            { t: CLICK + 70, transform: 'scale(0.984)', easing: 'cubic-bezier(.3,0,.4,1)' },
            { t: CLICK + 200, transform: 'scale(1.012)', easing: 'ease-in-out' },
            { t: CLICK + 320, transform: 'scale(1)' },
        ]);
        A(this.burst, [
            { t: CLICK, transform: 'scale(0.86)', opacity: 0, easing: 'cubic-bezier(.15,.8,.3,1)' },
            { t: CLICK + 40, opacity: 1 },
            { t: CLICK + 520, transform: 'scale(1.2)', opacity: 0 },
        ]);
        A(this.flash, [
            { t: CLICK, opacity: 0, easing: 'ease-out' },
            { t: CLICK + 50, opacity: 1, easing: 'ease-in' },
            { t: CLICK + 260, opacity: 0 },
        ]);
        A(this.finalBadge, [
            { t: CLICK + 240, transform: 'scale(1)', easing: 'cubic-bezier(.3,0,.4,1)' },
            { t: CLICK + 400, transform: 'scale(1.06)', easing: 'ease-in-out' },
            { t: CLICK + 560, transform: 'scale(0.995)', easing: 'ease-out' },
            { t: CLICK + 700, transform: 'scale(1)' },
        ]);
        let end = CLICK + 720;

        if (look.glint || look.shimmer) {                     // T2-T4: one white glint; T5: glint + prismatic shimmer
            A(this.bands[0], [
                { t: CLICK + 380, transform: 'translateX(-170%) rotate(20deg)', easing: 'cubic-bezier(.45,0,.35,1)' },
                { t: CLICK + 380 + (look.shimmer ? 640 : 520), transform: 'translateX(330%) rotate(20deg)' },
            ]);
            end = Math.max(end, CLICK + 1020);
        }

        if (look.shimmer) {
            A(this.bands[1], [
                { t: CLICK + 560, transform: 'translateX(-170%) rotate(20deg)', easing: 'cubic-bezier(.45,0,.35,1)' },
                { t: CLICK + 1360, transform: 'translateX(330%) rotate(20deg)' },
            ]);
            end = Math.max(end, CLICK + 1360);
        }

        // ------------------------------------------------------------------ side effects on the same clock
        cues.at(CLICK + 50, () => {
            const rect = this.stage.getBoundingClientRect();
            this.o.confetti.burst({
                x: rect.left + rect.width / 2,
                y: rect.top + rect.height / 2,
                ...look.confetti,
                seed: 101 * seed,
                scale: rect.width / S,
            });
        }, true);
        cues.at(CLICK + 280, () => {
            if (this.o.onReveal) {
                this.o.onReveal(look, { instant: false });
            }
        });
        cues.at(end + 20, () => {                             // hand over to plain CSS: nothing keeps running
            this.stage.classList.add('is-final');

            for (const animation of this.anims) {
                animation.cancel();
            }

            this.anims = [];
            this.bus = null;
            this.cues = null;
            this.setState('done');
        });
        this.o.ticker.add(this.cuesTicker);
        this.bus = sound ? this.o.sfx.play(sounds) : null;
    }

    /** a tap during the reveal: jump to the end (no confetti if it has not started, sounds cut) */
    skip() {
        if (this.state !== 'running') {
            return;
        }

        for (const animation of this.anims) {
            animation.finish();
        }

        if (this.bus) {
            this.o.sfx.stop(this.bus);
            this.bus = null;
        }

        if (this.cues) {
            this.cues.flush();
        }
    }

    /**
     * Where the frame pieces may start: inside the clip box horizontally (the page wrapper), so a badge next to the
     * edge of the screen takes its pieces in from closer instead of having them cut off or widening the page.
     */
    room(u) {
        const clip = this.o.clipRect ? this.o.clipRect() : null;

        if (!clip) {
            return { clampX: (piece, x) => x };
        }

        const stageLeft = this.stage.getBoundingClientRect().left;
        const minLeft = clip.left + 4 - stageLeft;           // px from the stage's left edge
        const maxRight = clip.right - 4 - stageLeft;

        return {
            clampX: (piece, x) => {
                const [bx, , bw] = piece.bbox;
                const cx = piece.centroid[0];
                const low = minLeft - cx * u + (cx - bx) * u * SPIN_MARGIN;
                const high = maxRight - cx * u - (bx + bw - cx) * u * SPIN_MARGIN;

                return low > high ? x : Math.min(Math.max(x, low), high);
            },
        };
    }
}
