type LogoVariant = 'horizontal' | 'masthead' | 'footer' | 'square' | 'compact' | 'mark';

const logoFiles: Record<LogoVariant, { src: string; width: number; height: number }> = {
    horizontal: { src: '/brand/apes-logo-horizontal.png', width: 440, height: 250 },
    masthead: { src: '/brand/apes-logo-masthead.png', width: 195, height: 145 },
    footer: { src: '/brand/apes-logo-footer-64.png', width: 64, height: 64 },
    square: { src: '/brand/apes-logo-square.png', width: 1024, height: 1024 },
    compact: { src: '/brand/apes-logo-compact.png', width: 512, height: 512 },
    mark: { src: '/brand/apes-logo-mark.png', width: 240, height: 154 },
};

export default function ApesLogo({
    variant,
    className = '',
    alt = 'APES Newsroom',
}: {
    variant: LogoVariant;
    className?: string;
    alt?: string;
}) {
    const logo = logoFiles[variant];

    if (variant === 'square') {
        return (
            <picture>
                <source
                    type="image/webp"
                    srcSet="/brand/apes-logo-square-384.webp 384w, /brand/apes-logo-square-768.webp 768w"
                    sizes="(min-width: 768px) 384px, calc(100vw - 6.5rem)"
                />
                <img
                    src={logo.src}
                    width={logo.width}
                    height={logo.height}
                    alt={alt}
                    className={className}
                    decoding="async"
                />
            </picture>
        );
    }

    return (
        <img
            src={logo.src}
            width={logo.width}
            height={logo.height}
            alt={alt}
            className={className}
            decoding="async"
        />
    );
}
