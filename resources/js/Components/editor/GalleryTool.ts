type GalleryItem = {
    url: string;
    alt: string;
    caption?: string;
};

type GalleryData = {
    items: GalleryItem[];
    caption?: string;
};

/**
 * URL-based gallery block matching the server allowlist (#72).
 */
export default class GalleryTool {
    static get toolbox() {
        return {
            title: 'Gallery',
            icon: '<svg width="17" height="15" viewBox="0 0 17 15" xmlns="http://www.w3.org/2000/svg"><rect x="1" y="1" width="7" height="6" rx="1"/><rect x="9" y="1" width="7" height="6" rx="1"/><rect x="1" y="8" width="7" height="6" rx="1"/><rect x="9" y="8" width="7" height="6" rx="1"/></svg>',
        };
    }

    private data: GalleryData;
    private readonly wrapper: HTMLElement;

    constructor({ data }: { data?: Partial<GalleryData> }) {
        const items = Array.isArray(data?.items) ? data.items : [];
        this.data = {
            items:
                items.length > 0
                    ? items.map((item) => ({
                          url: item?.url ?? '',
                          alt: item?.alt ?? '',
                          caption: item?.caption ?? '',
                      }))
                    : [{ url: '', alt: '', caption: '' }],
            caption: data?.caption ?? '',
        };
        this.wrapper = document.createElement('div');
    }

    render(): HTMLElement {
        this.wrapper.classList.add('ce-gallery');
        this.wrapper.replaceChildren();

        const itemsHost = document.createElement('div');
        itemsHost.classList.add('ce-gallery__items');
        this.data.items.forEach((item, index) => {
            itemsHost.appendChild(this.renderItem(item, index));
        });
        this.wrapper.appendChild(itemsHost);

        const addButton = document.createElement('button');
        addButton.type = 'button';
        addButton.className = 'ce-gallery__add';
        addButton.textContent = 'Add image';
        addButton.addEventListener('click', () => {
            this.data.items.push({ url: '', alt: '', caption: '' });
            this.render();
        });
        this.wrapper.appendChild(addButton);

        const caption = document.createElement('input');
        caption.type = 'text';
        caption.className = 'ce-gallery__caption';
        caption.placeholder = 'Gallery caption (optional)';
        caption.value = this.data.caption ?? '';
        caption.addEventListener('input', () => {
            this.data.caption = caption.value;
        });
        this.wrapper.appendChild(caption);

        return this.wrapper;
    }

    private renderItem(item: GalleryItem, index: number): HTMLElement {
        const row = document.createElement('div');
        row.className = 'ce-gallery__item';

        const url = document.createElement('input');
        url.type = 'url';
        url.placeholder = 'Image URL';
        url.value = item.url;
        url.addEventListener('input', () => {
            this.data.items[index].url = url.value;
        });

        const alt = document.createElement('input');
        alt.type = 'text';
        alt.placeholder = 'Alt text (required)';
        alt.value = item.alt;
        alt.addEventListener('input', () => {
            this.data.items[index].alt = alt.value;
        });

        const caption = document.createElement('input');
        caption.type = 'text';
        caption.placeholder = 'Caption (optional)';
        caption.value = item.caption ?? '';
        caption.addEventListener('input', () => {
            this.data.items[index].caption = caption.value;
        });

        const remove = document.createElement('button');
        remove.type = 'button';
        remove.textContent = 'Remove';
        remove.addEventListener('click', () => {
            if (this.data.items.length <= 1) {
                this.data.items[0] = { url: '', alt: '', caption: '' };
            } else {
                this.data.items.splice(index, 1);
            }
            this.render();
        });

        row.append(url, alt, caption, remove);
        return row;
    }

    save(): GalleryData {
        return {
            items: this.data.items
                .map((item) => ({
                    url: item.url.trim(),
                    alt: item.alt.trim(),
                    caption: (item.caption ?? '').trim() || undefined,
                }))
                .filter((item) => item.url !== '' || item.alt !== ''),
            caption: (this.data.caption ?? '').trim() || undefined,
        };
    }
}
