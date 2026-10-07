import type { OutputData } from '@editorjs/editorjs';
import type { EditorDriver } from '../../types/editor';
import EditorJsField from './EditorJsField';

export type EditorFieldProps = {
    driver: EditorDriver;
    initialData: OutputData;
    onChange: (data: OutputData) => void;
};

export default function EditorField({ initialData, onChange }: EditorFieldProps) {
    return <EditorJsField initialData={initialData} onChange={onChange} />;
}
