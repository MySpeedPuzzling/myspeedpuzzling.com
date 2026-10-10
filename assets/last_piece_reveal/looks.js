/*
 * What the last-piece reveal adds around a picture, per tier. The reveal never draws a badge itself (the badge art
 * comes from public/img/badges/); it only tints the puzzle card behind the picture, the burst behind the puzzle, the
 * confetti and the sound - more of everything the higher the tier, the pieces and their timing stay the same, so the
 * moment is recognisable. T2-T4 end with one white glint, T5 with the prismatic shimmer (no sparkle stars: the F1 T5
 * art decision of 2026-10-10 applies to the reveal too).
 *
 * `single` = a single-tier achievement (Supporter), `level` / `level-max` = the level medallion of the XP launch reveal.
 */
export const LOOKS = {
    1: {
        card: ['#ffffff', '#fbf3ee', '#f3dfd1'],
        ring: '#ef9f78',
        rays: ['#fb8180', '#eea47a', '#75b5fa', '#8fd5da'],
        confetti: { count: 36, colors: ['#fb8180', '#eea47a', '#75b5fa', '#8fd5da', '#fcd2b8'], dots: 0.14 },
        chime: [783.99, 1046.5],
        glint: false,
        shimmer: false,
    },
    2: {
        card: ['#ffffff', '#f4f7fb', '#e2e9f3'],
        ring: '#9aa9c2',
        rays: ['#75b5fa', '#c8d2e3', '#fb8180', '#8fd5da'],
        confetti: { count: 44, colors: ['#c8d2e3', '#75b5fa', '#fb8180', '#8fd5da', '#eef1f9'], dots: 0.14 },
        chime: [659.25, 987.77],
        glint: true,
        shimmer: false,
    },
    3: {
        card: ['#ffffff', '#fdf7e7', '#f8e9c0'],
        ring: '#f6b24c',
        rays: ['#fdd26f', '#fb8180', '#75b5fa', '#9a93f4'],
        confetti: { count: 56, colors: ['#fdd26f', '#fb8180', '#75b5fa', '#9a93f4', '#8fd5da'], dots: 0.12 },
        chime: [659.25, 783.99, 1046.5],
        glint: true,
        shimmer: false,
    },
    4: {
        card: ['#ffffff', '#f6f4fe', '#e6e2fb'],
        ring: '#9a91ea',
        rays: ['#c3bcf6', '#9a93f4', '#75b5fa', '#fb8180'],
        confetti: { count: 68, colors: ['#c3bcf6', '#9a93f4', '#75b5fa', '#fb8180', '#8fd5da'], dots: 0.12 },
        chime: [587.33, 739.99, 880, 1174.66],
        glint: true,
        shimmer: false,
    },
    5: {
        card: ['#ffffff', '#f2f7fd', '#e2ecf9'],
        ring: '#9a93f4',
        rays: ['#ffb3cf', '#fdd26f', '#8fd5da', '#75b5fa', '#9a93f4'],
        confetti: { count: 86, colors: ['#bfe9f6', '#c3bcf6', '#ffb8d2', '#8fd5da', '#a6ccfd', '#fde3a7'], dots: 0.1 },
        chime: [523.25, 659.25, 783.99, 1046.5, 1318.51],
        glint: false,
        shimmer: true,
    },
    single: {
        card: ['#ffffff', '#fff4f3', '#fde0de'],
        ring: '#ec726f',
        rays: ['#fb8180', '#fdd26f', '#75b5fa', '#8fd5da'],
        confetti: { count: 56, colors: ['#fb8180', '#fcd2b8', '#75b5fa', '#fdd26f', '#8fd5da'], dots: 0.12 },
        chime: [659.25, 783.99, 1046.5],
        glint: true,
        shimmer: false,
    },
    level: {
        card: ['#ffffff', '#f1f7ff', '#dde9fd'],
        ring: '#69b3fe',
        rays: ['#69b3fe', '#9a93f4', '#fb8180', '#fdd26f'],
        confetti: { count: 64, colors: ['#a6ccfd', '#c3bcf6', '#fb8180', '#fde3a7', '#8fd5da'], dots: 0.12 },
        chime: [587.33, 739.99, 880, 1174.66],
        glint: true,
        shimmer: false,
    },
    'level-max': {
        card: ['#ffffff', '#fff8e6', '#fbe7b0'],
        ring: '#f0a500',
        rays: ['#fdd26f', '#fb8180', '#9a93f4', '#75b5fa'],
        confetti: { count: 86, colors: ['#fde3a7', '#fdd26f', '#ffb8d2', '#c3bcf6', '#a6ccfd'], dots: 0.1 },
        chime: [523.25, 659.25, 783.99, 1046.5, 1318.51],
        glint: false,
        shimmer: true,
    },
};

export function lookFor(key) {
    return LOOKS[key] || LOOKS[String(key)] || LOOKS.single;
}
