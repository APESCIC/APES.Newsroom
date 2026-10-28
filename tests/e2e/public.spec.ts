import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import { fitViewportToPage, loginAs, openPanelPage, publicPages } from './support';

test.skip(!!process.env.CAPTURE_DIR, 'Capture run only');

for (const publicPage of publicPages) {
    test.describe(publicPage.name, () => {
        test.beforeEach(async ({ page }) => {
            if (publicPage.role) await loginAs(page, publicPage.role);
        });

        test('matches the Soft glass screenshot', async ({ page }) => {
            await openPanelPage(page, publicPage.path, publicPage.status);
            await fitViewportToPage(page);
            await expect(page).toHaveScreenshot(`${publicPage.name}.png`, { mask: [page.locator('.site-footer-fineprint')] });
        });

        test('has no serious or critical accessibility violations', async ({ page }) => {
            await openPanelPage(page, publicPage.path, publicPage.status);
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
                await openPanelPage(page, publicPage.path, publicPage.status);
                const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);

                expect(overflow).toBeLessThanOrEqual(0);
            });
        }
    });
}

test.describe('public shell', () => {
    test('the phone menu opens, shows channels and closes with Escape (screenshot)', async ({ page }) => {
        await page.setViewportSize({ width: 375, height: 812 });
        await openPanelPage(page, '/');

        const trigger = page.getByRole('button', { name: 'Open main menu' });
        const menuId = await trigger.getAttribute('aria-controls');
        await trigger.click();
        await expect(page.locator(`[id="${menuId}"]`).getByRole('link', { name: 'APES Shelter & Rescue' })).toBeVisible();
        await expect(page).toHaveScreenshot('public-phone-menu.png');
        await page.keyboard.press('Escape');
        await expect(page.getByRole('button', { name: 'Open main menu' })).toBeFocused();
    });

    test('the skip link moves keyboard focus to the main content', async ({ page }) => {
        await openPanelPage(page, '/');

        await page.keyboard.press('Tab');
        const skipLink = page.getByRole('link', { name: 'Skip to main content' });
        await expect(skipLink).toBeFocused();
        await page.keyboard.press('Enter');
        await expect(page).toHaveURL(/#main-content$/);
    });

    test('keyboard focus is visible on header, card and footer controls', async ({ page }) => {
        await openPanelPage(page, '/');

        for (const control of [
            page.getByRole('navigation', { name: 'Primary navigation' }).getByRole('link', { name: 'Home' }),
            page.getByRole('link', { name: 'Subscribe to APES CIC' }),
            page.getByRole('contentinfo').getByRole('link', { name: 'Mailing lists' }),
        ]) {
            await control.focus();
            await expect(control).toHaveCSS('outline-style', 'solid');
            await expect(control).toHaveCSS('outline-color', 'rgb(37, 99, 235)');
        }
    });

    test('readers who ask for less transparency get solid panels', async ({ page }) => {
        const cdp = await page.context().newCDPSession(page);
        await cdp.send('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-reduced-transparency', value: 'reduce' }] });
        await openPanelPage(page, '/');

        const card = page.locator('.sg-card').first();
        await expect(card).toHaveCSS('backdrop-filter', 'none');
        await expect(card).toHaveCSS('background-color', 'rgba(255, 255, 255, 0.94)');
        await expect(page.locator('.public-header')).toHaveCSS('background-color', 'rgb(255, 255, 255)');
        await expect(page.locator('.site-footer-panel').first()).toHaveCSS('backdrop-filter', 'none');
    });

    test('channel cards keep their accent border on the light base', async ({ page }) => {
        await openPanelPage(page, '/');

        const stream = page.getByRole('article', { name: 'Stream 4 APES' });
        await expect(stream).toHaveCSS('border-left-color', 'rgb(143, 106, 8)');
        await expect(stream).toHaveCSS('border-left-width', '4px');
    });
});
