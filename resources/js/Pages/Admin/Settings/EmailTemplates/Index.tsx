import { Link } from '@inertiajs/react';
import { Settings } from 'lucide-react';
import WorkspaceLayout from '../../../../Components/Layout/WorkspaceLayout';
import Badge from '../../../../Components/Workspace/ui/Badge';
import PillButton from '../../../../Components/Workspace/ui/PillButton';

export type EmailTemplateListItem = {
    key: string;
    name: string;
    group: string;
    is_security: boolean;
    has_override: boolean;
    updated_at: string | null;
    default_subject: string;
};

const GROUP_ORDER = ['account', 'security', 'mailing_consent', 'staff_alerts', 'tests'] as const;

export default function EmailTemplatesIndex({
    groups,
    groupLabels,
}: {
    groups: Record<string, EmailTemplateListItem[]>;
    groupLabels: Record<string, string>;
}) {
    const orderedGroups = GROUP_ORDER.filter((group) => (groups[group] ?? []).length > 0);

    return (
        <WorkspaceLayout
            area="Admin"
            active="email-templates"
            title="Email templates"
            subtitle="Subject and body for system emails the Newsroom sends."
            actions={
                <PillButton href="/admin/settings" variant="secondary" icon={Settings}>
                    Back to Settings
                </PillButton>
            }
        >
            <>
                <section className="sg-card flex flex-col gap-2 p-5 text-sm text-body sm:p-6" aria-labelledby="email-templates-intro-heading">
                    <h2 id="email-templates-intro-heading" className="text-base font-semibold">
                        How these work
                    </h2>
                    <p>
                        Each template starts from the built-in default. Saving creates an override; you can preview with sample data, send a test to yourself,
                        restore an earlier version, or revert to the default. Placeholders use{' '}
                        <code className="rounded bg-surface px-1">{'{{ tag }}'}</code> and only allowlisted tags are accepted.
                    </p>
                </section>

                {orderedGroups.map((groupKey) => {
                    const items = groups[groupKey] ?? [];
                    const headingId = `email-templates-group-${groupKey}`;

                    return (
                        <section key={groupKey} className="sg-card flex flex-col gap-3 p-5 sm:p-6" aria-labelledby={headingId}>
                            <h2 id={headingId} className="text-base font-semibold text-body">
                                {groupLabels[groupKey] ?? groupKey}
                            </h2>
                            <ul className="divide-y divide-border">
                                {items.map((template) => (
                                    <li key={template.key} className="flex flex-wrap items-center justify-between gap-2 py-3 first:pt-0 last:pb-0">
                                        <div className="min-w-0 flex-1">
                                            <Link
                                                href={`/admin/settings/email-templates/${template.key}`}
                                                className="font-semibold text-body underline-offset-2 hover:underline"
                                            >
                                                {template.name}
                                            </Link>
                                            <p className="mt-0.5 text-xs text-muted">
                                                <code>{template.key}</code>
                                                {template.is_security ? ' · Security template' : ''}
                                            </p>
                                        </div>
                                        <div className="flex flex-wrap items-center gap-2">
                                            {template.has_override ? <Badge tone="info">Overridden</Badge> : <Badge tone="neutral">Default</Badge>}
                                            <PillButton href={`/admin/settings/email-templates/${template.key}`} variant="secondary" size="sm">
                                                Edit
                                            </PillButton>
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        </section>
                    );
                })}
            </>
        </WorkspaceLayout>
    );
}
