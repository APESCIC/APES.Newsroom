import { test } from '@playwright/test';
import path from 'node:path';
import { fitViewportToPage, loginAs, openPanelPage, panelPages } from './support';

/**
 * Saves a desktop and phone screenshot of every panel page to CAPTURE_DIR,
 * for before-and-after evidence on pull requests. Skipped unless set.
 */
const captureDir = process.env.CAPTURE_DIR;

test.describe('capture panel screenshots', () => {
    test.skip(!captureDir, 'Set CAPTURE_DIR to capture screenshots');

    for (const panelPage of panelPages) {
        test(panelPage.name, async ({ page }) => {
            await loginAs(page, panelPage.role);

            await page.setViewportSize({ width: 1280, height: 900 });
            await openPanelPage(page, panelPage.path, panelPage.status);
            await fitViewportToPage(page);
            await page.screenshot({ path: path.join(captureDir!, `${panelPage.name}.jpg`), quality: 70 });

            await page.setViewportSize({ width: 375, height: 812 });
            await openPanelPage(page, panelPage.path, panelPage.status);
            await page.screenshot({ path: path.join(captureDir!, `${panelPage.name}-375.jpg`), quality: 70 });
        });
    }
});
