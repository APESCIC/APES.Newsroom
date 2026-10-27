import { cloneElement, isValidElement, useId, type ReactElement, type ReactNode } from 'react';

/**
 * Label, control, hint and error in the panel layout. The single child
 * control gets the generated id and aria-describedby.
 */
export default function FormField({
    label,
    hint,
    error,
    className = '',
    children,
}: {
    label: ReactNode;
    hint?: ReactNode;
    error?: string;
    className?: string;
    children: ReactElement<{ id?: string; 'aria-describedby'?: string; 'aria-invalid'?: boolean }>;
}) {
    const id = useId();
    const hintId = `${id}-hint`;
    const errorId = `${id}-error`;
    const describedBy = [hint ? hintId : null, error ? errorId : null].filter(Boolean).join(' ') || undefined;
    const control = isValidElement(children)
        ? cloneElement(children, {
              id: children.props.id ?? id,
              'aria-describedby': describedBy,
              'aria-invalid': error ? true : undefined,
          })
        : children;

    return (
        <div className={`flex flex-col gap-1.5 ${className}`.trim()}>
            <label htmlFor={children.props.id ?? id} className="text-sm font-semibold text-body">
                {label}
            </label>
            {control}
            {hint && (
                <p id={hintId} className="text-xs text-muted">
                    {hint}
                </p>
            )}
            {error && (
                <p id={errorId} className="text-sm font-medium text-danger" role="alert">
                    {error}
                </p>
            )}
        </div>
    );
}

export function FormSection({ title, description, children }: { title: string; description?: ReactNode; children: ReactNode }) {
    return (
        <section className="sg-card flex flex-col gap-4 p-5 sm:p-6">
            <div>
                <h2 className="text-base font-semibold text-body">{title}</h2>
                {description && <p className="mt-1 text-sm text-muted">{description}</p>}
            </div>
            {children}
        </section>
    );
}
