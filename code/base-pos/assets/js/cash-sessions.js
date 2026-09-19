const MVP_SIMPLE = true; // MVP ซ้อม — ซ่อนบัญชี เหลือแค่ เปิด/เติม/ปิด เหมือน Excel
let cashUser = null;
let cashPermissions = null;
let cashBranches = [];
let cashSession = null;

const cashEl = id => document.getElementById(id);
const cashMoney = value => `฿${Number(value || 0).toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
const cashEscape = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const canReviewCash = () => hasAppPermission('actions.cash_sessions.review', cashPermissions);

async function initCashPage() {
  if (MVP_SIMPLE) document.body.classList.add('mvp-simple');
  cashUser = await requireAuth();
  if (!cashUser) return;
  cashPermissions = await getAppPermissions();
  if (!cashPermissions || !hasAppPermission('actions.cash_sessions.read', cashPermissions)) return;
  const response = await apiRequest('branches/active');
  cashBranches = response.status === 'success' ? (response.data || []) : [];
  const select = cashEl('cashBranch');
  cashBranches.forEach(branch => select.appendChild(new Option(branch.name, branch.id)));
  if (!cashPermissions.multi_branch) {
    select.value = String(cashUser.branch_id || '');
    select.disabled = true;
  }
  select.addEventListener('change', loadCashPage);
  cashEl('refreshCashBtn').addEventListener('click', loadCashPage);
  cashEl('requestDepositBtn').addEventListener('click', requestCashDeposit);
  cashEl('depositSourceType').addEventListener('change', updateDepositPlaceholder);
  updateDepositPlaceholder();
  if (hasAppPermission('actions.adjustments.manage', cashPermissions)) {
    cashEl('adjustmentSection').classList.remove('hidden');
    cashEl('adjustmentHistorySection').classList.remove('hidden');
    cashEl('adjustmentType').addEventListener('change', renderAdjustmentFields);
    cashEl('createAdjustmentBtn').addEventListener('click', createAdjustmentDocument);
    renderAdjustmentFields();
    cashEl('initPositionBtn').classList.remove('hidden');
    cashEl('initPositionBtn').addEventListener('click', openInitPositionModal);
    cashEl('saveInitPositionBtn').addEventListener('click', saveInitPosition);
    cashEl('initPositionModal').addEventListener('click', e => { if (e.target === cashEl('initPositionModal')) closeInitPositionModal(); });
  }
  await loadCashPage();
}

function openInitPositionModal() {
  const select = cashEl('initPositionBranch');
  select.innerHTML = '';
  cashBranches.forEach(branch => select.appendChild(new Option(branch.name, branch.id)));
  select.value = cashEl('cashBranch').value || '';
  cashEl('initDrawerBalance').value = '';
  cashEl('initReserveBalance').value = '';
  cashEl('initEffectiveDate').value = cashLocalDate(new Date());
  cashEl('initPositionNote').value = '';
  cashEl('initPositionModal').classList.add('show');
  setTimeout(() => cashEl('initDrawerBalance').focus(), 50);
}

function closeInitPositionModal() {
  cashEl('initPositionModal').classList.remove('show');
}

async function saveInitPosition() {
  const branchId = Number(cashEl('initPositionBranch').value || 0);
  const drawer = Number(cashEl('initDrawerBalance').value);
  const reserve = Number(cashEl('initReserveBalance').value);
  const effectiveDate = cashEl('initEffectiveDate').value;
  const note = cashEl('initPositionNote').value.trim();
  if (!branchId) return showNotification('กรุณาเลือกสาขา', 'error');
  if (!Number.isFinite(drawer) || drawer < 0) return showNotification('ยอดเงินในลิ้นชักไม่ถูกต้อง', 'error');
  if (!Number.isFinite(reserve) || reserve < 0) return showNotification('ยอดเงินสำรองไม่ถูกต้อง', 'error');
  if (!effectiveDate) return showNotification('กรุณาเลือกวันที่ตั้งต้น', 'error');
  if (!note) return showNotification('กรุณาระบุเหตุผล/หลักฐานของยอดตั้งต้น', 'error');
  const button = cashEl('saveInitPositionBtn');
  setButtonLoading(button, true);
  try {
    const res = await apiRequest('cash-sessions/initialize-position', 'POST', {
      branch_id: branchId, drawer_balance: drawer, reserve_balance: reserve,
      effective_date: effectiveDate, note,
    });
    showNotification(res.message || (res.status === 'success' ? 'ตั้งยอดเริ่มต้นแล้ว' : 'ตั้งไม่สำเร็จ'), res.status === 'success' ? 'success' : 'error');
    if (res.status === 'success') {
      closeInitPositionModal();
      cashEl('cashBranch').value = String(branchId);
      await loadCashPage();
    }
  } catch (err) {
    showNotification(err.message || 'ไม่สามารถตั้งยอดเริ่มต้นได้', 'error');
  } finally {
    setButtonLoading(button, false);
  }
}

async function loadCashPage() {
  const branchId = Number(cashEl('cashBranch').value || 0);
  if (!branchId) return;
  const requests = [
    apiRequest(`cash-sessions/current?branch_id=${branchId}`),
    apiRequest(`cash-sessions/deposits?branch_id=${branchId}`),
    apiRequest(`cash-sessions?branch_id=${branchId}`),
  ];
  if (hasAppPermission('actions.adjustments.manage', cashPermissions)) requests.push(apiRequest(`adjustment-documents?branch_id=${branchId}`));
  const [currentRes, depositRes, historyRes, adjustmentRes] = await Promise.all(requests);
  cashSession = currentRes.status === 'success' ? currentRes.data : null;
  renderCashSession();
  const deposits = depositRes.status === 'success' ? (depositRes.data?.items || []) : [];
  renderCashDeposits(deposits);
  const sessions = historyRes && historyRes.status === 'success' ? (historyRes.data?.items || historyRes.data || []) : [];
  renderHistory(sessions, deposits);
  if (hasAppPermission('actions.adjustments.manage', cashPermissions)) {
    const adj = adjustmentRes && adjustmentRes.status === 'success' ? (adjustmentRes.data?.items || []) : [];
    renderAdjustmentDocuments(adj);
  }
}

function renderCashSession() {
  const positionOn = cashSession && Number(cashSession.cash_model_version) === 2;
  const statusMap = {
    pending_open: ['รออนุมัติเปิดยอด', 'warning'], open: ['เปิดทำการ', 'success'],
    pending_close: ['รออนุมัติปิดยอด', 'warning'], closed: ['ปิดยอดแล้ว', 'danger'], rejected: ['คำขอเปิดยอดถูกปฏิเสธ', 'danger'],
  };
  const [statusText, statusClass] = cashSession ? (statusMap[cashSession.status] || [cashSession.status, '']) : ['ยังไม่ได้เปิดยอดวันนี้', 'warning'];
  cashEl('cashStatusBand').className = `cash-status-band ${statusClass}`;
  cashEl('cashStatusText').textContent = statusText;
  cashEl('cashBusinessDate').textContent = cashSession?.business_date || new Date().toISOString().slice(0, 10);
  cashEl('openingActual').textContent = cashMoney(cashSession?.opening_actual);
  cashEl('ledgerTotal').textContent = cashMoney(cashSession?.ledger_total);
  cashEl('expectedCash').textContent = cashMoney(cashSession?.current_expected_cash);
  const ledgerLabel = cashEl('ledgerLabel');
  if (ledgerLabel) {
    ledgerLabel.textContent = positionOn ? 'เงินเข้า-ออก ลิ้นชัก' : 'เงินเข้า-ออกสุทธิ';
    ledgerLabel.title = positionOn ? 'เฉพาะเงินในลิ้นชัก ไม่รวมสำรอง/โอนธนาคาร' : '';
  }
  if (positionOn) {
    cashEl('drawerBalance').textContent = cashMoney(cashSession.drawer_balance);
    cashEl('reserveBalance').textContent = cashMoney(cashSession.reserve_balance);
    cashEl('businessTotalCash').textContent = cashMoney(cashSession.business_total_cash);
  }
  ['drawerStat', 'reserveStat', 'totalStat'].forEach(id => cashEl(id).classList.toggle('hidden', !positionOn));
  const initBtn = cashEl('initPositionBtn');
  if (initBtn) {
    if (positionOn) {
      initBtn.classList.add('hidden');
    } else {
      initBtn.classList.remove('hidden');
      initBtn.disabled = !!cashSession && ['pending_open', 'open', 'pending_close'].includes(cashSession.status);
    }
  }
  const variance = cashSession?.closing_variance ?? cashSession?.opening_variance ?? 0;
  cashEl('latestVariance').textContent = cashMoney(variance);
  cashEl('latestVariance').className = `cash-stat-value ${Number(variance) === 0 ? '' : 'danger'}`;
  if (MVP_SIMPLE) {
    // ปิดยอดแล้วลิ้นชัก = 0 ทันที — เก็บแค่ประวัติ (ยอดปิด) ไว้ดู
    // เปิด/ระหว่างวัน: ลิ้นชัก = current_expected_cash, ปิดแล้ว: 0
    let drawerVal = 0;
    let closedActual = null;
    if (cashSession && cashSession.status) {
      if (cashSession.status === 'closed') {
        drawerVal = 0;
        closedActual = cashSession.closing_actual;
      } else {
        drawerVal = cashSession?.current_expected_cash ?? 0;
      }
    }
    cashEl('drawerBalance').textContent = cashMoney(drawerVal);
    cashEl('drawerStat').classList.remove('hidden');
    // แสดงยอดปิดเป็นประวัติเมื่อปิดแล้ว
    const drawerLabel = document.querySelector('#drawerStat .cash-stat-label');
    if (drawerLabel) {
      drawerLabel.textContent = cashSession?.status === 'closed' && closedActual !== null
        ? `เงินในลิ้นชัก (ปิดยอด ${cashMoney(closedActual)})` : 'เงินในลิ้นชัก';
    }
  }
  renderSessionAction();
  renderCashHistory(cashSession);
  cashEl('requestDepositBtn').disabled = cashSession?.status !== 'open';
  if (cashEl('createAdjustmentBtn')) cashEl('createAdjustmentBtn').disabled = cashSession?.status !== 'open';
}

function renderSessionAction() {
  const body = cashEl('sessionActionBody');
  const title = cashEl('sessionActionTitle');
  if (!cashSession || !cashSession.status || cashSession.status === 'rejected') {
    title.textContent = MVP_SIMPLE ? '☀️ เปิดร้าน' : 'เปิดยอดประจำวัน';
    body.innerHTML = cashCountForm('open');
    bindCashCountForm('open');
    return;
  }
  if (cashSession.status === 'open') {
    title.textContent = MVP_SIMPLE ? '🌙 ปิดร้าน' : 'ปิดยอดประจำวัน';
    body.innerHTML = cashCountForm('close');
    bindCashCountForm('close');
    return;
  }
  if (cashSession.status === 'pending_open') {
    const expected = cashSession.opening_expected;
    const actual = cashSession.opening_actual;
    const reason = cashSession.opening_reason;
    title.textContent = 'คำขอเปิดยอด';
    body.innerHTML = `<div class="cash-approval-box">
      <div>ยอดตามระบบ <strong>${cashMoney(expected)}</strong></div><div>ยอดนับจริง <strong>${cashMoney(actual)}</strong></div>
      <div>ส่วนต่าง <strong>${cashMoney(Number(actual)-Number(expected))}</strong></div><div>${cashEscape(reason || '-')}</div>
      ${canReviewCash() ? `<div class="cash-approval-actions"><button class="btn btn-success" id="approveSessionBtn">อนุมัติ</button><button class="btn btn-danger" id="rejectSessionBtn">ปฏิเสธ</button></div>` : ''}
    </div>`;
    if (canReviewCash()) {
      cashEl('approveSessionBtn').onclick = () => reviewCashSession(true, true);
      cashEl('rejectSessionBtn').onclick = () => reviewCashSession(true, false);
    }
    return;
  }
  if (cashSession.status === 'pending_close') {
    // Legacy pending_close (ควรไม่มีอีกหลัง deploy นี้) — แสดงประวัติแล้วให้ admin ปิด/ปฏิเสธได้
    const expected = cashSession.closing_expected;
    const actual = cashSession.closing_actual;
    const reason = cashSession.closing_reason;
    title.textContent = 'รออนุมัติปิดยอด (เดิม)';
    body.innerHTML = `<div class="cash-approval-box">
      <div>ยอดตามระบบ <strong>${cashMoney(expected)}</strong></div><div>ยอดนับจริง <strong>${cashMoney(actual)}</strong></div>
      <div>ส่วนต่าง <strong>${cashMoney(Number(actual)-Number(expected))}</strong></div><div>${cashEscape(reason || '-')}</div>
      <div style="margin-top:8px;color:#6b7280;font-size:13px">ระบบใหม่ปิดยอดทันที ไม่ต้องอนุมัติ — รายการนี้ค้างจากก่อนอัปเดต</div>
      ${canReviewCash() ? `<div class="cash-approval-actions"><button class="btn btn-success" id="approveSessionBtn">อนุมัติ (ปิด)</button><button class="btn btn-danger" id="rejectSessionBtn">ปฏิเสธ (เปิดต่อ)</button></div>` : ''}
    </div>`;
    if (canReviewCash()) {
      cashEl('approveSessionBtn').onclick = () => reviewCashSession(false, true);
      cashEl('rejectSessionBtn').onclick = () => reviewCashSession(false, false);
    }
    return;
  }
  title.textContent = 'รอบประจำวัน';
  // ตัดฟีเจอร์เปิดรอบใหม่ — เหลือแค่ เปิด/เติม/ปิด ปิดแล้วจบวัน
  const closedActual = cashSession.closing_actual;
  body.innerHTML = cashSession.status === 'closed'
    ? `<div class="cash-empty">ปิดยอดแล้ว — ลิ้นชัก 0<br><small>ยอดปิดวันนี้ ${cashMoney(closedActual)} (ประวัติ) — เปิดใหม่ได้วันถัดไป</small></div>`
    : '<div class="cash-empty">ปิดยอดแล้ว</div>';
}

function cashCountForm(mode) {
  const expected = cashSession?.current_expected_cash ?? 0;
  if (mode === 'open') {
    return `<div class="cash-form-grid">
      <div class="full"><label for="cashActual">ใส่ยอดเงินที่จะเปิดลิ้นชักวันนี้</label><input id="cashActual" type="number" min="0" step="0.01" class="form-control" placeholder="0.00" value="" style="font-size:18px;text-align:center"><div style="font-size:13px;text-align:center;margin-top:6px;color:#6b7280">ใส่เท่าไหร่ = ลิ้นชักมีเท่านั้น ไม่ยกยอดวันก่อน</div></div>
      <div class="cash-action-row" style="justify-content:center"><button class="btn btn-success" id="submitCashCount" style="padding:10px 24px;font-size:16px">☀️ เปิดยอดวันนี้</button></div>
    </div>`;
  }
  return `<div class="cash-form-grid">
    <div class="full" style="text-align:center;background:#f8fafc;padding:10px;border-radius:6px">ยอดที่ควรมี <strong>${cashMoney(expected)}</strong> (เปิดวัน + เติมเงิน − ซื้อของ − ค่าใช้จ่าย) — ปิดแล้วลิ้นชักจะเป็น 0</div>
    <div class="full"><label for="cashActual">นับเงินในลิ้นชักได้เท่าไหร่</label><input id="cashActual" type="number" min="0" step="0.01" class="form-control" placeholder="0.00" value="" style="font-size:18px;text-align:center"><div id="closeVarianceHint" style="font-size:13px;text-align:center;margin-top:6px;color:#6b7280">กรอกยอดที่นับได้จริง — ปิดทันที ลิ้นชักจะเหลือ 0</div></div>
    <div class="full"><label for="cashReason">เหตุผล (ถ้ามี)</label><textarea id="cashReason" rows="2" maxlength="500" class="form-control" placeholder="ไม่บังคับ — ใส่ได้ถ้ามีเหตุผล"></textarea></div>
  </div>
  <div class="cash-action-row" style="justify-content:center"><button class="btn btn-primary" id="submitCashCount" style="padding:10px 24px;font-size:16px">🌙 ปิดยอดวันนี้</button></div>`;
}

function updateDepositPlaceholder() {
  const map = {
    reserve_transfer: 'เช่น เซฟสาขา / ตู้เซฟ',
    owner_capital: 'เช่น Owner เติมทุน 10,000 บาท',
    drawer_to_reserve: 'เช่น ย้ายเข้าตู้เซฟ 5,000 บาท',
    drawer_to_owner: 'เช่น Owner เบิกไปใช้ส่วนตัว'
  };
  const el = cashEl('depositSource');
  if (el) el.placeholder = map[cashEl('depositSourceType').value] || '';
}

function bindCashCountForm(mode) {
  if (mode === 'close') {
    const actualInput = cashEl('cashActual');
    const hint = () => {
      const h = cashEl('closeVarianceHint');
      if (!h || !actualInput) return;
      const expected = Number(cashSession?.current_expected_cash || 0);
      const actual = Number(actualInput.value);
      if (MVP_SIMPLE) {
        if (!actualInput.value || !Number.isFinite(actual)) {
          h.textContent = 'กรอกยอดที่นับได้จริง — ปิดแล้วลิ้นชักจะเป็น 0';
          h.style.color = '#6b7280';
          return;
        }
        const variance = actual - expected;
        const absV = Math.abs(variance);
        if (absV < 0.01) { h.textContent = '✅ ยอดตรงกัน — ปิดแล้วลิ้นชัก 0'; h.style.color = '#16a34a'; }
        else { h.textContent = `ℹ️ ต่าง ${variance > 0 ? '+' : ''}฿${Math.round(Math.abs(variance)).toString()} — ปิดได้ทันที ลิ้นชักจะเหลือ 0`; h.style.color = '#2563eb'; }
        return;
      }
      if (!actualInput.value || !Number.isFinite(actual)) {
        h.textContent = 'กรอกยอดที่นับได้จริง — ปิดทันที ลิ้นชักจะเป็น 0';
        h.style.color = '#6b7280';
        return;
      }
      const variance = actual - expected;
      const absV = Math.abs(variance);
      if (absV > 0.009) {
        h.textContent = `ℹ️ ส่วนต่าง ${variance > 0 ? '+' : ''}฿${Math.round(Math.abs(variance)).toString()} — ปิดได้ทันที ลิ้นชักจะเหลือ 0`;
        h.style.color = '#2563eb';
      } else {
        h.textContent = '✅ ยอดตรงกัน — ปิดแล้วลิ้นชัก 0';
        h.style.color = '#16a34a';
      }
    };
    if (actualInput) actualInput.addEventListener('input', hint);
  }
  cashEl('submitCashCount').onclick = async () => {
    const actual = Number(cashEl('cashActual')?.value ?? NaN);
    if (!Number.isFinite(actual) || actual < 0) {
      return showNotification(mode === 'open' ? 'กรุณาระบุจำนวนที่ดึงจากเซฟมาใส่ลิ้นชัก' : 'กรุณาระบุยอดเงินสดที่นับได้', 'error');
    }
    const button = cashEl('submitCashCount');
    setButtonLoading(button, true);
    try {
      const res = await apiRequest(`cash-sessions/${mode}`, 'POST', { branch_id:Number(cashEl('cashBranch').value), actual_cash:actual, reason:cashEl('cashReason')?.value.trim() || null });
      showNotification(res.message || (res.status === 'success' ? 'บันทึกแล้ว' : 'บันทึกไม่สำเร็จ'), res.status === 'success' ? 'success' : 'error');
      if (res.status === 'success') await loadCashPage();
    } finally { setButtonLoading(button, false); }
  };
}

async function reviewCashSession(isOpen, approve) {
  let note = '';
  if (!approve) {
    note = prompt('เหตุผลที่ปฏิเสธ') || '';
    if (!note.trim()) return;
  }
  const endpoint = `cash-sessions/${isOpen ? 'open' : 'close'}-${approve ? 'approve' : 'reject'}`;
  const res = await apiRequest(endpoint, 'POST', { id:Number(cashSession.id), review_note:note || null });
  showNotification(res.message, res.status === 'success' ? 'success' : 'error');
  if (res.status === 'success') loadCashPage();
}

// reopenCashSession removed — ระบบเหลือแค่ เปิด/เติม/ปิด (ปิดแล้วจบวัน)

async function requestCashDeposit() {
  const amount = Number(cashEl('depositAmount').value);
  let sourceType = cashEl('depositSourceType').value;
  let source = cashEl('depositSource').value.trim();
  let reason = cashEl('depositReason').value.trim();
  if (MVP_SIMPLE) {
    sourceType = 'owner_capital';
    source = reason || 'เติมเงิน';
    if (!(amount > 0) || !reason) return showNotification('กรุณากรอกจำนวนเงินและเหตุผล', 'error');
    reason = reason || 'เติมเงินระหว่างวัน';
  } else {
    if (!(amount > 0) || !source || !reason) return showNotification('กรุณากรอกข้อมูลเติมเงินให้ครบ', 'error');
  }
  const res = await apiRequest('cash-sessions/deposit-request', 'POST', { branch_id:Number(cashEl('cashBranch').value), amount, source_type:sourceType, source_name:source, reason });
  showNotification(res.message, res.status === 'success' ? 'success' : 'error');
  if (res.status === 'success') { cashEl('depositAmount').value=''; cashEl('depositSource').value=''; cashEl('depositReason').value=''; loadCashPage(); }
}

function renderCashHistory(session) {
  const movements = (session?.movements || []).map(m => ({ ...m, _sortTime: m.created_at || '', _kind: 'movement' }));
  const events = (session?.events || []).map(e => ({ ...e, _sortTime: e.created_at || '', _kind: 'event' }));

  const labels = {
    opened: 'เปิดยอด',
    open_approved: 'อนุมัติเปิดยอด',
    open_rejected: 'ปฏิเสธเปิดยอด',
    close_requested: 'ขอปิดยอด',
    closed: 'ปิดยอด',
    close_approved: 'อนุมัติปิดยอด',
    close_rejected: 'ปฏิเสธปิดยอด',
    reopened: 'เปิดรอบใหม่',
  };

  const rows = [...events, ...movements].sort((a, b) => a._sortTime.localeCompare(b._sortTime)).map(item => {
    if (item._kind === 'event') {
      const evt = item;
      const type = evt.event_type || '';
      const time = cashEscape((evt.created_at || '').slice(11, 16));
      const actor = cashEscape(evt.actor_name || '-');
      const reviewer = evt.reviewer_name ? ` โดย ${cashEscape(evt.reviewer_name)}` : '';
      const expected = evt.expected_amount !== null ? cashMoney(evt.expected_amount) : null;
      const actual = evt.actual_amount !== null ? cashMoney(evt.actual_amount) : null;
      const variance = evt.variance_amount !== null ? cashMoney(evt.variance_amount) : null;

      let desc = labels[type] || type;
      let amountHtml = '';
      if (type === 'opened' || type === 'open_approved' || type === 'reopened') {
        desc += actual ? ` ยอดเปิด ${actual}` : '';
        amountHtml = `<span class="text-right" style="color:var(--color-success)">+${actual}</span>`;
      } else if (type === 'closed' || type === 'close_approved' || type === 'close_requested') {
        desc += actual ? ` ยอดปิด ${actual}` : '';
        if (expected) desc += ` (ควรมี ${expected})`;
        if (variance) desc += ` ส่วนต่าง ${variance}`;
        amountHtml = `<span class="text-right" style="color:var(--color-danger)">-${actual}</span>`;
      }
      if (evt.reason) desc += ` — ${cashEscape(evt.reason)}`;
      return `<tr><td>${time}</td><td><span class="cash-direction event">กิจกรรม</span></td><td>${desc}${reviewer}</td><td>${actor}</td><td class="text-right">${amountHtml}</td></tr>`;
    }

    const m = item;
    const isBank = String(m.movement_type || '').startsWith('bank_');
    const dirLabel = isBank ? 'โอนธนาคาร' : (m.direction === 'in' ? 'เข้า' : 'ออก');
    const dirClass = isBank ? 'bank' : m.direction;
    return `<tr><td>${cashEscape((m.created_at || '').slice(11,16))}</td><td><span class="cash-direction ${dirClass}">${dirLabel}</span></td><td>${cashEscape(m.description)}</td><td>${cashEscape(m.recorded_by_name || '-')}</td><td class="text-right" style="color:${isBank ? '#1e40af' : (m.direction === 'in' ? 'var(--color-success)' : 'var(--color-danger)')}">${m.direction === 'in' ? '+' : '-'}${cashMoney(m.amount)}</td></tr>`;
  });

  cashEl('movementBody').innerHTML = rows.length ? rows.join('') : '<tr><td colspan="5" class="cash-empty">ยังไม่มีรายการเงินสด</td></tr>';
}

function renderCashDeposits(items) {
  const statusMap = { pending: ['รออนุมัติ','warning'], approved: ['อนุมัติแล้ว','success'], rejected: ['ปฏิเสธ','danger'] };
  cashEl('depositBody').innerHTML = items.length ? items.map(item => {
    const actions = item.status === 'pending' && canReviewCash() ? `<button class="btn btn-sm btn-success" onclick="reviewDeposit(${item.id},true)">อนุมัติ</button> <button class="btn btn-sm btn-danger" onclick="reviewDeposit(${item.id},false)">ปฏิเสธ</button>` : '';
    const [label, cls] = statusMap[item.status] || [item.status, ''];
    const badge = cls ? `<span class="badge badge-${cls}">${label}</span>` : cashEscape(item.status);
    return `<tr><td>${cashEscape(item.requested_at || '-')}</td><td>${cashEscape(item.requested_by_name || '-')}</td><td>${cashEscape(item.source_name)}</td><td>${cashEscape(item.reason)}</td><td class="text-right">${cashMoney(item.amount)}</td><td>${badge}</td><td>${actions}</td></tr>`;
  }).join('') : '<tr><td colspan="7" class="cash-empty">ไม่มีคำขอเติมเงินสด</td></tr>';
}

function renderHistory(sessions, deposits) {
  const body = cashEl('historyBody');
  if (!body) return;
  const rows = [];
  // เปิด/ปิด จาก cash_sessions — เก็บถาวรแยกสาขา
  (sessions || []).forEach(s => {
    const branch = cashEscape(s.branch_name || '');
    if (s.opening_actual !== null && s.opening_actual !== undefined) {
      rows.push({
        time: s.opened_at || s.created_at || s.business_date || '',
        type: 'เปิดยอด',
        user: cashEscape(s.opening_requested_by_name || s.branch_name || '-'),
        detail: `สาขา ${branch} วันที่ ${cashEscape(s.business_date || '')}`,
        amount: Number(s.opening_actual),
        sort: s.opened_at || s.created_at || s.business_date || '',
      });
    }
    // เติมเงินที่อนุมัติแล้ว — เอาจาก deposits ที่ approved (กันซ้ำกับตารางบน แต่รวมไว้ให้ดูถาวร)
    // ปิดยอด — มีเฉพาะเมื่อ closed
    if (s.status === 'closed' && s.closing_actual !== null && s.closing_actual !== undefined) {
      rows.push({
        time: s.closed_at || s.updated_at || s.business_date || '',
        type: 'ปิดยอด',
        user: cashEscape(s.closing_requested_by_name || '-'),
        detail: `สาขา ${branch} วันที่ ${cashEscape(s.business_date || '')}`,
        amount: Number(s.closing_actual),
        sort: s.closed_at || s.updated_at || s.business_date || '',
      });
    }
  });
  // เติมเงิน — จาก deposits ที่อนุมัติแล้ว รวมไว้ในประวัติถาวรด้วย
  (deposits || []).filter(d => d.status === 'approved').forEach(d => {
    rows.push({
      time: d.reviewed_at || d.requested_at || '',
      type: 'เติมเงิน',
      user: cashEscape(d.requested_by_name || '-'),
      detail: `${cashEscape(d.source_name || '')} — ${cashEscape(d.reason || '')}`,
      amount: Number(d.amount),
      sort: d.reviewed_at || d.requested_at || '',
    });
  });
  rows.sort((a, b) => String(b.sort).localeCompare(String(a.sort)));
  if (!rows.length) {
    body.innerHTML = '<tr><td colspan="5" class="cash-empty">ยังไม่มีประวัติ เปิด/เติม/ปิด ในสาขานี้</td></tr>';
    return;
  }
  body.innerHTML = rows.map(r => {
    const typeBadge = r.type === 'เปิดยอด' ? 'success' : r.type === 'ปิดยอด' ? 'danger' : 'warning';
    return `<tr><td>${cashEscape((r.time || '').slice(0,16).replace('T',' '))}</td><td><span class="badge badge-${typeBadge}">${r.type}</span></td><td>${r.user}</td><td>${r.detail}</td><td class="text-right">${cashMoney(r.amount)}</td></tr>`;
  }).join('');
}

async function reviewDeposit(id, approve) {
  const note = approve ? null : (prompt('เหตุผลที่ปฏิเสธ') || '');
  if (!approve && !note.trim()) return;
  const res = await apiRequest(`cash-sessions/deposit-${approve ? 'approve' : 'reject'}`, 'POST', { id, review_note:note });
  showNotification(res.message, res.status === 'success' ? 'success' : 'error');
  if (res.status === 'success') loadCashPage();
}

function renderAdjustmentFields() {
  const type = cashEl('adjustmentType').value;
  const today = cashLocalDate(new Date());
  const yesterdayDate = new Date();
  yesterdayDate.setDate(yesterdayDate.getDate() - 1);
  const yesterday = cashLocalDate(yesterdayDate);
  if (type === 'purchase_order_cancellation') {
    cashEl('adjustmentFields').innerHTML = `<div class="cash-form-grid"><div class="full"><label for="adjustmentTarget">เลขที่ใบรับซื้อ</label><input id="adjustmentTarget" class="form-control" placeholder="เช่น PO-BR01-20260730-001"></div></div>`;
    return;
  }
  if (type === 'sale_lot_revenue_correction') {
    cashEl('adjustmentFields').innerHTML = `<div class="cash-form-grid">
      <div class="full"><label for="adjustmentTarget">เลขที่ Sale Lot</label><input id="adjustmentTarget" class="form-control" placeholder="เช่น SL-20260730-001"></div>
      <div><label for="adjustmentDate">วันที่ได้รับเงินจริง</label><input id="adjustmentDate" type="date" max="${today}" value="${today}" class="form-control"></div>
      <div><label for="adjustmentAmount">ยอดรายรับที่ถูกต้อง</label><input id="adjustmentAmount" type="number" min="0" step="0.01" class="form-control" placeholder="0.00"></div>
      <div><label for="adjustmentPaymentMethod">วิธีรับเงินที่ถูกต้อง</label><select id="adjustmentPaymentMethod" class="form-control"><option value="cash">เงินสด</option><option value="bank_transfer">โอนธนาคาร</option></select></div>
      <div><label for="adjustmentNote">หมายเหตุรายการ</label><input id="adjustmentNote" maxlength="500" class="form-control"></div>
    </div>`;
    return;
  }
  cashEl('adjustmentFields').innerHTML = `<div class="cash-form-grid">
    <div><label for="adjustmentDate">วันที่รายจ่ายเดิม</label><input id="adjustmentDate" type="date" max="${yesterday}" value="${yesterday}" class="form-control"></div>
    <div><label for="adjustmentCategory">ประเภท</label><select id="adjustmentCategory" class="form-control"><option>ค่าไฟ</option><option>ค่าน้ำ</option><option>ค่าเช่า</option><option>ค่าเน็ต</option><option>ค่าน้ำมัน</option><option>เงินเดือนพนักงาน</option><option>อื่นๆ</option></select></div>
    <div><label for="adjustmentAmount">จำนวนเงิน</label><input id="adjustmentAmount" type="number" min="0.01" step="0.01" class="form-control" placeholder="0.00"></div>
    <div><label for="adjustmentPaymentMethod">วิธีจ่าย</label><select id="adjustmentPaymentMethod" class="form-control"><option value="cash">เงินสด</option><option value="bank_transfer">โอนธนาคาร</option></select></div>
    <div class="full"><label for="adjustmentBeneficiary">ผู้เบิกหรือผู้รับเงิน</label><input id="adjustmentBeneficiary" maxlength="200" class="form-control"></div>
    <div class="full"><label for="adjustmentNote">รายละเอียด</label><input id="adjustmentNote" maxlength="255" class="form-control"></div>
  </div>`;
}

async function createAdjustmentDocument() {
  const type = cashEl('adjustmentType').value;
  const payload = {
    adjustment_type: type,
    branch_id: Number(cashEl('cashBranch').value),
    reason: cashEl('adjustmentReason').value.trim(),
  };
  if (type === 'purchase_order_cancellation') {
    payload.target_reference = cashEl('adjustmentTarget').value.trim();
  } else if (type === 'sale_lot_revenue_correction') {
    payload.target_reference = cashEl('adjustmentTarget').value.trim();
    payload.effective_date = cashEl('adjustmentDate').value;
    payload.amount = Number(cashEl('adjustmentAmount').value);
    payload.payment_method = cashEl('adjustmentPaymentMethod').value;
    payload.note = cashEl('adjustmentNote').value.trim() || null;
  } else {
    payload.effective_date = cashEl('adjustmentDate').value;
    payload.category = cashEl('adjustmentCategory').value;
    payload.amount = Number(cashEl('adjustmentAmount').value);
    payload.payment_method = cashEl('adjustmentPaymentMethod').value;
    payload.beneficiary_name = cashEl('adjustmentBeneficiary').value.trim();
    payload.note = cashEl('adjustmentNote').value.trim() || null;
  }
  if (!payload.reason) return showNotification('กรุณาระบุเหตุผลการปรับปรุง', 'error');

  const button = cashEl('createAdjustmentBtn');
  setButtonLoading(button, true);
  try {
    const response = await apiRequest('adjustment-documents', 'POST', payload);
    showNotification(response.message || (response.status === 'success' ? 'บันทึกแล้ว' : 'บันทึกไม่สำเร็จ'), response.status === 'success' ? 'success' : 'error');
    if (response.status === 'success') {
      cashEl('adjustmentReason').value = '';
      renderAdjustmentFields();
      if (response.data?.branch_id) cashEl('cashBranch').value = String(response.data.branch_id);
      await loadCashPage();
    }
  } finally {
    setButtonLoading(button, false);
  }
}

function renderAdjustmentDocuments(items) {
  const labels = {
    purchase_order_cancellation: 'ยกเลิกใบรับซื้อย้อนหลัง',
    sale_lot_revenue_correction: 'แก้รายรับ LOT',
    historical_expense: 'เพิ่มรายจ่ายย้อนหลัง',
  };
  cashEl('adjustmentBody').innerHTML = items.length ? items.map(item => `<tr>
    <td class="cash-adjustment-reference">${cashEscape(item.reference_no)}</td>
    <td>${cashEscape(item.effective_date)}</td>
    <td>${cashEscape(labels[item.adjustment_type] || item.adjustment_type)}</td>
    <td>${cashEscape(item.target_type || '-')} #${cashEscape(item.target_id || '-')}</td>
    <td>${item.amount_before === null ? '-' : cashMoney(item.amount_before)}${item.payment_method_before ? `<br><small>${item.payment_method_before === 'cash' ? 'เงินสด' : 'โอน'}</small>` : ''}</td>
    <td>${item.amount_after === null ? '-' : cashMoney(item.amount_after)}${item.payment_method_after ? `<br><small>${item.payment_method_after === 'cash' ? 'เงินสด' : 'โอน'}</small>` : ''}</td>
    <td>${cashEscape(item.reason)}</td>
    <td>${cashEscape(item.created_by_name || '-')}</td>
    <td>${cashEscape(item.created_at || '-')}</td>
  </tr>`).join('') : '<tr><td colspan="9" class="cash-empty">ยังไม่มีเอกสารปรับปรุง</td></tr>';
}

function cashLocalDate(date) {
  return `${date.getFullYear()}-${String(date.getMonth()+1).padStart(2,'0')}-${String(date.getDate()).padStart(2,'0')}`;
}

document.addEventListener('DOMContentLoaded', initCashPage);
