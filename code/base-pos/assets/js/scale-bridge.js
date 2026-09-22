// scale-bridge.js — Tiger TI-01 RS232 → POS (Phase 2)
// ทางเลือก 2 แบบ (auto):
//   A) Web Serial API (Chrome/Edge) — ไม่ต้องลงโปรแกรม เสียบสาย → กด "เชื่อมตาชั่ง" ครั้งเดียว
//   B) HTTP Agent localhost:9130 — fallback สำหรับ Firefox/เก่า
// ทั้ง 2 แบบ fallback เป็น manual (แท็บเล็ต) ถ้าไม่เจอตาชั่ง

const SCALE_AGENT_URL = 'http://localhost:9130/weight';
const SCALE_POLL_MS = 500;
const SCALE_STABLE_MS = 1500;
const SCALE_CONNECT_THRESHOLD = 3;
const SCALE_DISCONNECT_THRESHOLD = 3;

// ── Parser — เหมือน scale_agent.py (Tiger TI-01 generic) ──
function parseScaleLine(raw) {
  const s = (raw || '').trim();
  if (!s) return null;
  const upper = s.toUpperCase();
  let stableHint = upper.includes('ST') && !upper.includes('US');
  if (upper.includes('US') && !upper.includes('STABLE')) stableHint = false;
  const m = s.match(/(-?\d+[.,]\d+)|(-?\d+)/);
  if (!m) return null;
  const w = parseFloat(m[0].replace(',', '.'));
  if (!isFinite(w) || w < -10 || w > 99999) return null;
  return { weight: w, stableHint, raw: s };
}

let scaleState = {
  connected: false,
  weight: 0,
  stable: false,
  raw: '',
  consecutiveOk: 0,
  consecutiveFail: 0,
  captured: null, // { weight, stable, raw, ts, deviceId }
  stableSince: null,
  autoCaptured: false,
  mode: 'serial', // 'serial' | 'agent' | 'manual'
};

let scalePollTimer = null;
let scaleBranchMode = 'disabled'; // disabled|auto|required — โหลดจาก /scale/health (default auto แบบเสียบแล้วเปิด)
let scaleDevices = [];

// ── Web Serial ──
let serialPort = null;
let serialReader = null;
let serialKeepReading = false;
let serialBuffer = '';
let _weightHistory = []; // for stable detection when no ST flag

function isWebSerialSupported() {
  return 'serial' in navigator;
}

async function tryAutoConnectSerial() {
  // ลองต่อ port ที่เคยอนุญาตไว้แล้ว (ไม่ต้องขอใหม่)
  if (!isWebSerialSupported()) return false;
  try {
    const ports = await navigator.serial.getPorts();
    if (ports.length > 0) {
      serialPort = ports[0];
      await openSerialPort(serialPort);
      console.log('[Scale] Auto-connected Web Serial', serialPort);
      return true;
    }
  } catch (e) { console.log('[Scale] autoConnect failed', e); }
  return false;
}

async function requestSerialPort() {
  if (!isWebSerialSupported()) {
    showNotification('เบราว์เซอร์นี้ไม่รองรับ Web Serial — ใช้ Chrome/Edge แล้วเสียบสาย USB-RS232', 'error');
    return;
  }
  try {
    serialPort = await navigator.serial.requestPort();
    await openSerialPort(serialPort);
    showNotification('เชื่อมตาชั่งสำเร็จ — พร้อมชั่ง', 'success');
  } catch (e) {
    if (e.name !== 'NotFoundError') {
      console.error('[Scale] requestPort failed', e);
      showNotification('เชื่อมตาชั่งไม่สำเร็จ: ' + e.message, 'error');
    }
  }
}

