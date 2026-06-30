// ===== Global State =====
let branches = [];
let categories = [];
let cart = [];
let selectedSeller = null;
let recentPOs = [];
let globalTier = { level: null };
let currentCatalogItem = null; // { id, name, unit, price, tierPrices: [] }

// ===== Photo State =====
let pendingItemPhotos = {};   // { [cartIndex]: File }
let pendingSellerIdPhoto = null; // File | null
let activePhotoTarget = null; // { type: 'item', index: N } | { type: 'seller-id' }
let cameraStream = null;

// ===== Init =====
document.addEventListener('DOMContentLoaded', async () => {
  await loadBranches();
  await loadCategories();
  await loadRecentPOs();
  buildTierButtons([]);

  document.getElementById('searchSellerInput').addEventListener('input', debounce(searchSellers, 300));
  document.getElementById('createNewSellerBtn').addEventListener('click', openNewSellerModal);
  document.getElementById('saveNewSellerBtn').addEventListener('click', saveNewSeller);
  document.getElementById('addItemBtn').addEventListener('click', addItemToCart);
  document.getElementById('savePOBtn').addEventListener('click', savePurchaseOrder);
  document.getElementById('clearPOBtn').addEventListener('click', clearAll);

  document.querySelectorAll('.close-modal').forEach(b =>
    b.addEventListener('click', e => e.target.closest('.modal').classList.remove('show'))
  );

  document.getElementById('itemUnitPrice').addEventListener('input', updateItemTotal);
  document.getElementById('itemQuantity').addEventListener('input', updateItemTotal);
  document.getElementById('itemWeightDeduct').addEventListener('input', updateItemTotal);
  document.getElementById('itemCategorySelect').addEventListener('change', saveCategoryToCatalog);
  document.getElementById('itemName').addEventListener('input', debounce(searchCatalog, 250));
  document.getElementById('itemName').addEventListener('input', onItemNameChanged);

  document.addEventListener('click', e => {
    if (!e.target.closest('#itemName') && !e.target.closest('#itemCatalogResults')) {
      closeCatalogDropdown();
    }
  });

  document.getElementById('viewPOModalClose').addEventListener('click', () =>
    document.getElementById('viewPOModal').classList.remove('show')
  );
});

function debounce(fn, ms) {
  let t;
  return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); };
}

// ===== Branch =====
async function loadBranches() {
  const res = await apiRequest('branches/active');
  if (res.status !== 'success') return;
  branches = res.data;
  const sel = document.getElementById('branchSelect');
  sel.innerHTML = branches.map(b => `<option value="${b.id}">${escapeHtml(b.name)}</option>`).join('');
  const saved = localStorage.getItem('selected_branch_id');
  if (saved && branches.find(b => b.id == saved)) sel.value = saved;
  sel.addEventListener('change', e => localStorage.setItem('selected_branch_id', e.target.value));
}

// ===== Categories =====
async function loadCategories() {
  const res = await apiRequest('inventory/categories');
  if (res.status !== 'success') return;
  categories = res.data;
  const sel = document.getElementById('itemCategorySelect');
  const active = categories.filter(c => c.status === 'active')
    .sort((a, b) => a.name.localeCompare(b.name, 'th'));
  sel.innerHTML = '<option value="">— เลือกหมวดหมู่ —</option>' +
    active.map(c => `<option value="${c.id}" data-name="${escapeHtml(c.name)}" data-unit="${escapeHtml(c.default_unit || 'ชิ้น')}">${escapeHtml(c.name)}</option>`).join('');
}

async function saveCategoryToCatalog() {
  const catalogId = document.getElementById('itemCatalogId').value;
  const categoryId = document.getElementById('itemCategorySelect').value;
  if (!catalogId || !categoryId) return;
  const opt = document.getElementById('itemCategorySelect').selectedOptions[0];
  document.getElementById('itemUnit').value = opt?.dataset.unit || 'ชิ้น';
  const res = await apiRequest('purchase-catalog/update-category', 'POST', { catalog_id: catalogId, category_id: categoryId });
  if (res.status === 'success') document.getElementById('itemCategoryId').value = categoryId;
}

// ===== Tier Buttons =====
// tierPrices: array of { label, price }
function buildTierButtons(tierPrices) {
  const container = document.getElementById('globalTierButtons');
  const colors = [
    { border: '#22c55e', active: '#22c55e' },
    { border: '#f59e0b', active: '#f59e0b' },
    { border: '#ef4444', active: '#ef4444' },
  ];
  container.innerHTML = '';
  for (let i = 0; i < 3; i++) {
    const tp = tierPrices[i];
    const label = tp?.label?.trim() || `บิล${i + 1}`;
    const priceStr = tp?.price > 0 ? `\n${parseFloat(tp.price).toFixed(2)} ฿` : '';
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'global-tier-btn';
    btn.dataset.level = i + 1;
    btn.dataset.color = colors[i].border;
    btn.innerText = label + priceStr;
    btn.style.cssText = `padding:10px 20px;border:2px solid ${colors[i].border};border-radius:10px;background:#fff;color:${colors[i].border};cursor:pointer;font-family:inherit;font-size:13px;font-weight:600;line-height:1.4;white-space:pre;transition:all 0.15s;box-shadow:0 1px 3px rgba(0,0,0,0.1);`;
    btn.addEventListener('click', () => onTierClick(btn, i + 1));
    container.appendChild(btn);
  }
  // restore active state
  if (globalTier.level) {
    const btns = container.querySelectorAll('.global-tier-btn');
    if (btns[globalTier.level - 1]) setTierActive(btns[globalTier.level - 1], globalTier.level);
  }
}

