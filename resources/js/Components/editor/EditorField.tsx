import type { OutputData } from '@editorjs/editorjs';
import type { EditorDriver } from '../../types/editor';
import EditorJsField from './EditorJsField';

export type EditorFieldProps = {
    driver: EditorDriver;
    initialData: OutputData;
    onChange: (data: OutputData) => void;
};

export default function EditorField({ driver, initialData, onChange }: EditorFieldProps) {
    switch (driver) {
        case 'editorjs':
            return <EditorJsField initialData={initialData} onChange={onChange} />;
        case 'tinymce':
            // TinyMCE lands in v1.12.0 (#248); until then the setting falls back to Editor.js.
            return <EditorJsField initialData={initialData} onChange={onChange} />;
        default: {
            const exhaustive: never = driver;
            return exhaustive;
        }
    }
}
