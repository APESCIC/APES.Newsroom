import { channelMeta } from '../../channelMeta';
import Badge, { type BadgeTone } from '../Workspace/ui/Badge';

const statuses: Record<string, { label: string; tone: BadgeTone }> = {
    draft: { label: 'Draft', tone: 'neutral' },
    in_review: { label: 'In review', tone: 'warning' },
    scheduled: { label: 'Scheduled', tone: 'info' },
    published: { label: 'Published', tone: 'ok' },
    unpublished: { label: 'Unpublished', tone: 'neutral' },
    deleted: { label: 'Deleted', tone: 'error' },
};

export function postStatusLabel(status: string): string {
    return statuses[status]?.label ?? status.replaceAll('_', ' ');
}

export function PostStatusBadge({ status }: { status: string }) {
    return <Badge tone={statuses[status]?.tone ?? 'neutral'}>{postStatusLabel(status)}</Badge>;
}

export function ChannelLabel({ value }: { value: string }) {
    const meta = channelMeta(value);
    return (
        <span
            className={`inline-flex min-h-6 items-center rounded-full border px-2.5 py-0.5 text-[0.6875rem] font-semibold tracking-wide uppercase ${
                meta?.badgeClass ?? 'border-border bg-page-tint text-muted'
            }`}
        >
            {meta?.label ?? value.replaceAll('_', ' ')}
        </span>
    );
}
