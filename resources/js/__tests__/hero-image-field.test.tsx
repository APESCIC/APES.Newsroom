import { act, fireEvent, render, screen } from '@testing-library/react';
import { useState } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import HeroImageField, { emptyHeroImage, type HeroImageValue } from '../Components/Staff/HeroImageField';
import type * as HeroUploadModule from '../Components/Staff/heroImageUpload';
import type { HeroMediaAsset } from '../Components/Staff/heroImageUpload';

const { uploadHeroImage, fetchHeroAsset } = vi.hoisted(() => ({
    uploadHeroImage: vi.fn(),
    fetchHeroAsset: vi.fn(),
}));

vi.mock('../Components/Staff/heroImageUpload', async (importOriginal) => ({
    ...(await importOriginal<typeof HeroUploadModule>()),
    uploadHeroImage,
    fetchHeroAsset,
}));

vi.mock('../Components/Staff/UnsplashPicker', () => ({ default: () => null }));

const limits = { max_kb: 1024, max_label: '1 MB', types: ['image/jpeg', 'image/png', 'image/webp'] };

function asset(overrides: Partial<HeroMediaAsset> = {}): HeroMediaAsset {
    return {
        id: 7,
        url: 'http://localhost/storage/media/2026/10/x/original.jpg',
        status: 'pending',
        failure_reason: null,
        width: 1200,
        height: 800,
        mime: 'image/jpeg',
        original_name: 'lemur',
        alt: null,
        thumbnail_url: 'http://localhost/storage/media/2026/10/x/original.jpg',
        ...overrides,
    };
}

let latest: HeroImageValue = emptyHeroImage;

function Harness({ initial = emptyHeroImage, errors = {} }: { initial?: HeroImageValue; errors?: Record<string, string> }) {
    const [value, setValue] = useState<HeroImageValue>(initial);
    latest = value;

    return (
        <HeroImageField
            value={value}
            limits={limits}
            errors={errors}
            onChange={(patch) => setValue((current) => ({ ...current, ...patch }))}
        />
    );
}

function chooseFile(file: File) {
    fireEvent.change(screen.getByLabelText('Hero image file'), { target: { files: [file] } });
}

