import { test, expect } from '@playwright/test';

const PAGES = [
    { name: 'home', url: '/' },
    { name: 'login', url: '/login' },
    { name: 'user-basic', url: '/user/basic' },
];

test.describe('Visual baselines', () => {
    for (const { name, url } of PAGES) {
        test(`${name} matches baseline`, async ({ page }) => {
            await page.goto(url);
            await expect(page).toHaveScreenshot(`baseline-${name}.png`, {
                maxDiffPixelRatio: 0.05,
            });
        });
    }
});
