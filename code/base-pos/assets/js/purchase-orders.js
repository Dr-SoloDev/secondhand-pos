let branches = [];
let categories = [];
let cart = [];
let selectedSeller = null;
let recentPOs = [];
let globalTier = { level: null, price: null, label: null };
let currentCatalogItem = null;

document.addEventListener('DOMContentLoaded', async () => {
  await loadBranches();
  await loadCategories();
  await loadRecentPOs();
  buildGlobalTierButtons();

  document.getElementById('searchSellerInput').addEventListener('input', debounce(searchSellers, 300));
  // ลบ loadCategories เพราะ categories เป็น global แล้ว (ไม่ต้อง reload ตอนเปลี่ยนสาขา)
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
  document.getElementById('itemWeightDeduct').addEventListener('input', updateItemTotal);
  document.getElementById('itemCategorySelect').addEventListener('change', saveCategoryToCatalog);
  document.getElementById('itemName').addEventListener('input', debounce(searchCatalog, 250));
  document.getElementById('itemName').addEventListener('input', () => {
    document.getElementById('itemCatalogId').value = '';
    document.getElementById('itemCategoryId').value = '';
    document.getElementById('itemCategorySelect').value = '';
  document.getElementById('itemUnit').value = 'ชิ้น';
  document.getElementById('itemTotalPreview').textContent = '\u0e40\u0e25\u0e37\u0e2d\u0e01\u0e2a\u0e34\u0e19\u0e04\u0e49\u0e32\u0e08\u0e32\u0e01\u0e41\u0e04\u0e15\u0e15\u0e32\u0e25\u0e47\u0e2d\u0e01';
  document.getElementById('itemTotalPreview').style.color = '#999';
  currentCatalogItem = null;
    document.getElementById('itemUnitPrice').value = '0';
    document.getElementById('itemCategoryId').value = '';
    buildGlobalTierButtons();
  });
  document.addEventListener('click', (e) => {
    if (!e.target.closest('#itemName') && !e.target.closest('#itemCatalogResults')) {
  document.getElementById('itemCatalogResults').innerHTML = '';
  document.getElementById('itemCatalogResults').style.display = 'none';
    }
  });

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
      `<option value="${b.id}">${escapeHtml(b.name)}</option>`
    ).join('');

    // อ่านสาขาที่เลือกไว้จาก localStorage
    const savedBranchId = localStorage.getItem('selected_branch_id');
    if (savedBranchId && branches.find(b => b.id == savedBranchId)) {
      sel.value = savedBranchId;
    }

    // บันทึกสาขาที่เลือกลง localStorage เมื่อเปลี่ยน
    sel.addEventListener('change', (e) => {
      localStorage.setItem('selected_branch_id', e.target.value);
      updateBranchBanner();
    });

    // แสดง banner สาขาปัจจุบัน
    updateBranchBanner();
  }
}

function updateBranchBanner() {
  // banner ถูกเอาออกจาก UI แล้ว — ไม่ต้องทำอะไร
  const banner = document.getElementById('branchBanner');
  if (banner) banner.style.display = 'none';
}

async function loadCategories() {
  const res = await apiRequest('inventory/categories');
  if (res.status === 'success') {
    categories = res.data;
    const sel = document.getElementById('itemCategorySelect');

    // Global categories — ไม่ filter branch
    const activeCategories = categories.filter(c => c.status === 'active');

    sel.innerHTML = '<option value="">— เลือกหมวดหมู่ —</option>' +
      activeCategories.sort((a, b) => a.name.localeCompare(b.name, 'th')).map(cat => {
        const unit = cat.default_unit || 'ชิ้น';
        return `<option value="${cat.id}" data-name="${escapeHtml(cat.name)}" data-unit="${escapeHtml(unit)}">${escapeHtml(cat.name)}</option>`;
      }).join('');
  }
}

async function saveCategoryToCatalog() {
  const catalogId = document.getElementById('itemCatalogId').value;
  const categoryId = document.getElementById('itemCategorySelect').value;

  // ถ้ายังไม่ได้เลือก catalog item หรือ category → skip
  if (!catalogId || !categoryId) return;

  // Auto-fill unit ตาม default_unit ของหมวดหมู่ที่เลือก
  const selectedOption = document.getElementById('itemCategorySelect').selectedOptions[0];
  const defaultUnit = selectedOption?.dataset.unit || 'ชิ้น';
  document.getElementById('itemUnit').value = defaultUnit;

  console.log('Saving category', categoryId, 'to catalog item', catalogId);

  try {
    const res = await apiRequest(`purchase-catalog/update-category`, 'POST', {
      catalog_id: catalogId,
      category_id: categoryId
    });

    if (res.status === 'success') {
      // Update hidden field
      document.getElementById('itemCategoryId').value = categoryId;
      console.log('✓ Category saved to catalog');
    } else {
      console.error('Failed to save category:', res.message);
    }
  } catch (error) {
    console.error('Error saving category:', error);
  }
}

function updateItemTotal() {
  const q = parseFloat(document.getElementById('itemQuantity').value || 0);
  const d = parseFloat(document.getElementById('itemWeightDeduct').value || 0);
  const p = parseFloat(document.getElementById('itemUnitPrice').value || 0);
  const net = Math.max(0, q - d);
  const el = document.getElementById('itemTotalPreview');
  if (q > 0 && p > 0) {
    el.style.color = '#333';
    el.innerHTML = `\u0e23\u0e27\u0e21: <strong>${formatCurrency(net * p)}</strong> (\u0e19\u0e49\u0e33\u0e2b\u0e19\u0e31\u0e01\u0e2a\u0e38\u0e17\u0e18\u0e34\u0e4c ${net.toFixed(2)} \u0e01\u0e01.)`;
  } else {
    el.style.color = '#999';
    el.textContent = q === 0 ? '\u0e01\u0e23\u0e38\u0e13\u0e32\u0e01\u0e33\u0e2b\u0e19\u0e14\u0e19\u0e49\u0e33\u0e2b\u0e19\u0e31\u0e01' : '\u0e23\u0e2d\u0e23\u0e32\u0e04\u0e32';
  }
}