function setTierActive(btn, level) {
  document.querySelectorAll('.global-tier-btn').forEach(b => {
    b.style.background = '#fff';
    b.style.color = b.dataset.color;
    b.style.boxShadow = '0 1px 3px rgba(0,0,0,0.1)';
    b.classList.remove('active');
  });
  btn.style.background = btn.dataset.color;
  btn.style.color = '#fff';
  btn.style.boxShadow = `0 2px 8px ${btn.dataset.color}66`;
  btn.classList.add('active');
  globalTier.level = level;
  const tierLabel = btn.innerText.split('\n')[0];
  document.getElementById('globalTierInfo').innerHTML =
    `<span style="color:${btn.dataset.color};font-weight:600">${tierLabel}</span> — ราคาจะถูกใช้กับทุกรายการในใบนี้อัตโนมัติ`;
}

function onTierClick(btn, level) {
  setTierActive(btn, level);
  // ถ้าเลือก item อยู่แล้ว → update ราคาทันที
  if (currentCatalogItem) {
    applyTierToPrice(currentCatalogItem.tierPrices);
  }
}

function applyTierToPrice(tierPrices) {
  if (!globalTier.level || !Array.isArray(tierPrices)) return;
  const tp = tierPrices[globalTier.level - 1];
  if (tp && tp.price > 0) {
    document.getElementById('itemUnitPrice').value = parseFloat(tp.price).toFixed(2);
    updateItemTotal();
  }
}

// ===== Item Search =====
function onItemNameChanged() {
  // เคลียร์ catalog item เมื่อ user พิมพ์ใหม่
  document.getElementById('itemCatalogId').value = '';
  document.getElementById('itemCategoryId').value = '';
  document.getElementById('itemCategorySelect').value = '';
  document.getElementById('itemUnit').value = 'ชิ้น';
  document.getElementById('itemUnitPrice').value = '0';
  document.getElementById('itemTotalPreview').textContent = 'เลือกสินค้าจากแคตาล็อก';
  document.getElementById('itemTotalPreview').style.color = '#999';
  currentCatalogItem = null;
  buildTierButtons([]);
}

function closeCatalogDropdown() {
  const box = document.getElementById('itemCatalogResults');
  box.innerHTML = '';
  box.style.display = 'none';
}

async function searchCatalog() {
  const q = document.getElementById('itemName').value.trim();
  const box = document.getElementById('itemCatalogResults');
  if (q.length < 1) { closeCatalogDropdown(); return; }

  const res = await apiRequest(`purchase-catalog/search?q=${encodeURIComponent(q)}`);
  if (res.status !== 'success') { closeCatalogDropdown(); return; }
  const items = res.data || [];

  if (items.length === 0) {
    box.innerHTML = '<div class="seller-item" style="color:#c00">ไม่พบสินค้าในระบบ — กรุณาเพิ่มในหน้า Master ก่อน</div>';
    box.style.display = 'block';
    return;
  }

  // exact match → auto select ทันที ไม่แสดง dropdown
  const exact = items.find(r => r.code === q || r.name === q);
  if (exact) {
    closeCatalogDropdown();
    selectCatalogItem(exact);
    return;
  }

  // แสดง dropdown
  box.innerHTML = '';
  items.forEach(it => {
    const div = document.createElement('div');
    div.className = 'seller-item';
    div.style.cursor = 'pointer';
    const priceHint = it.default_price > 0
      ? ` · ${formatCurrency(it.default_price)}/${escapeHtml(it.default_unit || 'ชิ้น')}`
      : '';
    const catHint = it.category_name ? `(${escapeHtml(it.category_name)})` : '';
    div.innerHTML = `<strong>${escapeHtml(it.code)}</strong> — ${escapeHtml(it.name)}
      <span style="color:#888;font-size:12px"> ${catHint}${priceHint}</span>`;
    div.addEventListener('click', () => {
      closeCatalogDropdown();
      selectCatalogItem(it);
    });
    box.appendChild(div);
  });
  box.style.display = 'block';
}

// item มาจาก API response โดยตรง (object ที่มี tier_prices เป็น array แน่ๆ)
function selectCatalogItem(item) {
  const tierPrices = Array.isArray(item.tier_prices) ? item.tier_prices : [];

  // เก็บ state
  currentCatalogItem = {
    id: item.id,
    code: item.code,
    name: item.name,
    unit: item.default_unit || 'ชิ้น',
    price: parseFloat(item.default_price || 0),
    catId: item.category_id || '',
    catName: item.category_name || '',
    tierPrices,
  };

  // fill fields
  document.getElementById('itemName').value = item.name;
  document.getElementById('itemCatalogId').value = item.id;
  document.getElementById('itemUnit').value = currentCatalogItem.unit;

  // set category
  const catEl = document.getElementById('itemCategorySelect');
  if (currentCatalogItem.catName) {
    const opt = Array.from(catEl.options).find(o => o.dataset.name === currentCatalogItem.catName);
    if (opt) {
      catEl.value = opt.value;
      document.getElementById('itemCategoryId').value = opt.value;
    } else {
      catEl.value = '';
      document.getElementById('itemCategoryId').value = currentCatalogItem.catId;
    }
  } else {
    catEl.value = '';
    document.getElementById('itemCategoryId').value = currentCatalogItem.catId;
  }

  // rebuild tier buttons พร้อมราคาจาก item นี้
  buildTierButtons(tierPrices);

  // set ราคา: ใช้ tier ที่ active อยู่ ถ้าไม่มีใช้ base price
  if (globalTier.level && tierPrices[globalTier.level - 1]?.price > 0) {
    document.getElementById('itemUnitPrice').value = parseFloat(tierPrices[globalTier.level - 1].price).toFixed(2);
  } else {
    document.getElementById('itemUnitPrice').value =
      currentCatalogItem.price > 0 ? currentCatalogItem.price.toFixed(2) : '0';
  }

  // reset น้ำหนัก
  document.getElementById('itemQuantity').value = '1';
  document.getElementById('itemWeightDeduct').value = '0';
  updateItemTotal();
}

