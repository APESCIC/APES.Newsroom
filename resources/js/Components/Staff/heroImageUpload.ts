export type HeroMediaAsset = {
    id: number;
    url: string;
    status: 'pending' | 'ready' | 'failed';
    failure_reason: string | null;
    width: number;
    height: number;
    mime: string;
    original_name: string | null;
    alt: string | null;
    thumbnail_url: string;
};

export type HeroUploadLimits = {
    max_kb: number;
    max_label: string;
    types: string[];
};

export const defaultHeroUploadLimits: HeroUploadLimits = {
    max_kb: 10240,
    max_label: '10 MB',
    types: ['image/jpeg', 'image/png', 'image/webp'],
};

const TYPE_LABELS: Record<string, string> = {
    'image/jpeg': 'JPEG',
    'image/png': 'PNG',
    'image/webp': 'WebP',
    'image/avif': 'AVIF',
};

export function typeList(types: string[]): string {
    const labels = types.map((type) => TYPE_LABELS[type] ?? type);
    if (labels.length <= 1) {
        return labels.join('');
    }

    return `${labels.slice(0, -1).join(', ')} or ${labels[labels.length - 1]}`;
}

/**
 * Quick client-side check so staff get an instant message; the server
 * re-checks the real file contents.
 */
export function validateHeroFile(file: File, limits: HeroUploadLimits): string | null {
    if (file.type === 'image/svg+xml') {
        return `SVG images are not accepted. Upload a ${typeList(limits.types)} image.`;
    }

    if (file.type !== '' && !limits.types.includes(file.type)) {
        return `This file is not a supported image. Upload a ${typeList(limits.types)} image.`;
    }

    if (file.size > limits.max_kb * 1024) {
        return `The image must be ${limits.max_label} or smaller.`;
    }

    return null;
}

function csrfToken(): string {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

function errorMessage(status: number, payload: unknown): string {
    const body = (payload ?? {}) as { errors?: Record<string, string[]>; message?: string };
    const fieldError = body.errors?.file?.[0];
    if (fieldError) {
        return fieldError;
    }

    if (status === 413) {
        return 'The image is larger than the server accepts.';
    }

    if (status === 429) {
        return 'Too many uploads in a short time. Wait a minute and try again.';
    }

    if (status === 401 || status === 419) {
        return 'Your session has expired. Reload the page and sign in again.';
    }

    return body.message || 'The upload failed. Try again.';
}

/**
 * XMLHttpRequest rather than fetch so the editor can show upload progress.
 */
export function uploadHeroImage(file: File, onProgress: (percent: number) => void): Promise<HeroMediaAsset> {
    return new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest();
        const body = new FormData();
        body.append('file', file);

        xhr.open('POST', '/staff/media/hero');
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.setRequestHeader('X-CSRF-TOKEN', csrfToken());
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.withCredentials = true;

        xhr.upload.onprogress = (event) => {
            if (event.lengthComputable) {
                onProgress(Math.round((event.loaded / event.total) * 100));
            }
        };

        xhr.onload = () => {
            let payload: unknown = null;
            try {
                payload = JSON.parse(xhr.responseText);
            } catch {
                payload = null;
            }

            const asset = (payload as { asset?: HeroMediaAsset } | null)?.asset;
            if (xhr.status >= 200 && xhr.status < 300 && asset) {
                onProgress(100);
                resolve(asset);

                return;
            }

            reject(new Error(errorMessage(xhr.status, payload)));
        };

        xhr.onerror = () => reject(new Error('The upload failed. Check your connection and try again.'));
        xhr.send(body);
    });
}

export async function fetchHeroAsset(id: number): Promise<HeroMediaAsset> {
    const response = await fetch(`/staff/media/${id}`, {
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
    });
    const payload = (await response.json()) as { asset?: HeroMediaAsset };
    if (!response.ok || !payload.asset) {
        throw new Error('Could not check the image status.');
    }

    return payload.asset;
}
