import { CircleCheck, X } from 'lucide-react';
import { useState } from 'react';

/** Frosted status message for the latest flash, dismissible. */
export default function Toast({ message }: { message?: string | null }) {
    const [dismissed, setDismissed] = useState<string | null>(null);

    if (!message || dismissed === message) return null;

    return (
        <div role="status" className="sg-card mb-4 flex items-start gap-3 px-4 py-3" data-testid="workspace-toast">
            <CircleCheck aria-hidden="true" className="mt-0.5 h-5 w-5 shrink-0 text-success" />
            <p className="flex-1 font-medium text-body">{message}</p>
            <button type="button" className="sg-icon-btn -my-2 -mr-2" aria-label="Dismiss message" onClick={() => setDismissed(message)}>
                <X aria-hidden="true" className="h-4 w-4" />
            </button>
        </div>
    );
}