// ===== Item Total Preview =====
function updateItemTotal() {
  const q = parseFloat(document.getElementById('itemQuantity').value || 0);
  const d = parseFloat(document.getElementById('itemWeightDeduct').value || 0);
  const p = parseFloat(document.getElementById('itemUnitPrice').value || 0);
  const net = Math.max(0, q - d);
  const el = document.getElementById('itemTotalPreview');
  if (q > 0 && p > 0) {
    el.style.color = '#333';
    el.innerHTML = `รวม: <strong>${formatCurrency(net * p)}</strong> (น้ำหนักสุทธิ์ ${net.toFixed(2)} กก.)`;
  } else {
    el.style.color = '#999';
    el.textContent = q === 0 ? 'กรุณากำหนดน้ำหนัก' : 'กรุณาเลือกระดับราคา (บิล)';
  }
}

// ===== Cart =====
function addItemToCart() {
  const name = document.getElementById('itemName').value.trim();
  const catalogId = document.getElementById('itemCatalogId').value;
  const catId = document.getElementById('itemCategoryId').value;
  const qty = parseFloat(document.getElementById('itemQuantity').value || 0);
  const deduct = parseFloat(document.getElementById('itemWeightDeduct').value || 0);
  const price = parseFloat(document.getElementById('itemUnitPrice').value || 0);
  const unit = document.getElementById('itemUnit').value || 'กก.';

  if (!name) { showNotification('กรุณากรอกชื่อสินค้า', 'error'); return; }
  if (!catalogId) { showNotification('กรุณาเลือกสินค้าจากรายการที่ระบบกำหนด', 'error'); return; }
  if (qty <= 0) { showNotification('น้ำหนักต้องมากกว่า 0', 'error'); return; }
  if (deduct < 0) { showNotification('น้ำหนักหักต้องไม่ติดลบ', 'error'); return; }
  if (deduct >= qty) { showNotification('น้ำหนักหักต้องน้อยกว่าน้ำหนักรวม', 'error'); return; }
  if (price <= 0) { showNotification('ราคาต้องมากกว่า 0 — กรุณาเลือกระดับบิลก่อน', 'error'); return; }

  const netQty = Math.max(0, qty - deduct);
  cart.push({
    _tempId: 'item_' + Date.now() + '_' + Math.random().toString(36).slice(2, 6),
    catalog_id: parseInt(catalogId),
    item_name: name,
    category_id: catId ? parseInt(catId) : null,
    quantity: qty,
    weight_deduction: deduct,
    net_quantity: netQty,
    unit,
    unit_price: price,
    total_price: netQty * price,
    price_tier: globalTier.level || null,
    notes: '',
  });

  // reset form item (คง globalTier ไว้)
  document.getElementById('itemName').value = '';
  document.getElementById('itemCatalogId').value = '';
  document.getElementById('itemCategoryId').value = '';
  document.getElementById('itemCategorySelect').value = '';
  document.getElementById('itemUnitPrice').value = '0';
  document.getElementById('itemQuantity').value = '1';
  document.getElementById('itemWeightDeduct').value = '0';
  document.getElementById('itemTotalPreview').textContent = 'เลือกสินค้าจากแคตาล็อก';
  document.getElementById('itemTotalPreview').style.color = '#999';
  document.getElementById('itemUnit').value = 'กก.';
  currentCatalogItem = null;
  buildTierButtons([]); // reset tier button label (ไม่ reset globalTier.level)

  renderCart();
}

window.removeFromCart = function(idx) {
  // Remove associated photo
  delete pendingItemPhotos[cart[idx]._tempId];
  cart.splice(idx, 1);
  renderCart();
};

function renderCart() {
  const tbody = document.querySelector('#cartTable tbody');
  if (cart.length === 0) {
    tbody.innerHTML = '<tr><td colspan="9" style="text-align:center;color:#888;padding:24px 12px">ยังไม่มีรายการ</td></tr>';
  } else {
    tbody.innerHTML = cart.map((it, i) => {
      const net = it.net_quantity ?? Math.max(0, (it.quantity || 0) - (it.weight_deduction || 0));
      return `<tr>
        <td style="color:#999">${i + 1}</td>
        <td style="font-weight:500">${escapeHtml(it.item_name)}</td>
        <td class="text-right">${it.quantity.toFixed(2)}</td>
        <td class="text-right" style="color:#dc3545">${it.weight_deduction > 0 ? it.weight_deduction.toFixed(2) : '-'}</td>
        <td class="text-right">${net.toFixed(2)}</td>
        <td class="text-right">${formatCurrency(it.unit_price)}</td>
        <td class="text-right"><strong>${formatCurrency(it.total_price)}</strong></td>
        <td style="text-align:center">
          <button class="btn-photo-picker btn-photo-picker-sm ${pendingItemPhotos[it._tempId] ? 'has-photo' : ''}"
                  onclick="openPhotoPicker('item', '${it._tempId}')" title="ถ่ายรูปสินค้า" type="button">
            ${pendingItemPhotos[it._tempId] ? '✓' : '+'}
          </button>
        </td>
        <td style="text-align:center"><button class="btn btn-sm btn-danger" onclick="removeFromCart(${i})" style="padding:2px 8px;font-size:12px">ลบ</button></td>
      </tr>`;
    }).join('');
  }
  const total = cart.reduce((s, it) => s + it.total_price, 0);
  const totalKg = cart.reduce((s, it) => s + (it.net_quantity ?? it.quantity), 0);
  document.getElementById('cartTotalAmount').textContent = formatCurrency(total);
  document.getElementById('cartTotalItems').textContent = `${totalKg.toFixed(2)} กก. (${cart.length} รายการ)`;
}

