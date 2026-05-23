// purchase-orders.js — รับซื้อของเก่า (POS รับซื้อ)
let branches = [];
let conditions = [];
let categories = [];
let cart = [];
let selectedSeller = null;
let recentPOs = [];

document.addEventListener('DOMContentLoaded', async () => {
  await Promise.all([loadBranches(), loadConditions(), loadCategories()]);
  await loadRecentPOs();

  document.getElementById('searchSellerInput').addEventListener('input', debounce(searchSellers, 300));
  document.getElementById('createNewSellerBtn').addEventListener('click', () => openNewSellerModal());
  document.getElementById('saveNewSellerBtn').addEventListener('click', saveNewSeller);
  document.getElementById('addItemBtn').addEventListener('click', addItemToCart);
  document.getElementById('savePOBtn').addEventListener('click', savePurchaseOrder);
  document.getElementById('clearPOBtn').addEventListener('click', clearAll);

  document.querySelectorAll('.close-modal').forEach(b => {
    b.addEventListener('click', e => e.target.closest('.modal').classList.remove('show'));
  });

  document.getElementById('itemUnitPrice').addEventListener('input', updateItemTotal);
  document.getElementById('itemQuantity').addEventListener('input', updateItemTotal);
  document.getElementById('itemConditionId').addEventListener('change', applyConditionMultiplier);

  document.getElementById('viewPOModalClose').addEventListener('click', () =>
    document.getElementById('viewPOModal').classList.remove('show'));
});

function debounce(fn, ms) {
  let t;
  return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); };
}

async function loadBranches() {
  const res = await apiRequest('branches/active');
  if (res.status === 'success') {
    branches = res.data;
    const sel = document.getElementById('branchSelect');
    sel.innerHTML = branches.map(b =>
      `<option value="${b.id}">${escapeHtml(b.name)} (${b.code})</option>`
    ).join('');
  }
}

async function loadConditions() {
  const res = await apiRequest('item-conditions');
  if (res.status === 'success') {
    conditions = res.data;
    const sel = document.getElementById('itemConditionId');
    sel.innerHTML = '<option value="">-- เลือกสภาพ --</option>' +
      conditions.map(c =>
        `<option value="${c.id}" data-mult="${c.price_multiplier}">${escapeHtml(c.name)} (x${c.price_multiplier})</option>`
      ).join('');
  }
}

async function loadCategories() {
  const res = await apiRequest('inventory/categories');
  if (res.status === 'success') {
    categories = res.data || [];
    const sel = document.getElementById('itemCategoryId');
    sel.innerHTML = '<option value="">-- เลือกหมวด --</option>' +
      categories.map(c => `<option value="${c.id}">${escapeHtml(c.name)}</option>`).join('');
  }
}

async function searchSellers() {
  const q = document.getElementById('searchSellerInput').value.trim();
  const list = document.getElementById('sellerSearchResults');
  if (!q) { list.innerHTML = ''; list.style.display = 'none'; return; }

  const res = await apiRequest(`sellers/search?q=${encodeURIComponent(q)}`);
  if (res.status !== 'success') return;
  const items = res.data || [];
  list.innerHTML = '';
  if (items.length === 0) {
    const empty = document.createElement('div');
    empty.className = 'seller-item';
    empty.style.color = '#888';
    empty.textContent = 'ไม่พบผู้ขาย — กดปุ่ม "+ ผู้ขายใหม่"';
    list.appendChild(empty);
  } else {
    items.forEach(s => {
      const row = document.createElement('div');
      row.className = 'seller-item';
      const parts = [`<strong>${escapeHtml(s.full_name)}</strong>`];
      if (s.id_card) parts.push(`<span style="color:#888;margin-left:8px">${maskIdCard(s.id_card)}</span>`);
      if (s.phone) parts.push(`<span style="color:#888;margin-left:8px">${escapeHtml(s.phone)}</span>`);
      if (s.is_blacklisted == 1) parts.push('<span style="color:#d32f2f;font-weight:bold;margin-left:8px">[Blacklist]</span>');
      row.innerHTML = parts.join('');
      row.addEventListener('click', () => selectSeller(s));
      list.appendChild(row);
    });
  }
  list.style.display = 'block';
}

