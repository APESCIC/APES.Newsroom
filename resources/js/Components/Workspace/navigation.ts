import {
    BarChart3,
    ClipboardCheck,
    Contact,
    FileStack,
    FileText,
    Globe,
    Image,
    IdCard,
    KeyRound,
    KeySquare,
    LayoutTemplate,
    Mail,
    MailCheck,
    MailPlus,
    Plug,
    Rocket,
    ScrollText,
    Settings,
    ShieldCheck,
    SquarePen,
    Tag,
    UserCircle,
    UserCog,
    Users,
    Wallet,
    Webhook,
    type LucideIcon,
} from 'lucide-react';
import type { SharedAuth } from '../../types/page';

export type WorkspaceArea = 'Admin' | 'Staff';

export type WorkspaceActive =
    | 'moderation'
    | 'posts'
    | 'review'
    | 'pages'
    | 'media'
    | 'newsletters'
    | 'people'
    | 'subscribers'
    | 'membership-plans'
    | 'offers'
    | 'metrics'
    | 'members'
    | 'webhooks'
    | 'releases'
    | 'settings'
    | 'integrations'
    | 'email-domains'
    | 'audit-log'
    | 'users'
    | 'api-tokens'
    | 'content-api-keys';

export type NavItem = {
    key: WorkspaceActive;
    href: string;
    label: string;
    icon: LucideIcon;
    /** Extra words the command palette matches on. */
    keywords?: string;
    visible?: (can: SharedAuth['can']) => boolean;
};

export type NavGroup = {
    heading: string;
    items: NavItem[];
};

const canReview = (can: SharedAuth['can']) => can.reviewPosts === true;

/** Sidebar sections for each panel, in display order (#353, #516). */
export const navigation: Record<WorkspaceArea, NavGroup[]> = {
    Staff: [
        {
            heading: 'Content',
            items: [
                { key: 'posts', href: '/staff/posts', label: 'Posts', icon: FileText, keywords: 'articles stories drafts' },
                { key: 'review', href: '/staff/posts/review', label: 'Review queue', icon: ClipboardCheck, keywords: 'approve submitted', visible: canReview },
                { key: 'pages', href: '/staff/pages', label: 'Pages', icon: FileStack, keywords: 'static about' },
                { key: 'media', href: '/staff/media', label: 'Media', icon: Image, keywords: 'images uploads library' },
            ],
        },
        {
            heading: 'Audience',
            items: [
                { key: 'people', href: '/staff/people', label: 'People', icon: Contact, keywords: 'members accounts readers profiles edit' },
                { key: 'newsletters', href: '/staff/newsletters', label: 'Newsletters', icon: Mail, keywords: 'email lists campaigns' },
                { key: 'subscribers', href: '/staff/subscribers', label: 'Subscribers', icon: Users, keywords: 'mailing contacts export' },
            ],
        },
        {
            heading: 'Commerce',
            items: [
                { key: 'membership-plans', href: '/staff/membership-plans', label: 'Plans', icon: Wallet, keywords: 'membership pricing stripe' },
                { key: 'offers', href: '/staff/offers', label: 'Offers', icon: Tag, keywords: 'discount coupon promotion' },
            ],
        },
        {
            heading: 'Insights',
            items: [{ key: 'metrics', href: '/staff/metrics', label: 'Metrics', icon: BarChart3, keywords: 'analytics stats views' }],
        },
    ],
    Admin: [
        {
            heading: 'People',
            items: [
                { key: 'users', href: '/admin/users', label: 'Users', icon: UserCog, keywords: 'accounts roles security' },
                { key: 'members', href: '/admin/members', label: 'Members', icon: IdCard, keywords: 'readers paying free' },
                { key: 'moderation', href: '/admin/moderation', label: 'Moderation', icon: ShieldCheck, keywords: 'comments profiles reports' },
            ],
        },
        {
            heading: 'Integrations',
            items: [
                { key: 'webhooks', href: '/admin/webhooks', label: 'Webhooks', icon: Webhook, keywords: 'outbound events' },
                { key: 'api-tokens', href: '/admin/api-tokens', label: 'API tokens', icon: KeyRound, keywords: 'admin api access' },
                { key: 'content-api-keys', href: '/admin/content-api-keys', label: 'Content API keys', icon: KeySquare, keywords: 'public api read' },
                { key: 'integrations', href: '/admin/settings/integrations', label: 'Integrations & keys', icon: Plug, keywords: 'mailgun stripe unsplash openai slack health' },
                { key: 'email-domains', href: '/admin/settings/email-domains', label: 'Sending domains', icon: MailCheck, keywords: 'email dns dkim spf' },
            ],
        },
        {
            heading: 'System',
            items: [
                { key: 'audit-log', href: '/admin/audit-log', label: 'Audit log', icon: ScrollText, keywords: 'history events security' },
                { key: 'releases', href: '/admin/releases', label: 'Releases', icon: Rocket, keywords: 'change log versions' },
                { key: 'settings', href: '/admin/settings', label: 'Settings', icon: Settings, keywords: 'site editor configuration' },
            ],
        },
    ],
};

