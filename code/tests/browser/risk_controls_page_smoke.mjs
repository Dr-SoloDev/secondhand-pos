#!/usr/bin/env node
import { spawn } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';

const BASE_URL = process.env.UI_BASE || 'http://127.0.0.1:8080';
const CHROME_BIN = process.env.CHROME_BIN || 'google-chrome';
const ADMIN_PASS = process.env.TEST_PASS || 'admin';
const DEBUG_PORT = Number(process.env.CDP_PORT || 9224);
const SHOT_DIR = process.env.SCREENSHOT_DIR || '/tmp/scrap-pos-risk-ui';
const delay = ms => new Promise(resolve => setTimeout(resolve, ms));

class CdpClient {
  constructor(url) { this.url = url; this.id = 0; this.pending = new Map(); this.listeners = new Map(); }
  async connect() {
    this.ws = new WebSocket(this.url);
    await new Promise((resolve, reject) => {
      const timer = setTimeout(() => reject(new Error('CDP connection timeout')), 10000);
      this.ws.addEventListener('open', () => { clearTimeout(timer); resolve(); }, { once: true });
      this.ws.addEventListener('error', () => reject(new Error('CDP connection failed')), { once: true });
    });
    this.ws.addEventListener('message', event => {
      const message = JSON.parse(typeof event.data === 'string' ? event.data : Buffer.from(event.data).toString('utf8'));
      if (message.id && this.pending.has(message.id)) {
        const pending = this.pending.get(message.id);
        this.pending.delete(message.id);
        message.error ? pending.reject(new Error(message.error.message)) : pending.resolve(message.result || {});
      } else {
        for (const callback of this.listeners.get(message.method) || []) callback(message.params || {});
      }
    });
  }
  on(method, callback) { this.listeners.set(method, [...(this.listeners.get(method) || []), callback]); }
  send(method, params = {}) {
    const id = ++this.id;
    this.ws.send(JSON.stringify({ id, method, params }));
    return new Promise((resolve, reject) => {
      this.pending.set(id, { resolve, reject });
      setTimeout(() => { if (this.pending.delete(id)) reject(new Error(`CDP timeout: ${method}`)); }, 30000);
    });
  }
  close() { this.ws?.close(); }
}

async function waitForJson(url) {
  for (let attempt = 0; attempt < 80; attempt += 1) {
    try { const response = await fetch(url); if (response.ok) return response.json(); } catch (_) {}
    await delay(125);
  }
  throw new Error(`Chrome debug endpoint unavailable: ${url}`);
}

async function launchChrome() {
  fs.mkdirSync(SHOT_DIR, { recursive: true });
  const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'scrap-pos-risk-'));
  const process = spawn(CHROME_BIN, [
    '--headless=new', '--disable-gpu', '--no-sandbox', '--disable-dev-shm-usage',
    '--disable-background-networking', '--no-first-run', `--remote-debugging-port=${DEBUG_PORT}`,
    `--user-data-dir=${profile}`, 'about:blank',
  ], { stdio: ['ignore', 'ignore', 'ignore'] });
  const targets = await waitForJson(`http://127.0.0.1:${DEBUG_PORT}/json/list`);
  const page = targets.find(target => target.type === 'page');
  if (!page?.webSocketDebuggerUrl) throw new Error('No Chrome page target');
  return { process, profile, wsUrl: page.webSocketDebuggerUrl };
}