function selectSeller(s) {
  if (s.is_blacklisted == 1) {
    if (!confirm('ผู้ขายนี้ Blacklist ยืนยันรับซื้อหรือไม่?')) return;
  }
  selectedSeller = s;
  const box = document.getElementById('selectedSellerBox');
  box.innerHTML = '';
  const wrap = document.createElement('div');
  wrap.className = 'selected-seller';
  const name = document.createElement('div');
  name.innerHTML = `<strong>${escapeHtml(s.full_name)}</strong>`;
  wrap.appendChild(name);
  if (s.id_card) {
    const idLine = document.createElement('div');
    idLine.textContent = `เลขบัตร: ${maskIdCard(s.id_card)}`;
    wrap.appendChild(idLine);
  }
  if (s.phone) {
    const phoneLine = document.createElement('div');
    phoneLine.textContent = `โทร: ${s.phone}`;
    wrap.appendChild(phoneLine);
  }
  const btn = document.createElement('button');
  btn.className = 'btn btn-sm btn-secondary';
  btn.textContent = 'เปลี่ยนผู้ขาย';
  btn.addEventListener('click', clearSeller);
  wrap.appendChild(btn);
  box.appendChild(wrap);
  document.getElementById('searchSellerInput').value = '';
  document.getElementById('sellerSearchResults').style.display = 'none';
}

function clearSeller() {
  selectedSeller = null;
  document.getElementById('selectedSellerBox').innerHTML = '<div style="color:#888">ยังไม่ได้เลือกผู้ขาย</div>';
}

function openNewSellerModal() {
  document.getElementById('newSellerModal').classList.add('show');
  document.getElementById('newFullName').value = document.getElementById('searchSellerInput').value || '';
  document.getElementById('newIdCard').value = '';
  document.getElementById('newPhone').value = '';
}

async function saveNewSeller() {
  const payload = {
    full_name: document.getElementById('newFullName').value.trim(),
    id_card: document.getElementById('newIdCard').value.replace(/\D/g, ''),
    phone: document.getElementById('newPhone').value.trim(),
  };
  if (!payload.full_name) { showNotification('กรุณากรอกชื่อ', 'error'); return; }
  if (payload.id_card && payload.id_card.length !== 13) {
    showNotification('เลขบัตรต้อง 13 หลัก', 'error'); return;
  }
  const res = await apiRequest('sellers', 'POST', payload);
  if (res.status === 'success') {
    showNotification('เพิ่มผู้ขายสำเร็จ', 'success');
    document.getElementById('newSellerModal').classList.remove('show');
    selectSeller({ ...payload, id: res.data.id, is_blacklisted: 0 });
  } else {
    showNotification(res.message || 'ผิดพลาด', 'error');
  }
}

function applyConditionMultiplier() {
  const sel = document.getElementById('itemConditionId');
  const opt = sel.options[sel.selectedIndex];
  const mult = parseFloat(opt?.dataset.mult || 1);
  const basePriceField = document.getElementById('itemBasePrice');
  const base = parseFloat(basePriceField.value || 0);
  if (base > 0) {
    document.getElementById('itemUnitPrice').value = (base * mult).toFixed(2);
    updateItemTotal();
  }
}

function updateItemTotal() {
  const q = parseFloat(document.getElementById('itemQuantity').value || 0);
  const p = parseFloat(document.getElementById('itemUnitPrice').value || 0);
  document.getElementById('itemTotalPreview').textContent = formatCurrency(q * p);
}