async function searchCatalog() {
  const q = document.getElementById('itemName').value.trim();
  const box = document.getElementById('itemCatalogResults');
  if (q.length < 1) { box.innerHTML = ''; box.style.display = 'none'; return; }

  const res = await apiRequest(`purchase-catalog/search?q=${encodeURIComponent(q)}`);
  if (res.status !== 'success') { box.innerHTML = ''; box.style.display = 'none'; return; }
  const items = res.data || [];

  if (items.length === 0) {
    box.innerHTML = '<div class="seller-item" style="color:#c00">\u0e44\u0e21\u0e48\u0e1e\u0e1a\u0e2a\u0e34\u0e19\u0e04\u0e49\u0e32\u0e43\u0e19\u0e23\u0e30\u0e1a\u0e1a \u2014 \u0e01\u0e23\u0e38\u0e13\u0e32\u0e40\u0e1e\u0e34\u0e48\u0e21\u0e43\u0e19\u0e2b\u0e19\u0e49\u0e32 Master \u0e01\u0e48\u0e2d\u0e19</div>';
    box.style.display = 'block';
    return;
  }

  // Auto-fill on exact code or name match
  const exact = items.find(r => r.code === q || r.name === q);
  if (exact) {
    box.innerHTML = '';
    selectCatalogItem({
      id: exact.id,
      code: exact.code,
      name: exact.name,
      unit: exact.default_unit || '',
      price: exact.default_price || 0,
      cat: exact.category_id || '',
      catName: exact.category_name || '',
      tierprices: JSON.stringify(exact.tier_prices || []),
    });
    return;
  }

  // Show dropdown
  box.innerHTML = items.map(it => `
    <div class="seller-item" data-id="${it.id}" data-code="${escapeHtml(it.code)}"
         data-name="${escapeHtml(it.name)}" data-unit="${escapeHtml(it.default_unit || '')}"
         data-price="${it.default_price || 0}" data-cat="${it.category_id || ''}"
         data-catname="${escapeHtml(it.category_name || '')}"
         data-tierprices='${escapeHtml(JSON.stringify(it.tier_prices || []))}'
         style="cursor:pointer">
      <strong>${escapeHtml(it.code)}</strong> \u2014 ${escapeHtml(it.name)}
      <span style="color:#888;font-size:12px">
        ${it.category_name ? `(${escapeHtml(it.category_name)})` : ''}
        ${it.default_price > 0 ? ` \u00b7 ${formatCurrency(it.default_price)}/${escapeHtml(it.default_unit || '\u0e0a\u0e34\u0e49\u0e19')}` : ''}
      </span>
    </div>
  `).join('');
  box.style.display = 'block';

  box.querySelectorAll('.seller-item[data-id]').forEach(el => {
    el.addEventListener('click', () => selectCatalogItem(el.dataset));
  });
}

function selectCatalogItem(d) {
  document.getElementById('itemName').value = d.name;
  document.getElementById('itemCatalogId').value = d.id;

  // Hide dropdown
  document.getElementById('itemCatalogResults').innerHTML = '';
  document.getElementById('itemCatalogResults').style.display = 'none';

  // Set category display
  const catName = d.catname || d.catName || '';
  const catEl = document.getElementById('itemCategorySelect');
  if (catName) {
    const option = Array.from(catEl.options).find(opt => opt.dataset.name === catName);
    if (option) {
      catEl.value = option.value;
      document.getElementById('itemCategoryId').value = option.value;
    } else {
      catEl.value = '';
      document.getElementById('itemCategoryId').value = d.cat || '';
    }
  } else {
    catEl.value = '';
    document.getElementById('itemCategoryId').value = d.cat || '';
  }

  // Set unit
  document.getElementById('itemUnit').value = d.unit || 'ชิ้น';

  // Parse tier prices once — รองรับทั้ง string (จาก data-attribute) และ array (จาก exact match)
  let tierPricesArr = [];
  if (d.tierprices) {
    try {
      const raw = typeof d.tierprices === 'string' ? d.tierprices : JSON.stringify(d.tierprices);
      // unescape HTML entities ที่อาจติดมาจาก data-attribute
      const txt = document.createElement('textarea');
      txt.innerHTML = raw;
      tierPricesArr = JSON.parse(txt.value);
    } catch(e) { console.warn('tierprices parse error', e); }
  }

  // Store current catalog item FIRST (tierprices เป็น array เพื่อใช้ใน applyTierPrice)
  currentCatalogItem = { ...d, _tierPricesArr: tierPricesArr };

  // Rebuild tier buttons พร้อมราคาของ item นี้
  // ถ้ามี tier active อยู่แล้ว buildGlobalTierButtons จะ apply ราคาให้อัตโนมัติ
  buildGlobalTierButtons(JSON.stringify(tierPricesArr));

  // Set unit price: ใช้ tier ที่ active อยู่ หรือ base price
  if (globalTier.level && tierPricesArr[globalTier.level - 1]) {
    const tp = tierPricesArr[globalTier.level - 1];
    document.getElementById('itemUnitPrice').value = parseFloat(tp.price || 0).toFixed(2);
  } else {
    const basePrice = parseFloat(d.price || 0);
    document.getElementById('itemUnitPrice').value = basePrice > 0 ? basePrice.toFixed(2) : '0';
  }

  // Reset weight/deduction
  document.getElementById('itemQuantity').value = '1';
  document.getElementById('itemWeightDeduct').value = '0';

  updateItemTotal();
}

