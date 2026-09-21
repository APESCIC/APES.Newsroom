type BookmarkData = {
    url: string;
    title?: string;
    description?: string;
    image?: string;
};

/**
 * URL-based bookmark card matching the server allowlist (#73).
 */
export default class BookmarkTool {
    static get toolbox() {
        return {
            title: 'Bookmark',
            icon: '<svg width="17" height="15" viewBox="0 0 17 15" xmlns="http://www.w3.org/2000/svg"><path d="M4 1h9v13l-4.5-3L4 14V1z"/></svg>',
        };
    }

    private data: BookmarkData;
    private readonly wrapper: HTMLElement;

    constructor({ data }: { data?: Partial<BookmarkData> }) {
        this.data = {
            url: data?.url ?? '',
            title: data?.title ?? '',
            description: data?.description ?? '',
            image: data?.image ?? '',
        };
        this.wrapper = document.createElement('div');
    }

    render(): HTMLElement {
        this.wrapper.classList.add('ce-bookmark');
        this.wrapper.replaceChildren();

        const url = document.createElement('input');
        url.type = 'url';
        url.placeholder = 'Bookmark URL';
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

        const description = document.createElement('textarea');
        description.placeholder = 'Description (optional)';
        description.value = this.data.description ?? '';
        description.rows = 2;
        description.addEventListener('input', () => {
            this.data.description = description.value;
        });

        const image = document.createElement('input');
        image.type = 'url';
        image.placeholder = 'Image URL (optional)';
        image.value = this.data.image ?? '';
        image.addEventListener('input', () => {
            this.data.image = image.value;
        });

        this.wrapper.append(url, title, description, image);
        return this.wrapper;
    }

    save(): BookmarkData {
        return {
            url: this.data.url.trim(),
            title: (this.data.title ?? '').trim() || undefined,
            description: (this.data.description ?? '').trim() || undefined,
            image: (this.data.image ?? '').trim() || undefined,
        };
    }
}
