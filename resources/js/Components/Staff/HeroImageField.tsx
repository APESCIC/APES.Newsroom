import { type ClipboardEvent, type DragEvent, type ReactNode, useEffect, useRef, useState } from 'react';
import FocalPointPicker from './FocalPointPicker';
import {
    defaultHeroUploadLimits,
    fetchHeroAsset,
    type HeroMediaAsset,
    type HeroUploadLimits,
    typeList,
    uploadHeroImage,
    validateHeroFile,
} from './heroImageUpload';
import UnsplashPicker from './UnsplashPicker';

export type HeroImageValue = {
    hero_image: string;
    hero_image_alt: string;
    hero_image_caption: string;
    hero_image_credit: string;
    hero_media_id: number | null;
    hero_focal_x: number | null;
    hero_focal_y: number | null;
};

export const emptyHeroImage: HeroImageValue = {
    hero_image: '',
    hero_image_alt: '',
    hero_image_caption: '',
    hero_image_credit: '',
    hero_media_id: null,
    hero_focal_x: null,
    hero_focal_y: null,
};

type UploadState =
    | { kind: 'idle' }
    | { kind: 'uploading'; percent: number; name: string }
    | { kind: 'processing' }
    | { kind: 'ready' }
    | { kind: 'failed'; message: string }
    | { kind: 'error'; message: string };

type HeroImageFieldProps = {
    value: HeroImageValue;
    onChange: (patch: Partial<HeroImageValue>) => void;
    errors: Record<string, string | undefined>;
    limits?: HeroUploadLimits;
    asset?: HeroMediaAsset | null;
    idPrefix?: string;
    libraryPicker?: (onPick: (asset: HeroMediaAsset) => void) => ReactNode;
};

const POLL_INTERVAL_MS = 1500;
const POLL_ATTEMPTS = 120;

function initialState(asset: HeroMediaAsset | null | undefined): UploadState {
    if (!asset) {
        return { kind: 'idle' };
    }

    if (asset.status === 'pending') {
        return { kind: 'processing' };
    }

    return asset.status === 'failed' ? { kind: 'failed', message: asset.failure_reason ?? 'Processing failed.' } : { kind: 'idle' };
}

function FieldMessage({ message, id }: { message?: string; id?: string }) {
    if (!message) {
        return null;
    }

    return (
        <p id={id} className="text-sm text-red-600" role="alert">
            {message}
        </p>
    );
}

/**
 * Hero image section shared by the post and page editors (#535, #536):
 * upload (drop, pick or paste), URL, Unsplash, alt text, caption, credit and
 * focal point.
 */
