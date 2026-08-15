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

  calcTransportNet();

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
  showTableLoading('lotTableBody', 10, 5);
  const res = await apiRequest('sale-lots');
  if (res.status !== 'success') {
    document.getElementById('lotTableBody').innerHTML = '<tr><td colspan="10" style="text-align:center;color:#888;padding:24px">โหลดข้อมูลไม่สำเร็จ</td></tr>';
    showNotification('โหลดข้อมูลไม่สำเร็จ', 'error');
    return;
  }
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
    tbody.innerHTML = '<tr><td colspan="10" style="text-align:center;color:#888;padding:24px">' +
      (q ? 'ไม่พบรายการที่ค้นหา' : 'ยังไม่มีรายการขาย Lot') + '</td></tr>';
    return;
  }

  tbody.innerHTML = filtered.map(lot => {
    const transport = parseFloat(lot.transport_cost || 0);
    const profit = parseFloat(lot.total_amount || 0) - parseFloat(lot.total_cost || 0);
    const netProfit = profit - transport;
    const profitClass = profit >= 0 ? 'profit-positive' : 'profit-negative';
    const profitSign = profit >= 0 ? '+' : '';
    return `<tr>
      <td><a href="#" onclick="viewLot(${lot.id});return false" style="font-family:monospace;font-size:12px">${escapeHtml(lot.reference_no || `SL-${lot.id}`)}</a></td>
      <td style="color:var(--color-text-light);font-size:13px">${lot.sale_date ? lot.sale_date.slice(0, 10) : formatDateTime(lot.created_at)}</td>
      <td>${escapeHtml(lot.buyer_name || '-')}</td>
      <td class="text-right">${formatCurrency(lot.total_amount)}</td>
      <td class="text-right" style="color:var(--color-text-light)">${formatCurrency(lot.total_cost)}</td>
      <td class="text-right">
        ${lot.actual_revenue != null
          ? `<span style="color:var(--color-success);font-weight:600">฿${parseFloat(lot.actual_revenue).toLocaleString('th-TH',{minimumFractionDigits:2})}</span>
             <br><span style="font-size:10px;color:var(--color-text-light)">${lot.revenue_payment_method === 'cash' ? 'เงินสด' : 'โอนธนาคาร'}</span>`
          : `<span style="color:var(--color-text-lighter);font-size:12px">ยังไม่บันทึก</span>`
        }
      </td>
      <td class="text-right">
        <span class="${profitClass}">${profitSign}${formatCurrency(profit)}</span>
        ${transport > 0 ? `<br><span style="font-size:10px;color:#888">สุทธิ ${profitSign}${formatCurrency(netProfit)}</span>` : ''}
      </td>
      <td>${statusBadgeHtml(lot.status)}</td>
      <td>
        <div class="action-cell">
          ${lot.status === 'draft' ? `
            <button class="btn btn-sm btn-success" onclick="confirmLot(${lot.id})" title="ยืนยัน Lot → ตัดสต็อก">✓</button>
            <button class="btn btn-sm btn-secondary" onclick="openModal(${lot.id})" title="แก้ไข Lot (แบบร่าง)">✎</button>
            <button class="btn btn-sm btn-danger" onclick="deleteLot(${lot.id}, '${escapeHtml(lot.reference_no || '')}')" title="ลบ Lot (แบบร่าง)">✕</button>
          ` : ''}
          ${lot.status === 'confirmed' ? `
            ${lot.actual_revenue == null
              ? `<button class="btn btn-sm btn-primary" onclick="openRevenueModal(${lot.id}, '${escapeHtml(lot.reference_no || '')}')" title="บันทึกรายรับจากบิลโรงงาน">💰</button>`
              : '<button class="btn btn-sm btn-secondary" type="button" disabled title="บันทึกรายรับแล้ว">💰</button>'}
            ${lot.actual_revenue == null
              ? `<button class="btn btn-sm btn-danger" onclick="cancelLot(${lot.id}, '${escapeHtml(lot.reference_no || '')}')" title="ยกเลิก Lot → คืนสต็อก">✕</button>`
              : ''}
          ` : ''}
          ${lot.status === 'cancelled' ? `
            <button class="btn btn-sm btn-danger" onclick="deleteLot(${lot.id}, '${escapeHtml(lot.reference_no || '')}')" style="padding:3px 8px" title="ลบ Lot ที่ยกเลิก">✕</button>
          ` : ''}
        </div>
      </td>
      <td style="font-size:11px;color:#888;text-align:center">
        ${lot.status === 'draft' ? 'บันทึกก่อน<br>ตัดสต็อก' : ''}
        ${lot.status === 'confirmed' && lot.actual_revenue == null ? 'กรอกบิล<br>โรงงาน' : ''}
        ${lot.status === 'confirmed' && lot.actual_revenue != null ? '✓ ครบ' : ''}
        ${lot.status === 'cancelled' ? 'ยกเลิกแล้ว' : ''}
      </td>
    </tr>`;
  }).join('');
}

