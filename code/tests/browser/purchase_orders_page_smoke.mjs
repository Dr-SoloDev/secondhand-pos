#!/usr/bin/env node
import { spawn } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import os from 'node:os';

const BASE_URL = process.env.UI_BASE || 'http://127.0.0.1:8080';
const CHROME_BIN = process.env.CHROME_BIN || 'google-chrome';
const TEST_USER = process.env.TEST_USER || 'admin';
const TEST_PASS = process.env.TEST_PASS || 'admin';
const DEBUG_PORT = Number(process.env.CDP_PORT || 9223);
const SHOT_DIR = process.env.SCREENSHOT_DIR || '/tmp/scrap-pos-ui';

const delay = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

class CdpClient {
  constructor(wsUrl) {
    this.wsUrl = wsUrl;
    this.seq = 0;
    this.pending = new Map();
    this.listeners = new Map();
  }

  async connect() {
    this.ws = new WebSocket(this.wsUrl);
    await new Promise((resolve, reject) => {
      const timer = setTimeout(() => reject(new Error('CDP WebSocket open timeout')), 10000);
      this.ws.addEventListener('open', () => {
        clearTimeout(timer);
        resolve();
      }, { once: true });
      this.ws.addEventListener('error', (event) => {
        clearTimeout(timer);
        reject(new Error(`CDP WebSocket error: ${event.message || 'unknown'}`));
      }, { once: true });
    });

    this.ws.addEventListener('message', (event) => {
      const raw = typeof event.data === 'string' ? event.data : Buffer.from(event.data).toString('utf8');
      const msg = JSON.parse(raw);
      if (msg.id && this.pending.has(msg.id)) {
        const { resolve, reject } = this.pending.get(msg.id);
        this.pending.delete(msg.id);
        if (msg.error) reject(new Error(`${msg.error.message}: ${msg.error.data || ''}`));
        else resolve(msg.result || {});
        return;
      }
      const callbacks = this.listeners.get(msg.method) || [];
      for (const cb of callbacks) cb(msg.params || {});
    });
  }

  on(method, cb) {
    const callbacks = this.listeners.get(method) || [];
    callbacks.push(cb);
    this.listeners.set(method, callbacks);
  }

  waitFor(method, predicate = () => true, timeoutMs = 15000) {
    return new Promise((resolve, reject) => {
      const timer = setTimeout(() => reject(new Error(`Timeout waiting for ${method}`)), timeoutMs);
      const cb = (params) => {
        if (!predicate(params)) return;
        clearTimeout(timer);
        const callbacks = this.listeners.get(method) || [];
        this.listeners.set(method, callbacks.filter((item) => item !== cb));
        resolve(params);
      };
      this.on(method, cb);
    });
  }

  send(method, params = {}) {
    const id = ++this.seq;
    this.ws.send(JSON.stringify({ id, method, params }));
    return new Promise((resolve, reject) => {
      this.pending.set(id, { resolve, reject });
      setTimeout(() => {
        if (!this.pending.has(id)) return;
        this.pending.delete(id);
        reject(new Error(`CDP command timeout: ${method}`));
      }, 30000);
    });
  }

  close() {
    this.ws?.close();
  }
}

async function waitForJson(url, timeoutMs = 10000) {
  const started = Date.now();
  let lastError;
  while (Date.now() - started < timeoutMs) {
    try {
      const res = await fetch(url);
      if (res.ok) return await res.json();
      lastError = new Error(`HTTP ${res.status}`);
    } catch (error) {
      lastError = error;
    }
    await delay(150);
  }
  throw lastError || new Error(`Timeout waiting for ${url}`);
}

async function launchChrome() {
  fs.mkdirSync(SHOT_DIR, { recursive: true });
  const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'scrap-pos-chrome-'));
  const chrome = spawn(CHROME_BIN, [
    '--headless=new',
    '--disable-gpu',
    '--no-sandbox',
    '--disable-dev-shm-usage',
    '--disable-background-networking',
    '--disable-sync',
    '--no-first-run',
    `--remote-debugging-port=${DEBUG_PORT}`,
    `--user-data-dir=${profile}`,
    'about:blank',
  ], {
    stdio: ['ignore', 'ignore', 'pipe'],
  });

  let stderr = '';
  chrome.stderr.on('data', (chunk) => { stderr += chunk.toString(); });

  const list = await waitForJson(`http://127.0.0.1:${DEBUG_PORT}/json/list`);
  const page = list.find((target) => target.type === 'page');
  if (!page?.webSocketDebuggerUrl) {
    throw new Error(`No debuggable Chrome page found. stderr: ${stderr.slice(-1000)}`);
  }

  return { chrome, profile, wsUrl: page.webSocketDebuggerUrl };
}