function buildGlobalTierButtons(tierPrices) {
  const container = document.getElementById('globalTierButtons');
  let prices = [];
  if (tierPrices) {
    try { prices = JSON.parse(tierPrices); } catch(e) {}
  }
  const colors = [
    { color: '#22c55e', bg: '#dcfce7' },
    { color: '#f59e0b', bg: '#fef3c7' },
    { color: '#ef4444', bg: '#fee2e2' },
  ];
  container.innerHTML = '';
  for (let idx = 0; idx < 3; idx++) {
    const c = colors[idx];
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'global-tier-btn';
    btn.dataset.level = idx + 1;
    btn.dataset.color = c.color;
    btn.dataset.bg = c.bg;
    const p = prices[idx];
    const label = p && p.label ? p.label.trim() : `บิล${idx + 1}`;
    const priceStr = p && p.price > 0 ? `\n${parseFloat(p.price).toFixed(2)} ฿` : '';
    btn.innerText = `${label}${priceStr}`;
    btn.style.cssText = `padding:10px 20px;border:2px solid ${c.color};border-radius:10px;background:#fff;color:${c.color};cursor:pointer;font-family:inherit;font-size:13px;font-weight:600;line-height:1.4;white-space:pre;transition:all 0.15s;box-shadow:0 1px 3px rgba(0,0,0,0.1);`;
    btn.addEventListener('click', () => selectGlobalTier(btn, idx + 1));
    container.appendChild(btn);
  }
  // Re-apply active state if tier was previously selected
  if (globalTier.level) {
    const btns = container.querySelectorAll('.global-tier-btn');
    const activeIdx = globalTier.level - 1;
    if (btns[activeIdx]) {
      selectGlobalTier(btns[activeIdx], globalTier.level);
    }
  }
}

function selectGlobalTier(btn, level) {
  // Unselect all
  document.querySelectorAll('.global-tier-btn').forEach(b => {
    const c = b.dataset.color;
    b.style.background = '#fff';
    b.style.color = c;
    b.style.boxShadow = '0 1px 3px rgba(0,0,0,0.1)';
    b.classList.remove('active');
  });
  // Select this
  const c = btn.dataset.color;
  btn.style.background = c;
  btn.style.color = '#fff';
  btn.style.boxShadow = `0 2px 8px ${c}66`;
  btn.classList.add('active');
  globalTier.level = level;
  const tierLabel = btn.innerText.split('\n')[0] || `ระดับ ${level}`;
  document.getElementById('globalTierInfo').innerHTML = `<span style="color:${c};font-weight:600">${tierLabel}</span> — ราคาจะถูกใช้กับทุกรายการในใบนี้อัตโนมัติ`;
  // If catalog item already selected, update price immediately
  if (currentCatalogItem) {
    // ใช้ _tierPricesArr ที่ parse ไว้แล้ว หรือ fallback ไป tierprices string
    const tiers = currentCatalogItem._tierPricesArr || currentCatalogItem.tierprices;
    if (tiers) applyTierPrice(tiers);
  }
}

function applyTierPrice(tierPrices) {
  if (!globalTier.level || !tierPrices) return;
  // รองรับทั้ง array และ string
  let tiers = [];
  if (Array.isArray(tierPrices)) {
    tiers = tierPrices;
  } else {
    try { tiers = JSON.parse(tierPrices || '[]'); } catch(e) { return; }
  }
  const selected = tiers[globalTier.level - 1];
  if (selected && selected.price > 0) {
    document.getElementById('itemUnitPrice').value = parseFloat(selected.price).toFixed(2);
    updateItemTotal();
  }
}

