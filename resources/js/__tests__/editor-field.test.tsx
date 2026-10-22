import type { OutputData } from '@editorjs/editorjs';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import EditorField from '../Components/editor/EditorField';

vi.mock('../Components/editor/EditorJsField', () => ({
    default: ({ initialData, onChange }: { initialData: OutputData; onChange: (data: OutputData) => void }) => (
        <div data-testid="editorjs-field">
            <output>{JSON.stringify(initialData)}</output>
            <button type="button" onClick={() => onChange({ blocks: [{ type: 'paragraph', data: { text: 'Next' } }] })}>
                Change
            </button>
        </div>
    ),
}));

vi.mock('../Components/editor/TinyMceField', () => ({
    default: ({ initialHtml, onChange }: { initialHtml: string; onChange: (html: string) => void }) => (
        <div data-testid="tinymce-field">
            <output>{initialHtml}</output>
            <button type="button" onClick={() => onChange('<p>Next</p>')}>
                Change
            </button>
        </div>
    ),
}));

const content: OutputData = { blocks: [{ type: 'paragraph', data: { text: 'Hello' } }] };

describe('EditorField', () => {
    it('renders Editor.js for the editorjs driver and forwards block changes', async () => {
        const user = userEvent.setup();
        const onChange = vi.fn();
        const onHtmlChange = vi.fn();
        render(
            <EditorField
                driver="editorjs"
                initialData={content}
                initialHtml="<p>Hello</p>"
                onChange={onChange}
                onHtmlChange={onHtmlChange}
            />,
        );

        expect(screen.getByTestId('editorjs-field')).toHaveTextContent('Hello');
        expect(screen.queryByTestId('tinymce-field')).not.toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: 'Change' }));
        expect(onChange).toHaveBeenCalledWith({ blocks: [{ type: 'paragraph', data: { text: 'Next' } }] });
        expect(onHtmlChange).not.toHaveBeenCalled();
    });

    it('lazy-loads TinyMCE for the tinymce driver and forwards HTML changes', async () => {
        const user = userEvent.setup();
        const onChange = vi.fn();
        const onHtmlChange = vi.fn();
        render(
            <EditorField
                driver="tinymce"
                initialData={content}
                initialHtml="<p>Hello</p>"
                onChange={onChange}
                onHtmlChange={onHtmlChange}
            />,
        );

        expect(await screen.findByTestId('tinymce-field')).toHaveTextContent('<p>Hello</p>');
        expect(screen.queryByTestId('editorjs-field')).not.toBeInTheDocument();
        await user.click(screen.getByRole('button', { name: 'Change' }));
        expect(onHtmlChange).toHaveBeenCalledWith('<p>Next</p>');
        expect(onChange).not.toHaveBeenCalled();
    });
});