async function openSerialPort(port) {
  try {
    await port.open({ baudRate: 9600, dataBits: 8, stopBits: 1, parity: 'none' });
  } catch (e) {
    // อาจเปิดอยู่แล้ว
    if (!e.message.includes('already open')) throw e;
  }
  scaleState.mode = 'serial';
  scaleState.connected = true;
  serialKeepReading = true;
  updateScaleUI();
  readSerialLoop(port);
  // ฟัง disconnect
  port.addEventListener('disconnect', () => {
    console.log('[Scale] Serial disconnected');
    serialKeepReading = false;
    scaleState.connected = false;
    scaleState.mode = 'manual';
    scaleState.stableSince = null;
    updateScaleUI();
  });
}

async function readSerialLoop(port) {
  const decoder = new TextDecoder();
  // Web Serial readable stream
  while (serialKeepReading && port.readable) {
    try {
      serialReader = port.readable.getReader();
      while (serialKeepReading) {
        const { value, done } = await serialReader.read();
        if (done) break;
        const chunk = decoder.decode(value, { stream: true });
        serialBuffer += chunk;
        // แยกบรรทัดด้วย \n หรือ \r
        let lines = serialBuffer.split(/[\r\n]+/);
        serialBuffer = lines.pop(); // ค้างไว้
        for (const line of lines) {
          const parsed = parseScaleLine(line);
          if (parsed) {
            onScaleWeight(parsed.weight, parsed.stableHint, parsed.raw, 'serial');
          } else if (line.trim()) {
            // เก็บ raw ไว้ debug
            scaleState.raw = line.trim();
            updateScaleUI();
          }
        }
      }
      serialReader.releaseLock();
    } catch (e) {
      console.error('[Scale] read error', e);
      try { serialReader?.releaseLock(); } catch {}
      await new Promise(r => setTimeout(r, 500));
    }
  }
  scaleState.connected = false;
  updateScaleUI();
}

async function disconnectSerial() {
  serialKeepReading = false;
  try { serialReader?.cancel(); } catch {}
  try { serialReader?.releaseLock(); } catch {}
  try { await serialPort?.close(); } catch {}
  serialPort = null;
  serialReader = null;
  scaleState.connected = false;
  scaleState.mode = 'manual';
  scaleState.stableSince = null;
  updateScaleUI();
  showNotification('ยกเลิกเชื่อมตาชั่งแล้ว — กลับเป็นคีย์มือ', 'info');
}

// ── Common weight handler (ใช้ทั้ง serial และ agent) ──
function onScaleWeight(weight, stableHint, raw, mode) {
  const now = Date.now();
  _weightHistory.push([now, weight]);
  _weightHistory = _weightHistory.filter(([t]) => now - t < 3000);

  let stable = stableHint;
  if (!stableHint && _weightHistory.length >= 3) {
    const recent = _weightHistory.filter(([t]) => now - t < 1500).map(([, v]) => v);
    if (recent.length >= 3 && Math.max(...recent) - Math.min(...recent) < 0.02 && weight > 0.01) {
      stable = true;
    }
  }

  scaleState.weight = Math.round(weight * 100) / 100;
  scaleState.stable = stable;
  scaleState.raw = raw;
  scaleState.mode = mode;
  scaleState.connected = true;
  scaleState.consecutiveOk = SCALE_CONNECT_THRESHOLD;
  scaleState.consecutiveFail = 0;

  // auto-capture เมื่อนิ่ง 1.5 วิ (mode auto เท่านั้น)
  if (scaleState.stable && scaleState.weight > 0.01) {
    if (!scaleState.stableSince) scaleState.stableSince = now;
    const stableDur = now - scaleState.stableSince;
    if (stableDur >= SCALE_STABLE_MS && !scaleState.autoCaptured && scaleBranchMode === 'auto') {
      captureScaleWeight(true);
    }
  } else {
    scaleState.stableSince = null;
    if (scaleState.autoCaptured) {
      const capW = scaleState.captured ? scaleState.captured.weight : 0;
      if (Math.abs(scaleState.weight - capW) > 0.05) scaleState.autoCaptured = false;
    }
  }
  updateScaleUI();
}

