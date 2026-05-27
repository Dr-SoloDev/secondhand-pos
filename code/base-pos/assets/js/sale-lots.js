let branches = [];
let categories = [];
let lots = [];
let lineItems = [];
let editId = null;
let confirmCallback = null;
let expenses = [];

function escapeHtml(s) {
  if (s == null) return '';
  return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

function formatDateTime(s) {
  if (!s) return '-';
  const d = new Date(s.replace(' ', 'T'));
  return d.toLocaleString('th-TH', { dateStyle: 'short', timeStyle: 'short' });
}

function statusBadgeHtml(status) {
  const map = {
    draft:     'badge-warning',
    confirmed: 'badge-success',
    cancelled: 'badge-danger',
  };
  const label = {
    draft:     'แบบร่าง',
    confirmed: 'ยืนยันแล้ว',
    cancelled: 'ยกเลิก',
  };
  return `<span class="badge ${map[status] || 'badge-warning'}">${label[status] || status || 'แบบร่าง'}</span>`;
}

document.addEventListener('DOMContentLoaded', () => {
  Promise.all([loadBranches(), loadCategories()]).then(loadLots);

  document.getElementById('newSaleLotBtn').addEventListener('click', () => openModal());
  document.getElementById('addLineItemBtn').addEventListener('click', addLineItem);
  document.getElementById('saveLotBtn').addEventListener('click', saveLot);
  document.getElementById('confirmOkBtn').addEventListener('click', () => {
    if (confirmCallback) confirmCallback();
    document.getElementById('confirmModal').classList.remove('show');
  });

  document.getElementById('searchInput').addEventListener('input', debounce(() => renderLots(), 250));

  document.querySelectorAll('.close-modal').forEach(b => {
    b.addEventListener('click', e => {
      e.target.closest('.modal').classList.remove('show');
      if (e.target.closest('#saleLotModal')) {
        editId = null;
        resetForm();
      }
    });
  });
});

function debounce(fn, ms) {
  let t;
  return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); };
}

async function loadBranches() {
  const res = await apiRequest('branches/active');
  if (res.status !== 'success') return;
  branches = res.data || [];
  const sel = document.getElementById('branchSelect');
  sel.innerHTML = '<option value="">\u2014 \u0e40\u0e25\u0e37\u0e2d\u0e01\u0e2a\u0e32\u0e02\u0e32 \u2014</option>' +
    branches.map(b => `<option value="${b.id}">${escapeHtml(b.name)}</option>`).join('');
}

async function loadCategories() {
  const res = await apiRequest('price-tiers');
  if (res.status === 'success') {
    categories = res.data || [];
  }
}

async function loadLots() {
  const res = await apiRequest('sale-lots');
  if (res.status !== 'success') { showNotification('โหลดข้อมูลไม่สำเร็จ', 'error'); return; }
  lots = res.data?.items || [];
  renderLots();
}

function renderLots() {
  const tbody = document.getElementById('lotTableBody');
  const q = document.getElementById('searchInput').value.trim().toLowerCase();
  const filtered = q
    ? lots.filter(lot =>
        (lot.reference_no || '').toLowerCase().includes(q) ||
        (lot.buyer_name || '').toLowerCase().includes(q))
    : lots;

  if (filtered.length === 0) {
    tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;color:#888;padding:24px">' +
      (q ? 'ไม่พบรายการที่ค้นหา' : 'ยังไม่มีรายการขาย Lot') + '</td></tr>';
    return;
  }

  tbody.innerHTML = filtered.map(lot => {
    const profit = parseFloat(lot.total_amount || 0) - parseFloat(lot.total_cost || 0);
    const profitClass = profit >= 0 ? 'profit-positive' : 'profit-negative';
    const profitSign = profit >= 0 ? '+' : '';
    return `<tr>
      <td><a href="#" onclick="viewLot(${lot.id});return false" style="font-family:monospace;font-size:12px">${escapeHtml(lot.reference_no || `SL-${lot.id}`)}</a></td>
      <td style="color:var(--color-text-light);font-size:13px">${lot.sale_date ? lot.sale_date.slice(0, 10) : formatDateTime(lot.created_at)}</td>
      <td>${escapeHtml(lot.buyer_name || '-')}</td>
      <td class="text-right">${formatCurrency(lot.total_amount)}</td>
      <td class="text-right" style="color:var(--color-text-light)">${formatCurrency(lot.total_cost)}</td>
      <td class="text-right"><span class="${profitClass}">${profitSign}${formatCurrency(profit)}</span></td>
      <td>${statusBadgeHtml(lot.status)}</td>
      <td>
        <div class="action-cell">
          ${lot.status === 'draft' ? `
            <button class="btn btn-sm btn-secondary" onclick="openModal(${lot.id})">แก้ไข</button>
            <button class="btn btn-sm btn-success" onclick="confirmLot(${lot.id}, '${escapeHtml(lot.reference_no || '')}')">ยืนยัน</button>
          ` : ''}
          ${lot.status === 'draft' ? `
            <button class="btn btn-sm btn-danger" onclick="deleteLot(${lot.id}, '${escapeHtml(lot.reference_no || '')}')" style="padding:3px 8px">ลบ</button>
          ` : ''}
        </div>
      </td>
    </tr>`;
  }).join('');
}

