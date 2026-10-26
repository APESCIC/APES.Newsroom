import type { ImgHTMLAttributes } from 'react';

export type HeroFocal = { x: number; y: number };

export type HeroImageFields = {
    hero_image?: string | null;
    hero_image_alt?: string | null;
    hero_image_srcset?: { webp: string; jpeg: string } | null;
    hero_image_width?: number | null;
    hero_image_height?: number | null;
    hero_image_focal?: HeroFocal | null;
    hero_image_social?: string | null;
};

export function focalPosition(focal?: HeroFocal | null): string | undefined {
    if (!focal) {
        return undefined;
    }

    return `${Math.round(focal.x * 100)}% ${Math.round(focal.y * 100)}%`;
}

type ResponsiveHeroImageProps = {
    image: HeroImageFields;
    alt?: string;
    sizes: string;
    className?: string;
    priority?: boolean;
};

/**
 * Hero image with WebP and JPEG srcsets when the image was uploaded, and the
 * focal point applied as object-position so cover crops keep the subject.
 */
export default function ResponsiveHeroImage({ image, alt, sizes, className, priority = false }: ResponsiveHeroImageProps) {
    if (!image.hero_image) {
        return null;
    }

    const loadingProps: ImgHTMLAttributes<HTMLImageElement> = priority
        ? { loading: 'eager', fetchPriority: 'high' }
        : { loading: 'lazy' };

    const img = (
        <img
            src={image.hero_image}
            srcSet={image.hero_image_srcset?.jpeg}
            sizes={image.hero_image_srcset ? sizes : undefined}
            width={image.hero_image_width ?? undefined}
            height={image.hero_image_height ?? undefined}
            alt={alt ?? image.hero_image_alt ?? ''}
            className={className}
            style={{ objectPosition: focalPosition(image.hero_image_focal) }}
            decoding="async"
            {...loadingProps}
        />
    );

    if (!image.hero_image_srcset) {
        return img;
    }

    return (
        <picture>
            <source type="image/webp" srcSet={image.hero_image_srcset.webp} sizes={sizes} />
            {img}
        </picture>
    );
}