function addItemToCart() {
  const name = document.getElementById('itemName').value.trim();
  const catalogId = document.getElementById('itemCatalogId').value;
  const catId = document.getElementById('itemCategoryId').value;
  const qty = parseFloat(document.getElementById('itemQuantity').value || 0);
  const deduct = parseFloat(document.getElementById('itemWeightDeduct').value || 0);
  const price = parseFloat(document.getElementById('itemUnitPrice').value || 0);
  const unit = document.getElementById('itemUnit').value || 'ชิ้น';

  if (!name) { showNotification('\u0e01\u0e23\u0e38\u0e13\u0e32\u0e01\u0e23\u0e2d\u0e01\u0e0a\u0e37\u0e48\u0e2d\u0e2a\u0e34\u0e19\u0e04\u0e49\u0e32', 'error'); return; }
  if (!catalogId) { showNotification('\u0e01\u0e23\u0e38\u0e13\u0e32\u0e40\u0e25\u0e37\u0e2d\u0e01\u0e2a\u0e34\u0e19\u0e04\u0e49\u0e32\u0e08\u0e32\u0e01\u0e23\u0e32\u0e22\u0e01\u0e32\u0e23\u0e17\u0e35\u0e48\u0e23\u0e30\u0e1a\u0e1a\u0e01\u0e33\u0e2b\u0e19\u0e14', 'error'); return; }
  if (qty <= 0) { showNotification('\u0e08\u0e33\u0e19\u0e27\u0e19\u0e15\u0e49\u0e2d\u0e07\u0e21\u0e32\u0e01\u0e01\u0e27\u0e48\u0e32 0', 'error'); return; }
  if (deduct < 0) { showNotification('\u0e19\u0e49\u0e33\u0e2b\u0e19\u0e31\u0e01\u0e2b\u0e31\u0e01\u0e15\u0e49\u0e2d\u0e07\u0e44\u0e21\u0e48\u0e15\u0e34\u0e14\u0e25\u0e1a', 'error'); return; }
  if (deduct >= qty) { showNotification('\u0e19\u0e49\u0e33\u0e2b\u0e19\u0e31\u0e01\u0e2b\u0e31\u0e01\u0e15\u0e49\u0e2d\u0e07\u0e19\u0e49\u0e2d\u0e22\u0e01\u0e27\u0e48\u0e32\u0e08\u0e33\u0e19\u0e27\u0e19', 'error'); return; }
  if (price <= 0) { showNotification('\u0e23\u0e32\u0e04\u0e32\u0e15\u0e49\u0e2d\u0e07\u0e21\u0e32\u0e01\u0e01\u0e27\u0e48\u0e32 0', 'error'); return; }

  const netQty = Math.max(0, qty - deduct);
  const tierLevel = globalTier.level || null;

  cart.push({
    catalog_id: parseInt(catalogId),
    item_name: name,
    category_id: catId ? parseInt(catId) : null,
    quantity: qty,
    weight_deduction: deduct,
    net_quantity: netQty,
    unit: unit,
    unit_price: price,
    total_price: netQty * price,
    price_tier: tierLevel,
    notes: '',
  });

  // Reset form
  document.getElementById('itemName').value = '';
  document.getElementById('itemCatalogId').value = '';
  document.getElementById('itemCatalogResults').innerHTML = '';
  document.getElementById('itemCategoryId').value = '';
  document.getElementById('itemCategorySelect').value = '';
  document.getElementById('itemUnitPrice').value = '0';
  document.getElementById('itemQuantity').value = '1';
  document.getElementById('itemWeightDeduct').value = '0';
  document.getElementById('itemTotalPreview').textContent = '\u0e40\u0e25\u0e37\u0e2d\u0e01\u0e2a\u0e34\u0e19\u0e04\u0e49\u0e32\u0e08\u0e32\u0e01\u0e41\u0e04\u0e15\u0e15\u0e32\u0e25\u0e47\u0e2d\u0e01';
  document.getElementById('itemTotalPreview').style.color = '#999';
  document.getElementById('itemUnit').value = 'ชิ้น';
  currentCatalogItem = null;
  buildGlobalTierButtons();

  renderCart();
}

window.removeFromCart = function(idx) {
  cart.splice(idx, 1);
  renderCart();
};

function renderCart() {
  const tbody = document.querySelector('#cartTable tbody');
  if (cart.length === 0) {
    tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;color:#888;padding:24px 12px">\u0e22\u0e31\u0e07\u0e44\u0e21\u0e48\u0e21\u0e35\u0e23\u0e32\u0e22\u0e01\u0e32\u0e23</td></tr>';
  } else {
    tbody.innerHTML = cart.map((it, i) => {
      const d = it.weight_deduction || 0;
      const q = it.quantity || 0;
      const net = it.net_quantity ?? Math.max(0, q - d);
      const p = it.unit_price || 0;
      return `
      <tr>
        <td style="color:#999">${i + 1}</td>
        <td style="font-weight:500">${escapeHtml(it.item_name)}</td>
        <td class="text-right">${q.toFixed(2)}</td>
        <td class="text-right" style="color:#dc3545">${d > 0 ? d.toFixed(2) : '-'}</td>
        <td class="text-right">${net.toFixed(2)}</td>
        <td class="text-right">${formatCurrency(p)}</td>
        <td class="text-right"><strong>${formatCurrency(it.total_price)}</strong></td>
        <td style="text-align:center"><button class="btn btn-sm btn-danger" onclick="removeFromCart(${i})" style="padding:2px 8px;font-size:12px">\u0e25\u0e1a</button></td>
      </tr>`;
    }).join('');
  }
  const total = cart.reduce((s, it) => s + it.total_price, 0);
  const totalQty = cart.reduce((s, it) => s + (it.net_quantity ?? it.quantity), 0);
  document.getElementById('cartTotalAmount').textContent = formatCurrency(total);
  document.getElementById('cartTotalItems').textContent = `${totalQty.toFixed(2)} \u0e01\u0e01. (${cart.length} \u0e23\u0e32\u0e22\u0e01\u0e32\u0e23)`;
}