function addLineItem() {
  lineItems.push({ key: Date.now() + Math.random(), category_id: '', quantity_kg: '', unit_price: '' });
  renderLineItems();
}

function removeLineItem(idx) {
  lineItems.splice(idx, 1);
  renderLineItems();
}

function updateLineItem(idx, field, value) {
  lineItems[idx] = { ...lineItems[idx], [field]: value };
  renderLineItems();
}

function renderLineItems() {
  const container = document.getElementById('lineItemsContainer');
  const itemCount = document.getElementById('itemCount');
  itemCount.textContent = lineItems.length;

  if (lineItems.length === 0) {
    container.innerHTML = '<div style="text-align:center;padding:24px;color:var(--color-text-lighter);border:2px dashed #ddd;border-radius:8px;font-size:14px">ยังไม่มีรายการ — กด "+ เพิ่มรายการ"</div>';
    calcTotal();
    return;
  }

  container.innerHTML = lineItems.map((item, idx) => {
    const qty = parseFloat(item.quantity_kg) || 0;
    const price = parseFloat(item.unit_price) || 0;
    const subtotal = qty * price;

    return `<div class="item-row-card">
      <div class="item-header">
        <span style="font-size:12px;color:var(--color-text-lighter)">รายการที่ ${idx + 1}</span>
        <button class="btn btn-sm btn-danger" onclick="removeLineItem(${idx})" style="padding:1px 8px;font-size:12px">ลบ</button>
      </div>
      <div class="item-fields">
        <div class="form-group" style="margin:0">
          <label style="font-size:11px;color:var(--color-text-lighter)">หมวดสินค้า</label>
          <select class="form-control" onchange="updateLineItem(${idx}, 'category_id', this.value)">
            <option value="">\u2014 \u0e40\u0e25\u0e37\u0e2d\u0e01\u0e2b\u0e21\u0e27\u0e14 \u2014</option>
            ${categories.map(c => `<option value="${c.id}" ${String(c.id) === String(item.category_id) ? 'selected' : ''}>${escapeHtml(c.name)}</option>`).join('')}
          </select>
        </div>
        <div class="form-group" style="margin:0">
          <label style="font-size:11px;color:var(--color-text-lighter)">น้ำหนัก (กก.)</label>
          <input type="number" class="form-control" min="0" step="0.01" value="${item.quantity_kg}"
            onchange="updateLineItem(${idx}, 'quantity_kg', this.value)"
            oninput="updateLineItem(${idx}, 'quantity_kg', this.value)">
        </div>
        <div class="form-group" style="margin:0">
          <label style="font-size:11px;color:var(--color-text-lighter)">ราคา/กก. (฿)</label>
          <input type="number" class="form-control" min="0" step="0.01" value="${item.unit_price}"
            onchange="updateLineItem(${idx}, 'unit_price', this.value)"
            oninput="updateLineItem(${idx}, 'unit_price', this.value)">
        </div>
      </div>
      <div class="item-subtotal">รวม: <strong>${formatCurrency(subtotal)}</strong></div>
    </div>`;
  }).join('');

  calcTotal();
}

function calcTotal() {
  const total = lineItems.reduce((s, it) => s + (parseFloat(it.quantity_kg) || 0) * (parseFloat(it.unit_price) || 0), 0);
  document.getElementById('totalAmountDisplay').textContent = formatCurrency(total);
}