async function evaluate(cdp, expression) {
  const result = await cdp.send('Runtime.evaluate', {
    expression,
    awaitPromise: true,
    returnByValue: true,
    userGesture: true,
  });
  if (result.exceptionDetails) {
    throw new Error(result.exceptionDetails.text || 'Runtime evaluation failed');
  }
  return result.result?.value;
}

async function waitForExpression(cdp, expression, timeoutMs = 15000) {
  const started = Date.now();
  while (Date.now() - started < timeoutMs) {
    const ok = await evaluate(cdp, expression).catch(() => false);
    if (ok) return true;
    await delay(150);
  }
  throw new Error(`Timeout waiting for expression: ${expression}`);
}

async function navigate(cdp, url) {
  const loaded = cdp.waitFor('Page.loadEventFired', () => true, 20000);
  await cdp.send('Page.navigate', { url });
  await loaded;
}

async function screenshot(cdp, label) {
  const png = await cdp.send('Page.captureScreenshot', { format: 'png', fromSurface: true });
  const file = path.join(SHOT_DIR, `${label}.png`);
  fs.writeFileSync(file, Buffer.from(png.data, 'base64'));
  return file;
}

async function collectSnapshot(cdp, label) {
  return evaluate(cdp, `(() => {
    const visible = (el) => {
      const style = getComputedStyle(el);
      const rect = el.getBoundingClientRect();
      return style.display !== 'none' && style.visibility !== 'hidden' && rect.width > 0 && rect.height > 0;
    };
    const text = (sel) => document.querySelector(sel)?.textContent.trim().replace(/\\s+/g, ' ') || '';
    const byTextOverflow = Array.from(document.querySelectorAll('button,label,h1,h2,h3,.global-tier-btn,.auto-price-display,.sidebar-menu span,td,th'))
      .filter(visible)
      .filter((el) => el.scrollWidth > el.clientWidth + 2 || el.scrollHeight > el.clientHeight + 2)
      .slice(0, 30)
      .map((el) => ({
        selector: el.id ? '#' + el.id : (el.className ? String(el.className).split(/\\s+/).filter(Boolean).map(c => '.' + c).join('') : el.tagName.toLowerCase()),
        text: el.textContent.trim().replace(/\\s+/g, ' ').slice(0, 120),
        client: [el.clientWidth, el.clientHeight],
        scroll: [el.scrollWidth, el.scrollHeight],
      }));
    const doc = document.documentElement;
    const body = document.body;
    const page = document.querySelector('.po-page');
    const recentRows = Array.from(document.querySelectorAll('#recentPOTable tbody tr')).map((tr) => tr.textContent.trim().replace(/\\s+/g, ' '));
    return {
      label: ${JSON.stringify(label)},
      url: location.href,
      title: document.title,
      userName: text('.user-name'),
      activeSidebar: text('.sidebar-menu a.active'),
      header: { h1: text('.po-page-header h1'), p: text('.po-page-header p') },
      branchOptions: Array.from(document.querySelectorAll('#branchSelect option')).map(o => o.textContent.trim()),
      tierButtons: Array.from(document.querySelectorAll('#globalTierButtons .global-tier-btn')).map(b => ({
        text: b.textContent.trim().replace(/\\s+/g, ' '),
        active: b.classList.contains('active'),
        level: b.dataset.level
      })),
      selectedSeller: text('#selectedSellerBox'),
      cartText: text('#cartTable tbody'),
      recentRows: recentRows.slice(0, 5),
      recentRowCount: recentRows.length,
      thermalPreviewPresent: !!document.querySelector('#thermalPreviewModal #thermalPreviewImage'),
      hiddenQrSection: document.querySelector('#poQrSection') ? getComputedStyle(document.querySelector('#poQrSection')).display === 'none' : null,
      fabVisible: document.querySelector('#fabCameraBtn') ? visible(document.querySelector('#fabCameraBtn')) : false,
      overflow: {
        document: doc.scrollWidth > doc.clientWidth + 2 ? [doc.clientWidth, doc.scrollWidth] : null,
        body: body.scrollWidth > body.clientWidth + 2 ? [body.clientWidth, body.scrollWidth] : null,
        poPage: page && page.scrollWidth > page.clientWidth + 2 ? [page.clientWidth, page.scrollWidth] : null,
        text: byTextOverflow
      }
    };
  })()`);
}