async function savePurchaseOrder() {
  if (!selectedSeller) { showNotification('\u0e01\u0e23\u0e38\u0e13\u0e32\u0e40\u0e25\u0e37\u0e2d\u0e01\u0e1c\u0e39\u0e49\u0e02\u0e32\u0e22', 'error'); return; }
  if (cart.length === 0) { showNotification('\u0e01\u0e23\u0e38\u0e13\u0e32\u0e40\u0e1e\u0e34\u0e48\u0e21\u0e23\u0e32\u0e22\u0e01\u0e32\u0e23\u0e2a\u0e34\u0e19\u0e04\u0e49\u0e32', 'error'); return; }

  const payload = {
    branch_id: parseInt(document.getElementById('branchSelect').value),
    seller_id: selectedSeller.id,
    payment_method: document.getElementById('paymentMethod').value,
    notes: document.getElementById('poNotes').value.trim(),
    items: cart.map(it => ({
      catalog_id: it.catalog_id,
      item_name: it.item_name,
      category_id: it.category_id,
      quantity: it.quantity,
      weight_deduction: it.weight_deduction,
      unit: it.unit,
      unit_price: it.unit_price,
      total_price: it.total_price,
      price_tier: it.price_tier,
      notes: it.notes,
    })),
  };

  const btn = document.getElementById('savePOBtn');
  btn.disabled = true;
  btn.textContent = '\u0e01\u0e33\u0e25\u0e31\u0e07\u0e1a\u0e31\u0e19\u0e17\u0e36\u0e01...';

  const res = await apiRequest('purchase-orders', 'POST', payload);

  btn.disabled = false;
  btn.innerHTML = '<i class="icon-additem"></i> \u0e1a\u0e31\u0e19\u0e17\u0e36\u0e01\u0e43\u0e1a\u0e23\u0e31\u0e1a\u0e0b\u0e37\u0e49\u0e2d';

  if (res.status === 'success') {
    const ref = res.data.reference_no;
    const amt = formatCurrency(res.data.total_amount);
    showNotification(`\u0e1a\u0e31\u0e19\u0e17\u0e36\u0e01\u0e2a\u0e33\u0e40\u0e23\u0e47\u0e08! \u0e40\u0e25\u0e02\u0e17\u0e35\u0e48: ${ref} \u0e22\u0e2d\u0e14\u0e23\u0e27\u0e21 ${amt}`, 'success');
    showReceipt(res.data.id);
    clearAll();
    loadRecentPOs();
  } else {
    showNotification(res.message || '\u0e1a\u0e31\u0e19\u0e17\u0e36\u0e01\u0e44\u0e21\u0e48\u0e2a\u0e33\u0e40\u0e23\u0e47\u0e08', 'error');
  }
}

function clearAll() {
  cart = [];
  selectedSeller = null;
  globalTier = { level: null, price: null, label: null };
  currentCatalogItem = null;
  document.getElementById('selectedSellerBox').innerHTML = '<div style="color:#888">\u0e22\u0e31\u0e07\u0e44\u0e21\u0e48\u0e44\u0e14\u0e49\u0e40\u0e25\u0e37\u0e2d\u0e01\u0e1c\u0e39\u0e49\u0e02\u0e32\u0e22</div>';
  document.getElementById('poNotes').value = '';
  document.getElementById('itemName').value = '';
  document.getElementById('itemCatalogId').value = '';
  document.getElementById('itemCatalogResults').innerHTML = '';
  document.getElementById('itemCategoryId').value = '';
  document.getElementById('itemCategorySelect').value = '';
  document.getElementById('itemUnitPrice').value = '0';
  document.getElementById('itemQuantity').value = '1';
  document.getElementById('itemWeightDeduct').value = '0';
  document.getElementById('globalTierInfo').textContent = '\u0e22\u0e31\u0e07\u0e44\u0e21\u0e48\u0e44\u0e14\u0e49\u0e40\u0e25\u0e37\u0e2d\u0e01 \u2014 \u0e08\u0e30\u0e43\u0e0a\u0e49\u0e23\u0e32\u0e04\u0e32\u0e1b\u0e01\u0e15\u0e34';
  // Reset tier buttons to placeholder
  buildGlobalTierButtons();
  renderCart();
}

