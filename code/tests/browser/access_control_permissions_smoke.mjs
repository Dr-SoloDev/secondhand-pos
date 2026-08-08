#!/usr/bin/env node
import { spawn } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';

const BASE_URL = process.env.UI_BASE || 'http://127.0.0.1:8080';
const CHROME_BIN = process.env.CHROME_BIN || 'google-chrome';
const ADMIN_PASS = process.env.TEST_PASS || 'admin';
const FIXTURE_PASS = process.env.AUTH_FIXTURE_PASSWORD || 'AccessTest123!';
const DEBUG_PORT = Number(process.env.CDP_PORT || 9225);
const SHOT_DIR = process.env.SCREENSHOT_DIR || '/tmp/scrap-pos-access-control-ui';
const delay = (ms) => new Promise(resolve => setTimeout(resolve, ms));

const roles = [
  {
    name: 'cashier', username: 'qa-auth-cashier-a', password: FIXTURE_PASS,
    visible: ['purchase-orders.html', 'catalog.html', 'sellers.html', 'inventory.html', 'reports.html', 'cash-sessions.html', 'expenses.html', 'stock-transfers.html', 'price-board.html'],
    hidden: ['sale-lots.html', 'employees.html', 'users.html', 'branches.html', 'settings.html'],
    forbiddenPage: 'sale-lots.html',
  },
  {
    name: 'manager', username: 'qa-auth-manager-a', password: FIXTURE_PASS,
    visible: ['sale-lots.html', 'employees.html', 'settings.html', 'stock-transfers.html'],
    hidden: ['users.html', 'branches.html'],
    forbiddenPage: 'users.html',
  },
  {
    name: 'super_manager', username: 'qa-auth-super-manager', password: FIXTURE_PASS,
    visible: ['sale-lots.html', 'employees.html', 'users.html', 'settings.html'],
    hidden: ['branches.html'],
    forbiddenPage: 'branches.html',
  },
  {
    name: 'admin', username: 'admin', password: ADMIN_PASS,
    visible: ['sale-lots.html', 'employees.html', 'users.html', 'branches.html', 'settings.html'],
    hidden: [],
  },
];

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
        return;
      }
      for (const callback of this.listeners.get(message.method) || []) callback(message.params || {});
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
  const profile = fs.mkdtempSync(path.join(os.tmpdir(), 'scrap-pos-access-'));
  const child = spawn(CHROME_BIN, [
    '--headless=new', '--disable-gpu', '--no-sandbox', '--disable-dev-shm-usage',
    '--disable-background-networking', '--no-first-run', `--remote-debugging-port=${DEBUG_PORT}`,
    `--user-data-dir=${profile}`, 'about:blank',
  ], { stdio: ['ignore', 'ignore', 'ignore'] });
  const targets = await waitForJson(`http://127.0.0.1:${DEBUG_PORT}/json/list`);
  const page = targets.find(target => target.type === 'page');
  if (!page?.webSocketDebuggerUrl) throw new Error('No Chrome page target');
  return { child, profile, wsUrl: page.webSocketDebuggerUrl };
}

