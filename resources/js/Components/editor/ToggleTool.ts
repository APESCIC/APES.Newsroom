type ToggleData = {
    title: string;
    content: string;
};

/**
 * Expandable toggle / accordion block matching the server allowlist (#73).
 */
export default class ToggleTool {
    static get toolbox() {
        return {
            title: 'Toggle',
            icon: '<svg width="17" height="15" viewBox="0 0 17 15" xmlns="http://www.w3.org/2000/svg"><path d="M3 4h11M3 8h11M3 12h7"/></svg>',
        };
    }

    private data: ToggleData;
    private readonly wrapper: HTMLElement;

    constructor({ data }: { data?: Partial<ToggleData> }) {
        this.data = {
            title: data?.title ?? '',
            content: data?.content ?? '',
        };
        this.wrapper = document.createElement('div');
    }

    render(): HTMLElement {
        this.wrapper.classList.add('ce-toggle');
        this.wrapper.replaceChildren();

        const title = document.createElement('input');
        title.type = 'text';
        title.placeholder = 'Toggle title';
        title.value = this.data.title;
        title.addEventListener('input', () => {
            this.data.title = title.value;
        });

        const content = document.createElement('div');
        content.contentEditable = 'true';
        content.dataset.placeholder = 'Toggle content';
        content.innerText = this.data.content;
        content.addEventListener('input', () => {
            this.data.content = content.innerText;
        });

        this.wrapper.append(title, content);
        return this.wrapper;
    }

    save(blockContent: HTMLElement): ToggleData {
        const title = blockContent.querySelector('input');
        const content = blockContent.querySelector('[contenteditable]');

        return {
            title: title?.value.trim() ?? this.data.title.trim(),
            content: content?.textContent ?? this.data.content,
        };
    }
}