async function loadRecentPOs() {
  const res = await apiRequest('purchase-orders?limit=10');
  if (res.status !== 'success') return;
  recentPOs = res.data.items || [];
  const tbody = document.querySelector('#recentPOTable tbody');
  if (recentPOs.length === 0) {
    tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;color:#888">\u0e22\u0e31\u0e07\u0e44\u0e21\u0e48\u0e21\u0e35\u0e43\u0e1a\u0e23\u0e31\u0e1a\u0e0b\u0e37\u0e49\u0e2d</td></tr>';
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

  const isPrecious = po.items.some(it => it.requires_precious_receipt == 1);
  const dt = formatDateTime(po.created_at);

  const itemRows = po.items.map(it => {
    const dq = parseFloat(it.weight_deduction || 0);
    const q  = parseFloat(it.quantity || 0);
    const net = Math.max(0, q - dq);
    return `<tr>
      <td>${escapeHtml(it.item_name)}</td>
      <td style="text-align:center">${dq > 0 ? dq.toFixed(2) : '-'}</td>
      <td style="text-align:center">${net.toFixed(2)} ${escapeHtml(it.unit)}</td>
      <td style="text-align:right">${formatCurrency(it.unit_price)}</td>
      <td style="text-align:right">${formatCurrency(it.total_price)}</td>
    </tr>`;
  }).join('');

  const billBody = `
    <div style="font-size:15px;font-weight:700;text-align:center">\u0e43\u0e1a\u0e23\u0e31\u0e1a\u0e0b\u0e37\u0e49\u0e2d\u0e02\u0e2d\u0e07\u0e40\u0e01\u0e48\u0e32</div>
    <div style="text-align:center;font-size:12px;margin-bottom:4px">${escapeHtml(po.branch_name)} (${escapeHtml(po.branch_code)})</div>
    <div style="display:flex;justify-content:space-between;font-size:12px;border-bottom:1px dashed #999;padding-bottom:6px;margin-bottom:6px">
      <span>\u0e40\u0e25\u0e02\u0e17\u0e35\u0e48: <strong>${escapeHtml(po.reference_no)}</strong></span>
      <span>${dt}</span>
    </div>
    <div style="font-size:12px;margin-bottom:6px">
      <div>\u0e1c\u0e39\u0e49\u0e02\u0e32\u0e22: <strong>${escapeHtml(po.seller_name)}</strong>${po.seller_id_card ? ` \u0e1a\u0e31\u0e15\u0e23: ${maskIdCard(po.seller_id_card)}` : ''}</div>
      <div>\u0e41\u0e04\u0e0a\u0e40\u0e0a\u0e35\u0e22\u0e23\u0e4c: ${escapeHtml(po.user_name || '-')}</div>
    </div>
    <table style="width:100%;border-collapse:collapse;font-size:12px">
      <thead><tr style="border-bottom:1px solid #333">
        <th style="text-align:left;padding:2px 4px">\u0e2a\u0e34\u0e19\u0e04\u0e49\u0e32</th>
        <th style="text-align:center;padding:2px 4px">\u0e2b\u0e31\u0e01(\u0e01\u0e01.)</th>
        <th style="text-align:center;padding:2px 4px">\u0e2a\u0e38\u0e17\u0e18\u0e34</th>
        <th style="text-align:right;padding:2px 4px">\u0e23\u0e32\u0e04\u0e32/\u0e01\u0e01.</th>
        <th style="text-align:right;padding:2px 4px">\u0e23\u0e27\u0e21</th>
      </tr></thead>
      <tbody>${itemRows}</tbody>
    </table>
    <div style="border-top:1px dashed #999;margin-top:6px;padding-top:6px;text-align:right;font-size:14px">
      <strong>\u0e22\u0e2d\u0e14\u0e23\u0e27\u0e21: ${formatCurrency(po.total_amount)}</strong>
      &nbsp;&nbsp;${po.payment_method === 'cash' ? '\u0e40\u0e07\u0e34\u0e19\u0e2a\u0e14' : '\u0e42\u0e2d\u0e19\u0e18\u0e19\u0e32\u0e04\u0e32\u0e23'}
    </div>
    <div style="font-size:11px;text-align:center;margin-top:8px;color:#555;border-top:1px dashed #ccc;padding-top:6px">
      \u0e23\u0e49\u0e32\u0e19\u0e1b\u0e34\u0e14\u0e27\u0e31\u0e19\u0e1e\u0e24\u0e2b\u0e31\u0e2a &nbsp;|&nbsp; 084-8233782<br>
      \u0e1a\u0e23\u0e34\u0e01\u0e32\u0e23\u0e14\u0e35 \u0e23\u0e32\u0e04\u0e32\u0e14\u0e35 \u0e15\u0e32\u0e0a\u0e31\u0e48\u0e07\u0e14\u0e34\u0e08\u0e34\u0e15\u0e2d\u0e25\u0e21\u0e32\u0e15\u0e23\u0e10\u0e32\u0e19\u0e01\u0e23\u0e30\u0e17\u0e23\u0e27\u0e07
    </div>`;

  const preciousExtra = isPrecious ? `
    <div style="border:1px solid #333;border-radius:4px;padding:10px;margin-top:12px;font-size:12px">
      <div style="font-weight:700;margin-bottom:6px">\u0e04\u0e33\u0e23\u0e31\u0e1a\u0e23\u0e2d\u0e07\u0e02\u0e2d\u0e07\u0e1c\u0e39\u0e49\u0e02\u0e32\u0e22</div>
      <div style="margin-bottom:8px">\u0e02\u0e49\u0e32\u0e1e\u0e40\u0e08\u0e49\u0e32\u0e44\u0e14\u0e49\u0e19\u0e33\u0e2a\u0e34\u0e19\u0e04\u0e49\u0e32\u0e17\u0e35\u0e48\u0e23\u0e30\u0e1a\u0e38\u0e43\u0e19\u0e1a\u0e34\u0e25\u0e19\u0e35\u0e49\u0e21\u0e32\u0e42\u0e14\u0e22\u0e2a\u0e38\u0e08\u0e23\u0e34\u0e15\u0e08\u0e23\u0e34\u0e07</div>
      <div style="margin-bottom:12px">
        \u0e25\u0e32\u0e22\u0e21\u0e37\u0e2d\u0e0a\u0e37\u0e48\u0e2d: ________________________________<br>
        <span style="font-size:11px">\u0e40\u0e27\u0e25\u0e32: ${dt} &nbsp;&nbsp; \u0e40\u0e25\u0e02\u0e1a\u0e34\u0e25: ${escapeHtml(po.reference_no)}</span>
      </div>
      <div style="font-size:11px;margin-bottom:8px">
        \u0e2b\u0e25\u0e31\u0e01\u0e10\u0e32\u0e19\u0e17\u0e35\u0e48\u0e41\u0e19\u0e1a: &nbsp;
        \u25a1 \u0e2a\u0e33\u0e40\u0e19\u0e32\u0e1a\u0e31\u0e15\u0e23\u0e1b\u0e23\u0e30\u0e0a\u0e32\u0e0a\u0e19 &nbsp;
        \u25a1 \u0e2a\u0e33\u0e40\u0e19\u0e32\u0e43\u0e1a\u0e02\u0e31\u0e1a\u0e02\u0e35\u0e48 &nbsp;
        \u25a1 \u0e40\u0e2d\u0e01\u0e2a\u0e32\u0e23\u0e23\u0e32\u0e0a\u0e01\u0e32\u0e23
      </div>
      <div style="font-size:11px;margin-bottom:4px">
        \u0e17\u0e31\u0e49\u0e07\u0e19\u0e35\u0e49\u0e44\u0e14\u0e49\u0e41\u0e2a\u0e14\u0e07\u0e04\u0e27\u0e32\u0e21\u0e1a\u0e23\u0e34\u0e2a\u0e38\u0e17\u0e18\u0e34\u0e4c\u0e42\u0e14\u0e22\u0e22\u0e34\u0e19\u0e22\u0e2d\u0e21\u0e43\u0e2b\u0e49\u0e16\u0e48\u0e32\u0e22\u0e23\u0e39\u0e1b\u0e2a\u0e33\u0e40\u0e19\u0e32\u0e1a\u0e31\u0e15\u0e23\u0e1b\u0e23\u0e30\u0e0a\u0e32\u0e0a\u0e19\u0e44\u0e27\u0e49\u0e40\u0e1b\u0e47\u0e19\u0e2b\u0e25\u0e31\u0e01\u0e10\u0e32\u0e19
      </div>
      <div style="font-size:11px;color:#c00;font-weight:600">
        \u0e17\u0e32\u0e07\u0e23\u0e49\u0e32\u0e19\u0e44\u0e21\u0e48\u0e23\u0e31\u0e1a\u0e0b\u0e37\u0e49\u0e2d\u0e02\u0e2d\u0e07\u0e17\u0e35\u0e48\u0e21\u0e35\u0e01\u0e32\u0e23\u0e25\u0e31\u0e01\u0e17\u0e23\u0e31\u0e1e\u0e22\u0e4c\u0e42\u0e14\u0e22\u0e40\u0e14\u0e47\u0e14\u0e02\u0e32\u0e14<br>
        \u0e17\u0e32\u0e07\u0e23\u0e49\u0e32\u0e19\u0e44\u0e21\u0e48\u0e23\u0e31\u0e1a\u0e1c\u0e34\u0e14\u0e0a\u0e2d\u0e1a\u0e15\u0e48\u0e2d\u0e2a\u0e34\u0e19\u0e04\u0e49\u0e32\u0e17\u0e35\u0e48\u0e40\u0e01\u0e34\u0e14\u0e08\u0e32\u0e01\u0e01\u0e32\u0e23\u0e01\u0e23\u0e30\u0e17\u0e33\u0e1c\u0e34\u0e14\u0e01\u0e0e\u0e2b\u0e21\u0e32\u0e22\u0e17\u0e32\u0e07\u0e2d\u0e32\u0e0d\u0e32\u0e17\u0e38\u0e01\u0e01\u0e23\u0e13\u0e35
      </div>
    </div>
    <div style="border:1px dashed #999;border-radius:4px;height:80px;margin-top:10px;display:flex;align-items:center;justify-content:center;font-size:11px;color:#888">
      \u0e41\u0e19\u0e1a\u0e2a\u0e33\u0e40\u0e19\u0e32\u0e1a\u0e31\u0e15\u0e23\u0e1b\u0e23\u0e30\u0e0a\u0e32\u0e0a\u0e19 / \u0e20\u0e32\u0e1e\u0e16\u0e48\u0e32\u0e22\u0e17\u0e35\u0e48\u0e19\u0e35\u0e48
    </div>` : '';

  const half = `<div style="padding:12px;font-family:'Sarabun',sans-serif">${billBody}${preciousExtra}</div>`;
  const printHtml = `<div style="display:grid;grid-template-columns:1fr 1fr;gap:0;border:1px solid #ccc">
    <div style="border-right:2px dashed #999">${half}</div>
    <div>${half}</div>
  </div>
  <div style="text-align:center;margin-top:8px;font-size:12px;color:#888">\u2702 \u0e09\u0e35\u0e01\u0e15\u0e23\u0e07\u0e40\u0e2a\u0e49\u0e19\u0e1b\u0e23\u0e38 \u2014 \u0e23\u0e49\u0e32\u0e19\u0e40\u0e01\u0e47\u0e1a\u0e0b\u0e49\u0e32\u0e22 | \u0e25\u0e39\u0e01\u0e04\u0e49\u0e32\u0e40\u0e01\u0e47\u0e1a\u0e02\u0e27\u0e32</div>`;

  document.getElementById('viewPOContent').innerHTML = printHtml;
  document.getElementById('viewPOModal').classList.add('show');
};

// Seller functions
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
    empty.textContent = '\u0e44\u0e21\u0e48\u0e1e\u0e1a\u0e1c\u0e39\u0e49\u0e02\u0e32\u0e22 \u2014 \u0e01\u0e14\u0e1b\u0e38\u0e48\u0e43\u0e2b\u0e21\u0e48';
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
    showBlacklistAlert(s, () => doSelectSeller(s));
    return;
  }
  doSelectSeller(s);
}

