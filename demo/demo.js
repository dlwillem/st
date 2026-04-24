/**
 * Demo-video opname van de DKG SelectieTool.
 *
 * Gebruik:
 *   cd demo
 *   npm init -y
 *   npm install playwright
 *   npx playwright install chromium
 *   node demo.js
 *
 * Aannames:
 *   - MAMP draait op http://localhost:8888/st
 *   - Account wjanssen@dkgservices.nl / willem bestaat met admin-rol
 *   - Er is minstens één traject met requirements + leveranciers + scores
 *
 * Het script valt terug op tekst-selectors waar mogelijk; als je aan
 * specifieke paginaloaders twijfelt vergroot je SPEED onderin.
 */

const { chromium } = require('playwright');
const path         = require('path');
const fs           = require('fs');

const BASE   = 'http://localhost:8888/st';
const EMAIL  = 'wjanssen@dkgservices.nl';
const PW     = 'tmT7Zzv=+TQ_j?G';
const SPEED  = 1;                             // 1 = normaal, 2 = dubbel zo traag
const W      = 1280;
const H      = 800;

const outputDir = path.join(__dirname, 'demo_output');
if (!fs.existsSync(outputDir)) fs.mkdirSync(outputDir, { recursive: true });

(async () => {
  const browser = await chromium.launch({ headless: false, slowMo: 60 });
  const context = await browser.newContext({
    viewport: { width: W, height: H },
    recordVideo: { dir: outputDir, size: { width: W, height: H } },
  });
  const page = await context.newPage();

  // ─── Helpers ───────────────────────────────────────────────────────────────
  const wait = (ms) => page.waitForTimeout(ms * SPEED);

  async function typeSlow(locator, text, delay = 70) {
    await locator.click();
    await locator.fill('');
    await locator.type(text, { delay });
  }

  async function smoothScroll(px, steps = 24) {
    const step = Math.round(px / steps);
    for (let i = 0; i < steps; i++) {
      await page.evaluate((s) => window.scrollBy(0, s), step);
      await page.waitForTimeout(20);
    }
  }

  async function goto(pathname) {
    await page.goto(BASE + pathname, { waitUntil: 'domcontentloaded' });
    // kleine buffer zodat webfonts + CSS renderen
    await wait(400);
  }

  async function highlight(locator, ms = 900) {
    // Visuele markering (oranje outline) zodat de kijker ziet waar we hoveren.
    const handle = await locator.elementHandle();
    if (!handle) return;
    await page.evaluate((el) => {
      const prev = el.style.boxShadow;
      el.style.transition = 'box-shadow .2s';
      el.style.boxShadow = '0 0 0 3px #f59e0b';
      el.dataset._prevShadow = prev || '';
    }, handle);
    await page.waitForTimeout(ms);
    await page.evaluate((el) => {
      el.style.boxShadow = el.dataset._prevShadow || '';
    }, handle);
  }

  // ─── 1. Login ──────────────────────────────────────────────────────────────
  console.log('▶ 1. Login');
  await goto('/pages/login.php');
  await wait(1200);
  await typeSlow(page.locator('input[name="email"]'), EMAIL, 80);
  await wait(250);
  await typeSlow(page.locator('input[name="password"]'), PW, 80);
  await wait(500);
  await page.click('button[type="submit"]');
  await page.waitForURL('**/pages/home.php', { timeout: 8000 }).catch(() => {});
  await wait(1200);

  // ─── 2. Home / dashboard ───────────────────────────────────────────────────
  console.log('▶ 2. Home');
  await smoothScroll(420);
  await wait(1500);
  await smoothScroll(420);
  await wait(1500);
  await page.evaluate(() => window.scrollTo({ top: 0, behavior: 'smooth' }));
  await wait(1000);

  // ─── 3. Trajecten-overzicht ────────────────────────────────────────────────
  console.log('▶ 3. Trajecten');
  await page.click('.sidebar-nav a[href*="trajecten.php"]');
  await page.waitForLoadState('domcontentloaded');
  await wait(1500);

  const firstCard = page.locator('a.traj-card').first();
  await firstCard.scrollIntoViewIfNeeded();
  await firstCard.hover();
  await highlight(firstCard, 1200);

  // Traject-id uit de href lezen zodat we alle subpagina's direct kunnen openen.
  const href = await firstCard.getAttribute('href');
  const m    = href && href.match(/id=(\d+)/);
  const tid  = m ? m[1] : null;
  if (!tid) throw new Error('Kon traject-id niet bepalen uit ' + href);
  console.log('   traject id =', tid);

  await firstCard.click();
  await page.waitForLoadState('domcontentloaded');
  await wait(1500);

  // ─── 4. Leveranciers-tab ───────────────────────────────────────────────────
  console.log('▶ 4. Leveranciers');
  await goto(`/pages/traject_detail.php?id=${tid}&tab=leveranciers`);
  await wait(1200);
  await smoothScroll(300);
  await wait(1000);
  await page.evaluate(() => window.scrollTo({ top: 0, behavior: 'smooth' }));
  await wait(600);

  // Eerste "Bekijk antwoorden"-link (valt terug op tekst-selector)
  const antwoordenLink = page.getByRole('link', { name: /Bekijk antwoorden/i }).first();
  if (await antwoordenLink.count()) {
    await antwoordenLink.scrollIntoViewIfNeeded();
    await highlight(antwoordenLink, 900);
    await antwoordenLink.click();
    await page.waitForLoadState('domcontentloaded');
    await wait(1800);
    // Toon classificatiefilter
    await smoothScroll(250);
    await wait(1500);
    await page.goBack();
    await page.waitForLoadState('domcontentloaded');
    await wait(800);
  }

  // ─── 5. Requirements-pagina (redesign) ────────────────────────────────────
  console.log('▶ 5. Requirements');
  await goto('/pages/requirements.php');
  await wait(1500);

  // Zoekveld: typ "login"
  const searchInput = page.locator('input[name="q"]').first();
  if (await searchInput.count()) {
    await typeSlow(searchInput, 'login', 90);
    await wait(500);
    await searchInput.press('Enter').catch(() => {});
    await wait(2000);
    await typeSlow(searchInput, '', 30); // wissen
    await wait(400);
  }

  // Klik een categorie-pill (NFR / VEND) als die bestaat
  const vendTab = page.getByRole('link', { name: /^VEND/i }).first();
  if (await vendTab.count()) {
    await highlight(vendTab, 700);
    await vendTab.click().catch(() => {});
    await page.waitForLoadState('domcontentloaded');
    await wait(1500);
  }

  // Terug naar alle requirements
  await goto('/pages/requirements.php');
  await wait(800);

  // ─── 6. Weging instellen ───────────────────────────────────────────────────
  console.log('▶ 6. Weging');
  await goto(`/pages/traject_detail.php?id=${tid}&tab=weging`);
  await wait(1800);
  await smoothScroll(300);
  await wait(2500);
  await page.evaluate(() => window.scrollTo({ top: 0, behavior: 'smooth' }));
  await wait(600);

  // ─── 7. Scoring — overzicht rondes per leverancier ────────────────────────
  console.log('▶ 7. Scoring');
  await goto(`/pages/traject_detail.php?id=${tid}&tab=scoring`);
  await wait(2000);
  await smoothScroll(250);
  await wait(1500);
  await page.evaluate(() => window.scrollTo({ top: 0, behavior: 'smooth' }));
  await wait(600);

  // ─── 8. Rapportage — ranking + drill-down met toelichtingen ───────────────
  console.log('▶ 8. Rapportage');
  await goto(`/pages/rapportage.php?traject_id=${tid}`);
  await wait(2000);
  await smoothScroll(300);
  await wait(1500);

  const detailsBtn = page.getByRole('link', { name: 'Details' }).first();
  if (await detailsBtn.count()) {
    await highlight(detailsBtn, 700);
    await detailsBtn.click();
    await page.waitForLoadState('domcontentloaded');
    // drilldown scrollt zichzelf in beeld via ingebouwde JS
    await wait(2500);

    // Klap het eerste requirement met toelichtingen uit
    const reqSummary = page.locator('.rp-req > summary').first();
    if (await reqSummary.count()) {
      await reqSummary.scrollIntoViewIfNeeded();
      await highlight(reqSummary, 700);
      await reqSummary.click();
      await wait(3000);
    }
    // En nog eentje om het idee duidelijk te maken
    const reqSummary2 = page.locator('.rp-req > summary').nth(2);
    if (await reqSummary2.count()) {
      await reqSummary2.scrollIntoViewIfNeeded();
      await reqSummary2.click();
      await wait(2500);
    }
  }

  // ─── 9. Methodiek-sectie ───────────────────────────────────────────────────
  console.log('▶ 9. Methodiek');
  await page.evaluate(() => {
    const el = document.querySelector('.rp-method');
    if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
  });
  await wait(3500);

  // ─── 10. Uitloggen ─────────────────────────────────────────────────────────
  console.log('▶ 10. Uitloggen');
  // Open de profiel-dropdown in de sidebar
  await page.locator('.s-prof-btn').click();
  await wait(700);
  await page.locator('button.dd-item.danger').click();
  await page.waitForURL('**/login.php', { timeout: 8000 }).catch(() => {});
  await wait(2000);

  // ─── Afsluiten ─────────────────────────────────────────────────────────────
  await context.close(); // triggert video-opslag
  await browser.close();

  // Bestandsnaam hernoemen naar iets herkenbaars
  const files = fs.readdirSync(outputDir).filter(f => f.endsWith('.webm'));
  if (files.length) {
    const latest = files
      .map(f => ({ f, t: fs.statSync(path.join(outputDir, f)).mtimeMs }))
      .sort((a, b) => b.t - a.t)[0].f;
    const stamp = new Date().toISOString().replace(/[:.]/g, '-').slice(0, 19);
    const out = path.join(outputDir, `dkg-demo-${stamp}.webm`);
    fs.renameSync(path.join(outputDir, latest), out);
    console.log('✅ Video opgeslagen:', out);
  } else {
    console.log('⚠ Geen video-bestand gevonden in', outputDir);
  }
})().catch((err) => {
  console.error('❌ Fout tijdens opname:', err);
  process.exit(1);
});
