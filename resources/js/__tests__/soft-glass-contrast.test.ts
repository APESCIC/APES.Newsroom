import { describe, expect, it } from 'vitest';
import css from '../../css/app.css?raw';

const sources = import.meta.glob<string>(['../**/*.{ts,tsx}', '!../__tests__/**'], { query: '?raw', import: 'default', eager: true });

type Rgb = [number, number, number];

function token(name: string): Rgb {
    const match = css.match(new RegExp(`--${name}:\\s*(#[0-9a-fA-F]{6})\\s*;`));
    if (!match) throw new Error(`Token --${name} is not a hex colour in app.css`);
    const hex = match[1];
    return [1, 3, 5].map((index) => parseInt(hex.slice(index, index + 2), 16)) as Rgb;
}

function over(top: Rgb, alpha: number, bottom: Rgb): Rgb {
    return top.map((channel, index) => Math.round(channel * alpha + bottom[index] * (1 - alpha))) as Rgb;
}

function luminance(colour: Rgb): number {
    const [r, g, b] = colour.map((channel) => {
        const value = channel / 255;
        return value <= 0.03928 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4;
    });
    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

function contrast(a: Rgb, b: Rgb): number {
    const [light, dark] = [luminance(a), luminance(b)].sort((x, y) => y - x);
    return (light + 0.05) / (dark + 0.05);
}

const white: Rgb = [255, 255, 255];
const base = token('color-sg-base');
const ink = token('color-sg-ink');
const muted = token('color-sg-muted');
const teal = token('color-sg-teal');
const tealDark = token('color-sg-teal-dark');
const accent = token('color-sg-accent');

/** Peaks of the three radial washes over the base, as in .public-shell::before. */
const washes: Record<string, Rgb> = {
    cyan: over([127, 231, 234], 0.6, base),
    lavender: over([185, 198, 255], 0.55, base),
    mint: over([167, 243, 208], 0.55, base),
};

/** A frosted card (white 45%) over each wash peak: the darkest card backgrounds. */
const cards = Object.fromEntries(Object.entries(washes).map(([name, wash]) => [name, over(white, 0.45, wash)]));

describe('Soft glass contrast (WCAG AA)', () => {
    it('meets 4.5:1 for every text pair in the design reference', () => {
        const pairs: Array<[string, Rgb, Rgb]> = [
            ['ink on base', ink, base],
            ['muted on base', muted, base],
            ['white on primary', white, teal],
            ['white on primary hover', white, tealDark],
            ['primary link on base', teal, base],
            ['primary link on white', teal, white],
            ['ok badge', token('color-sg-ok'), token('color-sg-ok-mist')],
            ['warn badge', token('color-sg-warn'), token('color-sg-warn-mist')],
            ['error badge', token('color-sg-error'), token('color-sg-error-mist')],
            ['info badge', token('color-sg-info'), token('color-sg-info-mist')],
            ['ink on info mist (callout)', ink, token('color-sg-info-mist')],
            ['ink on warn mist (legacy block)', ink, token('color-sg-warn-mist')],
            ['apes channel badge', token('color-apes-primary'), token('color-apes-mist')],
            ['shelter channel badge', token('color-shelter-text'), token('color-shelter-mist')],
            ['clinic channel badge', token('color-clinic-text'), token('color-clinic-mist')],
            ['times channel badge', token('color-times-text'), token('color-times-mist')],
            ['portal channel badge', token('color-portal-text'), token('color-portal-mist')],
            ['stream channel badge', token('color-stream-text'), token('color-stream-mist')],
        ];

        for (const [name, wash] of Object.entries(cards)) {
            pairs.push([`ink on a card over the ${name} wash`, ink, wash]);
            pairs.push([`muted on a card over the ${name} wash`, muted, wash]);
            pairs.push([`primary link on a card over the ${name} wash`, teal, wash]);
            pairs.push([`muted directly on the ${name} wash`, muted, washes[name]]);
        }

        const failing = pairs
            .map(([name, foreground, background]) => ({ name, ratio: contrast(foreground, background) }))
            .filter(({ ratio }) => ratio < 4.5)
            .map(({ name, ratio }) => `${name}: ${ratio.toFixed(2)}`);

        expect(failing).toEqual([]);
    });

    it('meets 3:1 for buttons, channel accents and borders', () => {
        const pairs: Array<[string, Rgb, Rgb]> = [
            ['primary button on base', teal, base],
            ['stream border on base', token('color-stream-accent'), base],
            ['stream border on white', token('color-stream-accent'), white],
            ['apes border on base', token('color-apes-primary'), base],
            ['shelter border on base', token('color-shelter-accent'), base],
            ['clinic border on base', token('color-clinic-accent'), base],
            ['times border on base', token('color-times-accent'), base],
            ['portal border on base', token('color-portal-accent'), base],
        ];

        const failing = pairs
            .map(([name, foreground, background]) => ({ name, ratio: contrast(foreground, background) }))
            .filter(({ ratio }) => ratio < 3)
            .map(({ name, ratio }) => `${name}: ${ratio.toFixed(2)}`);

        expect(failing).toEqual([]);
    });

    it('keeps the #00C4CC accent off text, because it fails contrast', () => {
        expect(contrast(accent, white)).toBeLessThan(4.5);
        expect(css).not.toMatch(/(^|[;{\s])color:\s*var\(--color-sg-accent\)/m);

        expect(Object.keys(sources).length).toBeGreaterThan(50);
        const offenders = Object.entries(sources)
            .filter(([, source]) => /\btext-sg-accent\b|text-\[#00C4CC\]/i.test(source))
            .map(([file]) => file);
        expect(offenders).toEqual([]);
    });
});
