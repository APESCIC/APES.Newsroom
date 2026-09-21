type VideoData = {
    url: string;
    caption?: string;
    poster?: string;
};

/**
 * URL-based video block matching the server allowlist (#72).
 */
export default class VideoTool {
    static get toolbox() {
        return {
            title: 'Video',
            icon: '<svg width="17" height="15" viewBox="0 0 17 15" xmlns="http://www.w3.org/2000/svg"><rect x="1" y="2" width="10" height="11" rx="1"/><path d="M11 5l5-2v9l-5-2z"/></svg>',
        };
    }

    private data: VideoData;
    private readonly wrapper: HTMLElement;

    constructor({ data }: { data?: Partial<VideoData> }) {
        this.data = {
            url: data?.url ?? '',
            caption: data?.caption ?? '',
            poster: data?.poster ?? '',
        };
        this.wrapper = document.createElement('div');
    }

    render(): HTMLElement {
        this.wrapper.classList.add('ce-video');
        this.wrapper.replaceChildren();

        const url = document.createElement('input');
        url.type = 'url';
        url.placeholder = 'Video URL';
        url.value = this.data.url;
        url.addEventListener('input', () => {
            this.data.url = url.value;
        });

        const poster = document.createElement('input');
        poster.type = 'url';
        poster.placeholder = 'Poster image URL (optional)';
        poster.value = this.data.poster ?? '';
        poster.addEventListener('input', () => {
            this.data.poster = poster.value;
        });

        const caption = document.createElement('input');
        caption.type = 'text';
        caption.placeholder = 'Caption (optional)';
        caption.value = this.data.caption ?? '';
        caption.addEventListener('input', () => {
            this.data.caption = caption.value;
        });

        this.wrapper.append(url, poster, caption);
        return this.wrapper;
    }

    save(): VideoData {
        return {
            url: this.data.url.trim(),
            caption: (this.data.caption ?? '').trim() || undefined,
            poster: (this.data.poster ?? '').trim() || undefined,
        };
    }
}