function addLineItem() {
  lineItems.push({ key: Date.now() + Math.random(), catalog_id: '', item_name: '', category_id: '', quantity_kg: '', unit_price: '' });
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
        <div class="form-group" style="margin:0;position:relative">
          <label style="font-size:11px;color:var(--color-text-lighter)">ชื่อสินค้า (จากแคตตาล็อก)</label>
          <input type="text" class="form-control" placeholder="พิมพ์ค้นหา..."
            value="${escapeHtml(item.item_name || '')}"
            id="sl-item-${idx}"
            autocomplete="off"
            oninput="searchSlCatalog(${idx}, this.value)"
            onfocus="searchSlCatalog(${idx}, this.value)">
          <div id="sl-results-${idx}" class="seller-results" style="display:none;position:absolute;z-index:100;width:100%;background:#fff;border:1px solid #ddd;border-radius:4px;max-height:200px;overflow-y:auto"></div>
        </div>
        <div class="form-group" style="margin:0">
          <label style="font-size:11px;color:var(--color-text-lighter)">น้ำหนัก (กก.)</label>
          <input type="number" class="form-control" min="0" step="0.01" value="${item.quantity_kg}"
            onchange="updateLineItem(${idx}, 'quantity_kg', this.value)">
        </div>
        <div class="form-group" style="margin:0">
          <label style="font-size:11px;color:var(--color-text-lighter)">ราคา/กก. (฿)</label>
          <input type="number" class="form-control" min="0" step="0.01" value="${item.unit_price}"
            onchange="updateLineItem(${idx}, 'unit_price', this.value)">
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

// Catalog search สำหรับ sale-lot line items
let slCatalogTimers = {};
async function searchSlCatalog(idx, q) {
  clearTimeout(slCatalogTimers[idx]);
  const box = document.getElementById(`sl-results-${idx}`);
  if (!box) return;
  if (!q || q.length < 1) { box.style.display = 'none'; return; }

  slCatalogTimers[idx] = setTimeout(async () => {
    const res = await apiRequest(`purchase-catalog/search?q=${encodeURIComponent(q)}`);
    if (!box) return;
    const items = res.status === 'success' ? (res.data || []) : [];
    if (!items.length) { box.style.display = 'none'; return; }

    box.innerHTML = '';
    items.forEach(it => {
      const tiers = it.tier_prices || [];
      const priceInfo = tiers.length ? ` · ${tiers[0].price} ฿/${it.default_unit||'กก.'}` : '';
      const div = document.createElement('div');
      div.className = 'seller-item';
      div.style.cursor = 'pointer';
      div.style.padding = '8px 10px';
      div.style.borderBottom = '1px solid #f0f0f0';
      div.innerHTML = `<strong>${escapeHtml(it.code)}</strong> — ${escapeHtml(it.name)}
        <span style="color:#888;font-size:12px">${it.category_name ? `(${escapeHtml(it.category_name)})` : ''}${priceInfo}</span>`;
      div.addEventListener('click', () => {
        selectSlCatalog(idx, it.id, it.name, it.category_id || 0, it.default_price || 0);
      });
      box.appendChild(div);
    });
    box.style.display = 'block';
  }, 250);
}

function selectSlCatalog(idx, catalogId, name, categoryId, price) {
  lineItems[idx] = { ...lineItems[idx], catalog_id: catalogId, item_name: name, category_id: categoryId, unit_price: price > 0 ? price : lineItems[idx].unit_price };
  renderLineItems();
  // ปิด dropdown หลัง render
  setTimeout(() => {
    const box = document.getElementById(`sl-results-${idx}`);
    if (box) box.style.display = 'none';
  }, 50);
}

// ปิด dropdown เมื่อคลิกที่อื่น
document.addEventListener('click', e => {
  if (!e.target.closest('[id^="sl-item-"]') && !e.target.closest('[id^="sl-results-"]')) {
    document.querySelectorAll('[id^="sl-results-"]').forEach(el => el.style.display = 'none');
  }
});

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

function calcTransportNet() {
  const transport = parseFloat(document.getElementById('transportCost').value) || 0;
  document.getElementById('transportCostDisplay').textContent = formatCurrency(transport);
}

function calcExpensesTotal() {
  const el = document.getElementById('totalExpensesDisplay');
  if (!el) return;
  const total = expenses.reduce((s, it) => s + (parseFloat(it.amount) || 0), 0);
  el.textContent = formatCurrency(total);
}

function resetForm() {
  document.getElementById('modalTitle').textContent = 'สร้าง Lot แบบร่าง';
  document.getElementById('saveLotBtn').textContent = 'ยืนยันขาย (ตัดสต็อก)';
  document.getElementById('buyerName').value = '';
  document.getElementById('saleDate').value = new Date().toISOString().slice(0, 10);
  document.getElementById('branchSelect').value = '';
  document.getElementById('transportCost').value = '0';
  document.getElementById('lotNotes').value = '';
  document.getElementById('saveError').style.display = 'none';
  document.getElementById('saveError').textContent = '';
  lineItems = [];
  expenses = [];
  editId = null;
  renderLineItems();
  renderExpenses();
  calcTransportNet();
}

async function openModal(id) {
  resetForm();
  if (id) {
    editId = id;
    document.getElementById('modalTitle').textContent = 'แก้ไข Lot';
    document.getElementById('saveLotBtn').textContent = 'กำลังโหลด...';
    document.getElementById('saveLotBtn').disabled = true;

    const res = await apiRequest(`sale-lots/sale-lot?id=${id}`);
    document.getElementById('saveLotBtn').textContent = 'ยืนยันขาย (ตัดสต็อก)';
    document.getElementById('saveLotBtn').disabled = false;

    if (res.status !== 'success') {
      showNotification('โหลดข้อมูลไม่สำเร็จ', 'error');
      return;
    }

    const d = res.data;
    document.getElementById('buyerName').value = d.buyer_name || '';
    document.getElementById('saleDate').value = d.sale_date ? d.sale_date.slice(0, 10) : new Date().toISOString().slice(0, 10);
    document.getElementById('branchSelect').value = d.branch_id || '';
    document.getElementById('transportCost').value = d.transport_cost || '0';
    document.getElementById('lotNotes').value = d.notes || '';
    calcTransportNet();

    lineItems = (d.items || []).map(it => ({
      key: Date.now() + Math.random(),
      id: it.id,
      catalog_id: it.catalog_id || '',
      item_name: it.item_name || it.category_name || '',
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

  const validItems = [];
  for (const it of lineItems) {
    const hasAnyValue = [it.catalog_id, it.item_name, it.category_id, it.quantity_kg, it.unit_price]
      .some(value => String(value ?? '').trim() !== '');
    if (!hasAnyValue) continue;

    const itemName = String(it.item_name || '').trim();
    const quantityKg = parseFloat(it.quantity_kg);
    const categoryId = parseInt(it.category_id, 10);

    if (!itemName || !Number.isFinite(quantityKg) || quantityKg <= 0 || !Number.isFinite(categoryId) || categoryId <= 0) {
      showNotification('แต่ละรายการต้องเลือกสินค้าจากแคตตาล็อกและระบุน้ำหนักมากกว่า 0', 'error');
      return;
    }

    validItems.push({
      ...(it.id ? { id: it.id } : {}),
      catalog_id: it.catalog_id ? parseInt(it.catalog_id, 10) : null,
      item_name: itemName,
      category_id: categoryId,
      quantity_kg: quantityKg,
      unit_price: parseFloat(it.unit_price) || 0,
    });
  }

  if (validItems.length === 0) {
    showNotification('กรุณาเพิ่มรายการสินค้าจากแคตตาล็อกอย่างน้อย 1 รายการ', 'error');
    return;
  }

  const validExpenses = expenses.filter(it => it.description && parseFloat(it.amount) > 0);

  const transportCost = parseFloat(document.getElementById('transportCost').value) || 0;

  const idempotencyKey = Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 8);

  const payload = {
    buyer_name: buyerName,
    sale_date: saleDate,
    branch_id: parseInt(branchId),
    transport_cost: transportCost,
    notes: notes || null,
    expenses: validExpenses.length > 0 ? validExpenses.map(it => ({
      description: it.description.trim(),
      amount: parseFloat(it.amount) || 0,
    })) : null,
    items: validItems,
    idempotency_key: idempotencyKey,
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
  btn.textContent = 'ยืนยันขาย (ตัดสต็อก)';

  if (res.status === 'success') {
    if (editId) {
      // แก้ไขแบบร่าง — ยังไม่ตัดสต็อก (ต้องกด ✓ ยืนยันในตาราง)
      showNotification('แก้ไข Lot สำเร็จ (แบบร่าง — ยังไม่ตัดสต็อก)', 'success');
      document.getElementById('saleLotModal').classList.remove('show');
      editId = null;
      resetForm();
      loadLots();
      return;
    }

    // LOT-CONFIRM-FIX: บันทึก = ยืนยันทันที (ตัดสต็อก)
    const newLotId = res.data && res.data.id;
    if (!newLotId) {
      showNotification('สร้าง Lot สำเร็จ แต่ไม่พบ ID — กด ✓ ในตารางเพื่อยืนยัน', 'error');
      document.getElementById('saleLotModal').classList.remove('show');
      editId = null;
      resetForm();
      loadLots();
      return;
    }

    btn.disabled = true;
    btn.textContent = 'กำลังยืนยัน (ตัดสต็อก)...';
    try {
      const confirmRes = await apiRequest(`sale-lots/confirm?id=${newLotId}`, 'POST');
      if (confirmRes.status === 'success') {
        showNotification('ยืนยันขาย Lot สำเร็จ (ตัดสต็อกแล้ว)', 'success');
      } else {
        // ยืนยันไม่สำเร็จ (เช่น สต็อกไม่พอ) → ลบแบบร่างทิ้ง ไม่ให้ค้าง
        try { await apiRequest(`sale-lots/sale-lot?id=${newLotId}`, 'DELETE'); } catch (e) {}
        errorEl.textContent = confirmRes.message || 'ยืนยันขายไม่สำเร็จ (สต็อกไม่พอ?)';
        errorEl.style.display = 'block';
        showNotification(confirmRes.message || 'ยืนยันขายไม่สำเร็จ', 'error');
        btn.disabled = false;
        btn.textContent = 'ยืนยันขาย (ตัดสต็อก)';
        return;
      }
    } catch (e) {
      // เน็ตขัดข้อง — เก็บแบบร่างไว้ ให้กด ✓ ยืนยันเองในตารางได้
      showNotification('บันทึกสำเร็จ แต่ยืนยันขัดข้อง — กด ✓ ในตารางเพื่อยืนยันและตัดสต็อก', 'error');
    }
    document.getElementById('saleLotModal').classList.remove('show');
    editId = null;
    resetForm();
    loadLots();
  } else {
    errorEl.textContent = res.message || 'บันทึกไม่สำเร็จ';
    errorEl.style.display = 'block';
  }
}

async function confirmLot(id) {
  const res = await apiRequest(`sale-lots/confirm?id=${id}`, 'POST');
  if (res.status === 'success') {
    showNotification('ยืนยัน Lot สำเร็จ', 'success');
    loadLots();
  } else {
    showNotification(res.message || 'ยืนยันไม่สำเร็จ', 'error');
  }
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

function cancelLot(id, refNo) {
  openConfirmDialog(
    'ยกเลิก Lot ขาย',
    `ต้องการยกเลิก Lot "${refNo}" ใช่หรือไม่? สต็อกจะถูกคืนกลับ`,
    async () => {
      const res = await apiRequest(`sale-lots/cancel?id=${id}`, 'POST', {});
      if (res.status === 'success') {
        showNotification('ยกเลิก Lot สำเร็จ', 'success');
        loadLots();
      } else {
        showNotification(res.message || 'ยกเลิกไม่สำเร็จ', 'error');
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

  const lotTransport = parseFloat(lot.transport_cost || 0);
  const lotExpenses = lot.expenses || [];
  const otherExpenses = lotExpenses.reduce((s, e) => s + (parseFloat(e.amount) || 0), 0);
  const totalExpenses = lot.profit_breakdown?.total_expenses || otherExpenses + lotTransport;
  const netProfit = lot.profit_breakdown?.net_profit || profit - totalExpenses;

  const html = `
    <div class="receipt">
      <h3 style="text-align:center;margin:0">รายละเอียด Lot ขาย</h3>
      <div style="text-align:center;color:#888;margin-bottom:12px">${escapeHtml(lot.reference_no)}</div>
      <div><strong>สาขา:</strong> ${escapeHtml(lot.branch_name || '-')}</div>
      <div><strong>วันที่ขาย:</strong> ${lot.sale_date ? lot.sale_date.slice(0, 10) : '-'}</div>
      <div><strong>ผู้ซื้อ:</strong> ${escapeHtml(lot.buyer_name || '-')}</div>
      <div><strong>พนักงาน:</strong> ${escapeHtml(lot.created_by_name || '-')}</div>
      ${lot.updated_by_name ? `<div><strong>แก้ไขโดย:</strong> ${escapeHtml(lot.updated_by_name)}</div>` : ''}
      <div style="margin-top:4px"><strong>สถานะ:</strong> ${statusBadgeHtml(lot.status)}</div>
      <hr>
      <table class="data-table" style="width:100%">
        <thead><tr><th>ชื่อสินค้า</th><th class="text-right">น้ำหนัก (กก.)</th><th class="text-right">ราคา/กก.</th><th class="text-right">รวม</th><th class="text-right">ต้นทุน</th></tr></thead>
        <tbody>
          ${(lot.items || []).map(it => `
            <tr>
              <td>${escapeHtml(it.item_name || it.category_name || '-')}</td>
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
      <hr>
      <div style="font-size:14px;font-weight:500;margin-bottom:6px">ค่าใช้จ่าย</div>
      ${lotTransport > 0 ? `
      <div style="display:flex;justify-content:space-between;font-size:13px;padding:3px 0">
        <span style="color:var(--color-text-light)">🚛 ค่าขนส่ง</span>
        <span>${formatCurrency(lotTransport)}</span>
      </div>
      ` : ''}
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
      ${lot.notes ? `<div style="margin-top:8px"><strong>หมายเหตุ:</strong> ${escapeHtml(lot.notes)}</div>` : ''}
    </div>
  `;
  document.getElementById('viewLotContent').innerHTML = html;
  document.getElementById('viewLotModal').classList.add('show');
}

// ---- บันทึกรายรับจริงจากบิลศูนย์ ----
let revenueTargetId = null;

function openRevenueModal(id, refNo) {
  revenueTargetId = id;
  document.getElementById('revenueModalTitle').textContent = `บันทึกรายรับ — ${refNo}`;
  document.getElementById('revenueAmount').value = '';
  document.getElementById('revenuePaymentMethod').value = 'bank_transfer';
  document.getElementById('revenueNote').value = '';
  document.getElementById('revenueDate').value = localDateString(new Date());
  document.getElementById('revenueError').textContent = '';
  document.getElementById('revenueModal').classList.add('show');
}

async function saveRevenue() {
  const amount = parseFloat(document.getElementById('revenueAmount').value);
  const note   = document.getElementById('revenueNote').value.trim();
  const date   = document.getElementById('revenueDate').value;
  const paymentMethod = document.getElementById('revenuePaymentMethod').value;
  const errEl  = document.getElementById('revenueError');

  if (isNaN(amount) || amount < 0) {
    errEl.textContent = 'กรุณากรอกยอดรายรับที่ถูกต้อง';
    return;
  }

  const btn = document.getElementById('saveRevenueBtn');
  btn.disabled = true;
  btn.textContent = 'กำลังบันทึก...';

  try {
    const res = await apiRequest(`sale-lots/record-revenue?id=${revenueTargetId}`, 'POST', {
      actual_revenue:      amount,
      actual_revenue_note: note || null,
      actual_revenue_date: date,
      payment_method: paymentMethod,
    });
    if (res.status === 'success') {
      document.getElementById('revenueModal').classList.remove('show');
      showNotification('บันทึกรายรับสำเร็จ', 'success');
      loadLots();
    } else {
      errEl.textContent = res.message || 'บันทึกไม่สำเร็จ';
    }
  } catch (e) {
    errEl.textContent = 'เกิดข้อผิดพลาด กรุณาลองใหม่';
  }

  btn.disabled = false;
  btn.textContent = 'บันทึก';
}

function localDateString(date) {
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, '0');
  const day = String(date.getDate()).padStart(2, '0');
  return `${year}-${month}-${day}`;
}