function showBlacklistAlert(s, onConfirm) {
  const existing = document.getElementById('blacklistAlertOverlay');
  if (existing) existing.remove();

  const dateStr = s.blacklisted_at
    ? new Date(s.blacklisted_at.replace(' ','T')).toLocaleDateString('th-TH', {year:'numeric',month:'short',day:'numeric'})
    : '-';

  const overlay = document.createElement('div');
  overlay.id = 'blacklistAlertOverlay';
  overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:9999;display:flex;align-items:center;justify-content:center';
  overlay.innerHTML = `
    <div style="background:#fff;border-radius:12px;padding:28px 32px;max-width:420px;width:90%;box-shadow:0 8px 32px rgba(0,0,0,0.2)">
      <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px">
        <div style="font-size:32px">\u26d4</div>
        <div>
          <div style="font-size:17px;font-weight:700;color:#c00">\u0e1c\u0e39\u0e49\u0e02\u0e32\u0e22\u0e23\u0e32\u0e22\u0e19\u0e35\u0e49\u0e2d\u0e22\u0e39\u0e48\u0e43\u0e19\u0e1a\u0e31\u0e0d\u0e0a\u0e35\u0e14\u0e33</div>
          <div style="font-size:14px;color:#555;margin-top:2px">${escapeHtml(s.full_name)}</div>
        </div>
      </div>
      <div style="background:#fff5f5;border:1px solid #fca5a5;border-radius:8px;padding:12px;margin-bottom:16px;font-size:13px">
        <div><strong>\u0e40\u0e2b\u0e15\u0e38\u0e1c\u0e25:</strong> ${escapeHtml(s.blacklist_reason || '\u0e44\u0e21\u0e48\u0e44\u0e14\u0e49\u0e23\u0e30\u0e1a\u0e38')}</div>
        <div style="margin-top:4px;color:#888"><strong>\u0e27\u0e31\u0e19\u0e17\u0e35\u0e48:</strong> ${dateStr}</div>
      </div>
      <div style="font-size:13px;color:#555;margin-bottom:20px">\u0e17\u0e48\u0e32\u0e19\u0e15\u0e49\u0e2d\u0e07\u0e01\u0e32\u0e23\u0e14\u0e33\u0e40\u0e19\u0e34\u0e19\u0e01\u0e32\u0e23\u0e23\u0e31\u0e1a\u0e0b\u0e37\u0e49\u0e2d\u0e15\u0e48\u0e2d\u0e2b\u0e23\u0e37\u0e2d\u0e44\u0e21\u0e48?</div>
      <div style="display:flex;gap:10px;justify-content:flex-end">
        <button id="blacklistCancelBtn" class="btn btn-secondary">\u0e22\u0e01\u0e40\u0e25\u0e34\u0e01</button>
        <button id="blacklistConfirmBtn" style="background:#c00;color:#fff;border:none;padding:8px 20px;border-radius:6px;cursor:pointer;font-family:inherit;font-size:14px;font-weight:600">\u0e14\u0e33\u0e40\u0e19\u0e34\u0e19\u0e01\u0e32\u0e23\u0e15\u0e48\u0e2d</button>
      </div>
    </div>`;

  document.body.appendChild(overlay);
  document.getElementById('blacklistCancelBtn').onclick = () => overlay.remove();
  document.getElementById('blacklistConfirmBtn').onclick = () => { overlay.remove(); onConfirm(); };
}

