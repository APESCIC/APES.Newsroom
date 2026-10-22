import { embedLabel, type EmbedBlockData } from './embedServices';

export const PLACEHOLDER_CLASS = 'nr-block-placeholder';

/**
 * Client twin of EditorHtmlPresenter::placeholder() for blocks inserted in
 * TinyMCE; the server restores the block from `data-block`.
 */
export function embedPlaceholderHtml(data: EmbedBlockData): string {
    const element = document.createElement('div');
    element.className = PLACEHOLDER_CLASS;
    element.setAttribute('contenteditable', 'false');
    element.setAttribute('data-block-type', 'embed');
    element.setAttribute('data-block', JSON.stringify(data));
    element.setAttribute('role', 'group');
    element.setAttribute('aria-label', 'Embed block. Read-only here; edit it in Editor.js.');

    const label = document.createElement('strong');
    label.textContent = 'Embed';
    element.append(label, ` ${embedLabel(data.service)} ${data.source}`);

    return element.outerHTML;
}