export function canUseArea(area: WorkspaceArea, can: SharedAuth['can']): boolean {
    return area === 'Admin' ? can.accessAdmin : can.accessStaff;
}

/** The groups and items this user may see in one panel. */
export function visibleGroups(area: WorkspaceArea, can: SharedAuth['can']): NavGroup[] {
    if (!canUseArea(area, can)) return [];

    return navigation[area]
        .map((group) => ({ ...group, items: group.items.filter((item) => item.visible?.(can) ?? true) }))
        .filter((group) => group.items.length > 0);
}

export function findNavItem(area: WorkspaceArea, active: WorkspaceActive): NavItem | undefined {
    return navigation[area].flatMap((group) => group.items).find((item) => item.key === active);
}

export function areaHome(area: WorkspaceArea): string {
    return navigation[area][0].items[0].href;
}

export type QuickAction = {
    id: string;
    href: string;
    label: string;
    icon: LucideIcon;
    keywords?: string;
    area: WorkspaceArea | 'Site';
    visible?: (can: SharedAuth['can']) => boolean;
};

/** Command palette actions beyond plain navigation (#517). */
export const quickActions: QuickAction[] = [
    { id: 'new-post', href: '/staff/posts/new', label: 'New post', icon: SquarePen, keywords: 'write draft article', area: 'Staff', visible: (can) => can.accessStaff },
    { id: 'new-page', href: '/staff/pages/new', label: 'New page', icon: LayoutTemplate, keywords: 'create static', area: 'Staff', visible: (can) => can.accessStaff },
    { id: 'new-newsletter', href: '/staff/newsletters/new', label: 'New newsletter', icon: MailPlus, keywords: 'send email list create', area: 'Staff', visible: (can) => can.accessStaff },
    { id: 'open-review', href: '/staff/posts/review', label: 'Open review queue', icon: ClipboardCheck, keywords: 'approve submitted', area: 'Staff', visible: canReview },
    { id: 'switch-staff', href: areaHome('Staff'), label: 'Switch to Staff panel', icon: FileText, keywords: 'panel workspace', area: 'Staff', visible: (can) => can.accessStaff && can.accessAdmin },
    { id: 'switch-admin', href: areaHome('Admin'), label: 'Switch to Admin panel', icon: ShieldCheck, keywords: 'panel workspace', area: 'Admin', visible: (can) => can.accessAdmin },
    { id: 'view-site', href: '/', label: 'View site', icon: Globe, keywords: 'home public newsroom', area: 'Site' },
    { id: 'account', href: '/account', label: 'Your account', icon: UserCircle, keywords: 'profile security password', area: 'Site' },
];
