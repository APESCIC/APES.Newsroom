import { uploadStaffMedia } from './uploadStaffMedia';

type FileData = {
    url: string;
    title?: string;
    size?: string;
};

/**
 * File attachment block with URL input or trusted staff upload (#73 / #75).
 */
export default class FileTool {
    static get toolbox() {
        return {
            title: 'File',
            icon: '<svg width="17" height="15" viewBox="0 0 17 15" xmlns="http://www.w3.org/2000/svg"><path d="M4 1h6l4 4v9H4V1z"/><path d="M10 1v4h4"/></svg>',
        };
    }

    private data: FileData;
    private readonly wrapper: HTMLElement;

    constructor({ data }: { data?: Partial<FileData> }) {
        this.data = {
            url: data?.url ?? '',
            title: data?.title ?? '',
            size: data?.size ?? '',
        };
        this.wrapper = document.createElement('div');
    }

    render(): HTMLElement {
        this.wrapper.classList.add('ce-file');
        this.wrapper.replaceChildren();

        const url = document.createElement('input');
        url.type = 'url';
        url.placeholder = 'File URL';
        url.value = this.data.url;
        url.addEventListener('input', () => {
            this.data.url = url.value;
        });

        const title = document.createElement('input');
        title.type = 'text';
        title.placeholder = 'Title (optional)';
        title.value = this.data.title ?? '';
        title.addEventListener('input', () => {
            this.data.title = title.value;
        });

        const size = document.createElement('input');
        size.type = 'text';
        size.placeholder = 'Size label (optional)';
        size.value = this.data.size ?? '';
        size.addEventListener('input', () => {
            this.data.size = size.value;
        });

        const status = document.createElement('p');
        status.className = 'ce-file__status';
        status.hidden = true;

        const picker = document.createElement('input');
        picker.type = 'file';
        picker.accept = '.pdf,.zip,.txt,.doc,.docx,.jpg,.jpeg,.png,.webp,.gif';
        picker.addEventListener('change', async () => {
            const file = picker.files?.[0];
            if (!file) {
                return;
            }

            status.hidden = false;
            status.textContent = `Uploading ${file.name}…`;

            try {
                const payload = await uploadStaffMedia(file, 'file');
                this.data.url = payload.file?.url ?? '';
                this.data.title = payload.file?.title ?? file.name;
                this.data.size = payload.file?.size ?? '';
                url.value = this.data.url;
                title.value = this.data.title ?? '';
                size.value = this.data.size ?? '';
                status.textContent = 'Upload complete.';
            } catch (error) {
                status.textContent = error instanceof Error ? error.message : 'Upload failed.';
            } finally {
                picker.value = '';
            }
        });

        this.wrapper.append(url, title, size, picker, status);
        return this.wrapper;
    }

    save(): FileData {
        return {
            url: this.data.url.trim(),
            title: (this.data.title ?? '').trim() || undefined,
            size: (this.data.size ?? '').trim() || undefined,
        };
    }
}
