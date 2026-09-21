import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
    testDir: './tests/e2e',
    fullyParallel: false,
    forbidOnly: !!process.env.CI,
    retries: process.env.CI ? 2 : 0,
    workers: 1,
    reporter: [['list']],
    use: {
        baseURL: 'http://127.0.0.1:8000',
        trace: 'retain-on-failure',
        // 使用系统 chrome，不下载 Playwright 自带浏览器
        launchOptions: {
            executablePath: '/usr/bin/google-chrome',
            args: ['--no-sandbox', '--disable-dev-shm-usage'],
        },
    },
    projects: [
        {
            name: 'chromium',
            use: { ...devices['Desktop Chrome'] },
        },
    ],
});