function addItemToCart() {
  const name = document.getElementById('itemName').value.trim();
  const condId = document.getElementById('itemConditionId').value;
  const condName = document.getElementById('itemConditionId').selectedOptions[0]?.text || '';
  const catId = document.getElementById('itemCategoryId').value;
  const qty = parseFloat(document.getElementById('itemQuantity').value || 0);
  const unit = document.getElementById('itemUnit').value || 'ชิ้น';
  const price = parseFloat(document.getElementById('itemUnitPrice').value || 0);
  const notes = document.getElementById('itemNotes').value.trim();

  if (!name) { showNotification('กรุณากรอกชื่อของ', 'error'); return; }
  if (!condId) { showNotification('กรุณาเลือกสภาพ', 'error'); return; }
  if (qty <= 0) { showNotification('จำนวนต้องมากกว่า 0', 'error'); return; }
  if (price <= 0) { showNotification('ราคาต้องมากกว่า 0', 'error'); return; }

  cart.push({
    item_name: name,
    condition_id: parseInt(condId),
    condition_name: condName,
    category_id: catId ? parseInt(catId) : null,
    quantity: qty,
    unit,
    unit_price: price,
    total_price: qty * price,
    notes,
  });

  // reset
  document.getElementById('itemName').value = '';
  document.getElementById('itemBasePrice').value = '';
  document.getElementById('itemUnitPrice').value = '';
  document.getElementById('itemQuantity').value = '1';
  document.getElementById('itemNotes').value = '';
  document.getElementById('itemTotalPreview').textContent = formatCurrency(0);

  renderCart();
}

window.removeFromCart = function(idx) {
  cart.splice(idx, 1);
  renderCart();
};

function renderCart() {
  const tbody = document.querySelector('#cartTable tbody');
  if (cart.length === 0) {
    tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;color:#888">ยังไม่มีรายการ</td></tr>';
  } else {
    tbody.innerHTML = cart.map((it, i) => `
      <tr>
        <td>${escapeHtml(it.item_name)}</td>
        <td>${escapeHtml(it.condition_name)}</td>
        <td>${it.quantity} ${escapeHtml(it.unit)}</td>
        <td>${formatCurrency(it.unit_price)}</td>
        <td><strong>${formatCurrency(it.total_price)}</strong></td>
        <td><button class="btn btn-sm btn-danger" onclick="removeFromCart(${i})">ลบ</button></td>
      </tr>
    `).join('');
  }
  const total = cart.reduce((s, it) => s + it.total_price, 0);
  const totalQty = cart.reduce((s, it) => s + it.quantity, 0);
  document.getElementById('cartTotalAmount').textContent = formatCurrency(total);
  document.getElementById('cartTotalItems').textContent = `${totalQty} ชิ้น (${cart.length} รายการ)`;
}

async function savePurchaseOrder() {
  if (!selectedSeller) { showNotification('กรุณาเลือกผู้ขาย', 'error'); return; }
  if (cart.length === 0) { showNotification('กรุณาเพิ่มรายการสินค้า', 'error'); return; }

  const payload = {
    branch_id: parseInt(document.getElementById('branchSelect').value),
    seller_id: selectedSeller.id,
    payment_method: document.getElementById('paymentMethod').value,
    notes: document.getElementById('poNotes').value.trim(),
    items: cart.map(it => ({
      item_name: it.item_name,
      condition_id: it.condition_id,
      category_id: it.category_id,
      quantity: it.quantity,
      unit: it.unit,
      unit_price: it.unit_price,
      total_price: it.total_price,
      notes: it.notes,
    })),
  };

  const btn = document.getElementById('savePOBtn');
  btn.disabled = true;
  btn.textContent = 'กำลังบันทึก...';

  const res = await apiRequest('purchase-orders', 'POST', payload);

  btn.disabled = false;
  btn.innerHTML = '<i class="icon-additem"></i> บันทึกใบรับซื้อ';

  if (res.status === 'success') {
    const ref = res.data.reference_no;
    const amt = formatCurrency(res.data.total_amount);
    showNotification(`บันทึกสำเร็จ! เลขที่: ${ref} ยอดรวม ${amt}`, 'success');
    showReceipt(res.data.id);
    clearAll();
    loadRecentPOs();
  } else {
    showNotification(res.message || 'บันทึกไม่สำเร็จ', 'error');
  }
}

