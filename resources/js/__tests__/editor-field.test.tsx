import type { OutputData } from '@editorjs/editorjs';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import EditorField from '../Components/editor/EditorField';
import type { EditorDriver } from '../types/editor';

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

const content: OutputData = { blocks: [{ type: 'paragraph', data: { text: 'Hello' } }] };

describe('EditorField', () => {
    it.each<EditorDriver>(['editorjs', 'tinymce'])('renders Editor.js for the %s driver and forwards changes', async (driver) => {
        const user = userEvent.setup();
        const onChange = vi.fn();
        render(<EditorField driver={driver} initialData={content} onChange={onChange} />);

        expect(screen.getByTestId('editorjs-field')).toHaveTextContent('Hello');
        await user.click(screen.getByRole('button', { name: 'Change' }));
        expect(onChange).toHaveBeenCalledWith({ blocks: [{ type: 'paragraph', data: { text: 'Next' } }] });
    });
});