describe('HeroImageField', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        uploadHeroImage.mockReset();
        fetchHeroAsset.mockReset();
        latest = emptyHeroImage;
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('rejects unsupported types and oversize files with clear messages, without uploading', () => {
        render(<Harness />);

        chooseFile(new File(['<svg/>'], 'logo.svg', { type: 'image/svg+xml' }));
        expect(screen.getByRole('alert')).toHaveTextContent('SVG images are not accepted. Upload a JPEG, PNG or WebP image.');

        chooseFile(new File(['GIF89a'], 'anim.gif', { type: 'image/gif' }));
        expect(screen.getByRole('alert')).toHaveTextContent('This file is not a supported image. Upload a JPEG, PNG or WebP image.');

        chooseFile(new File([new Uint8Array(1024 * 1024 + 1)], 'huge.jpg', { type: 'image/jpeg' }));
        expect(screen.getByRole('alert')).toHaveTextContent('The image must be 1 MB or smaller.');

        expect(uploadHeroImage).not.toHaveBeenCalled();
    });

    it('uploads, shows progress, then reports when processing finishes', async () => {
        let report: (percent: number) => void = () => undefined;
        let finish: (value: HeroMediaAsset) => void = () => undefined;
        uploadHeroImage.mockImplementation((_file: File, onProgress: (percent: number) => void) => {
            report = onProgress;

            return new Promise<HeroMediaAsset>((resolve) => {
                finish = resolve;
            });
        });
        fetchHeroAsset.mockResolvedValue(asset({ status: 'ready' }));
        render(<Harness />);

        chooseFile(new File(['jpeg'], 'lemur.jpg', { type: 'image/jpeg' }));
        act(() => report(45));
        expect(screen.getByText('Uploading lemur.jpg: 45%')).toBeInTheDocument();
        expect(screen.getByRole('progressbar', { name: 'Upload progress' })).toHaveAttribute('value', '45');

        await act(async () => finish(asset()));
        expect(screen.getByText('Uploaded. Creating web sizes...')).toBeInTheDocument();
        expect(latest).toMatchObject({ hero_media_id: 7, hero_image: 'http://localhost/storage/media/2026/10/x/original.jpg' });

        await act(async () => {
            vi.advanceTimersByTime(1500);
        });
        expect(fetchHeroAsset).toHaveBeenCalledWith(7);
        expect(screen.getByText('Image ready.')).toBeInTheDocument();
    });

    it('shows server rejection messages', async () => {
        uploadHeroImage.mockRejectedValue(new Error('This file is not a supported image. Upload a JPEG, PNG, WebP or AVIF image.'));
        render(<Harness />);

        chooseFile(new File(['not really'], 'fake.jpg', { type: 'image/jpeg' }));
        await act(async () => {});

        expect(screen.getByRole('alert')).toHaveTextContent('This file is not a supported image.');
    });

    it('shows processing failures reported by the server', async () => {
        uploadHeroImage.mockResolvedValue(asset({ status: 'failed', failure_reason: 'Processing failed: corrupt data' }));
        render(<Harness />);

        chooseFile(new File(['jpeg'], 'broken.jpg', { type: 'image/jpeg' }));
        await act(async () => {});

        expect(screen.getByRole('alert')).toHaveTextContent('Processing failed: corrupt data');
    });

    it('accepts dropped and pasted images', async () => {
        uploadHeroImage.mockResolvedValue(asset({ status: 'ready' }));
        render(<Harness />);
        const zone = screen.getByLabelText('Hero image upload area');
        const file = new File(['jpeg'], 'dropped.jpg', { type: 'image/jpeg' });

        fireEvent.drop(zone, { dataTransfer: { files: [file] } });
        await act(async () => {});
        expect(uploadHeroImage).toHaveBeenCalledWith(file, expect.any(Function));

        const pasted = new File(['png'], 'pasted.png', { type: 'image/png' });
        fireEvent.paste(zone, { clipboardData: { files: [pasted] } });
        await act(async () => {});
        expect(uploadHeroImage).toHaveBeenLastCalledWith(pasted, expect.any(Function));
    });

    it('offers keyboard-reachable replace and remove buttons, and remove clears every hero field', () => {
        render(
            <Harness
                initial={{
                    ...emptyHeroImage,
                    hero_image: 'http://localhost/storage/media/a/original.jpg',
                    hero_image_alt: 'Lemur',
                    hero_image_credit: 'APES',
                    hero_media_id: 3,
                    hero_focal_x: 0.2,
                    hero_focal_y: 0.4,
                }}
            />,
        );

        const replace = screen.getByRole('button', { name: 'Replace image' });
        replace.focus();
        expect(replace).toHaveFocus();
        expect(screen.getByLabelText('Hero image upload area')).toHaveAttribute('tabindex', '0');

        fireEvent.click(screen.getByRole('button', { name: 'Remove image' }));
        expect(latest).toEqual(emptyHeroImage);
        expect(screen.getByRole('button', { name: 'Upload image' })).toBeInTheDocument();
    });

    it('updates the focal point from labelled sliders', () => {
        render(<Harness initial={{ ...emptyHeroImage, hero_image: 'https://images.example.test/a.jpg', hero_image_alt: 'A' }} />);

        fireEvent.change(screen.getByLabelText(/^Horizontal/), { target: { value: '20' } });
        fireEvent.change(screen.getByLabelText(/^Vertical/), { target: { value: '75' } });

        expect(latest).toMatchObject({ hero_focal_x: 0.2, hero_focal_y: 0.75 });
        expect(screen.getByLabelText(/^Vertical/)).toHaveAttribute('aria-valuetext', '75% from the top');
    });

    it('editing the URL detaches the uploaded asset, and alt text errors are announced', () => {
        render(
            <Harness
                initial={{ ...emptyHeroImage, hero_image: 'http://localhost/storage/media/a/original.jpg', hero_media_id: 3 }}
                errors={{ hero_image_alt: 'Add alt text for the hero image before publishing.' }}
            />,
        );

        expect(screen.getByLabelText('Alt text (required to publish)')).toHaveAttribute('aria-invalid', 'true');
        expect(screen.getByRole('alert')).toHaveTextContent('Add alt text for the hero image before publishing.');

        fireEvent.change(screen.getByLabelText('Image URL'), { target: { value: 'https://images.example.test/b.jpg' } });
        expect(latest).toMatchObject({ hero_image: 'https://images.example.test/b.jpg', hero_media_id: null });
    });
});
