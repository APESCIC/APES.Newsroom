import type { MouseEvent } from 'react';
import { focalPosition, type HeroFocal } from '../Media/ResponsiveHeroImage';

type FocalPointPickerProps = {
    src: string;
    alt: string;
    value: HeroFocal | null;
    onChange: (focal: HeroFocal) => void;
    idPrefix: string;
};

function clamp(value: number): number {
    return Math.min(1, Math.max(0, Math.round(value * 100) / 100));
}

/**
 * Choose the part of the hero image that must stay visible when it is cropped
 * for cards and social previews. Click the image, or use the two sliders.
 */
export default function FocalPointPicker({ src, alt, value, onChange, idPrefix }: FocalPointPickerProps) {
    const focal = value ?? { x: 0.5, y: 0.5 };
    const position = focalPosition(focal);

    const pick = (event: MouseEvent<HTMLButtonElement>) => {
        const rect = event.currentTarget.getBoundingClientRect();
        if (rect.width === 0 || rect.height === 0) {
            return;
        }

        onChange({
            x: clamp((event.clientX - rect.left) / rect.width),
            y: clamp((event.clientY - rect.top) / rect.height),
        });
    };

    return (
        <div className="flex flex-col gap-3">
            <p id={`${idPrefix}-focal-help`} className="text-sm text-muted">
                Focal point: click the part of the image to keep in view, or use the sliders. Cards and social previews are cropped
                around it.
            </p>
            <button
                type="button"
                onClick={pick}
                className="relative block w-full max-w-md cursor-crosshair overflow-hidden rounded-control border border-border"
                aria-describedby={`${idPrefix}-focal-help`}
                aria-label="Set focal point by clicking the image"
            >
                <img src={src} alt="" className="block h-auto w-full" />
                <span
                    aria-hidden="true"
                    className="pointer-events-none absolute h-6 w-6 -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-white shadow-[0_0_0_2px_rgba(0,0,0,0.6)]"
                    style={{ left: `${focal.x * 100}%`, top: `${focal.y * 100}%` }}
                />
            </button>
            <div className="grid max-w-md gap-3 sm:grid-cols-2">
                <label className="flex flex-col gap-1 text-sm" htmlFor={`${idPrefix}-focal-x`}>
                    Horizontal ({Math.round(focal.x * 100)}%)
                    <input
                        id={`${idPrefix}-focal-x`}
                        type="range"
                        min={0}
                        max={100}
                        step={1}
                        value={Math.round(focal.x * 100)}
                        aria-valuetext={`${Math.round(focal.x * 100)}% from the left`}
                        onChange={(e) => onChange({ ...focal, x: clamp(Number(e.target.value) / 100) })}
                    />
                </label>
                <label className="flex flex-col gap-1 text-sm" htmlFor={`${idPrefix}-focal-y`}>
                    Vertical ({Math.round(focal.y * 100)}%)
                    <input
                        id={`${idPrefix}-focal-y`}
                        type="range"
                        min={0}
                        max={100}
                        step={1}
                        value={Math.round(focal.y * 100)}
                        aria-valuetext={`${Math.round(focal.y * 100)}% from the top`}
                        onChange={(e) => onChange({ ...focal, y: clamp(Number(e.target.value) / 100) })}
                    />
                </label>
            </div>
            <div className="flex flex-wrap items-end gap-4" aria-hidden="true" title={alt}>
                <figure className="w-40">
                    <img src={src} alt="" className="aspect-video w-full rounded-control object-cover" style={{ objectPosition: position }} />
                    <figcaption className="mt-1 text-xs text-muted">Card</figcaption>
                </figure>
                <figure className="w-24">
                    <img src={src} alt="" className="aspect-[3/4] w-full rounded-control object-cover" style={{ objectPosition: position }} />
                    <figcaption className="mt-1 text-xs text-muted">Featured</figcaption>
                </figure>
                <figure className="w-48">
                    <img src={src} alt="" className="aspect-[1200/630] w-full rounded-control object-cover" style={{ objectPosition: position }} />
                    <figcaption className="mt-1 text-xs text-muted">Social preview</figcaption>
                </figure>
            </div>
        </div>
    );
}