async function evaluate(cdp, expression) {
  for (let attempt = 0; attempt < 4; attempt += 1) {
    try {
      const response = await cdp.send('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true, userGesture: true });
      if (response.exceptionDetails) throw new Error(response.exceptionDetails.text || 'Browser evaluation failed');
      return response.result?.value;
    } catch (error) {
      if (!error.message.includes('Inspected target navigated') || attempt === 3) throw error;
      await delay(150);
    }
  }
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
  try {
    await cdp.send('Page.navigate', { url });
  } catch (error) {
    if (!error.message.includes('Inspected target navigated')) throw error;
  }
  await waitFor(cdp, 'document.readyState === "complete"', 20000);
}

async function login(cdp, role) {
  await navigate(cdp, `${BASE_URL}/index.html`);
  const result = await evaluate(cdp, `(async () => {
    localStorage.clear();
    const response = await fetch('/api/index.php/auth/login', {
      method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json'},
      body:JSON.stringify({username:${JSON.stringify(role.username)},password:${JSON.stringify(role.password)}})
    });
    const json = await response.json();
    if (json.status === 'success') localStorage.setItem('posUser', JSON.stringify(json.data.user));
    return json;
  })()`);
  if (result.status !== 'success') throw new Error(`Login failed for ${role.username}: ${result.message}`);
}

async function navState(cdp) {
  return evaluate(cdp, `(() => {
    const visible = el => { if (!el) return false; const s=getComputedStyle(el),r=el.getBoundingClientRect(); return s.display!=='none'&&s.visibility!=='hidden'&&r.width>0&&r.height>0; };
    return Object.fromEntries(Array.from(document.querySelectorAll('.sidebar-menu a[href]')).map(link => [link.getAttribute('href').split('?')[0], visible(link.closest('li') || link)]));
  })()`);
}

async function capture(cdp, name) {
  const result = await cdp.send('Page.captureScreenshot', { format: 'png', fromSurface: true });
  const file = path.join(SHOT_DIR, `${name}.png`);
  fs.writeFileSync(file, Buffer.from(result.data, 'base64'));
  return file;
}

async function run() {
  const browser = await launchChrome();
  const cdp = new CdpClient(browser.wsUrl);
  const issues = [];
  const snapshots = [];
  const screenshots = [];
  const consoleErrors = [];
  try {
    await cdp.connect();
    await Promise.all([cdp.send('Page.enable'), cdp.send('Runtime.enable'), cdp.send('Network.enable')]);
    cdp.on('Runtime.exceptionThrown', event => consoleErrors.push(
      event.exceptionDetails?.exception?.description || event.exceptionDetails?.text || 'runtime exception'
    ));
    cdp.on('Runtime.consoleAPICalled', event => {
      if (['error', 'assert'].includes(event.type)) consoleErrors.push((event.args || []).map(arg => arg.value || arg.description || '').join(' '));
    });

    for (const viewport of [
      { name: 'desktop', width: 1366, height: 768, mobile: false, scale: 1 },
      { name: 'mobile', width: 390, height: 844, mobile: true, scale: 2 },
    ]) {
      await cdp.send('Emulation.setDeviceMetricsOverride', {
        width: viewport.width, height: viewport.height, deviceScaleFactor: viewport.scale, mobile: viewport.mobile,
      });
      for (const role of roles) {
        await login(cdp, role);
        await navigate(cdp, `${BASE_URL}/admin/index.html?access_smoke=${Date.now()}`);
        await waitFor(cdp, `window.appPermissions?.role === ${JSON.stringify(role.name)}`);
        const nav = await navState(cdp);
        const permissions = await evaluate(cdp, 'window.appPermissions');
        const shouldAccessAudit = role.name === 'admin';
        if (permissions.pages?.['audit-log.html'] !== shouldAccessAudit) {
          issues.push(`${viewport.name}/${role.name}: audit-log page permission is incorrect`);
        }
        for (const page of role.visible) if (nav[page] !== true) issues.push(`${viewport.name}/${role.name}: ${page} should be visible`);
        for (const page of role.hidden) if (nav[page] !== false) issues.push(`${viewport.name}/${role.name}: ${page} should be hidden`);
        snapshots.push({
          viewport: viewport.name,
          role: role.name,
          href: await evaluate(cdp, 'location.href'),
          permissionRole: permissions.role,
          branchId: permissions.branch_id,
          multiBranch: permissions.multi_branch,
        });
        screenshots.push(await capture(cdp, `${viewport.name}-${role.name}-dashboard`));

        if (role.forbiddenPage) {
          await navigate(cdp, `${BASE_URL}/admin/${role.forbiddenPage}?direct=1`);
          await waitFor(cdp, 'location.pathname.endsWith("/admin/index.html") && document.readyState === "complete"');
          await delay(200);
        }
      }
    }

    await cdp.send('Emulation.setDeviceMetricsOverride', { width: 1366, height: 768, deviceScaleFactor: 1, mobile: false });
    const superRole = roles.find(role => role.name === 'super_manager');
    await login(cdp, superRole);
    await navigate(cdp, `${BASE_URL}/admin/users.html?access_smoke=${Date.now()}`);
    await waitFor(cdp, 'window.appPermissions?.role === "super_manager" && document.querySelector("#role")');
    const superOptions = await evaluate(cdp, 'Array.from(document.querySelectorAll("#role option")).map(option => option.value)');
    if (superOptions.includes('admin') || superOptions.includes('super_manager')) issues.push(`super_manager role options are unsafe: ${superOptions.join(',')}`);
    if (!superOptions.includes('cashier') || !superOptions.includes('manager')) issues.push(`super_manager role options are incomplete: ${superOptions.join(',')}`);
    if (await evaluate(cdp, '!!document.querySelector("#activityLogTable")')) issues.push('super_manager can see audit log UI');

    await navigate(cdp, `${BASE_URL}/admin/audit-log.html?direct=1`);
    await waitFor(cdp, 'location.pathname.endsWith("/admin/index.html") && document.readyState === "complete"');

    const adminRole = roles.find(role => role.name === 'admin');
    await login(cdp, adminRole);
    await navigate(cdp, `${BASE_URL}/admin/audit-log.html?access_smoke=${Date.now()}`);
    await waitFor(cdp, 'window.appPermissions?.role === "admin" && document.querySelector("#auditTable tbody")?.textContent.trim() !== "กำลังโหลด..."');
    const auditPageState = await evaluate(cdp, `(() => ({
      filters:document.querySelectorAll('#auditFilterForm input,#auditFilterForm select').length,
      exportVisible:(() => { const el=document.querySelector('#auditExport'); if(!el)return false; const s=getComputedStyle(el),r=el.getBoundingClientRect(); return s.display!=='none'&&r.width>0&&r.height>0; })(),
      rows:document.querySelectorAll('#auditTable tbody tr').length,
      overflow:document.body.scrollWidth > document.documentElement.clientWidth + 3
    }))()`);
    if (auditPageState.filters < 10 || !auditPageState.exportVisible || auditPageState.rows < 1 || auditPageState.overflow) {
      issues.push(`admin audit page is incomplete: ${JSON.stringify(auditPageState)}`);
    }
    screenshots.push(await capture(cdp, 'desktop-admin-audit-log'));

    await cdp.send('Emulation.setDeviceMetricsOverride', { width: 390, height: 844, deviceScaleFactor: 2, mobile: true });
    await navigate(cdp, `${BASE_URL}/admin/audit-log.html?access_mobile=${Date.now()}`);
    await waitFor(cdp, 'window.appPermissions?.role === "admin" && document.querySelector("#auditTable tbody")?.textContent.trim().length > 0');
    screenshots.push(await capture(cdp, 'mobile-admin-audit-log'));

    const relevantConsoleErrors = consoleErrors.filter(message => (
      !message.includes('[Violation]')
      && message !== 'Uncaught (in promise)'
      && !message.includes('Inspected target navigated')
      && !message.includes('AbortError: Transition was skipped')
    ));
    if (relevantConsoleErrors.length) issues.push(`Console errors: ${JSON.stringify(relevantConsoleErrors.slice(0, 10))}`);
    console.log(JSON.stringify({ status: issues.length ? 'fail' : 'success', snapshots, screenshots, superOptions, consoleErrors: relevantConsoleErrors, issues }, null, 2));
    process.exitCode = issues.length ? 1 : 0;
  } finally {
    cdp.close();
    browser.child.kill('SIGTERM');
    await delay(300);
    fs.rmSync(browser.profile, { recursive: true, force: true, maxRetries: 5 });
  }
}

run().catch(error => {
  console.error(JSON.stringify({ status: 'error', message: error.message, stack: error.stack }, null, 2));
  process.exit(1);
});