// ===== Save PO =====
async function savePurchaseOrder() {
  if (!selectedSeller) { showNotification('กรุณาเลือกผู้ขาย', 'error'); return; }
  if (cart.length === 0) { showNotification('กรุณาเพิ่มรายการสินค้า', 'error'); return; }

  // WF-03: soft reminder ก่อน save ถ้าเป็น blacklist seller (ไม่ block)
  if (selectedSeller.is_blacklisted == 1) {
    showNotification('⚠️ กำลังบันทึก PO ให้ผู้ขายที่อยู่ในบัญชีดำ', 'warning');
  }

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
  btn.textContent = 'กำลังบันทึก...';

  const res = await apiRequest('purchase-orders', 'POST', payload);
  btn.disabled = false;
  btn.innerHTML = '<i class="icon-additem"></i> บันทึกใบรับซื้อ';

  if (res.status === 'success') {
    const poId = res.data.id;
    showNotification(`บันทึกสำเร็จ! เลขที่: ${res.data.reference_no} ยอดรวม ${formatCurrency(res.data.total_amount)}`, 'success');

    // อัปโหลดรูปสินค้าที่ถ่ายค้างไว้
    const itemPhotoCount = Object.keys(pendingItemPhotos).length;
    if (itemPhotoCount > 0) {
      showUploadToast(`กำลังอัปโหลด ${itemPhotoCount} รูป...`);
      showUploadProgress();
      let uploaded = 0;
      for (const [tempId, file] of Object.entries(pendingItemPhotos)) {
        await uploadItemPhoto(poId, file);
        uploaded++;
        showUploadProgress((uploaded / itemPhotoCount) * 100);
      }
      hideUploadProgress();
      showUploadToast(`✅ อัปโหลด ${itemPhotoCount} รูปเรียบร้อย`, 2000);
    }

    showReceipt(poId);
    clearAll();
    loadRecentPOs();
  } else {
    showNotification(res.message || 'บันทึกไม่สำเร็จ', 'error');
  }
}

// ===== Clear All =====
function clearAll() {
  cart = [];
  selectedSeller = null;
  globalTier = { level: null };
  currentCatalogItem = null;

  // Reset photo state
  pendingItemPhotos = {};
  pendingSellerIdPhoto = null;

  document.getElementById('selectedSellerBox').innerHTML = '<div class="text-muted" style="font-size:13px">ยังไม่ได้เลือกผู้ขาย</div>';
  document.getElementById('poNotes').value = '';
  document.getElementById('itemName').value = '';
  document.getElementById('itemCatalogId').value = '';
  document.getElementById('itemCategoryId').value = '';
  document.getElementById('itemCategorySelect').value = '';
  document.getElementById('itemUnitPrice').value = '0';
  document.getElementById('itemQuantity').value = '1';
  document.getElementById('itemWeightDeduct').value = '0';
  document.getElementById('itemTotalPreview').textContent = 'เลือกสินค้าจากแคตาล็อก';
  document.getElementById('itemTotalPreview').style.color = '#999';
  document.getElementById('globalTierInfo').textContent = 'ยังไม่ได้เลือก — จะใช้ราคาปกติ';
  buildTierButtons([]);
  renderCart();
}

