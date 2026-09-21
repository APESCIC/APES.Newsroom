import EditorJS, { type OutputData } from '@editorjs/editorjs';
import Delimiter from '@editorjs/delimiter';
import Embed from '@editorjs/embed';
import Header from '@editorjs/header';
import Image from '@editorjs/image';
import LinkTool from '@editorjs/link';
import EditorjsList from '@editorjs/list';
import Paragraph from '@editorjs/paragraph';
import Quote from '@editorjs/quote';
import Table from '@editorjs/table';
import { useEffect, useRef } from 'react';
import AudioTool from './AudioTool';
import BookmarkTool from './BookmarkTool';
import CalloutTool from './CalloutTool';
import FileTool from './FileTool';
import GalleryTool from './GalleryTool';
import ProductTool from './ProductTool';
import ToggleTool from './ToggleTool';
import VideoTool from './VideoTool';

function csrfToken(): string {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

async function postJson(url: string, body: Record<string, string>) {
    const response = await fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
            'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'same-origin',
        body: JSON.stringify(body),
    });

    return response.json();
}

type Props = {
    initialData: OutputData;
    onChange: (data: OutputData) => void;
};

export default function EditorJsField({ initialData, onChange }: Props) {
    const holderRef = useRef<HTMLDivElement | null>(null);
    const editorRef = useRef<EditorJS | null>(null);
    const onChangeRef = useRef(onChange);
    onChangeRef.current = onChange;

    useEffect(() => {
        if (!holderRef.current || editorRef.current) {
            return;
        }

        const editor = new EditorJS({
            holder: holderRef.current,
            data: initialData,
            placeholder: 'Write your article…',
            tools: {
                paragraph: {
                    class: Paragraph,
                    inlineToolbar: true,
                },
                header: {
                    class: Header,
                    config: {
                        levels: [2, 3, 4],
                        defaultLevel: 2,
                    },
                },
                list: {
                    class: EditorjsList,
                    inlineToolbar: true,
                },
                quote: {
                    class: Quote,
                    inlineToolbar: true,
                },
                image: {
                    class: Image,
                    config: {
                        uploader: {
                            async uploadByUrl(url: string) {
                                return postJson('/staff/media/by-url', { url });
                            },
                            async uploadByFile(file: File) {
                                return Promise.reject(
                                    new Error(`File upload is not configured (${file.name}). Use image by URL.`),
                                );
                            },
                        },
                        captionPlaceholder: 'Caption',
                    },
                },
                table: Table,
                delimiter: Delimiter,
                callout: CalloutTool,
                gallery: GalleryTool,
                video: VideoTool,
                audio: AudioTool,
                file: FileTool,
                bookmark: BookmarkTool,
                product: ProductTool,
                toggle: ToggleTool,
                linkTool: {
                    class: LinkTool,
                    config: {
                        endpoint: '/staff/media/link-meta',
                        headers: {
                            'X-CSRF-TOKEN': csrfToken(),
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                    },
                },
                embed: {
                    class: Embed,
                    config: {
                        services: {
                            youtube: true,
                            vimeo: true,
                            twitter: true,
                            instagram: true,
                            codepen: true,
                        },
                    },
                },
            },
            async onChange() {
                const data = await editor.save();
                onChangeRef.current(data);
            },
        });

        editorRef.current = editor;

        return () => {
            editor.destroy();
            editorRef.current = null;
        };
        // Mount once; content updates flow via onChange to parent form state.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    return <div ref={holderRef} className="editorjs-field rounded border border-neutral-300 bg-white px-4 py-3" />;
}
