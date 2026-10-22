import type { EditorDriver } from '../../types/editor';
import { editorLabel } from './EditorField';

type Props = {
    sessionEditor: EditorDriver;
    siteEditor: EditorDriver;
};

/**
 * The editor is fixed when the page opens (#261); saves stay in that editor's
 * format even if an admin changes the site setting meanwhile.
 */
export default function EditorSessionNotice({ sessionEditor, siteEditor }: Props) {
    if (sessionEditor === siteEditor) {
        return null;
    }

    return (
        <p className="mb-2 rounded border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900" role="status">
            The site editor is now {editorLabel(siteEditor)}. You can keep working in {editorLabel(sessionEditor)}; your
            changes save safely. Reload the page after saving to switch editors.
        </p>
    );
}