function doSelectSeller(s) {
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
    idLine.textContent = `\u0e40\u0e25\u0e02\u0e1a\u0e31\u0e15\u0e23: ${maskIdCard(s.id_card)}`;
    wrap.appendChild(idLine);
  }
  if (s.phone) {
    const phoneLine = document.createElement('div');
    phoneLine.textContent = `\u0e42\u0e17\u0e23: ${s.phone}`;
    wrap.appendChild(phoneLine);
  }
  const btn = document.createElement('button');
  btn.className = 'btn btn-sm btn-secondary';
  btn.textContent = '\u0e40\u0e1b\u0e25\u0e35\u0e48\u0e22\u0e19\u0e1c\u0e39\u0e49\u0e02\u0e32\u0e22';
  btn.addEventListener('click', clearSeller);
  wrap.appendChild(btn);
  box.appendChild(wrap);
  document.getElementById('searchSellerInput').value = '';
  document.getElementById('sellerSearchResults').style.display = 'none';
}

function clearSeller() {
  selectedSeller = null;
  document.getElementById('selectedSellerBox').innerHTML = '<div style="color:#888">\u0e22\u0e31\u0e07\u0e44\u0e21\u0e48\u0e44\u0e14\u0e49\u0e40\u0e25\u0e37\u0e2d\u0e01\u0e1c\u0e39\u0e49\u0e02\u0e32\u0e22</div>';
}

function openNewSellerModal() {
  document.getElementById('newSellerModal').classList.add('show');
  document.getElementById('newFullName').value = document.getElementById('searchSellerInput').value || '';
  document.getElementById('newIdCard').value = '';
  document.getElementById('newPhone').value = '';
  document.getElementById('newAddress').value = '';
  document.getElementById('newVehiclePlate').value = '';
}

async function saveNewSeller() {
  const payload = {
    full_name: document.getElementById('newFullName').value.trim(),
    id_card: document.getElementById('newIdCard').value.replace(/\D/g, ''),
    phone: document.getElementById('newPhone').value.trim(),
    address: document.getElementById('newAddress').value.trim(),
    vehicle_plate: document.getElementById('newVehiclePlate').value.trim(),
  };
  if (!payload.full_name) { showNotification('\u0e01\u0e23\u0e38\u0e13\u0e32\u0e01\u0e23\u0e2d\u0e01\u0e0a\u0e37\u0e48\u0e2d-\u0e19\u0e32\u0e21\u0e2a\u0e01\u0e38\u0e25', 'error'); return; }
  if (payload.id_card && payload.id_card.length !== 13) {
    showNotification('\u0e40\u0e25\u0e02\u0e1a\u0e31\u0e15\u0e23\u0e15\u0e49\u0e2d\u0e07 13 \u0e2b\u0e25\u0e31\u0e01', 'error'); return;
  }
  const res = await apiRequest('sellers', 'POST', payload);
  if (res.status === 'success') {
    showNotification('\u0e40\u0e1e\u0e34\u0e48\u0e21\u0e1c\u0e39\u0e49\u0e02\u0e32\u0e22\u0e2a\u0e33\u0e40\u0e23\u0e47\u0e08', 'success');
    document.getElementById('newSellerModal').classList.remove('show');
    selectSeller({ ...payload, id: res.data.id, is_blacklisted: 0 });
  } else {
    showNotification(res.message || '\u0e1c\u0e34\u0e14\u0e1e\u0e25\u0e32\u0e14', 'error');
  }
}

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
