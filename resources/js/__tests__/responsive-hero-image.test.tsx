import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import ResponsiveHeroImage, { focalPosition } from '../Components/Media/ResponsiveHeroImage';

describe('ResponsiveHeroImage', () => {
    it('renders WebP and JPEG srcsets with the focal point and lazy loading', () => {
        const { container } = render(
            <ResponsiveHeroImage
                image={{
                    hero_image: '/storage/media/a/w2000.jpg',
                    hero_image_alt: 'Rescued parrot on a perch',
                    hero_image_srcset: {
                        webp: '/storage/media/a/w640.webp 640w, /storage/media/a/w2000.webp 2000w',
                        jpeg: '/storage/media/a/w640.jpg 640w, /storage/media/a/w2000.jpg 2000w',
                    },
                    hero_image_width: 2000,
                    hero_image_height: 1333,
                    hero_image_focal: { x: 0.25, y: 0.8 },
                }}
                sizes="100vw"
            />,
        );

        const img = screen.getByRole('img', { name: 'Rescued parrot on a perch' });
        expect(img).toHaveAttribute('loading', 'lazy');
        expect(img).toHaveAttribute('srcset', expect.stringContaining('w640.jpg 640w'));
        expect(img).toHaveAttribute('width', '2000');
        expect(img.style.objectPosition).toBe('25% 80%');

        const source = container.querySelector('source');
        expect(source).toHaveAttribute('type', 'image/webp');
        expect(source).toHaveAttribute('srcset', expect.stringContaining('w2000.webp 2000w'));
    });

    it('renders a plain eager image for external URLs when marked as priority', () => {
        const { container } = render(
            <ResponsiveHeroImage image={{ hero_image: 'https://images.example.test/a.jpg', hero_image_alt: 'Lemur' }} sizes="100vw" priority />,
        );

        const img = screen.getByRole('img', { name: 'Lemur' });
        expect(img).toHaveAttribute('loading', 'eager');
        expect(img).not.toHaveAttribute('srcset');
        expect(container.querySelector('picture')).toBeNull();
    });

    it('renders nothing without an image', () => {
        const { container } = render(<ResponsiveHeroImage image={{ hero_image: null }} sizes="100vw" />);
        expect(container).toBeEmptyDOMElement();
    });

    it('formats focal points as CSS positions', () => {
        expect(focalPosition({ x: 0.5, y: 0.333 })).toBe('50% 33%');
        expect(focalPosition(null)).toBeUndefined();
    });
});
