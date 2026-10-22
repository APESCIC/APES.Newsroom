/** Plain text as safe inline HTML for Editor.js / TinyMCE text fields. */
export function escapeHtml(text: string): string {
    const element = document.createElement('p');
    element.textContent = text;

    return element.innerHTML;
}
