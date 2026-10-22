import { act, render } from '@testing-library/react';
import { createRef } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { EditorFieldHandle } from '../Components/editor/EditorField';
import TinyMceField from '../Components/editor/TinyMceField';

type ButtonSpec = { onAction: () => void };
type DialogSpec = {
    onSubmit: (dialog: { getData: () => { url: string; caption: string }; close: () => void }) => void;
};
type InitOptions = {
    toolbar: string;
    valid_elements: string;
    valid_styles: Record<string, string>;
    setup: (editor: unknown) => void;
    images_upload_handler: (blobInfo: { blob: () => Blob; filename: () => string }) => Promise<string>;
};
type EditorProps = {
    licenseKey: string;
    tinymceScriptSrc: unknown;
    initialValue: string;
    init: InitOptions;
    onInit: (event: unknown, editor: unknown) => void;
    onEditorChange: (html: string) => void;
};

const mocks = vi.hoisted(() => ({
    props: null as EditorProps | null,
    upload: vi.fn(),
}));

vi.mock('@tinymce/tinymce-react', () => ({
    Editor: (props: EditorProps) => {
        mocks.props = props;
        return <textarea data-testid="tinymce" defaultValue={props.initialValue} />;
    },
}));

vi.mock('../Components/editor/tinymce/setup', () => ({ default: {} }));

vi.mock('../Components/editor/uploadStaffMedia', () => ({
    uploadStaffMedia: mocks.upload,
}));

function fakeEditor(initialHtml: string) {
    const body = document.createElement('div');
    body.innerHTML = initialHtml;
    const buttons: Record<string, ButtonSpec> = {};
    let dialog: DialogSpec | null = null;

    return {
        body,
        buttons,
        dialog: () => dialog,
        getBody: () => body,
        getContent: () => body.innerHTML,
        getContainer: () => document.createElement('div'),
        dom: {
            add: (parent: HTMLElement, tag: string, _attrs: object, html: string) => {
                const element = document.createElement(tag);
                element.innerHTML = html;
                parent.append(element);
            },
        },
        undoManager: { add: vi.fn() },
        insertContent: vi.fn(),
        execCommand: vi.fn(),
        formatter: { formatChanged: () => ({ unbind: vi.fn() }) },
        notificationManager: { open: vi.fn() },
        windowManager: {
            open: (spec: DialogSpec) => {
                dialog = spec;
            },
        },
        ui: {
            registry: {
                addButton: (name: string, spec: ButtonSpec) => {
                    buttons[name] = spec;
                },
                addToggleButton: (name: string, spec: ButtonSpec) => {
                    buttons[name] = spec;
                },
            },
        },
    };
}

function props(): EditorProps {
    if (!mocks.props) {
        throw new Error('TinyMCE Editor was not rendered');
    }

    return mocks.props;
}

describe('TinyMceField', () => {
    beforeEach(() => {
        mocks.props = null;
        mocks.upload.mockReset();
    });

    it('loads the self-hosted GPL build with a restricted schema', () => {
        render(<TinyMceField initialHtml="<p>Hello</p>" onChange={vi.fn()} />);

        expect(props().licenseKey).toBe('gpl');
        expect(props().tinymceScriptSrc).toEqual([]);
        expect(props().initialValue).toBe('<p>Hello</p>');
        expect(props().init.toolbar).toContain('nrembed');
        expect(props().init.valid_styles).toEqual({ '*': '' });
        expect(props().init.valid_elements).not.toMatch(/script|iframe|style|span|font/);
        expect(props().init.valid_elements).toContain('div[class|contenteditable|data-block-type|data-block|role|aria-label]');
    });

    it('ignores TinyMCE re-serialising the initial HTML but forwards real edits', () => {
        const onChange = vi.fn();
        render(<TinyMceField initialHtml="<p>Hello</p>" onChange={onChange} />);
        const editor = fakeEditor('<p>Hello</p>');

        act(() => props().onInit({}, editor));
        act(() => props().onEditorChange('<p>Hello</p>'));
        expect(onChange).not.toHaveBeenCalled();

        act(() => props().onEditorChange('<p>Hello there</p>'));
        act(() => props().onEditorChange('<p>Hello</p>'));
        expect(onChange.mock.calls).toEqual([['<p>Hello there</p>'], ['<p>Hello</p>']]);
    });

    it('uploads pasted or chosen images through the staff media endpoint', async () => {
        mocks.upload.mockResolvedValue({ success: 1, file: { url: 'https://cdn.example.test/pic.png' } });
        render(<TinyMceField initialHtml="" onChange={vi.fn()} />);
        const blob = new Blob(['png'], { type: 'image/png' });

        const url = await props().init.images_upload_handler({ blob: () => blob, filename: () => 'pic.png' });

        expect(url).toBe('https://cdn.example.test/pic.png');
        expect(mocks.upload).toHaveBeenCalledWith(expect.any(File), 'image');
        expect((mocks.upload.mock.calls[0][0] as File).name).toBe('pic.png');
    });

    it('appends escaped AI paragraphs through the editor handle', () => {
        const onChange = vi.fn();
        const ref = createRef<EditorFieldHandle>();
        render(<TinyMceField ref={ref} initialHtml="<p>Start</p>" onChange={onChange} />);
        const editor = fakeEditor('<p>Start</p>');
        act(() => props().onInit({}, editor));

        act(() => ref.current?.appendParagraphs(['<b>AI</b> & more']));

        expect(editor.body.innerHTML).toBe('<p>Start</p><p>&lt;b&gt;AI&lt;/b&gt; &amp; more</p>');
        expect(editor.undoManager.add).toHaveBeenCalled();
        expect(onChange).toHaveBeenCalledWith('<p>Start</p><p>&lt;b&gt;AI&lt;/b&gt; &amp; more</p>');
    });

    it('inserts supported embeds as read-only placeholders and refuses other links', () => {
        render(<TinyMceField initialHtml="" onChange={vi.fn()} />);
        const editor = fakeEditor('');
        props().init.setup(editor);
        const close = vi.fn();

        editor.buttons.nrembed.onAction();
        editor.dialog()?.onSubmit({ getData: () => ({ url: 'https://evil.example/video', caption: '' }), close });
        expect(editor.insertContent).not.toHaveBeenCalled();
        expect(editor.notificationManager.open).toHaveBeenCalledWith(expect.objectContaining({ type: 'error' }));
        expect(close).not.toHaveBeenCalled();

        editor.dialog()?.onSubmit({ getData: () => ({ url: 'https://youtu.be/dQw4w9WgXcQ', caption: ' Clip ' }), close });
        expect(close).toHaveBeenCalled();
        const placeholder = document.createElement('div');
        placeholder.innerHTML = editor.insertContent.mock.calls[0][0] as string;
        const element = placeholder.firstElementChild as HTMLElement;

        expect(element.getAttribute('contenteditable')).toBe('false');
        expect(element.dataset.blockType).toBe('embed');
        expect(element.getAttribute('aria-label')).toBe('Embed block. Read-only here; edit it in Editor.js.');
        expect(JSON.parse(element.dataset.block ?? '{}')).toEqual({
            service: 'youtube',
            source: 'https://youtu.be/dQw4w9WgXcQ',
            embed: 'https://www.youtube.com/embed/dQw4w9WgXcQ',
            width: 580,
            height: 320,
            caption: 'Clip',
        });
    });
});