// ===== Recent POs =====
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
    </tr>`).join('');
}

// ===== Receipt =====
window.showReceipt = async function(id) {
  const res = await apiRequest(`purchase-orders/order?id=${id}`);
  if (res.status !== 'success') return;
  const po = res.data;
  const dt = formatDateTime(po.created_at);
  const isPrecious = po.items.some(it => it.requires_precious_receipt == 1);

  const itemRows = po.items.map((it, i) => {
    const dq = parseFloat(it.weight_deduction || 0);
    const q = parseFloat(it.quantity || 0);
    const net = Math.max(0, q - dq);
    const bg = i % 2 === 0 ? '#fff' : '#f9f9f9';
    return `<tr style="background:${bg}">
      <td style="padding:4px 5px">${escapeHtml(it.item_name)}</td>
      <td style="text-align:center;padding:4px 5px;color:#666">${dq > 0 ? dq.toFixed(2) : '-'}</td>
      <td style="text-align:center;padding:4px 5px">${net.toFixed(2)} ${escapeHtml(it.unit)}</td>
      <td style="text-align:right;padding:4px 5px">${formatCurrency(it.unit_price)}</td>
      <td style="text-align:right;padding:4px 5px;font-weight:600">${formatCurrency(it.total_price)}</td>
    </tr>`;
  }).join('');

  const billBody = `
    <div style="border:2px solid #222;border-radius:4px;padding:6px 10px;text-align:center;margin-bottom:8px">
      <div style="font-size:16px;font-weight:800;letter-spacing:1px">ใบรับซื้อของเก่า</div>
      <div style="font-size:12px;color:#444;margin-top:2px">${escapeHtml(po.branch_name)} &nbsp;·&nbsp; สาขา ${escapeHtml(po.branch_code)}</div>
    </div>
    <div style="display:flex;justify-content:space-between;align-items:center;font-size:11px;margin-bottom:6px">
      <span>เลขที่ <strong style="font-size:12px">${escapeHtml(po.reference_no)}</strong></span>
      <span style="color:#555">${dt}</span>
    </div>
    <div style="background:#f7f7f7;border-radius:3px;padding:5px 8px;font-size:12px;margin-bottom:8px;line-height:1.7">
      <div>ผู้ขาย &nbsp;<strong>${escapeHtml(po.seller_name)}</strong>${po.seller_id_card ? `<span style="color:#666;font-size:11px"> &nbsp;บัตร ${maskIdCard(po.seller_id_card)}</span>` : ''}</div>
      <div style="color:#555;font-size:11px">แคชเชียร์ &nbsp;${escapeHtml(po.user_name || '-')}</div>
    </div>
    <table style="width:100%;border-collapse:collapse;font-size:11.5px">
      <thead>
        <tr style="background:#222;color:#fff">
          <th style="text-align:left;padding:4px 5px;font-weight:600">สินค้า</th>
          <th style="text-align:center;padding:4px 5px;font-weight:600">หัก</th>
          <th style="text-align:center;padding:4px 5px;font-weight:600">สุทธิ</th>
          <th style="text-align:right;padding:4px 5px;font-weight:600">ราคา/กก.</th>
          <th style="text-align:right;padding:4px 5px;font-weight:600">รวม</th>
        </tr>
      </thead>
      <tbody>${itemRows}</tbody>
    </table>
    <div style="margin-top:8px;padding:6px 8px;background:#222;color:#fff;border-radius:3px;display:flex;justify-content:space-between;align-items:center">
      <span style="font-size:11px;opacity:.8">${po.payment_method === 'cash' ? '💵 เงินสด' : '🏦 โอนธนาคาร'}</span>
      <span style="font-size:15px;font-weight:800">฿ ${formatCurrency(po.total_amount)}</span>
    </div>
    <div style="font-size:10.5px;text-align:center;margin-top:8px;color:#666;border-top:1px dashed #ccc;padding-top:6px;line-height:1.8">
      ปิดวันพฤหัส &nbsp;|&nbsp; 084-8233782<br>
      <span style="font-size:10px">บริการดี ราคาดี ตาชั่งดิจิตอลมาตรฐานกระทรวง</span>
    </div>`;

  const preciousExtra = isPrecious ? `
    <div style="border:1px solid #333;border-radius:4px;padding:10px;margin-top:12px;font-size:12px">
      <div style="font-weight:700;margin-bottom:6px">คำรับรองของผู้ขาย</div>
      <div style="margin-bottom:10px">ข้าพเจ้าได้นำสินค้าที่ระบุในบิลนี้มาโดยสุจริต และยินยอมให้ทางร้านบันทึกข้อมูล</div>
      <div style="margin-bottom:4px;font-size:11px">ลายมือชื่อ:</div>
      <div style="border-bottom:1.5px solid #333;height:32px;margin-bottom:6px"></div>
      <div style="font-size:10px;color:#555;margin-bottom:10px">
        วันที่/เวลา: ${dt} &nbsp;&nbsp;&nbsp; เลขบิล: ${escapeHtml(po.reference_no)}
      </div>
      <div style="font-size:11px;margin-bottom:8px">
        หลักฐานที่แนบ: &nbsp; ☐ สำเนาบัตรประชาชน &nbsp; ☐ สำเนาใบขับขี่ &nbsp; ☐ เอกสารราชการ
      </div>
      <div style="font-size:10px;color:#c00;font-weight:600;line-height:1.5">
        ทางร้านไม่รับซื้อของที่มีการลักทรัพย์โดยเด็ดขาด<br>
        ทางร้านไม่รับผิดชอบต่อสินค้าที่เกิดจากการกระทำผิดกฎหมายทุกกรณี
      </div>
    </div>
    <div style="border:1.5px dashed #999;border-radius:4px;height:90px;margin-top:8px;display:flex;align-items:center;justify-content:center;font-size:10px;color:#aaa;flex-direction:column;gap:4px">
      <span>📎</span>
      <span>แนบสำเนาบัตรประชาชน / ภาพถ่ายที่นี่</span>
    </div>` : '';

  const half = `
    <div style="width:138mm;padding:10px 14px;font-family:'Sarabun',sans-serif;font-size:11px;box-sizing:border-box;">
      ${billBody}${preciousExtra}
    </div>`;

  document.getElementById('viewPOContent').innerHTML = `
    <style>
      @media print {
        @page { size: A4 landscape; margin: 8mm; }
        /* ซ่อน UI ที่ไม่ต้องพิมพ์ */
        #viewPOModal .modal-header,
        #viewPOModal .modal-footer,
        #billPrintHint,
        #poQrSection { display: none !important; }
        /* ลบ border กรอบ modal */
        #billPrintWrap { border: none !important; }
        /* ถ้า Type B ยาวเกิน → ขึ้นหน้าใหม่โดยอัตโนมัติ */
        #billPrintWrap > div { page-break-inside: avoid; }
      }
    </style>
    <div id="billPrintWrap" style="display:flex;flex-direction:row;border:1px solid #ccc;width:fit-content;margin:0 auto;">
      <div style="border-right:2px dashed #999">${half}</div>
      <div>${half}</div>
    </div>
    <div id="billPrintHint" style="text-align:center;font-size:12px;color:#888;margin-top:8px">
      ✂ พับครึ่งแนวยาวฉีกตรงเส้นปรุ — ร้านเก็บซ้าย | ลูกค้าเก็บขวา
    </div>`;
  document.getElementById('viewPOModal').classList.add('show');
  // WF-01: สร้าง QR code หลังเปิด modal
  generatePhotoQR(id);
};

// ===== WF-01: QR Code สำหรับถ่ายรูป =====
async function generatePhotoQR(poId) {
  const section  = document.getElementById('poQrSection');
  const canvas   = document.getElementById('poQrCanvas');
  const hint     = document.getElementById('poQrHint');
  if (!section || !canvas) return;

  section.style.display = 'none';
  canvas.innerHTML = '';

  try {
    const res = await apiRequest(`purchase-orders/photo-token?id=${poId}`);
    if (res.status !== 'success') return;

    const { token, expires } = res.data;
    const origin = location.origin + location.pathname.replace(/\/admin\/.*$/, '');
    const url    = `${origin}/photo-upload.html?po=${poId}&token=${encodeURIComponent(token)}&expires=${expires}`;

    // สร้าง QR ด้วย qrcode.js (global QRCode)
    new QRCode(canvas, {
      text:           url,
      width:          160,
      height:         160,
      colorDark:      '#000000',
      colorLight:     '#ffffff',
      correctLevel:   QRCode.CorrectLevel.M,
    });

    hint.textContent = `ลิงก์ใช้ได้ถึง ${new Date(expires * 1000).toLocaleTimeString('th-TH', {hour:'2-digit', minute:'2-digit'})} น.`;
    section.style.display = 'block';
  } catch (e) {
    // QR ไม่สำคัญ ไม่แสดง error
    console.warn('QR generation failed:', e);
  }
}

// ===== Sellers =====
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
    empty.textContent = 'ไม่พบผู้ขาย — กด + ผู้ขายใหม่';
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
  document.getElementById('blacklistAlertOverlay')?.remove();
  const dateStr = s.blacklisted_at
    ? new Date(s.blacklisted_at.replace(' ', 'T')).toLocaleDateString('th-TH', { year: 'numeric', month: 'short', day: 'numeric' })
    : '-';
  const overlay = document.createElement('div');
  overlay.id = 'blacklistAlertOverlay';
  overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:9999;display:flex;align-items:center;justify-content:center';
  overlay.innerHTML = `
    <div style="background:#fff;border-radius:12px;padding:28px 32px;max-width:420px;width:90%;box-shadow:0 8px 32px rgba(0,0,0,0.2)">
      <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px">
        <div style="font-size:32px">⛔</div>
        <div>
          <div style="font-size:17px;font-weight:700;color:#c00">ผู้ขายรายนี้อยู่ในบัญชีดำ</div>
          <div style="font-size:14px;color:#555;margin-top:2px">${escapeHtml(s.full_name)}</div>
        </div>
      </div>
      <div style="background:#fff5f5;border:1px solid #fca5a5;border-radius:8px;padding:12px;margin-bottom:16px;font-size:13px">
        <div><strong>เหตุผล:</strong> ${escapeHtml(s.blacklist_reason || 'ไม่ได้ระบุ')}</div>
        <div style="margin-top:4px;color:#888"><strong>วันที่:</strong> ${dateStr}</div>
      </div>
      <div style="font-size:13px;color:#555;margin-bottom:20px">ท่านต้องการดำเนินการรับซื้อต่อหรือไม่?</div>
      <div style="display:flex;gap:10px;justify-content:flex-end">
        <button id="blacklistCancelBtn" class="btn btn-secondary">ยกเลิก</button>
        <button id="blacklistConfirmBtn" style="background:#c00;color:#fff;border:none;padding:8px 20px;border-radius:6px;cursor:pointer;font-family:inherit;font-size:14px;font-weight:600">ดำเนินการต่อ</button>
      </div>
    </div>`;
  document.body.appendChild(overlay);
  document.getElementById('blacklistCancelBtn').onclick = () => overlay.remove();
  document.getElementById('blacklistConfirmBtn').onclick = () => { overlay.remove(); onConfirm(); };
}

function doSelectSeller(s) {
  selectedSeller = s;
  const box = document.getElementById('selectedSellerBox');
  const wrap = document.createElement('div');
  wrap.className = 'selected-seller';
  wrap.innerHTML = `<div><strong>${escapeHtml(s.full_name)}</strong></div>`;
  if (s.id_card) wrap.innerHTML += `<div>เลขบัตร: ${maskIdCard(s.id_card)}</div>`;
  if (s.phone)   wrap.innerHTML += `<div>โทร: ${escapeHtml(s.phone)}</div>`;
  // WF-03: แสดง badge ถ้าอยู่ใน blacklist (หลัง confirm popup แล้ว)
  if (s.is_blacklisted == 1) {
    wrap.innerHTML += `<div style="color:#c00;font-size:12px;font-weight:600;margin-top:6px;padding:4px 8px;background:#fff5f5;border-radius:4px;display:inline-block">⛔ ผู้ขายรายนี้อยู่ในบัญชีดำ</div>`;
  }
  const btn = document.createElement('button');
  btn.className = 'btn btn-sm btn-secondary';
  btn.textContent = 'เปลี่ยนผู้ขาย';
  btn.addEventListener('click', clearSeller);
  wrap.appendChild(btn);
  box.innerHTML = '';
  box.appendChild(wrap);
  document.getElementById('searchSellerInput').value = '';
  document.getElementById('sellerSearchResults').style.display = 'none';
}

function clearSeller() {
  selectedSeller = null;
  document.getElementById('selectedSellerBox').innerHTML = '<div class="text-muted" style="font-size:13px">ยังไม่ได้เลือกผู้ขาย</div>';
}

function openNewSellerModal() {
  // Reset form
  document.getElementById('newSellerModal').classList.add('show');
  document.getElementById('newFullName').value = document.getElementById('searchSellerInput').value || '';
  document.getElementById('newIdCard').value = '';
  document.getElementById('newPhone').value = '';
  document.getElementById('newAddress').value = '';
  document.getElementById('newVehiclePlate').value = '';

  // Reset ID card photo
  pendingSellerIdPhoto = null;
  document.getElementById('sellerIdPhotoPreview').style.display = 'none';
  const btn = document.getElementById('sellerIdPhotoBtn');
  btn.textContent = '+';
  btn.classList.remove('has-photo');
}

async function saveNewSeller() {
  const payload = {
    full_name: document.getElementById('newFullName').value.trim(),
    id_card: document.getElementById('newIdCard').value.replace(/\D/g, ''),
    phone: document.getElementById('newPhone').value.trim(),
    address: document.getElementById('newAddress').value.trim(),
    vehicle_plate: document.getElementById('newVehiclePlate').value.trim(),
  };
  if (!payload.full_name) { showNotification('กรุณากรอกชื่อ-นามสกุล', 'error'); return; }
  if (payload.id_card && payload.id_card.length !== 13) {
    showNotification('เลขบัตรต้อง 13 หลัก', 'error'); return;
  }
  const res = await apiRequest('sellers', 'POST', payload);
  if (res.status === 'success') {
    const sellerId = res.data.id;
    showNotification('เพิ่มผู้ขายสำเร็จ', 'success');

    // อัปโหลดรูปบัตรประชาชนถ้ามี
    if (pendingSellerIdPhoto) {
      await uploadSellerIdPhoto(sellerId, pendingSellerIdPhoto);
      pendingSellerIdPhoto = null;
    }

    document.getElementById('newSellerModal').classList.remove('show');
    selectSeller({ ...payload, id: sellerId, is_blacklisted: 0 });
  } else {
    showNotification(res.message || 'ผิดพลาด', 'error');
  }
}

// ===== Utilities =====
function escapeHtml(s) {
  if (s == null) return '';
  return String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

function maskIdCard(id) {
  if (!id) return '-';
  if (id.length !== 13) return id;
  return `${id[0]}-${id.substring(1, 5)}-XXXXX-${id.substring(10, 12)}-${id[12]}`;
}

function formatDateTime(s) {
  if (!s) return '-';
  return new Date(s.replace(' ', 'T')).toLocaleString('th-TH', { dateStyle: 'short', timeStyle: 'short' });
}

// banner removed — no-op
function updateBranchBanner() {}

// ================================================================
// PHOTO PICKER MODULE — LINE-style "+" button, camera, upload
// ================================================================

// ── Open bottom sheet ──────────────────────────────────────
window.openPhotoPicker = function(type, id) {
  activePhotoTarget = { type, id };
  document.getElementById('photoSheetOverlay').classList.add('show');
};

// ── Close bottom sheet ─────────────────────────────────────
function closePhotoSheet() {
  document.getElementById('photoSheetOverlay').classList.remove('show');
  activePhotoTarget = null;
}

// ── Open camera (webcam / mobile camera) ───────────────────
async function openCamera(facingMode = 'environment') {
  closePhotoSheet();
  const overlay = document.getElementById('cameraOverlay');
  const video = document.getElementById('cameraVideo');
  const preview = document.getElementById('cameraPreviewOverlay');

  overlay.classList.add('show');
  preview.classList.remove('show');

  try {
    if (cameraStream) {
      cameraStream.getTracks().forEach(t => t.stop());
    }
    cameraStream = await navigator.mediaDevices.getUserMedia({
      video: { facingMode, width: { ideal: 1920 }, height: { ideal: 1080 } },
      audio: false,
    });
    video.srcObject = cameraStream;
    video.play();
  } catch (err) {
    // ถ้า environment ไม่ได้ ให้ลอง user (selfie)
    if (facingMode === 'environment') {
      try {
        cameraStream = await navigator.mediaDevices.getUserMedia({
          video: { facingMode: 'user', width: { ideal: 1920 }, height: { ideal: 1080 } },
          audio: false,
        });
        video.srcObject = cameraStream;
        video.play();
      } catch (err2) {
        showNotification('ไม่สามารถเปิดกล้องได้: ' + err2.message, 'error');
        overlay.classList.remove('show');
      }
    } else {
      showNotification('ไม่สามารถเปิดกล้องได้: ' + err.message, 'error');
      overlay.classList.remove('show');
    }
  }
}

// ── Stop camera stream ─────────────────────────────────────
function stopCamera() {
  if (cameraStream) {
    cameraStream.getTracks().forEach(t => t.stop());
    cameraStream = null;
  }
  const video = document.getElementById('cameraVideo');
  video.srcObject = null;
}

// ── Capture photo from webcam ──────────────────────────────
function capturePhoto() {
  const video = document.getElementById('cameraVideo');
  const canvas = document.getElementById('cameraCanvas');
  const flash = document.getElementById('cameraFlash');

  // Flash effect
  flash.classList.remove('flash-out');
  flash.classList.add('flash');
  setTimeout(() => {
    flash.classList.remove('flash');
    flash.classList.add('flash-out');
  }, 100);

  canvas.width = video.videoWidth || 1280;
  canvas.height = video.videoHeight || 720;
  const ctx = canvas.getContext('2d');
  ctx.drawImage(video, 0, 0, canvas.width, canvas.height);

  // Show preview
  const previewImg = document.getElementById('cameraPreviewImg');
  previewImg.src = canvas.toDataURL('image/jpeg', 0.85);
  document.getElementById('cameraPreviewOverlay').classList.add('show');
}

// ── Confirm captured photo ─────────────────────────────────
function confirmPhoto() {
  const canvas = document.getElementById('cameraCanvas');

  // Convert canvas to File
  canvas.toBlob(async (blob) => {
    const file = new File([blob], `photo_${Date.now()}.jpg`, { type: 'image/jpeg' });

    if (activePhotoTarget) {
      if (activePhotoTarget.type === 'item') {
        pendingItemPhotos[activePhotoTarget.id] = file;
      } else if (activePhotoTarget.type === 'seller-id') {
        pendingSellerIdPhoto = file;
        showSellerIdPhotoPreview(file);
      }
    }

    // Close camera
    closeCamera();
    renderCart(); // refresh to show ✓

    showUploadToast('📸 ถ่ายรูปสำเร็จ', 1500);
  }, 'image/jpeg', 0.85);
}

// ── Close camera overlay ───────────────────────────────────
function closeCamera() {
  stopCamera();
  document.getElementById('cameraOverlay').classList.remove('show');
  document.getElementById('cameraPreviewOverlay').classList.remove('show');
}

// ── Retry photo (go back to live view) ─────────────────────
function retryPhoto() {
  document.getElementById('cameraPreviewOverlay').classList.remove('show');
}

// ── Show ID card photo preview ─────────────────────────────
function showSellerIdPhotoPreview(file) {
  const preview = document.getElementById('sellerIdPhotoPreview');
  const thumb = document.getElementById('sellerIdPhotoThumb');
  const btn = document.getElementById('sellerIdPhotoBtn');

  preview.style.display = 'block';
  thumb.src = URL.createObjectURL(file);
  btn.textContent = '✓';
  btn.classList.add('has-photo');
}

// ── Upload seller ID card photo ────────────────────────────
async function uploadSellerIdPhoto(sellerId, file) {
  const formData = new FormData();
  formData.append('photo', file);

  try {
    const res = await fetch(`/api/index.php/sellers/photo?id=${sellerId}`, {
      method: 'POST',
      headers: {
        'Authorization': 'Bearer ' + getToken(),
      },
      body: formData,
    });
    const json = await res.json();
    if (json.status === 'success') {
      showUploadToast('✅ อัปโหลดรูปบัตรประชาชนสำเร็จ', 1500);
    } else {
      console.warn('Seller photo upload failed:', json);
    }
  } catch (err) {
    console.error('Seller photo upload error:', err);
  }
}

// ── Upload single item photo to PO ─────────────────────────
async function uploadItemPhoto(poId, file) {
  const formData = new FormData();
  formData.append('photo', file);

  try {
    const res = await fetch(`/api/index.php/purchase-orders/photos?id=${poId}`, {
      method: 'POST',
      headers: {
        'Authorization': 'Bearer ' + getToken(),
      },
      body: formData,
    });
    const json = await res.json();
    if (json.status !== 'success') {
      console.warn('Item photo upload failed:', json);
    }
    return json;
  } catch (err) {
    console.error('Item photo upload error:', err);
  }
}

// ── Upload progress bar ────────────────────────────────────
function showUploadProgress(percent) {
  const bar = document.getElementById('uploadProgress');
  const fill = document.getElementById('uploadProgressBar');
  if (percent === undefined) {
    bar.classList.add('show');
    fill.style.width = '0%';
  } else {
    fill.style.width = Math.min(percent, 100) + '%';
    if (percent >= 100) {
      setTimeout(() => bar.classList.remove('show'), 600);
    }
  }
}
function hideUploadProgress() {
  document.getElementById('uploadProgress').classList.remove('show');
}

// ── Upload toast ───────────────────────────────────────────
function showUploadToast(msg, duration = 0) {
  const el = document.getElementById('toastUpload');
  el.textContent = msg;
  el.classList.add('show');
  if (duration > 0) {
    setTimeout(() => el.classList.remove('show'), duration);
  }
}

// ── Get JWT token from storage ────────────────────────────
function getToken() {
  return localStorage.getItem('posToken') || sessionStorage.getItem('posToken') || '';
}

// ================================================================
// PHOTO PICKER EVENT BINDINGS
// ================================================================
document.addEventListener('DOMContentLoaded', () => {
  // Bottom sheet: ถ่ายรูป
  document.getElementById('sheetCameraBtn').addEventListener('click', () => {
    openCamera('environment');
  });

  // Bottom sheet: เลือกรูป
  document.getElementById('sheetGalleryBtn').addEventListener('click', () => {
    closePhotoSheet();
    document.getElementById('photoFileInput').click();
  });

  // Bottom sheet: ยกเลิก
  document.getElementById('sheetCancelBtn').addEventListener('click', closePhotoSheet);
  document.getElementById('photoSheetOverlay').addEventListener('click', (e) => {
    if (e.target === e.currentTarget) closePhotoSheet();
  });

  // File input change
  document.getElementById('photoFileInput').addEventListener('change', (e) => {
    const file = e.target.files[0];
    if (!file) return;

    if (activePhotoTarget) {
      if (activePhotoTarget.type === 'item') {
        pendingItemPhotos[activePhotoTarget.id] = file;
        renderCart();
      } else if (activePhotoTarget.type === 'seller-id') {
        pendingSellerIdPhoto = file;
        showSellerIdPhotoPreview(file);
      }
    }

    e.target.value = ''; // reset so same file can be re-selected
    showUploadToast('🖼️ เลือกรูปสำเร็จ', 1500);
  });

  // Camera controls
  document.getElementById('cameraCaptureBtn').addEventListener('click', capturePhoto);
  document.getElementById('cameraConfirmBtn').addEventListener('click', confirmPhoto);
  document.getElementById('cameraRetryBtn').addEventListener('click', retryPhoto);
  document.getElementById('cameraCloseBtn').addEventListener('click', closeCamera);
  document.getElementById('cameraOverlay').addEventListener('click', (e) => {
    if (e.target === e.currentTarget) closeCamera();
  });

  // Seller ID card photo
  document.getElementById('sellerIdPhotoBtn').addEventListener('click', () => {
    openPhotoPicker('seller-id', 0);
  });
  document.getElementById('sellerIdPhotoRemove').addEventListener('click', () => {
    pendingSellerIdPhoto = null;
    document.getElementById('sellerIdPhotoPreview').style.display = 'none';
    const btn = document.getElementById('sellerIdPhotoBtn');
    btn.textContent = '+';
    btn.classList.remove('has-photo');
  });
});
