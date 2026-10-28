import { test } from '@playwright/test';
import path from 'node:path';
import { fitViewportToPage, loginAs, openPanelPage, panelPages, publicPages, type Role } from './support';

/**
 * Saves a desktop and phone screenshot of every panel and public page to
 * CAPTURE_DIR, for before-and-after evidence on pull requests. Skipped unless
 * set. CAPTURE_ONLY=public or CAPTURE_ONLY=panels limits the run to one set.
 */
const captureDir = process.env.CAPTURE_DIR;
const captureOnly = process.env.CAPTURE_ONLY;

type CapturePage = { name: string; path: string; role?: Role; status?: number };

const pages: CapturePage[] = [
    ...(captureOnly === 'public' ? [] : panelPages),
    ...(captureOnly === 'panels' ? [] : publicPages),
];

test.describe('capture screenshots', () => {
    test.skip(!captureDir, 'Set CAPTURE_DIR to capture screenshots');

    for (const capturePage of pages) {
        test(capturePage.name, async ({ page }) => {
            if (capturePage.role) await loginAs(page, capturePage.role);

            await page.setViewportSize({ width: 1280, height: 900 });
            await openPanelPage(page, capturePage.path, capturePage.status);
            await fitViewportToPage(page);
            await page.screenshot({ path: path.join(captureDir!, `${capturePage.name}.jpg`), quality: 70 });

            await page.setViewportSize({ width: 375, height: 812 });
            await openPanelPage(page, capturePage.path, capturePage.status);
            await page.screenshot({ path: path.join(captureDir!, `${capturePage.name}-375.jpg`), quality: 70 });
        });
    }
});
