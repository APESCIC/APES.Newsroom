type MarkupFormat = 'markdown' | 'html';

type MarkupData = {
    format: MarkupFormat;
    source: string;
};

/**
 * Markdown/HTML authoring block matching the server allowlist (#74).
 * Server sanitizes and produces renderable HTML; client only edits source.
 */
export default class MarkupTool {
    static get toolbox() {
        return {
            title: 'Markdown / HTML',
            icon: '<svg width="17" height="15" viewBox="0 0 17 15" xmlns="http://www.w3.org/2000/svg"><path d="M2 3h13v9H2z"/><path d="M4 6l2 3 2-3M11 6v3"/></svg>',
        };
    }

    private data: MarkupData;
    private readonly wrapper: HTMLElement;

    constructor({ data }: { data?: Partial<MarkupData> & { html?: string } }) {
        const format = data?.format === 'html' ? 'html' : 'markdown';
        this.data = {
            format,
            source: data?.source ?? '',
        };
        this.wrapper = document.createElement('div');
    }

    render(): HTMLElement {
        this.wrapper.classList.add('ce-markup');
        this.wrapper.replaceChildren();

        const format = document.createElement('select');
        format.className = 'ce-markup__format';
        for (const value of ['markdown', 'html'] as const) {
            const option = document.createElement('option');
            option.value = value;
            option.textContent = value === 'markdown' ? 'Markdown' : 'HTML';
            if (value === this.data.format) {
                option.selected = true;
            }
            format.appendChild(option);
        }
        format.addEventListener('change', () => {
            this.data.format = format.value === 'html' ? 'html' : 'markdown';
        });

        const source = document.createElement('textarea');
        source.className = 'ce-markup__source';
        source.rows = 8;
        source.placeholder =
            this.data.format === 'html'
                ? 'Enter HTML (sanitized on save)…'
                : 'Enter Markdown (converted and sanitized on save)…';
        source.value = this.data.source;
        source.addEventListener('input', () => {
            this.data.source = source.value;
        });

        this.wrapper.append(format, source);
        return this.wrapper;
    }

    save(): MarkupData {
        return {
            format: this.data.format === 'html' ? 'html' : 'markdown',
            source: this.data.source,
        };
    }
}
