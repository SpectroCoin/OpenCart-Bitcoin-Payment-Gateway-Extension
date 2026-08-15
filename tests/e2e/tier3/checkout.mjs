/**
 * Drives a real shopper through OpenCart's checkout in a real browser.
 *
 * Tier 2 drives the extension's own confirm() over HTTP, which proves the
 * payload and the callback contract but never that a shopper can reach the
 * gateway in the first place. OpenCart 4's checkout is a single AJAX-driven
 * page (register -> shipping address -> shipping method -> payment method ->
 * confirm), each step gated behind the one before it, so "is SpectroCoin
 * offered" is only answerable by walking a shopper all the way there.
 *
 * Prints PASS/FAIL/INFO lines for the shell wrapper to count.
 */

import { chromium } from 'playwright';

const SHOP = process.env.SHOP_URL || 'http://shop.test';
const PRODUCT_URL = process.env.PRODUCT_URL;
const TITLE = process.env.EXT_TITLE || 'SpectroCoin';
const STOCK_TITLE = process.env.STOCK_TITLE || 'Cash On Delivery';

let failed = 0;
const pass = (m) => console.log(`PASS ${m}`);
const fail = (m) => { failed++; console.log(`FAIL ${m}`); };
const info = (m) => console.log(`INFO ${m}`);

const browser = await chromium.launch();
// The stub answers as spectrocoin.com with a certificate from a CA generated
// by the harness, which the browser has no reason to trust.
const ctx = await browser.newContext({ ignoreHTTPSErrors: true });
const page = await ctx.newPage();
page.setDefaultTimeout(30000);

const shot = async (name) => {
  try { await page.screenshot({ path: `/work/artifacts/${name}.png`, fullPage: true }); } catch {}
};

// Fills the first matching selector that exists; a no-op if none do, so the
// same helper works whether or not a field is present on this run.
const fill = async (sel, value) => {
  const el = page.locator(sel).first();
  if (await el.count()) { await el.fill(value).catch(() => {}); return true; }
  return false;
};

try {
  // ---- add to cart ------------------------------------------------------
  await page.goto(PRODUCT_URL, { waitUntil: 'domcontentloaded' });
  const addToCart = page.getByRole('button', { name: 'Add to Cart' }).first();
  if (await addToCart.count()) {
    await addToCart.click();
    await page.waitForTimeout(1500);
    pass('product can be added to the cart');
  } else {
    fail('no add-to-cart button on the product page');
    await shot('product');
  }

  // ---- checkout: choose guest -------------------------------------------
  await page.goto(`${SHOP}/index.php?route=checkout/checkout`, { waitUntil: 'domcontentloaded' });
  await page.waitForTimeout(1500);

  // OpenCart defaults this radio group to "Register"; guest has to be picked
  // explicitly or the form demands an account password.
  const guestRadio = page.locator('#input-guest');
  if (await guestRadio.count()) {
    await guestRadio.click();
    pass('guest checkout can be selected');
  } else {
    fail('no guest-checkout option on the checkout page');
    await shot('checkout-start');
  }

  await fill('#input-firstname', 'Tier');
  await fill('#input-lastname', 'Three');
  await fill('#input-email', `tier3-${Date.now()}@example.com`);
  await fill('#input-shipping-address-1', '1 Test Street');
  await fill('#input-shipping-city', 'Aberdeen');
  await fill('#input-shipping-postcode', 'AB101AB');
  const zoneSelect = page.locator('#input-shipping-zone');
  if (await zoneSelect.count()) {
    await zoneSelect.selectOption({ index: 1 });
  }

  const registerButton = page.locator('#button-register');
  if (await registerButton.count()) {
    await registerButton.click();
    await page.waitForTimeout(2500);
    pass('shopper details were accepted');
  } else {
    fail('no continue button on the shopper-details step');
    await shot('checkout-register');
  }

  // ---- shipping method ----------------------------------------------------
  const chooseShipping = page.locator('#button-shipping-methods');
  if (await chooseShipping.count()) {
    await chooseShipping.click();
    await page.waitForSelector('#form-shipping-method', { timeout: 15000 }).catch(() => {});
    const shippingRadio = page.locator('#form-shipping-method input[type="radio"]').first();
    if (await shippingRadio.count()) {
      await shippingRadio.check().catch(() => {});
      await page.locator('#button-shipping-method').click();
      await page.waitForTimeout(2500);
      pass('a shipping method can be chosen');
    } else {
      fail('no shipping method was offered - the shop cannot ship the order');
      await shot('shipping-method-empty');
    }
  } else {
    fail('no "choose shipping method" control on the checkout page');
    await shot('shipping-method-missing');
  }

  // ---- payment method: the assertion this tier exists for -----------------
  const choosePayment = page.locator('#button-payment-methods');
  if (!(await choosePayment.count())) {
    fail('no "choose payment method" control on the checkout page');
    await shot('payment-method-missing');
  } else {
    await choosePayment.click();
    await page.waitForSelector('#form-payment-method', { timeout: 15000 }).catch(() => {});

    // Decisive control: if the payment step is empty for every method, that
    // is a shop/fixture problem, not evidence against our extension. A stock
    // OpenCart method has to show up too, or "ours is missing" tells us
    // nothing.
    const stockOffered = await page.locator('#form-payment-method').getByText(STOCK_TITLE, { exact: false }).count();
    if (stockOffered > 0) {
      pass(`a stock payment method (${STOCK_TITLE}) is offered too (control for an empty payment step)`);
    } else {
      fail(`no stock payment method is offered, not even ${STOCK_TITLE} - this is a fixture problem, not the extension`);
      await shot('payment-method-no-control');
    }

    const offered = await page.locator('#form-payment-method').getByText(TITLE, { exact: false }).count();
    if (offered > 0) {
      pass('the extension is offered at checkout');
    } else {
      fail('the extension is NOT offered at checkout');
      await shot('payment-method-no-spectrocoin');
    }

    const option = page.locator('#form-payment-method label', { hasText: TITLE }).first();
    if (await option.count()) {
      await option.click().catch(() => {});
      pass('the extension can be selected');
    } else {
      fail('the extension could not be selected');
    }

    await page.locator('#button-payment-method').click();
    await page.waitForTimeout(2500);
  }

  // ---- confirm order --------------------------------------------------
  await page.waitForTimeout(1500);
  const confirmButton = page.locator('#checkout-payment').getByText('Confirm Order', { exact: false }).first();
  if (!(await confirmButton.count())) {
    fail('no Confirm Order button once SpectroCoin is selected');
    await shot('confirm-missing');
  } else {
    await Promise.all([
      page.waitForURL(/spectrocoin\.com\/pay\//, { timeout: 45000 }).catch(() => {}),
      confirmButton.click(),
    ]);
    await page.waitForTimeout(3000);

    const url = page.url();
    info(`landed on: ${url}`);
    if (/spectrocoin\.com\/pay\//.test(url)) {
      pass('confirming the order redirects the shopper to SpectroCoin');
    } else {
      fail(`confirming the order did not redirect to SpectroCoin (landed on ${url})`);
      await shot('after-confirm');
    }
  }
} catch (err) {
  fail(`browser run threw: ${err.message.split('\n')[0]}`);
  await shot('threw');
} finally {
  await browser.close();
}

process.exit(failed === 0 ? 0 : 1);
