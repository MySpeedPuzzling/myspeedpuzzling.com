/*
 * Pastel jigsaw-piece confetti of the last-piece reveal: one fixed canvas (created on the burst, removed when the last
 * particle is gone), correct interior pieces from the same knob template as the puzzle (one connector per side) with a
 * navy outline and a paler back when they flip, plus a few dots. The physics runs in fixed 1/240 s steps, so it looks
 * the same at 30, 60 or 120 Hz; no library.
 */

const NAVY = '#2b3445';

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

function mixHex(a, b, t) {
    const parse = (hex) => [1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16));
    const A = parse(a);
    const B = parse(b);

    return '#' + A.map((v, i) => Math.round(v + (B[i] - v) * t).toString(16).padStart(2, '0')).join('');
}

export class PieceConfetti {
    /**
     * @param {string[]} shapes SVG path data of unit pieces (body side 1, centred on 0,0)
     * @param {import('./reveal.js').Ticker} ticker
     */
    constructor(shapes, ticker) {
        this.paths = shapes.map((d) => new Path2D(d));
        this.ticker = ticker;
        this.parts = [];
        this.canvas = null;
        this.ctx = null;
        this.tLast = 0;
        this.onTick = (now) => this.tick(now);
    }

    ensureCanvas() {
        if (this.canvas) {
            return;
        }

        const canvas = document.createElement('canvas');
        canvas.className = 'lpr-confetti';
        canvas.setAttribute('aria-hidden', 'true');
        this.dpr = Math.min(window.devicePixelRatio || 1, 2);
        document.body.appendChild(canvas);
        // Its box ends at the phone bottom bar (.lpr-confetti): the pixels follow the box, so viewport px stay 1:1
        const box = canvas.getBoundingClientRect();
        canvas.width = Math.round((box.width || window.innerWidth) * this.dpr);
        canvas.height = Math.round((box.height || window.innerHeight) * this.dpr);
        this.canvas = canvas;
        this.ctx = canvas.getContext('2d');
    }

    /** x, y: viewport px of the burst centre; scale: stage size / 300 */
    burst({ x, y, count, colors, dots, seed, scale }) {
        this.ensureCanvas();

        if (!this.ctx) {
            return;
        }

        const random = mulberry32(seed);
        const sizeScale = Math.max(scale, 0.8);            // a phone-sized stage: the burst shrinks, the pieces stay readable

        if (this.parts.length === 0) {
            this.tLast = performance.now();
        }

        for (let i = 0; i < count; i++) {
            const kind = random() < dots ? 'dot' : 'piece';
            const angle = random() * Math.PI * 2;
            const speed = (240 + random() * 440) * scale;
            const r0 = (18 + random() * 60) * scale;
            const color = colors[Math.floor(random() * colors.length)];

            this.parts.push({
                kind,
                shape: Math.floor(random() * this.paths.length),
                color,
                back: mixHex(color, '#ffffff', 0.5),
                x: x + Math.cos(angle) * r0,
                y: y + Math.sin(angle) * r0,
                vx: Math.cos(angle) * speed,
                vy: Math.sin(angle) * speed * 0.9 - 250 * scale,
                rot: random() * Math.PI * 2,
                vrot: (random() - 0.5) * 9,
                tilt: random() * Math.PI * 2,
                vtilt: 5 + random() * 8,
                wob: random() * Math.PI * 2,
                vwob: 2.5 + random() * 3.5,
                amp: (14 + random() * 26) * scale,
                size: (kind === 'piece' ? 9 + random() * 4 : 4 + random() * 3) * sizeScale,
                life: 1.55 + random() * 0.75,
                age: 0,
            });
        }

        this.ticker.add(this.onTick);
    }

    step(dt) {
        const gravity = 720;
        const drag = Math.exp(-3.1 * dt);

        for (const p of this.parts) {
            p.vx *= drag;
            p.vy = p.vy * drag + gravity * dt;
            p.x += (p.vx + Math.sin(p.wob) * p.amp) * dt;
            p.y += p.vy * dt;
            p.rot += p.vrot * dt;
            p.tilt += p.vtilt * dt;
            p.wob += p.vwob * dt;
            p.age += dt;
        }

        this.parts = this.parts.filter((p) => p.age < p.life);
    }

    tick(now) {
        if (!this.canvas) {
            return false;
        }

        // a tab that comes back from the background resumes calmly instead of jumping
        let dt = Math.min(Math.max(0, (now - this.tLast) / 1000), 0.25);
        this.tLast = now;

        while (dt > 1e-6) {
            const h = Math.min(1 / 240, dt);
            this.step(h);
            dt -= h;
        }

        const ctx = this.ctx;
        ctx.setTransform(1, 0, 0, 1, 0, 0);
        ctx.clearRect(0, 0, this.canvas.width, this.canvas.height);

        for (const p of this.parts) {
            const fadeIn = Math.min(1, p.age / 0.06);
            const fadeOut = p.age > p.life * 0.68 ? Math.max(0, 1 - (p.age - p.life * 0.68) / (p.life * 0.32)) : 1;
            ctx.globalAlpha = fadeIn * fadeOut;
            const c = Math.cos(p.tilt);
            ctx.setTransform(this.dpr, 0, 0, this.dpr, 0, 0);
            ctx.translate(p.x, p.y);
            ctx.rotate(p.rot);

            if (p.kind === 'dot') {
                ctx.scale(1, Math.max(0.25, Math.abs(c)));
                ctx.beginPath();
                ctx.arc(0, 0, p.size / 2, 0, Math.PI * 2);
                ctx.fillStyle = p.color;
                ctx.fill();
                continue;
            }

            ctx.scale(p.size, p.size * (Math.abs(c) < 0.3 ? 0.3 * Math.sign(c || 1) : c));
            const path = this.paths[p.shape];
            ctx.fillStyle = c < 0 ? p.back : p.color;
            ctx.fill(path);
            ctx.lineJoin = 'round';
            ctx.lineWidth = 1.15 / p.size;
            ctx.strokeStyle = NAVY;
            ctx.stroke(path);
        }

        if (this.parts.length === 0) {
            this.removeCanvas();
            return false;
        }

        return true;
    }

    removeCanvas() {
        if (this.canvas) {
            this.canvas.remove();
        }

        this.canvas = null;
        this.ctx = null;
    }

    clear() {
        this.parts = [];
        this.ticker.remove(this.onTick);
        this.removeCanvas();
    }
}