// ── HTTP Agent poll (fallback) ──
async function pollAgent() {
  try {
    const ctrl = new AbortController();
    const t = setTimeout(() => ctrl.abort(), 800);
    const res = await fetch(SCALE_AGENT_URL, { signal: ctrl.signal });
    clearTimeout(t);
    if (!res.ok) throw new Error('HTTP ' + res.status);
    const data = await res.json();
    if (data.connected && data.weight !== undefined) {
      onScaleWeight(parseFloat(data.weight) || 0, !!data.stable, data.raw || '', 'agent');
    } else {
      throw new Error('not connected');
    }
  } catch (e) {
    // ถ้าใช้ serial อยู่ ไม่ต้องทำอะไร — serial เป็นหลัก
    if (scaleState.mode === 'serial' && scaleState.connected) return;
    scaleState.consecutiveFail++;
    scaleState.consecutiveOk = 0;
    if (scaleState.consecutiveFail >= SCALE_DISCONNECT_THRESHOLD) {
      if (scaleState.connected && scaleState.mode === 'agent') console.log('[Scale] Agent disconnected');
      if (scaleState.mode === 'agent') {
        scaleState.connected = false;
        scaleState.mode = 'manual';
        scaleState.stableSince = null;
      }
    }
    updateScaleUI();
  }
}

// ── Init ──
async function initScaleBridge() {
  // โหลด scale_mode ของสาขาปัจจุบัน (default auto — เสียบแล้วเปิด)
  try {
    const branchId = getCurrentBranchId();
    if (branchId) {
      const res = await apiRequest(`scale/health?branch_id=${branchId}`);
      if (res.status === 'success') {
        scaleBranchMode = res.data.scale_mode || 'auto';
        scaleDevices = res.data.devices || [];
      }
    } else {
      scaleBranchMode = 'auto'; // ไม่มี branch param → auto (เสียบแล้วเปิด)
    }
  } catch (e) { scaleBranchMode = 'auto'; }

  // ลอง auto-connect serial ที่เคยอนุญาตไว้
  const autoOk = await tryAutoConnectSerial();

  // ไม่ว่า serial จะต่อได้หรือไม่ ให้ poll agent เป็น fallback (+ สำหรับแท็บเล็ตจะ fallback เป็น manual)
  startAgentPoll();

  // re-check branch mode เมื่อเปลี่ยนสาขา
  document.getElementById('branchSelect')?.addEventListener('change', async () => {
    try {
      const bid = getCurrentBranchId();
      const res = await apiRequest(`scale/health?branch_id=${bid}`);
      if (res.status === 'success') {
        scaleBranchMode = res.data.scale_mode || 'auto';
        scaleDevices = res.data.devices || [];
      }
    } catch (e) {}
    updateScaleUI();
  });

  // ฟัง serial connect/disconnect ของ browser (เสียบสายใหม่)
  if (isWebSerialSupported()) {
    navigator.serial.addEventListener('connect', async (e) => {
      console.log('[Scale] Serial connect event', e);
      showNotification('พบตาชั่งเสียบใหม่ — กำลังเชื่อม...', 'info');
      try { await openSerialPort(e.target); } catch (err) { console.error(err); }
    });
    navigator.serial.addEventListener('disconnect', () => {
      console.log('[Scale] Serial disconnect event');
      scaleState.connected = false;
      scaleState.mode = 'manual';
      updateScaleUI();
    });
  }

  updateScaleUI();
}

function getCurrentBranchId() {
  try {
    const u = JSON.parse(localStorage.getItem('posUser') || '{}');
    if (u.role === 'cashier' && u.branch_id) return String(u.branch_id);
  } catch (e) {}
  return document.getElementById('branchSelect')?.value || '';
}

function startAgentPoll() {
  if (scalePollTimer) return;
  scalePollTimer = setInterval(pollAgent, SCALE_POLL_MS);
  pollAgent();
}

function stopAgentPoll() {
  if (scalePollTimer) { clearInterval(scalePollTimer); scalePollTimer = null; }
}

