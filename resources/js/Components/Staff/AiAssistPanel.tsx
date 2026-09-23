import { useEffect, useState } from 'react';

type Props = {
    title: string;
    excerpt: string;
    onInsert: (text: string) => void;
};

export default function AiAssistPanel({ title, excerpt, onInsert }: Props) {
    const [enabled, setEnabled] = useState(false);
    const [prompt, setPrompt] = useState('');
    const [suggestion, setSuggestion] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');

    useEffect(() => {
        void fetch('/staff/ai/status', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then((r) => r.json())
            .then((payload) => setEnabled(Boolean(payload.enabled)))
            .catch(() => setEnabled(false));
    }, []);

    if (!enabled) {
        return null;
    }

    async function suggest() {
        setBusy(true);
        setError('');
        try {
            const response = await fetch('/staff/ai/suggest', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-XSRF-TOKEN': decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? ''),
                },
                body: JSON.stringify({
                    prompt: prompt || `Suggest an opening paragraph for: ${title}`,
                    context: [title, excerpt].filter(Boolean).join('\n'),
                }),
            });
            const payload = await response.json();
            if (!response.ok) {
                setError(payload.message || 'AI assist unavailable.');
                return;
            }
            setSuggestion(payload.suggestion || '');
        } catch {
            setError('AI assist unavailable.');
        } finally {
            setBusy(false);
        }
    }

    return (
        <div className="rounded border border-edge/40 p-3">
            <p className="text-sm font-bold text-body">AI draft assist</p>
            <p className="mt-1 text-xs text-muted">Suggestions insert into the draft only. Never auto-publishes.</p>
            <textarea
                className="form-input mt-2"
                rows={2}
                value={prompt}
                onChange={(e) => setPrompt(e.target.value)}
                placeholder="Optional prompt (defaults to opening paragraph from title)"
            />
            <div className="mt-2 flex gap-2">
                <button type="button" className="button-secondary" disabled={busy} onClick={() => void suggest()}>
                    Suggest
                </button>
                {suggestion && (
                    <button type="button" className="button-primary" onClick={() => onInsert(suggestion)}>
                        Insert into draft
                    </button>
                )}
            </div>
            {error && <p className="mt-2 text-sm text-danger">{error}</p>}
            {suggestion && <p className="mt-2 whitespace-pre-wrap text-sm text-body">{suggestion}</p>}
        </div>
    );
}