async function run() {
  const browser = await launchChrome();
  const cdp = new CdpClient(browser.wsUrl);
  const issues = [];
  const networkErrors = [];
  const consoleErrors = [];
  const dialogs = [];
  const screenshots = [];
  const snapshots = [];

  try {
    await cdp.connect();
    await cdp.send('Page.enable');
    await cdp.send('Runtime.enable');
    await cdp.send('Network.enable');
    await cdp.send('Log.enable');

    cdp.on('Runtime.exceptionThrown', (params) => {
      consoleErrors.push({ type: 'exception', text: params.exceptionDetails?.text || '', url: params.exceptionDetails?.url || '' });
    });
    cdp.on('Runtime.consoleAPICalled', (params) => {
      if (!['error', 'warning', 'assert'].includes(params.type)) return;
      consoleErrors.push({
        type: params.type,
        text: (params.args || []).map((arg) => arg.value || arg.description || '').join(' ').slice(0, 300),
      });
    });
    cdp.on('Network.responseReceived', (params) => {
      const response = params.response || {};
      if (response.status >= 400) {
        networkErrors.push({ status: response.status, type: params.type, url: response.url });
      }
    });
    cdp.on('Network.loadingFailed', (params) => {
      if (params.canceled) return;
      networkErrors.push({ status: 'failed', type: params.type, url: params.requestId, errorText: params.errorText });
    });
    cdp.on('Page.javascriptDialogOpening', (params) => {
      dialogs.push({ type: params.type, message: params.message });
      cdp.send('Page.handleJavaScriptDialog', { accept: false }).catch(() => {});
    });

    await cdp.send('Emulation.setDeviceMetricsOverride', {
      width: 1366,
      height: 768,
      deviceScaleFactor: 1,
      mobile: false,
    });

    await navigate(cdp, `${BASE_URL}/index.html`);
    const login = await evaluate(cdp, `(async () => {
      const res = await fetch('/api/index.php/auth/login', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ username: ${JSON.stringify(TEST_USER)}, password: ${JSON.stringify(TEST_PASS)} })
      });
      const json = await res.json();
      if (json.status === 'success') {
        localStorage.setItem('posUser', JSON.stringify(json.data.user));
      }
      return { status: json.status, message: json.message, user: json.data?.user || null };
    })()`);
    if (login.status !== 'success') throw new Error(`Login failed: ${login.message}`);

    await navigate(cdp, `${BASE_URL}/admin/purchase-orders.html`);
    await waitForExpression(cdp, `(() => {
      return document.querySelectorAll('#branchSelect option').length > 0
        && document.querySelectorAll('#globalTierButtons .global-tier-btn').length === 3
        && document.querySelectorAll('#recentPOTable tbody tr').length > 0
        && !!document.querySelector('#itemName');
    })()`);
    await delay(500);

    snapshots.push(await collectSnapshot(cdp, 'desktop-initial'));
    screenshots.push(await screenshot(cdp, 'purchase-orders-desktop-initial'));

    const testData = await evaluate(cdp, `(async () => {
      const [sellerRes, catalogRes] = await Promise.all([
        fetch('/api/index.php/sellers', { credentials: 'same-origin' }).then(r => r.json()),
        fetch('/api/index.php/purchase-catalog/search?q=' + encodeURIComponent('เหล็ก'), { credentials: 'same-origin' }).then(r => r.json())
      ]);
      const sellers = sellerRes.data || [];
      const seller = sellers.find(s => s.full_name && !s.full_name.includes('โอนสต็อก')) || sellers[0] || null;
      const catalogItems = catalogRes.data || [];
      const item = catalogItems.find(it => it.code) || catalogItems[0] || null;
      return {
        sellerName: seller?.full_name || '',
        sellerQuery: seller?.phone || seller?.id_card || (seller?.full_name || '').slice(0, 4),
        itemName: item?.name || '',
        itemQuery: item?.code || item?.name || ''
      };
    })()`);
    if (!testData.sellerQuery || !testData.itemQuery) {
      throw new Error(`Missing UI test seed data: ${JSON.stringify(testData)}`);
    }

    await evaluate(cdp, `(() => {
      const el = document.querySelector('#searchSellerInput');
      el.value = ${JSON.stringify(testData.sellerQuery)};
      el.dispatchEvent(new Event('input', { bubbles: true }));
      return true;
    })()`);
    await waitForExpression(cdp, `document.querySelectorAll('#sellerSearchResults .seller-item').length > 0`);
    const sellerSearch = await evaluate(cdp, `Array.from(document.querySelectorAll('#sellerSearchResults .seller-item')).map(el => el.textContent.trim().replace(/\\s+/g, ' '))`);
    await evaluate(cdp, `document.querySelector('#sellerSearchResults .seller-item')?.click()`);
    await waitForExpression(cdp, `document.querySelector('#selectedSellerBox')?.textContent.includes(${JSON.stringify(testData.sellerName)})`);

    await evaluate(cdp, `(() => {
      const el = document.querySelector('#itemName');
      el.value = ${JSON.stringify(testData.itemQuery)};
      el.dispatchEvent(new Event('input', { bubbles: true }));
      return true;
    })()`);
    await waitForExpression(cdp, `document.querySelector('#itemCatalogId')?.value !== '' && document.querySelector('#itemUnitPrice')?.value !== '0.00'`);
    const itemSelected = await evaluate(cdp, `({
      name: document.querySelector('#itemName')?.value,
      catalogId: document.querySelector('#itemCatalogId')?.value,
      unitPrice: document.querySelector('#itemUnitPrice')?.value,
      activeElement: document.activeElement?.id || ''
    })`);
    await evaluate(cdp, `(() => {
      const qty = document.querySelector('#itemQuantity');
      const deduct = document.querySelector('#itemWeightDeduct');
      qty.value = '12.50';
      qty.dispatchEvent(new Event('input', { bubbles: true }));
      deduct.value = '0.50';
      deduct.dispatchEvent(new Event('input', { bubbles: true }));
      document.querySelector('#addItemBtn').click();
      return true;
    })()`);
    await waitForExpression(cdp, `document.querySelector('#cartTable tbody')?.textContent.includes(${JSON.stringify(testData.itemName)})`);
    await delay(250);

    snapshots.push(await collectSnapshot(cdp, 'desktop-after-cart'));
    screenshots.push(await screenshot(cdp, 'purchase-orders-desktop-after-cart'));

    await evaluate(cdp, `document.querySelector('#recentPOTable tbody a')?.click()`);
    await waitForExpression(cdp, `document.querySelector('#viewPOModal')?.classList.contains('show') && document.querySelector('#btnOpenPrintThermal')`);
    await delay(1200);
    const receiptModal = await evaluate(cdp, `(() => {
      const imgCount = document.querySelectorAll('#poPhotoGrid img').length;
      const qr = document.querySelector('#poQrSection');
      const wrap = document.querySelector('#billPrintWrap');
      return {
        visible: document.querySelector('#viewPOModal')?.classList.contains('show') || false,
        contentHasShopName: wrap?.textContent.includes('รักษ์สะอาด') || false,
        contentHasFooter: wrap?.textContent.includes('ขอบคุณ') || false,
        qrDisplay: qr ? getComputedStyle(qr).display : null,
        photoCount: imgCount,
        a4Button: document.querySelector('#btnOpenPrint')?.textContent.trim() || '',
        thermalButton: document.querySelector('#btnOpenPrintThermal')?.textContent.trim() || ''
      };
    })()`);
    screenshots.push(await screenshot(cdp, 'purchase-orders-receipt-modal'));

    await evaluate(cdp, `document.querySelector('#btnOpenPrintThermal')?.click()`);
    const thermalResult = await waitForExpression(cdp, `(() => {
      const modal = document.querySelector('#thermalPreviewModal');
      const error = Array.from(document.querySelectorAll('#notification-container .notification')).map(n => n.textContent).join(' ');
      return modal?.classList.contains('show') || error.includes('ตัวอย่าง') || error.includes('Print Server') || error.includes('ไม่สำเร็จ');
    })()`, 30000).then(() => evaluate(cdp, `(() => {
      const modal = document.querySelector('#thermalPreviewModal');
      const img = document.querySelector('#thermalPreviewImage');
      const notifications = Array.from(document.querySelectorAll('#notification-container .notification')).map(n => n.textContent.trim().replace(/\\s+/g, ' '));
      return {
        modalVisible: modal?.classList.contains('show') || false,
        imageLoaded: !!img?.complete && img.naturalWidth > 0,
        imageSize: img ? [img.naturalWidth, img.naturalHeight] : null,
        confirmDisabled: document.querySelector('#thermalPreviewConfirm')?.disabled || false,
        notifications
      };
    })()`));
    screenshots.push(await screenshot(cdp, 'purchase-orders-thermal-preview'));

    await evaluate(cdp, `(() => {
      document.querySelector('#thermalPreviewModal')?.classList.remove('show');
      document.querySelector('#viewPOModal')?.classList.remove('show');
      sessionStorage.removeItem('cart_backup');
      sessionStorage.removeItem('cart_backup_time');
      return true;
    })()`);
    await cdp.send('Emulation.setDeviceMetricsOverride', {
      width: 390,
      height: 844,
      deviceScaleFactor: 2,
      mobile: true,
    });
    await navigate(cdp, `${BASE_URL}/admin/purchase-orders.html?smoke_mobile=${Date.now()}`);
    try {
      await waitForExpression(cdp, `document.querySelectorAll('#branchSelect option').length > 0 && document.querySelectorAll('#globalTierButtons .global-tier-btn').length === 3 && !!document.querySelector('#searchSellerInput')`);
    } catch (error) {
      const mobileDiag = await evaluate(cdp, `(() => ({
        href: location.href,
        title: document.title,
        bodyText: document.body?.textContent.trim().replace(/\\s+/g, ' ').slice(0, 300) || '',
        hasPosUser: !!localStorage.getItem('posUser'),
        branchOptions: document.querySelectorAll('#branchSelect option').length,
        tierButtons: document.querySelectorAll('#globalTierButtons .global-tier-btn').length,
        hasSearchInput: !!document.querySelector('#searchSellerInput'),
        notifications: Array.from(document.querySelectorAll('#notification-container .notification')).map(n => n.textContent.trim().replace(/\\s+/g, ' '))
      }))()`).catch((diagError) => ({ diagnosticError: diagError.message }));
      throw new Error(`${error.message}; mobileDiag=${JSON.stringify(mobileDiag)}`);
    }
    await delay(700);
    snapshots.push(await collectSnapshot(cdp, 'mobile-initial'));
    screenshots.push(await screenshot(cdp, 'purchase-orders-mobile-initial'));

    const activeTierCount = snapshots[0].tierButtons.filter((button) => button.active).length;
    if (activeTierCount !== 1) issues.push(`Expected exactly one active tier button, got ${activeTierCount}`);
    if (!snapshots[0].branchOptions.length) issues.push('Branch dropdown is empty');
    if (!snapshots[0].recentRowCount) issues.push('Recent purchase orders table is empty or did not load');
    if (snapshots[0].activeSidebar !== 'รับซื้อของ') issues.push(`Wrong active sidebar: ${snapshots[0].activeSidebar}`);
    if (snapshots.some((snapshot) => snapshot.fabVisible)) issues.push('Deprecated photo FAB is visible and can overlap purchase inputs');
    if (!sellerSearch.some((text) => text.includes(testData.sellerName))) issues.push(`Seller search did not return ${testData.sellerName}: ${sellerSearch.join(' | ')}`);
    if (!itemSelected.catalogId || itemSelected.unitPrice === '0.00') issues.push(`Catalog item did not auto-select/prices did not fill: ${JSON.stringify(itemSelected)}`);
    if (!receiptModal.visible || !receiptModal.contentHasShopName) issues.push(`Receipt modal did not show expected shop content: ${JSON.stringify(receiptModal)}`);
    if (!thermalResult.modalVisible || !thermalResult.imageLoaded) issues.push(`Thermal preview did not load image: ${JSON.stringify(thermalResult)}`);

    const relevantNetworkErrors = networkErrors.filter((entry) => !String(entry.url).includes('favicon'));
    const relevantConsoleErrors = consoleErrors.filter((entry) => !String(entry.text).includes('[Violation]'));
    if (relevantNetworkErrors.length) issues.push(`Network errors found: ${JSON.stringify(relevantNetworkErrors.slice(0, 8))}`);
    if (relevantConsoleErrors.length) issues.push(`Console errors/warnings found: ${JSON.stringify(relevantConsoleErrors.slice(0, 8))}`);

    console.log(JSON.stringify({
      status: issues.length ? 'fail' : 'success',
      baseUrl: BASE_URL,
      screenshots,
      testData,
      sellerSearch,
      itemSelected,
      receiptModal,
      thermalResult,
      snapshots,
      dialogs,
      networkErrors: relevantNetworkErrors,
      consoleErrors: relevantConsoleErrors,
      issues,
    }, null, 2));

    process.exitCode = issues.length ? 1 : 0;
  } finally {
    cdp.close();
    await new Promise((resolve) => {
      if (!browser.chrome || browser.chrome.killed) {
        resolve();
        return;
      }
      const timer = setTimeout(resolve, 3000);
      browser.chrome.once('close', () => {
        clearTimeout(timer);
        resolve();
      });
      browser.chrome.kill('SIGTERM');
    });
    fs.rmSync(browser.profile, { recursive: true, force: true, maxRetries: 5 });
  }
}

run().catch((error) => {
  console.error(JSON.stringify({
    status: 'error',
    message: error.message,
    stack: error.stack,
  }, null, 2));
  process.exit(1);
});
