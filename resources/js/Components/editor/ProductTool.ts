type ProductData = {
    title: string;
    description?: string;
    url?: string;
    priceLabel?: string;
};

/**
 * Display-only product card matching the server allowlist (#73).
 * No checkout, SKU, or Stripe fields.
 */
export default class ProductTool {
    static get toolbox() {
        return {
            title: 'Product',
            icon: '<svg width="17" height="15" viewBox="0 0 17 15" xmlns="http://www.w3.org/2000/svg"><rect x="2" y="4" width="13" height="10" rx="1"/><path d="M5 4V3a3.5 3.5 0 0 1 7 0v1"/></svg>',
        };
    }

    private data: ProductData;
    private readonly wrapper: HTMLElement;

    constructor({ data }: { data?: Partial<ProductData> }) {
        this.data = {
            title: data?.title ?? '',
            description: data?.description ?? '',
            url: data?.url ?? '',
            priceLabel: data?.priceLabel ?? '',
        };
        this.wrapper = document.createElement('div');
    }

    render(): HTMLElement {
        this.wrapper.classList.add('ce-product');
        this.wrapper.replaceChildren();

        const title = document.createElement('input');
        title.type = 'text';
        title.placeholder = 'Product title';
        title.value = this.data.title;
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

        const url = document.createElement('input');
        url.type = 'url';
        url.placeholder = 'Link URL (optional)';
        url.value = this.data.url ?? '';
        url.addEventListener('input', () => {
            this.data.url = url.value;
        });

        const priceLabel = document.createElement('input');
        priceLabel.type = 'text';
        priceLabel.placeholder = 'Price label (optional)';
        priceLabel.value = this.data.priceLabel ?? '';
        priceLabel.addEventListener('input', () => {
            this.data.priceLabel = priceLabel.value;
        });

        this.wrapper.append(title, description, url, priceLabel);
        return this.wrapper;
    }

    save(): ProductData {
        return {
            title: this.data.title.trim(),
            description: (this.data.description ?? '').trim() || undefined,
            url: (this.data.url ?? '').trim() || undefined,
            priceLabel: (this.data.priceLabel ?? '').trim() || undefined,
        };
    }
}
