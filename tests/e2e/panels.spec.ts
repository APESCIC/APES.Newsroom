import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import { fitViewportToPage, loginAs, openPanelPage, panelPages } from './support';

test.skip(!!process.env.CAPTURE_DIR, 'Capture run only');

for (const panelPage of panelPages) {
    test.describe(panelPage.name, () => {
        test.beforeEach(async ({ page }) => {
            await loginAs(page, panelPage.role);
        });

        test('matches the Soft glass screenshot', async ({ page }) => {
            await openPanelPage(page, panelPage.path, panelPage.status);
            await fitViewportToPage(page);
            await expect(page).toHaveScreenshot(`${panelPage.name}.png`, {
                mask: (panelPage.mask ?? []).map((selector) => page.locator(selector)),
            });
        });

        test('has no serious or critical accessibility violations', async ({ page }) => {
            await openPanelPage(page, panelPage.path, panelPage.status);
            const results = await new AxeBuilder({ page })
                .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
                .exclude('[aria-label="Local role preview"]')
                .analyze();
            const blocking = results.violations
                .filter((violation) => violation.impact === 'serious' || violation.impact === 'critical')
                .map((violation) => `${violation.id}: ${violation.nodes.map((node) => node.target.join(' ')).join(', ')}`);

            expect(blocking).toEqual([]);
        });

        for (const width of [375, 768, 1440]) {
            test(`does not scroll sideways at ${width}px`, async ({ page }) => {
                await page.setViewportSize({ width, height: 900 });
                await openPanelPage(page, panelPage.path, panelPage.status);
                const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);

                expect(overflow).toBeLessThanOrEqual(0);
            });
        }
    });
}

test.describe('shell', () => {
    test('phone layout uses a drawer and stacked table rows', async ({ page }) => {
        await loginAs(page, 'admin');
        await page.setViewportSize({ width: 375, height: 812 });
        await openPanelPage(page, '/staff/posts');

        await expect(page.getByTestId('workspace-sidebar')).toBeHidden();
        await expect(page).toHaveScreenshot('phone-staff-posts.png');

        await page.getByRole('button', { name: 'Open workspace navigation' }).click();
        const drawer = page.getByRole('dialog', { name: 'Workspace navigation' });
        await expect(drawer).toBeVisible();
        await expect(drawer.getByRole('button', { name: 'Close workspace navigation' })).toBeFocused();
        await expect(page).toHaveScreenshot('phone-navigation-drawer.png');
        await page.keyboard.press('Escape');
        await expect(drawer).toBeHidden();
    });

    test('Ctrl+K opens the command palette and runs a result', async ({ page }) => {
        await loginAs(page, 'admin');
        await openPanelPage(page, '/staff/posts');

        await page.keyboard.press('Control+k');
        const palette = page.getByRole('dialog', { name: 'Search and run commands' });
        await expect(palette.getByRole('combobox')).toBeFocused();
        await expect(page).toHaveScreenshot('command-palette.png');

        await page.keyboard.type('audit');
        await page.keyboard.press('Enter');
        await expect(page).toHaveURL(/\/admin\/audit-log$/);
    });

    test('staff never see admin destinations in the palette', async ({ page }) => {
        await loginAs(page, 'staff');
        await openPanelPage(page, '/staff/posts');

        await page.keyboard.press('Control+k');
        const options = page.getByRole('dialog', { name: 'Search and run commands' }).getByRole('option');
        await expect(options.first()).toBeVisible();
        expect(await options.allTextContents()).not.toContainEqual(expect.stringContaining('Admin ·'));
    });

    test('the skip link moves keyboard focus to the main content', async ({ page }) => {
        await loginAs(page, 'staff');
        await openPanelPage(page, '/staff');

        await page.keyboard.press('Tab');
        const skipLink = page.getByRole('link', { name: 'Skip to main content' });
        await expect(skipLink).toBeFocused();
        await page.keyboard.press('Enter');
        await expect(page.locator('#main-content')).toBeFocused();
    });

    test('row actions open from the keyboard and the confirm dialog returns focus', async ({ page }) => {
        await loginAs(page, 'admin');
        await openPanelPage(page, '/staff/pages');

        const trigger = page.getByRole('button', { name: /^Actions for / }).first();
        await trigger.focus();
        await page.keyboard.press('Enter');
        const menu = page.getByRole('menu');
        await expect(menu.getByRole('menuitem').first()).toBeFocused();

        await menu.getByRole('menuitem', { name: 'Delete' }).focus();
        await page.keyboard.press('Enter');
        const dialog = page.getByRole('alertdialog', { name: 'Delete this page?' });
        await expect(dialog.getByRole('button', { name: 'Cancel' })).toBeFocused();

        await page.keyboard.press('Tab');
        await page.keyboard.press('Tab');
        await expect(dialog.getByRole('button', { name: 'Cancel' })).toBeFocused();

        await page.keyboard.press('Escape');
        await expect(dialog).toBeHidden();
        await expect(trigger).toBeFocused();
    });

    test('readers who ask for less transparency get solid panels', async ({ page }) => {
        await loginAs(page, 'staff');
        const cdp = await page.context().newCDPSession(page);
        await cdp.send('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-reduced-transparency', value: 'reduce' }] });
        await openPanelPage(page, '/staff/posts');

        const card = page.locator('.sg-card').first();
        await expect(card).toHaveCSS('backdrop-filter', 'none');
        await expect(card).toHaveCSS('background-color', 'rgba(255, 255, 255, 0.94)');
    });

    test('ships a solid fallback for browsers without backdrop-filter', async ({ page }) => {
        await loginAs(page, 'staff');
        await openPanelPage(page, '/staff/posts');

        const surfaceAlpha = await page.evaluate(() => {
            for (const sheet of Array.from(document.styleSheets)) {
                for (const rule of Array.from(sheet.cssRules)) {
                    if (!(rule instanceof CSSSupportsRule) || !rule.conditionText.includes('not') || !rule.conditionText.includes('backdrop-filter')) continue;
                    const shell = Array.from(rule.cssRules).find((inner): inner is CSSStyleRule => inner instanceof CSSStyleRule && inner.selectorText === '.workspace-shell');
                    const probe = document.createElement('div');
                    probe.style.backgroundColor = shell?.style.getPropertyValue('--sg-surface').trim() ?? '';
                    document.body.append(probe);
                    const match = getComputedStyle(probe).backgroundColor.match(/rgba?\(([^)]+)\)/);
                    probe.remove();
                    return match ? Number(match[1].split(',')[3] ?? 1) : 0;
                }
            }
            return 0;
        });

        expect(surfaceAlpha).toBeGreaterThanOrEqual(0.9);
    });
});
