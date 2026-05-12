import { chromium } from '/home/ivan/hosts/buddetal/buddet_v7/node_modules/playwright/index.mjs'

const SITE = 'https://artbeton.market'
const API = 'https://api.artbeton.market/api'
const SCREENSHOT_DIR = '/tmp/qa-screenshots'

async function sleep(ms) {
  return new Promise(resolve => setTimeout(resolve, ms))
}

async function run() {
  let browser
  try {
    browser = await chromium.connectOverCDP('http://localhost:9222')
  } catch {
    console.log('CDP not available, launching headless browser...')
    browser = await chromium.launch({ headless: true })
  }

  const context = browser.contexts()[0] || await browser.newContext({ viewport: { width: 1280, height: 720 } })
  const page = await context.newPage()

  let passed = 0
  let failed = 0

  function check(name, condition) {
    if (condition) {
      console.log(`  PASS: ${name}`)
      passed++
    } else {
      console.log(`  FAIL: ${name}`)
      failed++
    }
  }

  try {
    // ═══════════════════════════════════════════
    // TEST 1: Registration form (no password fields)
    // ═══════════════════════════════════════════
    console.log('\nTEST 1: Registration form layout')
    await page.goto(`${SITE}/uk/product`, { waitUntil: 'networkidle', timeout: 30000 })
    await sleep(2000)

    // Find and click auth trigger
    const authTriggers = ['button:has-text("Увійти")', 'button:has-text("Вхід")', 'a:has-text("Увійти")', '[aria-label*="user"]', '[aria-label*="login"]']
    for (const sel of authTriggers) {
      const el = page.locator(sel).first()
      if (await el.isVisible().catch(() => false)) {
        await el.click()
        await sleep(1500)
        break
      }
    }
    await page.screenshot({ path: `${SCREENSHOT_DIR}/01-auth-modal.png`, fullPage: false })

    // Find registration toggle
    const regTriggers = ['button:has-text("Зареєструватись")', 'button:has-text("Створити")', 'button:has-text("Register")', 'a:has-text("Зареєструватись")']
    for (const sel of regTriggers) {
      const el = page.locator(sel).first()
      if (await el.isVisible().catch(() => false)) {
        await el.click()
        await sleep(1000)
        break
      }
    }
    await page.screenshot({ path: `${SCREENSHOT_DIR}/02-registration-form.png`, fullPage: false })

    const passwordInputs = await page.locator('input[type="password"]').count()
    check('No password fields in registration', passwordInputs === 0)

    const emailOk = await page.locator('#email').isVisible().catch(() => false)
    const firstNameOk = await page.locator('#firstName').isVisible().catch(() => false)
    const lastNameOk = await page.locator('#lastName').isVisible().catch(() => false)
    const phoneOk = await page.locator('#phone').isVisible().catch(() => false)
    check('Email field visible', emailOk)
    check('First name field visible', firstNameOk)
    check('Last name field visible', lastNameOk)
    check('Phone field visible', phoneOk)

    // ═══════════════════════════════════════════
    // TEST 2: Submit registration
    // ═══════════════════════════════════════════
    console.log('\nTEST 2: Submit registration')
    const testEmail = `qa-browser-${Date.now()}@example.com`

    if (emailOk) {
      await page.locator('#email').fill(testEmail)
      await page.locator('#firstName').fill('QABrowser')
      await page.locator('#lastName').fill('Test')
      await page.locator('#phone').fill('+380991112233')
      await page.screenshot({ path: `${SCREENSHOT_DIR}/03-form-filled.png`, fullPage: false })

      // Click submit inside the registration form, not the search button
      await page.locator('[role="dialog"] button[type="submit"], form button[type="submit"]').last().click({ timeout: 10000 })
      await sleep(4000)
      await page.screenshot({ path: `${SCREENSHOT_DIR}/04-after-submit.png`, fullPage: false })

      // Check for success screen
      const successVisible = await page.locator('text=/перевірте.*пошту|check.*email/i').first().isVisible().catch(() => false)
      const successTitle = await page.locator('text=/перевірте/i').first().isVisible().catch(() => false)
      check('Success screen shown after registration', successVisible || successTitle)
    }

    // ═══════════════════════════════════════════
    // TEST 3: Confirm email - no token
    // ═══════════════════════════════════════════
    console.log('\nTEST 3: Confirm email page - no token')
    await page.goto(`${SITE}/uk/confirm-email`, { waitUntil: 'networkidle', timeout: 30000 })
    await sleep(2000)
    await page.screenshot({ path: `${SCREENSHOT_DIR}/05-confirm-no-token.png`, fullPage: false })

    const invalidMsg = await page.locator('text=/невірне.*посилання|invalid.*link/i').first().isVisible().catch(() => false)
    check('Invalid token message shown', invalidMsg)

    // ═══════════════════════════════════════════
    // TEST 4: Confirm email - with token (shows password form)
    // ═══════════════════════════════════════════
    console.log('\nTEST 4: Confirm email page - with fake token')
    await page.goto(`${SITE}/uk/confirm-email?token=fake-token-test`, { waitUntil: 'networkidle', timeout: 30000 })
    await sleep(2000)
    await page.screenshot({ path: `${SCREENSHOT_DIR}/06-confirm-with-token.png`, fullPage: false })

    const pwdVisible = await page.locator('#password').isVisible().catch(() => false)
    const pwdRepeatVisible = await page.locator('#passwordRepeat').isVisible().catch(() => false)
    check('Password field on confirm page', pwdVisible)
    check('Confirm password field on confirm page', pwdRepeatVisible)

    // Submit with fake token - should show error
    if (pwdVisible) {
      await page.locator('#password').fill('TestPassword123')
      await page.locator('#passwordRepeat').fill('TestPassword123')
      await page.locator('form button[type="submit"]').last().click({ timeout: 10000 })
      await sleep(3000)
      await page.screenshot({ path: `${SCREENSHOT_DIR}/07-confirm-error.png`, fullPage: false })

      const errorVisible = await page.locator('text=/invalid|expired|помилка|недійсне|закінчився/i').first().isVisible().catch(() => false)
      check('Error message on invalid token submit', errorVisible)
    }

    // ═══════════════════════════════════════════
    // TEST 5: Mobile viewport
    // ═══════════════════════════════════════════
    console.log('\nTEST 5: Mobile viewport registration')
    await page.setViewportSize({ width: 375, height: 812 })
    await page.goto(`${SITE}/uk/product`, { waitUntil: 'networkidle', timeout: 30000 })
    await sleep(2000)

    // Try mobile auth trigger
    for (const sel of authTriggers) {
      const el = page.locator(sel).first()
      if (await el.isVisible().catch(() => false)) {
        await el.click()
        await sleep(1500)
        break
      }
    }
    for (const sel of regTriggers) {
      const el = page.locator(sel).first()
      if (await el.isVisible().catch(() => false)) {
        await el.click()
        await sleep(1000)
        break
      }
    }
    await page.screenshot({ path: `${SCREENSHOT_DIR}/08-mobile-registration.png`, fullPage: true })

    const mobilePwdCount = await page.locator('input[type="password"]').count()
    check('No password fields on mobile', mobilePwdCount === 0)

    // ═══════════════════════════════════════════
    // SUMMARY
    // ═══════════════════════════════════════════
    console.log('\n═══════════════════════════════════════════')
    console.log(`QA RESULTS: ${passed} passed, ${failed} failed`)
    console.log('Screenshots: ' + SCREENSHOT_DIR)
    console.log('═══════════════════════════════════════════')

  } catch (err) {
    console.error('Test error:', err.message)
    await page.screenshot({ path: `${SCREENSHOT_DIR}/error.png`, fullPage: false }).catch(() => {})
  } finally {
    await page.close()
    if (!browser.contexts) await browser.close()
  }
}

run().catch(console.error)
