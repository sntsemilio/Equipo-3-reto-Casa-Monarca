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
  const docTitle = `Flujo Gestor ${stamp}`;
  const docRoute = `/certificados/flujo-gestor-${stamp}.pdf`;

  try {
    await page.goto(`${BASE_URL}/index.html`, { waitUntil: 'domcontentloaded' });

    // 1) Login
    await page.fill('#login-email', ADMIN_EMAIL);
    await page.fill('#login-password', ADMIN_PASSWORD);
    await page.click('#btn-login');

    await page.waitForURL(/dashboard\.html$/, { timeout: 20000 });

    // 2) Confirmar rol en interfaz
    const roleText = ((await page.locator('#user-role-pill').textContent()) || '').trim().toLowerCase();
    assert(roleText.includes('administrador'), 'El usuario autenticado debe mostrarse como Administrador.');

    // 3) Crear documento en estado borrador
    await page.click('.sidebar-menu a[data-section="documentos"]');
    await page.fill('#doc-title', docTitle);
    await page.fill('#doc-route', docRoute);
    await page.fill('#doc-description', 'Documento generado por E2E_FlujoGestor');
    await page.fill('#doc-content', `Contenido de prueba ${stamp}`);
    const createToasts = await page.locator('.toast.toast-success').count();
    await page.click('#btn-save-doc');
    await waitForNewToast(page, createToasts, 'success');

    const row = page.locator('#docs-tbody tr', { hasText: docTitle }).first();
    await row.waitFor({ state: 'visible', timeout: 15000 });

    const draftStatus = ((await row.locator('.badge').first().textContent()) || '').trim().toLowerCase();
    assert(draftStatus.includes('borrador'), 'El documento recién creado debe estar en borrador.');

    // 4) Emitir y firmar documento
    const emitToasts = await page.locator('.toast.toast-success').count();
    await row.locator('button[data-action="emitir"]').click();
    await waitForNewToast(page, emitToasts, 'success');

    const emittedRow = page.locator('#docs-tbody tr', { hasText: docTitle }).first();
    await emittedRow.waitFor({ state: 'visible', timeout: 15000 });

    const emittedStatus = ((await emittedRow.locator('.badge').first().textContent()) || '').trim().toLowerCase();
    assert(emittedStatus.includes('emitido'), 'El documento debe quedar emitido tras firmarlo.');

    // 5) Consulta externa en verificar.php por token QR
    const verifyHref = await emittedRow.locator('a[href*="verificar.php?token="]').first().getAttribute('href');
    assert(verifyHref && verifyHref.includes('token='), 'Debe existir un enlace de verificación pública por token.');

    const verifyPage = await context.newPage();
    await verifyPage.goto(`${BASE_URL}/${verifyHref}`, { waitUntil: 'domcontentloaded' });

    await verifyPage.locator('#verify-result').waitFor({ state: 'visible', timeout: 15000 });

    const publicState = ((await verifyPage.locator('#verify-state-pill').textContent()) || '').trim().toLowerCase();
    assert(publicState.includes('emitido'), 'La consulta pública debe reportar estado emitido antes de revocación.');

    const publicAuth = ((await verifyPage.locator('#verify-auth-pill').textContent()) || '').trim().toLowerCase();
    assert(publicAuth.includes('auténtico') || publicAuth.includes('autentico'), 'La consulta pública debe indicar autenticidad.');

    await verifyPage.close();

    // 6) Revocación
    page.once('dialog', (dialog) => dialog.accept('Revocación validada en E2E.'));
    const revokeToasts = await page.locator('.toast.toast-success').count();
    await emittedRow.locator('button[data-action="revocar"]').click();
    await waitForNewToast(page, revokeToasts, 'success');

    const revokedRow = page.locator('#docs-tbody tr', { hasText: docTitle }).first();
    await revokedRow.waitFor({ state: 'visible', timeout: 15000 });

    const revokedStatus = ((await revokedRow.locator('.badge').first().textContent()) || '').trim().toLowerCase();
    assert(revokedStatus.includes('revocado'), 'El documento debe quedar revocado.');

    // 7) Validación de bitácora
    await page.click('.sidebar-menu a[data-section="bitacora"]');
    await page.click('#btn-refresh-bitacora');

    await page.locator('#bitacora-tbody').waitFor({ state: 'visible', timeout: 10000 });

    const bitacoraText = ((await page.locator('#bitacora-tbody').textContent()) || '').toUpperCase();
    assert(bitacoraText.includes('CREAR_DOCUMENTO'), 'Bitácora debe contener evento CREAR_DOCUMENTO.');
    assert(bitacoraText.includes('EMITIR_DOCUMENTO'), 'Bitácora debe contener evento EMITIR_DOCUMENTO.');
    assert(bitacoraText.includes('REVOCAR_DOCUMENTO'), 'Bitácora debe contener evento REVOCAR_DOCUMENTO.');

    // 8) Cierre de sesión
    await page.click('#btn-logout');
    await page.waitForURL(/index\.html$/, { timeout: 15000 });

    console.log('E2E OK: flujo integral del gestor validado.');
  } finally {
    await context.close();
    await browser.close();
  }
}

run().catch((error) => {
  console.error('E2E FAIL:', error.message);
  process.exit(1);
});
