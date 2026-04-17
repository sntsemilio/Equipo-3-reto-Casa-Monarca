import { chromium } from 'playwright';

const BASE_URL = process.env.BASE_URL || 'http://localhost:8080/frontend';
const ADMIN_EMAIL = process.env.E2E_EMAIL || 'admin@casamonarca.org';
const ADMIN_PASSWORD = process.env.E2E_PASSWORD || 'admin123';
const HEADLESS = process.env.HEADLESS !== 'false';

function assert(condition, message) {
  if (!condition) {
    throw new Error(message);
  }
}

async function waitForToast(page, type = 'success') {
  const toast = page.locator(`.toast.toast-${type}`).last();
  await toast.waitFor({ state: 'visible', timeout: 15000 });
  return toast;
}

async function waitForNewToast(page, previousCount, type = 'success') {
  const selector = `.toast.toast-${type}`;
  await page.waitForFunction(
    ({ sel, prev }) => document.querySelectorAll(sel).length > prev,
    { sel: selector, prev: previousCount },
    { timeout: 15000 }
  );

  return waitForToast(page, type);
}

async function run() {
  const browser = await chromium.launch({ headless: HEADLESS });
  const context = await browser.newContext();
  const page = await context.newPage();

  const stamp = Date.now();
  const docTitle = `Lifecycle E2E ${stamp}`;
  const docRoute = `/certificados/lifecycle-${stamp}.pdf`;

  try {
    await page.goto(`${BASE_URL}/index.html`, { waitUntil: 'domcontentloaded' });

    await page.fill('#login-email', ADMIN_EMAIL);
    await page.fill('#login-password', ADMIN_PASSWORD);
    await page.click('#btn-login');
    await page.waitForURL(/dashboard\.html$/, { timeout: 20000 });

    await page.click('.sidebar-menu a[data-section="documentos"]');
    await page.fill('#doc-title', docTitle);
    await page.fill('#doc-route', docRoute);
    await page.fill('#doc-description', 'Documento de prueba lifecycle');
    await page.fill('#doc-content', `Contenido lifecycle ${stamp}`);
    const createToasts = await page.locator('.toast.toast-success').count();
    await page.click('#btn-save-doc');
    await waitForNewToast(page, createToasts, 'success');

    const row = page.locator('#docs-tbody tr', { hasText: docTitle }).first();
    await row.waitFor({ state: 'visible', timeout: 15000 });

    const draftStatus = ((await row.locator('.badge').first().textContent()) || '').trim().toLowerCase();
    assert(draftStatus.includes('borrador'), 'El documento debe iniciar en borrador.');

    const emitToasts = await page.locator('.toast.toast-success').count();
    await row.locator('button[data-action="emitir"]').click();
    await waitForNewToast(page, emitToasts, 'success');

    const emittedRow = page.locator('#docs-tbody tr', { hasText: docTitle }).first();
    await emittedRow.waitFor({ state: 'visible', timeout: 15000 });
    const emittedStatus = ((await emittedRow.locator('.badge').first().textContent()) || '').trim().toLowerCase();
    assert(emittedStatus.includes('emitido'), 'El documento debe pasar a emitido.');

    page.once('dialog', (dialog) => dialog.accept('Revocacion desde E2E lifecycle'));
    const revokeToasts = await page.locator('.toast.toast-success').count();
    await emittedRow.locator('button[data-action="revocar"]').click();
    await waitForNewToast(page, revokeToasts, 'success');

    const revokedRow = page.locator('#docs-tbody tr', { hasText: docTitle }).first();
    await revokedRow.waitFor({ state: 'visible', timeout: 15000 });
    const revokedStatus = ((await revokedRow.locator('.badge').first().textContent()) || '').trim().toLowerCase();
    assert(revokedStatus.includes('revocado'), 'El documento debe quedar revocado.');

    await page.click('#btn-logout');
    await page.waitForURL(/index\.html$/, { timeout: 15000 });

    console.log('E2E OK: ciclo de vida de documento validado.');
  } finally {
    await context.close();
    await browser.close();
  }
}

run().catch((error) => {
  console.error('E2E FAIL:', error.message);
  process.exit(1);
});