function captureScaleWeight(isAuto) {
  if (!scaleState.connected || scaleState.weight <= 0) return;
  const deviceId = scaleDevices[0]?.id || null;
  scaleState.captured = {
    weight: scaleState.weight,
    stable: scaleState.stable,
    raw: scaleState.raw,
    ts: new Date().toISOString().slice(0, 19).replace('T', ' '),
    deviceId: deviceId,
    isAuto: !!isAuto,
  };
  if (isAuto) scaleState.autoCaptured = true;
  const qtyEl = document.getElementById('itemQuantity');
  if (qtyEl) {
    qtyEl.value = scaleState.weight.toFixed(2);
    qtyEl.dispatchEvent(new Event('input', { bubbles: true }));
  }
  updateItemTotal();
  updateScaleUI();
  if (isAuto) {
    showNotification(`จับน้ำหนักอัตโนมัติ ${scaleState.weight.toFixed(2)} กก.`, 'success');
  } else {
    showNotification(`จับน้ำหนัก ${scaleState.weight.toFixed(2)} กก.`, 'success');
  }
}

function clearScaleCapture() {
  scaleState.captured = null;
  scaleState.autoCaptured = false;
  scaleState.stableSince = null;
  const qtyEl = document.getElementById('itemQuantity');
  if (qtyEl && scaleState.connected) qtyEl.value = '';
  updateScaleUI();
}

function getScaleCaptureForCart() {
  // เรียกตอน addItemToCart — คืน provenance ที่จะส่งไป API
  // ถ้าไม่ connected หรือไม่ captured → manual (แท็บเล็ต)
  if (!scaleState.connected || !scaleState.captured) {
    return { weight_source: 'manual', scale_device_id: null, scale_raw_kg: null, scale_stable: null, captured_at: null, override_reason: null };
  }
  // ถ้าเป็น auto mode แต่ไม่มี stable → ยังให้เป็น scale ได้ (บางรุ่นไม่มี flag)
  const qtyVal = parseFloat(document.getElementById('itemQuantity')?.value || 0);
  const capW = scaleState.captured.weight;
  if (Math.abs(qtyVal - capW) > 0.01) {
    return { weight_source: 'manual_override', scale_device_id: scaleState.captured.deviceId, scale_raw_kg: scaleState.captured.weight, scale_stable: scaleState.captured.stable ? 1 : 0, captured_at: scaleState.captured.ts, override_reason: null };
  }
  return {
    weight_source: 'scale',
    scale_device_id: scaleState.captured.deviceId,
    scale_raw_kg: scaleState.captured.weight,
    scale_stable: scaleState.captured.stable ? 1 : 0,
    captured_at: scaleState.captured.ts,
    override_reason: null,
  };
}

function isScaleConnected() {
  return scaleState.connected;
}

