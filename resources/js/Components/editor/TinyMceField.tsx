import { Editor, type IAllProps } from '@tinymce/tinymce-react';
import { useId, useImperativeHandle, useRef, useState, type Ref } from 'react';
import type { Editor as TinyMceEditor } from 'tinymce';
import { embedPlaceholderHtml, PLACEHOLDER_CLASS } from './blockPlaceholder';
import type { EditorFieldHandle } from './EditorField';
import { resolveEmbed, SUPPORTED_EMBED_LABELS } from './embedServices';
import { escapeHtml } from './escapeHtml';
import './tinymce/setup';
import { uploadStaffMedia } from './uploadStaffMedia';

type Props = {
    initialHtml: string;
    onChange: (html: string) => void;
    ref?: Ref<EditorFieldHandle>;
};

/**
 * Only markup that maps to Editor.js blocks (see HtmlToBlocksConverter) is
 * allowed; TinyMCE strips everything else, including pasted fonts and colours.
 */
const VALID_ELEMENTS = [
    'p',
    'h2',
    'h3',
    'h4',
    'strong/b',
    'em/i',
    'code',
    'br',
    'a[href|rel]',
    'ul',
    'ol',
    'li',
    'blockquote',
    'cite',
    'figure[class|data-credit]',
    'figcaption',
    'img[src|alt]',
    'table',
    'thead',
    'tbody',
    'tr',
    'th',
    'td',
    'hr',
    `div[class|contenteditable|data-block-type|data-block|role|aria-label]`,
].join(',');

const CONTENT_STYLE = `
    body { font-family: system-ui, sans-serif; font-size: 16px; line-height: 1.6; margin: 1rem; }
    .${PLACEHOLDER_CLASS} {
        background: #f1f5f9; border: 1px dashed #64748b; border-radius: 6px;
        color: #1e293b; cursor: default; margin: 0.75rem 0; padding: 0.5rem 0.75rem;
    }
    figure.image { display: table; margin: 1rem 0; }
    figure.image img { max-width: 100%; height: auto; }
    figure.image figcaption { color: #475569; font-size: 0.875rem; }
`;

function registerCustomControls(editor: TinyMceEditor) {
    editor.ui.registry.addToggleButton('nrinlinecode', {
        icon: 'sourcecode',
        tooltip: 'Inline code',
        onAction: () => editor.execCommand('mceToggleFormat', false, 'code'),
        onSetup: (api) => {
            const unbind = editor.formatter.formatChanged('code', (active) => api.setActive(active));
            return () => unbind.unbind();
        },
    });

    editor.ui.registry.addButton('nrembed', {
        icon: 'embed',
        tooltip: 'Embed a post or video',
        onAction: () => {
            editor.windowManager.open({
                title: 'Embed a post or video',
                body: {
                    type: 'panel',
                    items: [
                        {
                            type: 'input',
                            name: 'url',
                            label: `Link (${SUPPORTED_EMBED_LABELS.join(', ')})`,
                        },
                        { type: 'input', name: 'caption', label: 'Caption (optional)' },
                    ],
                },
                buttons: [
                    { type: 'cancel', text: 'Cancel' },
                    { type: 'submit', text: 'Embed', buttonType: 'primary' },
                ],
                onSubmit: (dialog) => {
                    const { url, caption } = dialog.getData() as { url: string; caption: string };
                    const embed = resolveEmbed(url, caption);

                    if (!embed) {
                        editor.notificationManager.open({
                            text: `That link is not supported. Use a ${SUPPORTED_EMBED_LABELS.join(', ')} link.`,
                            type: 'error',
                            timeout: 6000,
                        });
                        return;
                    }

                    editor.insertContent(embedPlaceholderHtml(embed));
                    dialog.close();
                },
            });
        },
    });
}

const EDITOR_OPTIONS: IAllProps['init'] = {
    promotion: false,
    branding: false,
    menubar: false,
    height: 560,
    plugins: 'autolink image link lists table wordcount',
    toolbar:
        'undo redo | blocks | bold italic nrinlinecode link | bullist numlist blockquote | image table hr nrembed | removeformat',
    block_formats: 'Paragraph=p; Heading 2=h2; Heading 3=h3; Heading 4=h4',
    valid_elements: VALID_ELEMENTS,
    valid_styles: { '*': '' },
    paste_webkit_styles: 'none',
    paste_remove_styles_if_webkit: true,
    noneditable_class: PLACEHOLDER_CLASS,
    entity_encoding: 'raw',
    convert_urls: false,
    relative_urls: false,
    link_default_protocol: 'https',
    link_target_list: false,
    link_title: false,
    image_caption: true,
    image_description: true,
    image_dimensions: false,
    image_title: false,
    image_advtab: false,
    automatic_uploads: true,
    images_file_types: 'jpg,jpeg,png,gif,webp',
    images_upload_handler: async (blobInfo) => {
        const file = new File([blobInfo.blob()], blobInfo.filename(), { type: blobInfo.blob().type });
        const response = await uploadStaffMedia(file, 'image');

        return response.file?.url ?? '';
    },
    table_appearance_options: false,
    table_advtab: false,
    table_cell_advtab: false,
    table_row_advtab: false,
    table_resize_bars: false,
    table_default_attributes: {},
    table_default_styles: {},
    table_style_by_css: false,
    object_resizing: false,
    contextmenu: 'link image table',
    elementpath: false,
    statusbar: true,
    skin: 'oxide',
    content_css: 'default',
    content_style: CONTENT_STYLE,
    iframe_aria_text: 'Article body editor. Press Alt+0 for keyboard help.',
    setup: registerCustomControls,
};

export default function TinyMceField({ initialHtml, onChange, ref }: Props) {
    const helpId = useId();
    const editorRef = useRef<TinyMceEditor | null>(null);
    const onChangeRef = useRef(onChange);
    onChangeRef.current = onChange;
    const [initialValue] = useState(initialHtml);
    // TinyMCE re-serialises the initial HTML; reporting that as an edit would
    // mark the form dirty and autosave a revision on every page load.
    const loadedHtml = useRef<string | null>(null);

    useImperativeHandle(
        ref,
        () => ({
            appendParagraphs(texts: string[]) {
                const editor = editorRef.current;

                if (!editor) {
                    return;
                }

                const body = editor.getBody();
                texts.forEach((text) => editor.dom.add(body, 'p', {}, escapeHtml(text)));
                editor.undoManager.add();
                onChangeRef.current(editor.getContent());
            },
        }),
        [],
    );

    return (
        <div className="tinymce-field" role="group" aria-describedby={helpId}>
            <Editor
                licenseKey="gpl"
                tinymceScriptSrc={[]}
                initialValue={initialValue}
                init={EDITOR_OPTIONS}
                onInit={(_event, editor) => {
                    editorRef.current = editor;
                    loadedHtml.current = editor.getContent();
                    editor.getContainer().setAttribute('aria-describedby', helpId);
                }}
                onEditorChange={(html) => {
                    if (loadedHtml.current !== null && html === loadedHtml.current) {
                        return;
                    }

                    loadedHtml.current = null;
                    onChangeRef.current(html);
                }}
            />
            <p id={helpId} className="mt-2 text-sm text-muted">
                Grey boxes are blocks such as callouts, galleries and embeds. They are read-only here: you can move or
                delete them, but edit their contents in Editor.js.
            </p>
        </div>
    );
}
