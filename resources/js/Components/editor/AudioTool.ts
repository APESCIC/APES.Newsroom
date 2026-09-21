type AudioData = {
    url: string;
    caption?: string;
};

/**
 * URL-based audio block matching the server allowlist (#72).
 */
export default class AudioTool {
    static get toolbox() {
        return {
            title: 'Audio',
            icon: '<svg width="17" height="15" viewBox="0 0 17 15" xmlns="http://www.w3.org/2000/svg"><path d="M8 1v10a3 3 0 1 1-2-2.83V4l8-2v6a3 3 0 1 1-2-2.83V1L8 1z"/></svg>',
        };
    }

    private data: AudioData;
    private readonly wrapper: HTMLElement;

    constructor({ data }: { data?: Partial<AudioData> }) {
        this.data = {
            url: data?.url ?? '',
            caption: data?.caption ?? '',
        };
        this.wrapper = document.createElement('div');
    }

    render(): HTMLElement {
        this.wrapper.classList.add('ce-audio');
        this.wrapper.replaceChildren();

        const url = document.createElement('input');
        url.type = 'url';
        url.placeholder = 'Audio URL';
        url.value = this.data.url;
        url.addEventListener('input', () => {
            this.data.url = url.value;
        });

        const caption = document.createElement('input');
        caption.type = 'text';
        caption.placeholder = 'Caption (optional)';
        caption.value = this.data.caption ?? '';
        caption.addEventListener('input', () => {
            this.data.caption = caption.value;
        });

        this.wrapper.append(url, caption);
        return this.wrapper;
    }

    save(): AudioData {
        return {
            url: this.data.url.trim(),
            caption: (this.data.caption ?? '').trim() || undefined,
        };
    }
}