// Expense functions
function addExpense() {
  expenses.push({ key: Date.now() + Math.random(), description: '', amount: '' });
  renderExpenses();
}

function removeExpense(idx) {
  expenses.splice(idx, 1);
  renderExpenses();
}

function updateExpense(idx, field, value) {
  expenses[idx] = { ...expenses[idx], [field]: field === 'amount' ? parseFloat(value) || 0 : value };
  renderExpenses();
}

function renderExpenses() {
  const container = document.getElementById('expensesContainer');
  if (!container) return;
  if (expenses.length === 0) {
    container.innerHTML = '<div style="text-align:center;padding:16px;color:var(--color-text-lighter);border:2px dashed #ddd;border-radius:8px;font-size:13px">ยังไม่มีค่าใช้จ่าย</div>';
    calcExpensesTotal();
    return;
  }
  container.innerHTML = expenses.map((item, idx) => {
    return `<div style="display:flex;gap:8px;align-items:center;margin-bottom:6px">
      <input type="text" class="form-control" value="${escapeHtml(item.description || '')}"
        onchange="updateExpense(${idx}, 'description', this.value)"
        placeholder="รายการค่าใช้จ่าย" style="flex:1;padding:8px 12px;font-size:13px">
      <input type="number" class="form-control" min="0" step="0.01" value="${item.amount || ''}"
        onchange="updateExpense(${idx}, 'amount', this.value)"
        placeholder="จำนวนเงิน" style="width:150px;padding:8px 12px;font-size:13px">
      <button class="btn btn-sm btn-danger" onclick="removeExpense(${idx})" style="padding:2px 10px;font-size:13px">ลบ</button>
    </div>`;
  }).join('');
  calcExpensesTotal();
}

function calcExpensesTotal() {
  const el = document.getElementById('totalExpensesDisplay');
  if (!el) return;
  const total = expenses.reduce((s, it) => s + (parseFloat(it.amount) || 0), 0);
  el.textContent = formatCurrency(total);
}

function resetForm() {
  document.getElementById('modalTitle').textContent = 'ขาย Lot ใหม่';
  document.getElementById('buyerName').value = '';
  document.getElementById('saleDate').value = new Date().toISOString().slice(0, 10);
  document.getElementById('branchSelect').value = '';
  document.getElementById('lotNotes').value = '';
  document.getElementById('saveError').style.display = 'none';
  document.getElementById('saveError').textContent = '';
  lineItems = [];
  expenses = [];
  editId = null;
  renderLineItems();
  renderExpenses();
}

async function openModal(id) {
  resetForm();
  if (id) {
    editId = id;
    document.getElementById('modalTitle').textContent = 'แก้ไข Lot ขาย';
    document.getElementById('saveLotBtn').textContent = 'กำลังโหลด...';
    document.getElementById('saveLotBtn').disabled = true;

    const res = await apiRequest(`sale-lots/sale-lot?id=${id}`);
    document.getElementById('saveLotBtn').textContent = 'บันทึก Lot ขาย';
    document.getElementById('saveLotBtn').disabled = false;

    if (res.status !== 'success') {
      showNotification('โหลดข้อมูลไม่สำเร็จ', 'error');
      return;
    }

    const d = res.data;
    document.getElementById('buyerName').value = d.buyer_name || '';
    document.getElementById('saleDate').value = d.sale_date ? d.sale_date.slice(0, 10) : new Date().toISOString().slice(0, 10);
    document.getElementById('branchSelect').value = d.branch_id || '';
    document.getElementById('lotNotes').value = d.notes || '';

    lineItems = (d.items || []).map(it => ({
      key: Date.now() + Math.random(),
      id: it.id,
      category_id: String(it.category_id || ''),
      quantity_kg: it.quantity_kg ?? it.quantity ?? '',
      unit_price: it.unit_price ?? '',
    }));
    renderLineItems();

    expenses = (d.expenses || []).map(it => ({
      key: Date.now() + Math.random(),
      description: it.description || '',
      amount: it.amount || '',
    }));
    renderExpenses();
  }

  document.getElementById('saleLotModal').classList.add('show');
}

