import { expect, type Page } from '@playwright/test';

export type Role = 'staff' | 'admin' | 'super_admin';

export type PanelPage = {
    name: string;
    path: string;
    role: Role;
    /** Expected HTTP status; panel error pages are 403 or 404. */
    status?: number;
    /** Selectors hidden in screenshots because their content changes every run. */
    mask?: string[];
};

/**
 * Every staff and admin page, as seeded by VisualRegressionSeeder on a fresh
 * database. Test sign-ins write audit entries, so the audit log is filtered to
 * the seeded day.
 */
export const panelPages: PanelPage[] = [
    { name: 'staff-dashboard', path: '/staff', role: 'staff' },
    { name: 'staff-dashboard-admin', path: '/staff', role: 'admin' },
    { name: 'staff-not-found', path: '/staff/no-such-page', role: 'staff', status: 404 },
    { name: 'staff-posts', path: '/staff/posts', role: 'staff' },
    { name: 'staff-post-new', path: '/staff/posts/new', role: 'staff' },
    { name: 'staff-post-edit', path: '/staff/posts/1/edit', role: 'admin' },
    { name: 'staff-review-queue', path: '/staff/posts/review', role: 'admin' },
    { name: 'staff-pages', path: '/staff/pages', role: 'staff' },
    { name: 'staff-media', path: '/staff/media', role: 'staff' },
    { name: 'staff-newsletters', path: '/staff/newsletters', role: 'staff' },
    { name: 'staff-subscribers', path: '/staff/subscribers', role: 'staff' },
    { name: 'staff-plans', path: '/staff/membership-plans', role: 'staff' },
    { name: 'staff-offers', path: '/staff/offers', role: 'staff' },
    { name: 'staff-metrics', path: '/staff/metrics', role: 'staff' },
    { name: 'admin-dashboard', path: '/admin', role: 'admin', mask: ['[aria-label="Recent audit events"]'] },
    { name: 'admin-forbidden-for-staff', path: '/admin', role: 'staff', status: 403 },
    { name: 'admin-users', path: '/admin/users', role: 'admin' },
    { name: 'admin-user-show', path: '/admin/users/1', role: 'admin' },
    { name: 'admin-moderation', path: '/admin/moderation', role: 'admin' },
    { name: 'admin-members', path: '/admin/members', role: 'admin' },
    { name: 'admin-webhooks', path: '/admin/webhooks', role: 'admin' },
    { name: 'admin-api-tokens', path: '/admin/api-tokens', role: 'admin' },
    { name: 'admin-content-api-keys', path: '/admin/content-api-keys', role: 'admin' },
    { name: 'admin-integrations', path: '/admin/settings/integrations', role: 'super_admin' },
    { name: 'admin-email-domains', path: '/admin/settings/email-domains', role: 'admin' },
    { name: 'admin-audit-log', path: '/admin/audit-log?from=2026-10-20&to=2026-10-20', role: 'admin' },
    { name: 'admin-releases', path: '/admin/releases', role: 'admin' },
    { name: 'admin-settings', path: '/admin/settings', role: 'admin' },
];

export async function loginAs(page: Page, role: Role): Promise<void> {
    await page.goto('/login');
    const xsrf = (await page.context().cookies()).find((cookie) => cookie.name === 'XSRF-TOKEN');
    expect(xsrf, 'XSRF-TOKEN cookie').toBeDefined();

    const response = await page.request.post(`/_dev/login/${role}`, {
        headers: { 'X-XSRF-TOKEN': decodeURIComponent(xsrf!.value) },
        maxRedirects: 0,
    });
    expect(response.status(), 'dev login (APP_ENV must be local)').toBe(302);
}

/** Load a panel page and wait until fonts and editors have settled. */
export async function openPanelPage(page: Page, path: string, status = 200): Promise<void> {
    const response = await page.goto(path);
    expect(response?.status(), `GET ${path}`).toBe(status);
    await page.waitForLoadState('networkidle');
    await page.addStyleTag({ content: '[aria-label="Local role preview"] { display: none !important; }' });
    await page.evaluate(() => document.fonts.ready);
}

/**
 * Grow the viewport to the whole page so the fixed background and sidebar
 * cover long pages in the screenshot, as they do when scrolling a real browser.
 */
export async function fitViewportToPage(page: Page, width = 1280): Promise<void> {
    const height = await page.evaluate(() => document.documentElement.scrollHeight);
    await page.setViewportSize({ width, height: Math.min(Math.max(height, 720), 4000) });
}