async function evaluate(cdp, expression) {
  const response = await cdp.send('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true, userGesture: true });
  if (response.exceptionDetails) throw new Error(response.exceptionDetails.text || 'Browser evaluation failed');
  return response.result?.value;
}

async function waitFor(cdp, expression, timeout = 15000) {
  const started = Date.now();
  while (Date.now() - started < timeout) {
    if (await evaluate(cdp, expression).catch(() => false)) return;
    await delay(150);
  }
  throw new Error(`Timeout waiting for ${expression}`);
}

async function navigate(cdp, url) {
  await cdp.send('Page.navigate', { url });
  await waitFor(cdp, 'document.readyState === "complete"', 20000);
}

async function login(cdp, username) {
  await navigate(cdp, `${BASE_URL}/index.html`);
  const result = await evaluate(cdp, `(async () => {
    const response = await fetch('/api/index.php/auth/login', { method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json'}, body:JSON.stringify({username:${JSON.stringify(username)},password:${JSON.stringify(ADMIN_PASS)}}) });
    const json = await response.json();
    if (json.status === 'success') localStorage.setItem('posUser', JSON.stringify(json.data.user));
    return json;
  })()`);
  if (result.status !== 'success') throw new Error(`Login failed for ${username}: ${result.message}`);
}

async function capture(cdp, name) {
  const result = await cdp.send('Page.captureScreenshot', { format: 'png', fromSurface: true });
  const file = path.join(SHOT_DIR, `${name}.png`);
  fs.writeFileSync(file, Buffer.from(result.data, 'base64'));
  return file;
}

async function pageSnapshot(cdp, page) {
  return evaluate(cdp, `(() => {
    const visible = el => { if (!el) return false; const s=getComputedStyle(el),r=el.getBoundingClientRect(); return s.display!=='none'&&s.visibility!=='hidden'&&r.width>0&&r.height>0; };
    const overflowText = Array.from(document.querySelectorAll('button,label,h1,h2,h3,th,td,.badge'))
      .filter(visible).filter(el => el.scrollWidth > el.clientWidth + 3 || el.scrollHeight > el.clientHeight + 3)
      .slice(0,20).map(el => ({ text:el.textContent.trim().replace(/\\s+/g,' ').slice(0,80), client:[el.clientWidth,el.clientHeight], scroll:[el.scrollWidth,el.scrollHeight] }));
    const reversalReceive = Array.from(document.querySelectorAll('#transfersBody tr')).filter(row => row.textContent.includes('โอนย้อนกลับ') && row.textContent.includes('ตรวจรับ')).length;
    return {
      page:${JSON.stringify(page)}, href:location.href, title:document.title,
      bodyOverflow:document.body.scrollWidth > document.documentElement.clientWidth + 3 ? [document.documentElement.clientWidth,document.body.scrollWidth] : null,
      overflowText,
      user:document.querySelector('.user-name')?.textContent.trim() || '',
      adjustmentVisible:visible(document.querySelector('#adjustmentSection')),
      expenseBeneficiary:!!document.querySelector('#expBeneficiary'),
      expenseReviewModal:!!document.querySelector('#expenseReviewModal'),
      poCancellationQueueVisible:visible(document.querySelector('#poCancellationQueue')),
      poCancellationModal:!!document.querySelector('#poCancellationModal'),
      reversalRequestModal:!!document.querySelector('#reversalRequestModal'),
      reversalReviewModal:!!document.querySelector('#reversalReviewModal'),
      reversalReceive,
      revenuePaymentMethod:!!document.querySelector('#revenuePaymentMethod'),
      tableRows:Array.from(document.querySelectorAll('tbody')).reduce((sum,tbody)=>sum+tbody.querySelectorAll('tr').length,0),
    };
  })()`);
}

async function run() {
  const browser = await launchChrome();
  const cdp = new CdpClient(browser.wsUrl);
  const issues = [];
  const screenshots = [];
  const snapshots = [];
  const consoleErrors = [];
  const networkErrors = [];
  try {
    await cdp.connect();
    await Promise.all([cdp.send('Page.enable'), cdp.send('Runtime.enable'), cdp.send('Network.enable')]);
    cdp.on('Runtime.exceptionThrown', event => consoleErrors.push(event.exceptionDetails?.text || 'runtime exception'));
    cdp.on('Runtime.consoleAPICalled', event => {
      if (['error', 'assert'].includes(event.type)) consoleErrors.push((event.args || []).map(arg => arg.value || arg.description || '').join(' '));
    });
    cdp.on('Network.responseReceived', event => {
      if (event.response?.status >= 400) networkErrors.push({ status:event.response.status, url:event.response.url });
    });

    await cdp.send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 900, deviceScaleFactor: 1, mobile: false });
    await login(cdp, 'admin');
    const pages = [
      ['cash-sessions', '#cashStatusBand'], ['expenses', '#expenseBody'], ['purchase-orders', '#recentPOTable tbody'],
      ['stock-transfers', '#transfersBody'], ['sale-lots', '#lotTableBody'],
    ];
    for (const [page, selector] of pages) {
      await navigate(cdp, `${BASE_URL}/admin/${page}.html?risk_smoke=${Date.now()}`);
      await waitFor(cdp, `document.querySelector(${JSON.stringify(selector)}) && document.querySelector(${JSON.stringify(selector)}).textContent.trim().length > 0`);
      await delay(500);
      snapshots.push(await pageSnapshot(cdp, `admin-desktop-${page}`));
      screenshots.push(await capture(cdp, `admin-desktop-${page}`));
    }

    const adminCash = snapshots.find(item => item.page === 'admin-desktop-cash-sessions');
    const adminExpense = snapshots.find(item => item.page === 'admin-desktop-expenses');
    const adminPO = snapshots.find(item => item.page === 'admin-desktop-purchase-orders');
    const adminTransfer = snapshots.find(item => item.page === 'admin-desktop-stock-transfers');
    const adminLot = snapshots.find(item => item.page === 'admin-desktop-sale-lots');
    if (!adminCash?.adjustmentVisible) issues.push('Admin adjustment section is not visible');
    if (!adminExpense?.expenseBeneficiary || !adminExpense?.expenseReviewModal) issues.push('Expense workflow controls are missing');
    if (!adminPO?.poCancellationQueueVisible || !adminPO?.poCancellationModal) issues.push('PO cancellation controls are missing');
    if (!adminTransfer?.reversalRequestModal || !adminTransfer?.reversalReviewModal) issues.push('Transfer reversal controls are missing');
    if (adminTransfer?.reversalReceive) issues.push('A reversal row exposes the normal receive action');
    if (!adminLot?.revenuePaymentMethod) issues.push('Sale Lot payment method control is missing');

    await cdp.send('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 2, mobile: true });
    for (const page of ['cash-sessions', 'expenses', 'stock-transfers']) {
      await navigate(cdp, `${BASE_URL}/admin/${page}.html?risk_mobile=${Date.now()}`);
      await waitFor(cdp, 'document.body && document.body.textContent.trim().length > 0');
      await delay(600);
      snapshots.push(await pageSnapshot(cdp, `admin-mobile-${page}`));
      screenshots.push(await capture(cdp, `admin-mobile-${page}`));
    }

    await cdp.send('Emulation.setDeviceMetricsOverride', { width: 1366, height: 768, deviceScaleFactor: 1, mobile: false });
    await login(cdp, 'manager-br02');
    await navigate(cdp, `${BASE_URL}/admin/stock-transfers.html?manager_smoke=${Date.now()}`);
    await waitFor(cdp, `document.querySelector('#fromBranch')?.options.length > 0`);
    const managerState = await evaluate(cdp, `({
      transferLinkVisible:(() => { const el=document.querySelector('a[href="stock-transfers.html"]')?.closest('li'); return el ? getComputedStyle(el).display !== 'none' : false; })(),
      saleLotLinkVisible:(() => { const el=document.querySelector('a[href="sale-lots.html"]')?.closest('li'); return el ? getComputedStyle(el).display !== 'none' : false; })(),
      sourceDisabled:document.querySelector('#fromBranch')?.disabled || false,
      sourceValue:document.querySelector('#fromBranch')?.value || ''
    })`);
    if (!managerState.transferLinkVisible || !managerState.sourceDisabled || managerState.sourceValue !== '2') issues.push(`Manager transfer scope is wrong: ${JSON.stringify(managerState)}`);
    if (managerState.saleLotLinkVisible) issues.push('Manager can see Sale Lot navigation');

    const relevantNetwork = networkErrors.filter(item => !item.url.includes('favicon') && !item.url.includes('/auth/verify'));
    if (consoleErrors.length) issues.push(`Console errors: ${JSON.stringify(consoleErrors.slice(0,8))}`);
    if (relevantNetwork.length) issues.push(`Network errors: ${JSON.stringify(relevantNetwork.slice(0,8))}`);
    for (const snapshot of snapshots) {
      if (snapshot.bodyOverflow) issues.push(`${snapshot.page} has body overflow ${snapshot.bodyOverflow.join('->')}`);
    }

    console.log(JSON.stringify({ status:issues.length ? 'fail' : 'success', screenshots, snapshots, managerState, consoleErrors, networkErrors:relevantNetwork, issues }, null, 2));
    process.exitCode = issues.length ? 1 : 0;
  } finally {
    cdp.close();
    browser.process.kill('SIGTERM');
    await delay(300);
    fs.rmSync(browser.profile, { recursive: true, force: true, maxRetries: 5 });
  }
}

run().catch(error => { console.error(JSON.stringify({ status:'error', message:error.message, stack:error.stack }, null, 2)); process.exit(1); });