function clearAll() {
  cart = [];
  selectedSeller = null;
  document.getElementById('selectedSellerBox').innerHTML = '<div style="color:#888">ยังไม่ได้เลือกผู้ขาย</div>';
  document.getElementById('poNotes').value = '';
  renderCart();
}

async function loadRecentPOs() {
  const res = await apiRequest('purchase-orders?limit=10');
  if (res.status !== 'success') return;
  recentPOs = res.data.items || [];
  const tbody = document.querySelector('#recentPOTable tbody');
  if (recentPOs.length === 0) {
    tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;color:#888">ยังไม่มีใบรับซื้อ</td></tr>';
    return;
  }
  tbody.innerHTML = recentPOs.map(po => `
    <tr>
      <td><a href="#" onclick="showReceipt(${po.id});return false">${escapeHtml(po.reference_no)}</a></td>
      <td>${escapeHtml(po.branch_name || '-')}</td>
      <td>${escapeHtml(po.seller_name || '-')}</td>
      <td>${po.total_items}</td>
      <td><strong>${formatCurrency(po.total_amount)}</strong></td>
      <td>${formatDateTime(po.created_at)}</td>
    </tr>
  `).join('');
}

window.showReceipt = async function(id) {
  const res = await apiRequest(`purchase-orders/order?id=${id}`);
  if (res.status !== 'success') return;
  const po = res.data;
  const html = `
    <div class="receipt">
      <h3 style="text-align:center;margin:0">ใบรับซื้อของเก่า</h3>
      <div style="text-align:center;color:#888;margin-bottom:12px">${escapeHtml(po.reference_no)}</div>
      <div><strong>สาขา:</strong> ${escapeHtml(po.branch_name)} (${escapeHtml(po.branch_code)})</div>
      <div><strong>วันที่:</strong> ${formatDateTime(po.created_at)}</div>
      <div><strong>ผู้ขาย:</strong> ${escapeHtml(po.seller_name)} ${po.seller_id_card ? `(${maskIdCard(po.seller_id_card)})` : ''}</div>
      <div><strong>พนักงาน:</strong> ${escapeHtml(po.user_name || '-')}</div>
      <hr>
      <table class="data-table" style="width:100%">
        <thead><tr><th>สินค้า</th><th>สภาพ</th><th>จำนวน</th><th>ราคา/หน่วย</th><th>รวม</th></tr></thead>
        <tbody>
          ${po.items.map(it => `
            <tr>
              <td>${escapeHtml(it.item_name)}</td>
              <td>${escapeHtml(it.condition_name)}</td>
              <td>${it.quantity} ${escapeHtml(it.unit)}</td>
              <td>${formatCurrency(it.unit_price)}</td>
              <td>${formatCurrency(it.total_price)}</td>
            </tr>
          `).join('')}
        </tbody>
      </table>
      <hr>
      <div style="text-align:right;font-size:18px"><strong>รวมจ่าย: ${formatCurrency(po.total_amount)}</strong></div>
      <div style="text-align:right;color:#888">วิธีจ่าย: ${po.payment_method === 'cash' ? 'เงินสด' : 'โอนธนาคาร'}</div>
      ${po.notes ? `<div style="margin-top:8px"><strong>หมายเหตุ:</strong> ${escapeHtml(po.notes)}</div>` : ''}
    </div>
  `;
  document.getElementById('viewPOContent').innerHTML = html;
  document.getElementById('viewPOModal').classList.add('show');
};

function escapeHtml(s) {
  if (s == null) return '';
  return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

function maskIdCard(id) {
  if (!id) return '-';
  if (id.length !== 13) return id;
  return id.substring(0, 1) + '-' + id.substring(1, 5) + '-XXXXX-' + id.substring(10, 12) + '-' + id.substring(12);
}

function formatDateTime(s) {
  if (!s) return '-';
  const d = new Date(s.replace(' ', 'T'));
  return d.toLocaleString('th-TH', { dateStyle: 'short', timeStyle: 'short' });
}
