export type StaffMediaKind = 'image' | 'file';

export type StaffMediaUploadResponse = {
    success?: number;
    file?: {
        url?: string;
        title?: string | null;
        size?: string | null;
    };
    message?: string;
};

function csrfToken(): string {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

/**
 * Multipart upload to staff media endpoint (#75).
 */
export async function uploadStaffMedia(
    file: File,
    kind: StaffMediaKind,
): Promise<StaffMediaUploadResponse> {
    const body = new FormData();
    body.append('file', file);
    body.append('kind', kind);

    const response = await fetch('/staff/media/upload', {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
            'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'same-origin',
        body,
    });

    const payload = (await response.json()) as StaffMediaUploadResponse;

    if (!response.ok || payload.success !== 1 || !payload.file?.url) {
        throw new Error(payload.message ?? `Upload failed (${file.name}).`);
    }

    return payload;
}