export default function HeroImageField({
    value,
    onChange,
    errors,
    limits = defaultHeroUploadLimits,
    asset = null,
    idPrefix = 'hero',
    libraryPicker,
}: HeroImageFieldProps) {
    const fileInput = useRef<HTMLInputElement | null>(null);
    const [state, setState] = useState<UploadState>(() => initialState(asset));
    const [pollingId, setPollingId] = useState<number | null>(asset?.status === 'pending' ? asset.id : null);
    const [dragging, setDragging] = useState(false);
    const busy = state.kind === 'uploading';

    useEffect(() => {
        if (pollingId === null) {
            return;
        }

        let attempts = 0;
        let cancelled = false;
        const timer = window.setInterval(() => {
            attempts++;
            fetchHeroAsset(pollingId)
                .then((latest) => {
                    if (cancelled || latest.status === 'pending') {
                        return;
                    }

                    window.clearInterval(timer);
                    setPollingId(null);
                    setState(
                        latest.status === 'ready'
                            ? { kind: 'ready' }
                            : { kind: 'failed', message: latest.failure_reason ?? 'Processing failed.' },
                    );
                })
                .catch(() => undefined);

            if (attempts >= POLL_ATTEMPTS) {
                window.clearInterval(timer);
                setPollingId(null);
            }
        }, POLL_INTERVAL_MS);

        return () => {
            cancelled = true;
            window.clearInterval(timer);
        };
    }, [pollingId]);

    const applyAsset = (picked: HeroMediaAsset) => {
        onChange({
            hero_image: picked.url,
            hero_media_id: picked.id,
            hero_focal_x: null,
            hero_focal_y: null,
            hero_image_credit: '',
            ...(value.hero_image_alt.trim() === '' && picked.alt ? { hero_image_alt: picked.alt } : {}),
        });

        if (picked.status === 'pending') {
            setState({ kind: 'processing' });
            setPollingId(picked.id);
        } else if (picked.status === 'failed') {
            setState({ kind: 'failed', message: picked.failure_reason ?? 'Processing failed.' });
        } else {
            setState({ kind: 'ready' });
        }
    };

    const startUpload = (file: File) => {
        const problem = validateHeroFile(file, limits);
        if (problem) {
            setState({ kind: 'error', message: problem });

            return;
        }

        setState({ kind: 'uploading', percent: 0, name: file.name });
        uploadHeroImage(file, (percent) => setState({ kind: 'uploading', percent, name: file.name }))
            .then(applyAsset)
            .catch((error: Error) => setState({ kind: 'error', message: error.message }));
    };

    const onDrop = (event: DragEvent<HTMLDivElement>) => {
        event.preventDefault();
        setDragging(false);
        const file = event.dataTransfer.files?.[0];
        if (file) {
            startUpload(file);
        }
    };

    const onPaste = (event: ClipboardEvent<HTMLDivElement>) => {
        const file = Array.from(event.clipboardData?.files ?? []).find((item) => item.type.startsWith('image/'));
        if (file) {
            event.preventDefault();
            startUpload(file);
        }
    };

    const remove = () => {
        onChange({ ...emptyHeroImage });
        setPollingId(null);
        setState({ kind: 'idle' });
    };

    const hasImage = value.hero_image.trim() !== '';
    const focal = value.hero_focal_x !== null && value.hero_focal_y !== null ? { x: value.hero_focal_x, y: value.hero_focal_y } : null;
    const altId = `${idPrefix}-alt`;
    const altHelpId = `${idPrefix}-alt-help`;

    return (
        <fieldset className="flex flex-col gap-4 border-t border-border/60 pt-4">
            <legend className="font-medium">Hero image</legend>

            <div
                tabIndex={0}
                onDragOver={(event) => {
                    event.preventDefault();
                    setDragging(true);
                }}
                onDragLeave={() => setDragging(false)}
                onDrop={onDrop}
                onPaste={onPaste}
                aria-describedby={`${idPrefix}-drop-help`}
                aria-label="Hero image upload area"
                className={`flex flex-col gap-3 rounded-control border-2 border-dashed p-4 focus:outline-none focus-visible:ring-2 focus-visible:ring-teal-deep ${
                    dragging ? 'border-teal-deep bg-teal-50/60' : 'border-border'
                }`}
            >
                {hasImage ? (
                    <img
                        src={value.hero_image}
                        alt={value.hero_image_alt || 'Hero image preview'}
                        className="max-h-64 w-auto max-w-full self-start rounded-control object-contain"
                    />
                ) : (
                    <p className="text-sm text-muted">No hero image yet.</p>
                )}
                <p id={`${idPrefix}-drop-help`} className="text-sm text-muted">
                    Drag an image here, choose a file, or paste an image while this area is focused. {typeList(limits.types)}, up to{' '}
                    {limits.max_label}.
                </p>
                <div className="flex flex-wrap gap-2">
                    <button type="button" className="button-secondary" disabled={busy} onClick={() => fileInput.current?.click()}>
                        {hasImage ? 'Replace image' : 'Upload image'}
                    </button>
                    {libraryPicker?.(applyAsset)}
                    {hasImage && (
                        <button type="button" className="button-secondary" disabled={busy} onClick={remove}>
                            Remove image
                        </button>
                    )}
                </div>
                <input
                    ref={fileInput}
                    type="file"
                    accept={limits.types.join(',')}
                    className="sr-only"
                    tabIndex={-1}
                    aria-label="Hero image file"
                    onChange={(event) => {
                        const file = event.target.files?.[0];
                        event.target.value = '';
                        if (file) {
                            startUpload(file);
                        }
                    }}
                />
                <div aria-live="polite" className="text-sm">
                    {state.kind === 'uploading' && (
                        <div className="flex flex-col gap-1">
                            <span>
                                Uploading {state.name}: {state.percent}%
                            </span>
                            <progress max={100} value={state.percent} aria-label="Upload progress" className="w-full max-w-md" />
                        </div>
                    )}
                    {state.kind === 'processing' && <span>Uploaded. Creating web sizes...</span>}
                    {state.kind === 'ready' && <span className="text-success">Image ready.</span>}
                </div>
                {(state.kind === 'error' || state.kind === 'failed') && <FieldMessage message={state.message} />}
            </div>

            <div>
                <label htmlFor={`${idPrefix}-url`}>Image URL</label>
                <input
                    id={`${idPrefix}-url`}
                    placeholder="https://"
                    value={value.hero_image}
                    onChange={(event) => onChange({ hero_image: event.target.value, hero_media_id: null })}
                    className="form-input w-full"
                />
                <FieldMessage message={errors.hero_image ?? errors.hero_media_id} />
            </div>

            <div>
                <label htmlFor={altId}>Alt text{hasImage ? ' (required to publish)' : ''}</label>
                <input
                    id={altId}
                    value={value.hero_image_alt}
                    onChange={(event) => onChange({ hero_image_alt: event.target.value })}
                    aria-required={hasImage}
                    aria-invalid={errors.hero_image_alt ? true : undefined}
                    aria-describedby={altHelpId}
                    maxLength={255}
                    className="form-input w-full"
                />
                <p id={altHelpId} className="text-xs text-muted">
                    Describe what the image shows for people who cannot see it.
                </p>
                <FieldMessage message={errors.hero_image_alt} />
            </div>

            <div className="grid gap-4 sm:grid-cols-2">
                <div>
                    <label htmlFor={`${idPrefix}-caption`}>Caption (optional)</label>
                    <input
                        id={`${idPrefix}-caption`}
                        value={value.hero_image_caption}
                        onChange={(event) => onChange({ hero_image_caption: event.target.value })}
                        maxLength={500}
                        className="form-input w-full"
                    />
                    <FieldMessage message={errors.hero_image_caption} />
                </div>
                <div>
                    <label htmlFor={`${idPrefix}-credit`}>Credit (optional)</label>
                    <input
                        id={`${idPrefix}-credit`}
                        value={value.hero_image_credit}
                        onChange={(event) => onChange({ hero_image_credit: event.target.value })}
                        maxLength={255}
                        className="form-input w-full"
                    />
                    <FieldMessage message={errors.hero_image_credit} />
                </div>
            </div>

            {hasImage && (
                <FocalPointPicker
                    src={value.hero_image}
                    alt={value.hero_image_alt}
                    value={focal}
                    idPrefix={idPrefix}
                    onChange={(next) => onChange({ hero_focal_x: next.x, hero_focal_y: next.y })}
                />
            )}
            <FieldMessage message={errors.hero_focal_x ?? errors.hero_focal_y} />

            <UnsplashPicker
                onSelect={({ url, credit, alt }) => {
                    onChange({
                        hero_image: url,
                        hero_media_id: null,
                        hero_focal_x: null,
                        hero_focal_y: null,
                        hero_image_credit: credit,
                        ...(alt ? { hero_image_alt: alt } : {}),
                    });
                    setState({ kind: 'idle' });
                }}
            />
        </fieldset>
    );
}