async function saveLot() {
  const buyerName = document.getElementById('buyerName').value.trim();
  const saleDate = document.getElementById('saleDate').value;
  const branchId = document.getElementById('branchSelect').value;
  const notes = document.getElementById('lotNotes').value.trim();

  const errorEl = document.getElementById('saveError');
  errorEl.style.display = 'none';

  if (!buyerName) { showNotification('กรุณากรอกชื่อผู้ซื้อ', 'error'); return; }
  if (!branchId) { showNotification('กรุณาเลือกสาขา', 'error'); return; }
  if (lineItems.length === 0) { showNotification('กรุณาเพิ่มรายการสินค้าอย่างน้อย 1 รายการ', 'error'); return; }

  const validItems = lineItems.filter(it => it.category_id && parseFloat(it.quantity_kg) > 0);
  if (validItems.length === 0) {
    showNotification('แต่ละรายการต้องมีหมวดสินค้าและน้ำหนักมากกว่า 0', 'error');
    return;
  }

  const validExpenses = expenses.filter(it => it.description && parseFloat(it.amount) > 0);

  const payload = {
    buyer_name: buyerName,
    sale_date: saleDate,
    branch_id: parseInt(branchId),
    notes: notes || null,
    expenses: validExpenses.length > 0 ? validExpenses.map(it => ({
      description: it.description.trim(),
      amount: parseFloat(it.amount) || 0,
    })) : null,
    items: validItems.map(it => ({
      ...(it.id ? { id: it.id } : {}),
      category_id: parseInt(it.category_id),
      quantity_kg: parseFloat(it.quantity_kg),
      unit_price: parseFloat(it.unit_price) || 0,
    })),
  };

  const btn = document.getElementById('saveLotBtn');
  btn.disabled = true;
  btn.textContent = 'กำลังบันทึก...';

  let res;
  if (editId) {
    res = await apiRequest(`sale-lots/sale-lot?id=${editId}`, 'PUT', payload);
  } else {
    res = await apiRequest('sale-lots', 'POST', payload);
  }

  btn.disabled = false;
  btn.textContent = 'บันทึก Lot ขาย';

  if (res.status === 'success') {
    showNotification(editId ? 'แก้ไข Lot สำเร็จ' : 'บันทึก Lot สำเร็จ', 'success');
    document.getElementById('saleLotModal').classList.remove('show');
    editId = null;
    resetForm();
    loadLots();
  } else {
    errorEl.textContent = res.message || 'บันทึกไม่สำเร็จ';
    errorEl.style.display = 'block';
  }
}

function confirmLot(id, refNo) {
  openConfirmDialog(
    'ยืนยัน Lot ขาย',
    `ต้องการยืนยัน Lot "${refNo}" ใช่หรือไม่? ไม่สามารถแก้ไขได้หลังยืนยัน`,
    async () => {
      const res = await apiRequest(`sale-lots/confirm?id=${id}`, 'POST');
      if (res.status === 'success') {
        showNotification('ยืนยัน Lot สำเร็จ', 'success');
        loadLots();
      } else {
        showNotification(res.message || 'ยืนยันไม่สำเร็จ', 'error');
      }
    }
  );
}

function deleteLot(id, refNo) {
  openConfirmDialog(
    'ลบ Lot ขาย',
    `ต้องการลบ Lot "${refNo}" ใช่หรือไม่? การดำเนินการนี้ไม่สามารถย้อนกลับได้`,
    async () => {
      const res = await apiRequest(`sale-lots/sale-lot?id=${id}`, 'DELETE');
      if (res.status === 'success') {
        showNotification('ลบ Lot สำเร็จ', 'success');
        loadLots();
      } else {
        showNotification(res.message || 'ลบไม่สำเร็จ', 'error');
      }
    }
  );
}

function openConfirmDialog(title, message, callback) {
  document.getElementById('confirmTitle').textContent = title;
  document.getElementById('confirmMessage').textContent = message;
  confirmCallback = callback;
  document.getElementById('confirmModal').classList.add('show');
}

