import { test, expect } from '@playwright/test';

test.describe('Wizard smoke tests', () => {
    test('homepage loads', async ({ page }) => {
        await page.goto('/');
        await expect(page.locator('body')).toBeVisible();
    });

    test('login page has form', async ({ page }) => {
        await page.goto('/login');
        await expect(page.locator('input[name="email"]')).toBeVisible();
        await expect(page.locator('input[name="password"]')).toBeVisible();
        await expect(page.locator('form:has(input[name="password"]) button[type="submit"]')).toBeVisible();
    });

    test('static pages render with BS5', async ({ page }) => {
        await page.goto('/login');
        // BS5 form-control 类应生效
        const input = page.locator('input[name="email"]');
        await expect(input).toHaveClass(/form-control/);
    });
});
