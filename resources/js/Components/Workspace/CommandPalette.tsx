import { router } from '@inertiajs/react';
import { CornerDownLeft, Search, type LucideIcon } from 'lucide-react';
import { useId, useMemo, useRef, useState, type KeyboardEvent } from 'react';
import type { SharedAuth } from '../../types/page';
import { quickActions, visibleGroups, type WorkspaceArea } from './navigation';
import { useDialogFocus } from './useDialogFocus';

type Command = {
    id: string;
    label: string;
    section: string;
    href: string;
    icon: LucideIcon;
    keywords: string;
};

/** Everything this user may jump to. Admin-only entries never reach staff (#517). */
export function commandsFor(can: SharedAuth['can']): Command[] {
    const areas: WorkspaceArea[] = ['Staff', 'Admin'];
    const pages = areas.flatMap((area) =>
        visibleGroups(area, can).flatMap((group) =>
            group.items.map((item) => ({
                id: `${area}:${item.key}`,
                label: item.label,
                section: `${area} · ${group.heading}`,
                href: item.href,
                icon: item.icon,
                keywords: `${area} ${group.heading} ${item.keywords ?? ''}`,
            })),
        ),
    );
    const actions = quickActions
        .filter((action) => action.visible?.(can) ?? true)
        .map((action) => ({
            id: action.id,
            label: action.label,
            section: 'Quick actions',
            href: action.href,
            icon: action.icon,
            keywords: action.keywords ?? '',
        }));

    return [...actions, ...pages];
}

export function filterCommands(commands: Command[], query: string): Command[] {
    const terms = query.toLowerCase().split(/\s+/).filter(Boolean);
    if (terms.length === 0) return commands;

    return commands.filter((command) => {
        const haystack = `${command.label} ${command.section} ${command.keywords}`.toLowerCase();
        return terms.every((term) => haystack.includes(term));
    });
}

export default function CommandPalette({ open, can, onClose }: { open: boolean; can: SharedAuth['can']; onClose: () => void }) {
    const [query, setQuery] = useState('');
    const [activeIndex, setActiveIndex] = useState(0);
    const dialogRef = useRef<HTMLDivElement>(null);
    const titleId = useId();
    const listId = useId();
    const commands = useMemo(() => commandsFor(can), [can]);
    const results = useMemo(() => filterCommands(commands, query), [commands, query]);

    const close = () => {
        setQuery('');
        setActiveIndex(0);
        onClose();
    };

    useDialogFocus(open, dialogRef, close, { initialFocus: 'input', lockScroll: true });

    if (!open) return null;

    const run = (command: Command | undefined) => {
        if (!command) return;
        close();
        router.visit(command.href);
    };

    const onKeyDown = (event: KeyboardEvent<HTMLInputElement>) => {
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            setActiveIndex((index) => (results.length ? (index + 1) % results.length : 0));
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            setActiveIndex((index) => (results.length ? (index - 1 + results.length) % results.length : 0));
        } else if (event.key === 'Enter') {
            event.preventDefault();
            run(results[activeIndex]);
        }
    };

    const optionId = (index: number) => `${listId}-option-${index}`;

    return (
        <div className="sg-overlay flex items-start justify-center px-4 pt-[12vh]" onClick={close}>
            <div
                ref={dialogRef}
                role="dialog"
                aria-modal="true"
                aria-labelledby={titleId}
                className="sg-dialog flex max-h-[70vh] w-full max-w-xl flex-col overflow-hidden"
                onClick={(event) => event.stopPropagation()}
                data-testid="command-palette"
            >
                <h2 id={titleId} className="sr-only">
                    Search and run commands
                </h2>
                <div className="flex items-center gap-3 border-b border-border px-4">
                    <Search aria-hidden="true" className="h-5 w-5 text-muted" />
                    <input
                        type="text"
                        role="combobox"
                        aria-label="Search pages and actions"
                        aria-expanded="true"
                        aria-controls={listId}
                        aria-autocomplete="list"
                        aria-activedescendant={results.length ? optionId(activeIndex) : undefined}
                        className="min-h-14 flex-1 bg-transparent text-base text-body outline-none placeholder:text-muted"
                        placeholder="Jump to a page or run an action…"
                        value={query}
                        onChange={(event) => {
                            setQuery(event.target.value);
                            setActiveIndex(0);
                        }}
                        onKeyDown={onKeyDown}
                    />
                    <kbd className="sg-kbd">Esc</kbd>
                </div>
                <ul id={listId} role="listbox" aria-label="Results" className="overflow-y-auto p-2">
                    {results.length === 0 && <li className="px-3 py-6 text-center text-muted">No matching pages or actions.</li>}
                    {results.map((command, index) => {
                        const Icon = command.icon;
                        const selected = index === activeIndex;
                        return (
                            <li
                                key={command.id}
                                id={optionId(index)}
                                role="option"
                                aria-selected={selected}
                                className="sg-palette-option flex cursor-pointer items-center gap-3 rounded-xl px-3 py-2.5"
                                onMouseMove={() => setActiveIndex(index)}
                                onClick={() => run(command)}
                            >
                                <Icon aria-hidden="true" className="h-4 w-4 shrink-0" />
                                <span className="flex-1 font-medium">{command.label}</span>
                                <span className="text-xs text-muted">{command.section}</span>
                                {selected && <CornerDownLeft aria-hidden="true" className="h-4 w-4 text-muted" />}
                            </li>
                        );
                    })}
                </ul>
            </div>
        </div>
    );
}
