import type { OutputData } from '@editorjs/editorjs';
import { lazy, Suspense, type Ref } from 'react';
import type { EditorDriver } from '../../types/editor';
import EditorJsField from './EditorJsField';

const TinyMceField = lazy(() => import('./TinyMceField'));

export type EditorFieldHandle = {
    appendParagraphs: (texts: string[]) => void;
};

export type EditorFieldProps = {
    driver: EditorDriver;
    initialData: OutputData;
    initialHtml: string;
    onChange: (data: OutputData) => void;
    onHtmlChange: (html: string) => void;
    ref?: Ref<EditorFieldHandle>;
};

export function editorLabel(driver: EditorDriver): string {
    switch (driver) {
        case 'editorjs':
            return 'Editor.js';
        case 'tinymce':
            return 'TinyMCE';
        default: {
            const exhaustive: never = driver;
            return exhaustive;
        }
    }
}

export default function EditorField({ driver, initialData, initialHtml, onChange, onHtmlChange, ref }: EditorFieldProps) {
    switch (driver) {
        case 'editorjs':
            return <EditorJsField initialData={initialData} onChange={onChange} ref={ref} />;
        case 'tinymce':
            return (
                <Suspense fallback={<p className="text-sm text-muted">Loading editor…</p>}>
                    <TinyMceField initialHtml={initialHtml} onChange={onHtmlChange} ref={ref} />
                </Suspense>
            );
        default: {
            const exhaustive: never = driver;
            return exhaustive;
        }
    }
}