function updateScaleUI() {
  const badge = document.getElementById('scaleStatusBadge');
  const liveEl = document.getElementById('scaleLiveWeight');
  const captureBtn = document.getElementById('scaleCaptureBtn');
  const clearBtn = document.getElementById('scaleClearBtn');
  const connectBtn = document.getElementById('scaleConnectBtn');
  const disconnectBtn = document.getElementById('scaleDisconnectBtn');
  const qtyEl = document.getElementById('itemQuantity');
  const hintEl = document.getElementById('scaleLockHint');

  const hasSerial = isWebSerialSupported();

  // Connect/Disconnect buttons
  if (connectBtn) {
    connectBtn.style.display = hasSerial && !scaleState.connected ? '' : 'none';
    connectBtn.disabled = !hasSerial;
    connectBtn.title = hasSerial ? 'เสียบสาย USB-RS232 แล้วกดเพื่อเชื่อม Tiger TI-01' : 'เบราว์เซอร์นี้ไม่รองรับ Web Serial — ใช้ Chrome/Edge';
  }
  if (disconnectBtn) {
    disconnectBtn.style.display = hasSerial && scaleState.connected && scaleState.mode === 'serial' ? '' : 'none';
  }

  // Badge
  if (badge) {
    if (scaleState.connected) {
      const stableIcon = scaleState.stable ? '●นิ่ง' : '○รอ';
      const color = scaleState.stable ? '#16a34a' : '#d97706';
      const via = scaleState.mode === 'serial' ? 'Web Serial' : 'Agent';
      badge.innerHTML = `<span style="color:${color};font-weight:700">🟢 ตาชั่ง ${scaleState.weight.toFixed(2)} กก. ${stableIcon}</span> <span style="font-size:10px;color:#888">(${via})</span>`;
      badge.title = `Raw: ${scaleState.raw} | Mode: ${scaleState.mode}`;
    } else {
      if (hasSerial) {
        badge.innerHTML = '<span style="color:#888">⚪ คีย์มือ — <a href="#" onclick="window.scaleBridge.connect();return false" style="color:#2563eb;text-decoration:underline">กดเชื่อมตาชั่ง</a> ถ้าเสียบสายแล้ว</span>';
        badge.title = 'เสียบสาย USB-RS232 แล้วกด เชื่อมตาชั่ง (Web Serial) — ไม่ต้องลงโปรแกรม';
      } else {
        badge.innerHTML = '<span style="color:#888">⚪ คีย์มือ</span>';
        badge.title = 'พิมพ์น้ำหนักเองได้ปกติ — ถ้ามีตาชั่งให้ใช้ Chrome/Edge แล้วกดเชื่อมตาชั่ง';
      }
    }
  }

  if (liveEl) {
    if (scaleState.connected) {
      liveEl.textContent = scaleState.weight.toFixed(2);
      liveEl.style.color = scaleState.stable ? '#16a34a' : '#d97706';
    } else {
      liveEl.textContent = '—';
      liveEl.style.color = '#999';
    }
  }

  if (captureBtn) {
    captureBtn.disabled = !scaleState.connected || scaleState.weight <= 0;
    captureBtn.style.opacity = captureBtn.disabled ? '0.5' : '1';
    captureBtn.title = scaleState.connected ? `จับน้ำหนัก ${scaleState.weight.toFixed(2)} กก.` : 'รอตาชั่ง';
  }
  if (clearBtn) {
    clearBtn.style.display = scaleState.captured ? '' : 'none';
  }

  // Lock quantity input เมื่อต่อตาชั่งอยู่ (ไม่ว่า auto/disabled — เสียบแล้วเปิด)
  if (qtyEl) {
    if (scaleState.connected) {
      qtyEl.readOnly = true;
      qtyEl.style.background = '#f0fdf4';
      qtyEl.style.cursor = 'not-allowed';
      qtyEl.title = 'ล็อค — น้ำหนักมาจากตาชั่ง กด จับน้ำหนัก หรือรอ auto-capture (หักพิมพ์มือได้)';
      if (hintEl) hintEl.style.display = '';
    } else {
      qtyEl.readOnly = false;
      qtyEl.style.background = '';
      qtyEl.style.cursor = '';
      qtyEl.title = '';
      if (hintEl) hintEl.style.display = 'none';
    }
  }

  const capEl = document.getElementById('scaleCapturedDisplay');
  if (capEl) {
    if (scaleState.captured) {
      const autoTag = scaleState.captured.isAuto ? ' (auto)' : '';
      capEl.textContent = `${scaleState.captured.weight.toFixed(2)} กก.${autoTag}`;
      capEl.style.color = '#16a34a';
    } else {
      capEl.textContent = scaleState.connected ? 'รอจับน้ำหนัก' : '—';
      capEl.style.color = '#999';
    }
  }
}

// Expose for purchase-orders.js
window.scaleBridge = {
  init: initScaleBridge,
  connect: requestSerialPort,
  disconnect: disconnectSerial,
  capture: () => captureScaleWeight(false),
  clear: clearScaleCapture,
  getCapture: getScaleCaptureForCart,
  isConnected: isScaleConnected,
  getMode: () => scaleBranchMode,
  isSerialSupported: isWebSerialSupported,
};
