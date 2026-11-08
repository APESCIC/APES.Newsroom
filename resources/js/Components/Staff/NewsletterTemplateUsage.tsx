import Badge from '../Workspace/ui/Badge';

export type NewsletterTemplateUsage = 'own' | 'default_edited' | 'built_in';

export function templateUsageLabel(usage: NewsletterTemplateUsage): string {
    switch (usage) {
        case 'own':
            return 'Own template';
        case 'default_edited':
            return 'Default template (edited)';
        case 'built_in':
            return 'Default template';
        default: {
            const unreachable: never = usage;
            return unreachable;
        }
    }
}

export function NewsletterTemplateBadge({ usage }: { usage: NewsletterTemplateUsage }) {
    return <Badge tone={usage === 'own' ? 'info' : 'neutral'}>{templateUsageLabel(usage)}</Badge>;
}