async function viewLot(id) {
  const res = await apiRequest(`sale-lots/sale-lot?id=${id}`);
  if (res.status !== 'success') return;
  const lot = res.data;
  const profit = parseFloat(lot.total_amount || 0) - parseFloat(lot.total_cost || 0);
  const marginPct = lot.total_amount > 0 ? ((profit / lot.total_amount) * 100).toFixed(1) : '0.0';

  const lotExpenses = lot.expenses || [];
  const totalExpenses = lot.profit_breakdown?.total_expenses || lotExpenses.reduce((s, e) => s + (parseFloat(e.amount) || 0), 0);
  const netProfit = lot.profit_breakdown?.net_profit || profit - totalExpenses;

  const html = `
    <div class="receipt">
      <h3 style="text-align:center;margin:0">รายละเอียด Lot ขาย</h3>
      <div style="text-align:center;color:#888;margin-bottom:12px">${escapeHtml(lot.reference_no)}</div>
      <div><strong>สาขา:</strong> ${escapeHtml(lot.branch_name || '-')}</div>
      <div><strong>วันที่ขาย:</strong> ${lot.sale_date ? lot.sale_date.slice(0, 10) : '-'}</div>
      <div><strong>ผู้ซื้อ:</strong> ${escapeHtml(lot.buyer_name || '-')}</div>
      <div><strong>พนักงาน:</strong> ${escapeHtml(lot.created_by_name || '-')}</div>
      <div style="margin-top:4px"><strong>สถานะ:</strong> ${statusBadgeHtml(lot.status)}</div>
      <hr>
      <table class="data-table" style="width:100%">
        <thead><tr><th>หมวดสินค้า</th><th class="text-right">น้ำหนัก (กก.)</th><th class="text-right">ราคา/กก.</th><th class="text-right">รวม</th><th class="text-right">ต้นทุน</th></tr></thead>
        <tbody>
          ${(lot.items || []).map(it => `
            <tr>
              <td>${escapeHtml(it.category_name || '-')}</td>
              <td class="text-right">${parseFloat(it.quantity_kg).toFixed(3)}</td>
              <td class="text-right">${formatCurrency(it.unit_price)}</td>
              <td class="text-right">${formatCurrency(it.subtotal)}</td>
              <td class="text-right" style="color:var(--color-text-light)">${formatCurrency(it.fifo_cost)}</td>
            </tr>
          `).join('')}
        </tbody>
      </table>
      <hr>
      <div style="display:flex;justify-content:space-between">
        <div>
          <div style="font-size:13px;color:#888">ยอดขายรวม</div>
          <div style="font-size:18px;font-weight:700">${formatCurrency(lot.total_amount)}</div>
        </div>
        <div style="text-align:right">
          <div style="font-size:13px;color:#888">ต้นทุนรวม</div>
          <div style="font-size:18px;font-weight:700">${formatCurrency(lot.total_cost)}</div>
        </div>
        <div style="text-align:right">
          <div style="font-size:13px;color:#888">กำไรขั้นต้น</div>
          <div style="font-size:18px;font-weight:700;${profit >= 0 ? 'color:var(--color-success)' : 'color:var(--color-danger)'}">
            ${profit >= 0 ? '+' : ''}${formatCurrency(profit)}
            <span style="font-size:13px;font-weight:400">(${marginPct}%)</span>
          </div>
        </div>
      </div>
      ${lotExpenses.length > 0 ? `
      <hr>
      <div style="font-size:14px;font-weight:500;margin-bottom:6px">ค่าใช้จ่าย</div>
      ${lotExpenses.map(e => `
        <div style="display:flex;justify-content:space-between;font-size:13px;padding:3px 0">
          <span style="color:var(--color-text-light)">${escapeHtml(e.description)}</span>
          <span>${formatCurrency(e.amount)}</span>
        </div>
      `).join('')}
      <div style="display:flex;justify-content:space-between;font-size:13px;padding:3px 0;border-top:1px solid #ddd;margin-top:3px">
        <span style="font-weight:500">รวมค่าใช้จ่าย</span>
        <span style="font-weight:500;color:var(--color-danger)">-${formatCurrency(totalExpenses)}</span>
      </div>
      <hr>
      <div style="display:flex;justify-content:space-between">
        <div>
          <div style="font-size:13px;color:#888">กำไรสุทธิ</div>
          <div style="font-size:18px;font-weight:700;${netProfit >= 0 ? 'color:var(--color-success)' : 'color:var(--color-danger)'}">
            ${netProfit >= 0 ? '+' : ''}${formatCurrency(netProfit)}
          </div>
        </div>
      </div>
      ` : ''}
      ${lot.notes ? `<div style="margin-top:8px"><strong>หมายเหตุ:</strong> ${escapeHtml(lot.notes)}</div>` : ''}
    </div>
  `;
  document.getElementById('viewLotContent').innerHTML = html;
  document.getElementById('viewLotModal').classList.add('show');
}
