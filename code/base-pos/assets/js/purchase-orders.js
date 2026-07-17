// ===== Global State =====
let branches = [];
let categories = [];
let cart = [];
let selectedSeller = null;
let recentPOs = [];
let globalTier = { level: 1 };
let currentCatalogItem = null; // { id, name, unit, price, tierPrices: [] }

// ===== Signature State =====
let pendingSignatureDataUrl = null; // data URL of signature or null
let pendingSignatureBlob = null;    // Blob for upload
let isDrawing = false;

// ===== Photo State =====
let pendingItemPhotos = {};   // { [cartIndex]: File }
let pendingSellerIdPhoto = null; // File | null
let pendingNewItemPhoto = null; // File | null — ถ่ายตอนคีย์ก่อนกดเพิ่ม
let activePhotoTarget = null; // { type: 'item', index: N } | { type: 'seller-id' }
let cameraStream = null;

// === Cart State Export (สำหรับ common.js save ก่อน 401) ===
window.getCurrentCartState = function() {
  if (cart.length === 0 && !selectedSeller) return null;
  return {
    branch_id: document.getElementById('branchSelect')?.value,
    seller: selectedSeller,
    items: cart,
    tier: globalTier,
    hasSignature: !!pendingSignatureDataUrl,
  };
};

function restoreCartFromBackup() {
  const saved = restoreCartState();
  if (!saved) return;

  const confirmed = confirm(
    '⚠️ พบข้อมูลที่ยังไม่ได้บันทึกจาก session ก่อนหน้า\n' +
    `(${saved.items?.length || 0} รายการ, ผู้ขาย: ${saved.seller?.full_name || 'ไม่มี'})\n\n` +
    'ต้องการกู้คืนข้อมูลหรือไม่?'
  );

  if (!confirmed) {
    clearCartState();
    return;
  }

  // Restore branch
  if (saved.branch_id) {
    document.getElementById('branchSelect').value = saved.branch_id;
  }

  // Restore seller
  if (saved.seller) {
    selectSeller(saved.seller);
  }

  // Restore cart items
  if (saved.items?.length) {
    cart = saved.items;
    renderCart();
  }

  // Restore tier
  if (saved.tier?.level) {
    globalTier.level = saved.tier.level;
    const btns = document.querySelectorAll('.global-tier-btn');
    if (btns[saved.tier.level - 1]) {
      setTierActive(btns[saved.tier.level - 1], saved.tier.level);
    }
  }

  clearCartState();
  showNotification('กู้คืนข้อมูลสำเร็จ', 'success');
}

// ===== Init =====
document.addEventListener('DOMContentLoaded', async () => {
  await loadBranches();
  await loadCategories();
  await loadRecentPOs();
  buildTierButtons([]);
  restoreCartFromBackup();

  document.getElementById('searchSellerInput').addEventListener('input', debounce(searchSellers, 300));
  document.getElementById('createNewSellerBtn').addEventListener('click', openNewSellerModal);
  document.getElementById('saveNewSellerBtn').addEventListener('click', saveNewSeller);
  document.getElementById('addItemBtn').addEventListener('click', addItemToCart);
  document.getElementById('savePOBtn').addEventListener('click', savePurchaseOrder);
  document.getElementById('clearPOBtn').addEventListener('click', clearAll);

  // IMP-6: Keyboard shortcut Ctrl+Enter = บันทึกใบรับซื้อ
  document.addEventListener('keydown', function(e) {
    if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
      const saveBtn = document.getElementById('savePOBtn');
      if (saveBtn && !saveBtn.disabled) {
        e.preventDefault();
        savePurchaseOrder();
      }
    }
  });

  document.querySelectorAll('.close-modal').forEach(b =>
    b.addEventListener('click', e => e.target.closest('.modal').classList.remove('show'))
  );

  document.getElementById('itemQuantity').addEventListener('input', updateItemTotal);
  document.getElementById('itemWeightDeduct').addEventListener('input', updateItemTotal);

  // IMP: focus → select all — แคชเชียร์พิมพ์ตัวเลขแทนที่ได้เลย ไม่ต้องกดลบ
  ['itemQuantity', 'itemWeightDeduct'].forEach(id =>
    document.getElementById(id).addEventListener('focus', function() { this.select(); })
  );
  document.getElementById('itemCategorySelect').addEventListener('change', saveCategoryToCatalog);
  document.getElementById('itemName').addEventListener('input', debounce(function(e) {
    const q = e.target.value.trim();
    if (currentCatalogItem && currentCatalogItem.name !== q) {
      clearCatalogSelection();
    }
    if (q.length >= 1) {
      searchCatalogImmediate(q);
    } else {
      closeCatalogDropdown();
    }
  }, 250));

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
    // เว้นบรรทัดที่ 2 ไว้เสมอ (แม้ไม่มีราคา) ปุ่มจะไม่เปลี่ยนความสูงตอนเลือก/ไม่เลือกสินค้า
    const priceStr = tp?.price > 0 ? `\n${parseFloat(tp.price).toFixed(2)} ฿` : '\n ';
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
    updatePriceDisplay(parseFloat(tp.price));
    updateItemTotal();
  }
}

// ===== Item Search =====
function clearCatalogSelection() {
  document.getElementById('itemCatalogId').value = '';
  document.getElementById('itemCategoryId').value = '';
  document.getElementById('itemCategorySelect').value = '';
  document.getElementById('itemUnit').value = 'กก.';
  document.getElementById('itemUnitPrice').value = '0';
  document.getElementById('itemPriceDisplay').textContent = '—';
  document.getElementById('itemPriceDisplay').style.color = '#999';
  document.getElementById('itemTotalPreview').textContent = 'เลือกสินค้า';
  document.getElementById('itemTotalPreview').style.color = '#999';
  currentCatalogItem = null;
  resetItemPhotoBtn();
  buildTierButtons([]);
}

function closeCatalogDropdown() {
  const box = document.getElementById('itemCatalogResults');
  box.innerHTML = '';
  box.style.display = 'none';
}

async function searchCatalogImmediate(q) {
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
    focusNextField();
    return;
  }

  // ผลลัพธ์เดียว → auto-select (แคชเชียร์จำรหัสได้ พิมพ์บางส่วนก็เจอ)
  if (items.length === 1) {
    closeCatalogDropdown();
    selectCatalogItem(items[0]);
    focusNextField();
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
    requiresPreciousReceipt: item.requires_precious_receipt == 1,
    requiresIdCard: item.requires_id_card == 1,
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

  // auto-fill price → แสดงใน priceDisplay (read-only)
  let price = 0;
  if (globalTier.level && tierPrices[globalTier.level - 1]?.price > 0) {
    price = parseFloat(tierPrices[globalTier.level - 1].price);
  } else {
    price = currentCatalogItem.price > 0 ? currentCatalogItem.price : 0;
  }
  updatePriceDisplay(price);

  // reset น้ำหนัก
  document.getElementById('itemQuantity').value = '1';
  document.getElementById('itemWeightDeduct').value = '0';
  updateItemTotal();
}

// ===== Keyboard Flow Helper =====
function focusNextField() {
  // ขยับ focus ไปช่องน้ำหนัก ให้แคชเชียร์คีย์ Tab ต่อเนื่องได้
  const qty = document.getElementById('itemQuantity');
  if (qty) setTimeout(() => qty.focus(), 50);
}

// ===== Price Display (read-only, auto-fill from tier) =====
function updatePriceDisplay(price) {
  const el = document.getElementById('itemPriceDisplay');
  if (price > 0) {
    el.textContent = formatCurrency(price);
    el.style.color = '#059669';
    el.style.fontWeight = '700';
  } else {
    el.textContent = '—';
    el.style.color = '#999';
  }
  document.getElementById('itemUnitPrice').value = price.toFixed(2);
}

// ===== Item Total Preview =====
function updateItemTotal() {
  const q = parseFloat(document.getElementById('itemQuantity').value || 0);
  const d = parseFloat(document.getElementById('itemWeightDeduct').value || 0);
  const net = Math.max(0, q - d);
  const p = parseFloat(document.getElementById('itemUnitPrice').value) || 0;

  const totalEl = document.getElementById('itemTotalPreview');
  if (q > 0 && p > 0) {
    totalEl.textContent = formatCurrency(net * p);
    totalEl.style.color = '#059669';
    totalEl.style.fontWeight = '700';
  } else {
    totalEl.textContent = q === 0 ? 'กำหนดน้ำหนัก' : 'เลือกราคา (บิล)';
    totalEl.style.color = '#999';
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
  const tempId = 'item_' + Date.now() + '_' + Math.random().toString(36).slice(2, 6);

  // associate pending photo ถ้ามี
  if (pendingNewItemPhoto) {
    pendingItemPhotos[tempId] = pendingNewItemPhoto;
    pendingNewItemPhoto = null;
  }

  const isPrecious = currentCatalogItem?.requiresPreciousReceipt || false;
  const isIdCard = currentCatalogItem?.requiresIdCard || false;
  cart.push({
    _tempId: tempId,
    catalog_id: parseInt(catalogId, 10),
    item_name: name,
    category_id: catId ? parseInt(catId, 10) : null,
    quantity: qty,
    weight_deduction: deduct,
    net_quantity: netQty,
    unit,
    unit_price: price,
    total_price: netQty * price,
    price_tier: globalTier.level || null,
    requires_precious_receipt: isPrecious ? 1 : 0,
    requires_id_card: isIdCard ? 1 : 0,
    notes: '',
  });

  // reset form item (คง globalTier ไว้)
  resetItemPhotoBtn();
  document.getElementById('itemName').value = '';
  document.getElementById('itemCatalogId').value = '';
  document.getElementById('itemCategoryId').value = '';
  document.getElementById('itemCategorySelect').value = '';
  document.getElementById('itemUnitPrice').value = '0';
  document.getElementById('itemQuantity').value = '1';
  document.getElementById('itemWeightDeduct').value = '0';
  document.getElementById('itemTotalPreview').textContent = 'เลือกสินค้าจากแคตาล็อก';
  document.getElementById('itemTotalPreview').style.color = '#999';
  currentCatalogItem = null;
  buildTierButtons([]); // reset tier button label (ไม่ reset globalTier.level)

  renderCart();
  saveCartState(window.getCurrentCartState());
}

window.removeFromCart = function(idx) {
  // Remove associated photo
  delete pendingItemPhotos[cart[idx]._tempId];
  cart.splice(idx, 1);
  renderCart();
  updatePhotoUI();
  updatePreciousWarning();
  updateIdCardWarning();
  saveCartState(window.getCurrentCartState());
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
  const big = document.getElementById('cartTotalAmountBig');
  if (big) big.textContent = formatCurrency(total);
  document.getElementById('cartTotalItems').textContent = `${totalKg.toFixed(2)} กก. (${cart.length} รายการ)`;

  updatePreciousWarning();
  updateIdCardWarning();
}

function updateIdCardWarning() {
  const hasIdCard = cart.some(it => it.requires_id_card == 1);
  const sellerCard = document.getElementById('poSellerCard');
  if (!sellerCard) return;

  let warn = sellerCard.querySelector('.id-card-warning');
  if (hasIdCard) {
    if (!warn) {
      warn = document.createElement('div');
      warn.className = 'id-card-warning';
      warn.style.cssText = 'padding:8px 10px;background:#fff7ed;border:1px solid #fed7aa;border-radius:6px;font-size:12px;line-height:1.5;margin-bottom:4px;';
      warn.innerHTML = '<span style="color:#b45309;font-weight:600">⚠️ สินค้าต้องใช้บัตรประชาชน: กรุณากรอกเลขบัตรประชาชนผู้ขายก่อนบันทึก</span>';
      sellerCard.insertBefore(warn, sellerCard.firstChild);
    }
  } else {
    if (warn) warn.remove();
  }
}

function updatePreciousWarning() {
  const hasPrecious = cart.some(it => it.requires_precious_receipt == 1);
  const sellerCard = document.getElementById('poSellerCard');
  if (!sellerCard) return;

  let warn = sellerCard.querySelector('.precious-warning');
  let actions = document.getElementById('preciousActions');
  if (hasPrecious) {
    if (!warn) {
      warn = document.createElement('div');
      warn.className = 'precious-warning';
      warn.innerHTML = '<span style="color:#d97706;font-weight:600">⚠️ โลหะมีค่า — ต้องถ่ายบัตรประชาชน + เซ็นรับรองก่อนบันทึก</span>';
      sellerCard.insertBefore(warn, sellerCard.firstChild);
    }
    if (actions) actions.style.display = 'flex';
  } else {
    if (warn) warn.remove();
    if (actions) actions.style.display = 'none';
  }
}

// ===== Pre-Flight Checklist (IMP-1) =====
function showPreFlightChecklist() {
  return new Promise((resolve) => {
    const hasPrecious = cart.some(it => it.requires_precious_receipt == 1);
    const hasIdCardRequired = cart.some(it => it.requires_id_card == 1);
    const sellerComplete = !!(selectedSeller.full_name && selectedSeller.phone);
    const signatureOk = !hasPrecious || !!pendingSignatureDataUrl;
    const hasPhotos = Object.keys(pendingItemPhotos).length > 0;

    const overlay = document.createElement('div');
    overlay.className = 'modal-overlay';
    overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:9999;display:flex;align-items:center;justify-content:center';
    overlay.innerHTML = `
      <div style="background:#fff;border-radius:12px;padding:28px 32px;max-width:460px;width:90%;box-shadow:0 8px 32px rgba(0,0,0,0.2)">
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:20px">
          <div style="font-size:28px">✅</div>
          <div>
            <div style="font-size:18px;font-weight:700">ตรวจสอบก่อนบันทึก</div>
            <div style="font-size:13px;color:#6b7280">กรุณาตรวจสอบข้อมูลให้พร้อมก่อนบันทึก</div>
          </div>
        </div>

        <div style="display:flex;flex-direction:column;gap:10px;margin-bottom:20px">
          <div class="checklist-item" style="display:flex;align-items:center;gap:10px;padding:8px 12px;background:${sellerComplete ? '#f0fdf4' : '#fef2f2'};border-radius:8px">
            <span style="font-size:18px">${sellerComplete ? '✅' : '⚠️'}</span>
            <span style="font-size:14px">ผู้ขาย: <strong>${escapeHtml(selectedSeller.full_name)}</strong> ${sellerComplete ? '' : '(ข้อมูลไม่ครบ — ควรมีเบอร์โทร)'}</span>
          </div>
          <div class="checklist-item" style="display:flex;align-items:center;gap:10px;padding:8px 12px;background:#f0fdf4;border-radius:8px">
            <span style="font-size:18px">✅</span>
            <span style="font-size:14px">สินค้า: <strong>${cart.length}</strong> รายการ</span>
          </div>
          <div class="checklist-item" style="display:flex;align-items:center;gap:10px;padding:8px 12px;background:${signatureOk ? '#f0fdf4' : '#fef2f2'};border-radius:8px">
            <span style="font-size:18px">${signatureOk ? '✅' : '⚠️'}</span>
            <span style="font-size:14px">ลายเซ็นรับรอง: ${hasPrecious ? '<strong>จำเป็น</strong> (สินค้าโลหะมีค่า)' : '<strong>ไม่จำเป็น</strong>'}</span>
          </div>
          <div class="checklist-item" style="display:flex;align-items:center;gap:10px;padding:8px 12px;background:#f0fdf4;border-radius:8px">
            <span style="font-size:18px">${hasPhotos ? '✅' : '⏭️'}</span>
            <span style="font-size:14px">รูปถ่ายสินค้า: ${hasPhotos ? '<strong>' + Object.keys(pendingItemPhotos).length + ' รูป</strong>' : '<span style="color:#888">ไม่ได้ถ่าย</span>'}</span>
          </div>
        </div>

        <div style="margin-bottom:20px">
          <label style="display:flex;align-items:flex-start;gap:8px;cursor:pointer;font-size:14px">
            <input type="checkbox" id="checklistConfirm" style="margin-top:2px;width:16px;height:16px">
            <span>ฉันตรวจสอบข้อมูลข้างต้นแล้วว่าถูกต้อง และพร้อมบันทึกใบรับซื้อ</span>
          </label>
        </div>

        <div style="display:flex;gap:10px;justify-content:flex-end">
          <button class="btn btn-secondary" id="checklistCancelBtn">ตรวจสอบอีกครั้ง</button>
          <button class="btn btn-primary" id="checklistSaveBtn" disabled style="opacity:0.6">บันทึกใบรับซื้อ</button>
        </div>
      </div>`;
    document.body.appendChild(overlay);

    // Enable save button only when checkbox is checked
    document.getElementById('checklistConfirm').addEventListener('change', function() {
      const btn = document.getElementById('checklistSaveBtn');
      btn.disabled = !this.checked;
      btn.style.opacity = this.checked ? '1' : '0.6';
    });

    document.getElementById('checklistCancelBtn').onclick = () => {
      overlay.remove();
      resolve(false);
    };
    document.getElementById('checklistSaveBtn').onclick = () => {
      overlay.remove();
      resolve(true);
    };
  });
}

// ===== Save PO =====
async function savePurchaseOrder() {
  if (!selectedSeller) { showNotification('กรุณาเลือกผู้ขาย', 'error'); return; }
  if (cart.length === 0) { showNotification('กรุณาเพิ่มรายการสินค้า', 'error'); return; }

  // Rule A: ทุก order ต้องมีข้อมูลผู้ขายอย่างน้อย 1 อย่าง (ชื่อ/เบอร์/ทะเบียน/รูป)
  const hasAnySeller = !!(selectedSeller.full_name || selectedSeller.phone || selectedSeller.vehicle_plate || pendingSellerIdPhoto || selectedSeller.id_card_photo);
  if (!hasAnySeller) {
    showNotification('⚠️ กรุณาให้ข้อมูลผู้ขายอย่างน้อย 1 อย่าง (ชื่อ / เบอร์โทร / ทะเบียนรถ / รูปถ่าย)', 'error');
    return;
  }

  // Rule B: signature บังคับเฉพาะ โลหะมีค่า/ทองแดง (ม.357 — รับรองของได้มาโดยสุจริต)
  const hasPrecious = cart.some(it => it.requires_precious_receipt == 1);
  if (hasPrecious && !pendingSignatureDataUrl) {
    showNotification('⚠️ สินค้าโลหะมีค่า/ทองแดง — กรุณาเซ็นรับรองว่าของได้มาโดยสุจริตก่อนบันทึก', 'error');
    return;
  }

  // ตรวจสอบสินค้าต้องใช้บัตรประชาชน (requires_id_card)
  const hasIdCard = cart.some(it => it.requires_id_card == 1);
  if (hasIdCard && !selectedSeller.id_card) {
    showNotification('⚠️ สินค้ารายการนี้ต้องใช้บัตรประชาชน — กรุณากรอกเลขบัตรประชาชนผู้ขายก่อนบันทึก', 'error');
    document.getElementById('searchSellerInput').focus();
    return;
  }

  // WF-03: soft reminder ก่อน save ถ้าเป็น blacklist seller (ไม่ block)
  if (selectedSeller.is_blacklisted == 1) {
    showNotification('⚠️ กำลังบันทึก PO ให้ผู้ขายที่อยู่ในบัญชีดำ', 'warning');
  }

  // IMP-1: Pre-flight checklist ก่อนบันทึก
  const checklistConfirmed = await showPreFlightChecklist();
  if (!checklistConfirmed) return;

  const idempotencyKey = Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 8);

  const payload = {
    branch_id: parseInt(document.getElementById('branchSelect').value, 10),
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
    idempotency_key: idempotencyKey,
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

    // อัปโหลดลายเซ็น
    if (pendingSignatureBlob) {
      await uploadItemPhoto(poId, pendingSignatureBlob);
    }

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
  pendingSignatureDataUrl = null;
  pendingSignatureBlob = null;
  resetItemPhotoBtn();
  resetSellerPhotoUI();
  updatePhotoUI();

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
  updatePreciousWarning();
  updateIdCardWarning();
  // Reset signature UI
  const sigStatus = document.getElementById('sigStatus');
  if (sigStatus) {
    sigStatus.textContent = 'ยังไม่เซ็น';
    sigStatus.style.color = '#888';
  }
  clearCartState();
}

// ===== Recent POs =====
async function loadRecentPOs() {
  const tbody = document.querySelector('#recentPOTable tbody');
  showTableLoading(tbody, 6, 4);
  const res = await apiRequest('purchase-orders?limit=10');
  if (res.status !== 'success') {
    tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;color:#888">โหลดใบรับซื้อล่าสุดไม่สำเร็จ</td></tr>';
    return;
  }
  recentPOs = res.data.items || [];
  if (recentPOs.length === 0) {
    tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;color:#888">ยังไม่มีใบรับซื้อ</td></tr>';
    return;
  }
  tbody.innerHTML = recentPOs.map(po => `
    <tr>
      <td><a href="#" onclick="showReceipt(${po.id});event.preventDefault()">${escapeHtml(po.reference_no)}</a></td>
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
      <div style="font-size:16px;font-weight:800;letter-spacing:0">ใบรับซื้อของเก่า</div>
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
      <span style="font-size:11px;opacity:.8">${po.payment_method === 'cash' ? 'เงินสด' : 'โอนธนาคาร'}</span>
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
      <span><i class="icon-image"></i></span>
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

  // G2: Print button handler — open print-receipt.html in new tab
  const btnPrint = document.getElementById('btnOpenPrint');
  if (btnPrint) {
    btnPrint.onclick = () => window.open(`print-receipt.html?id=${id}&auto=1`, '_blank');
  }

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
      const isBlacklisted = s.is_blacklisted == 1;
      if (isBlacklisted) row.classList.add('seller-item--blacklisted');
      const badgeHtml = isBlacklisted
        ? '<span class="seller-badge-blacklist">⚠️ บัญชีดำ</span>'
        : '';
      const idHtml = s.national_id
        ? `<span class="seller-item__id">${maskIdCard(s.national_id)}</span>`
        : '';
      row.innerHTML =
        `<span class="seller-item__name"><strong>${escapeHtml(s.name)}</strong>${badgeHtml}</span>${idHtml}`;
      // normalize to legacy field names so rest of code works unchanged
      const normalized = { ...s, full_name: s.name, id_card: s.national_id };
      row.addEventListener('click', () => selectSeller(normalized));
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
  // Photo button for already-selected seller
  if (s.id_card_photo) {
    wrap.innerHTML += `<div style="margin-top:4px"><img src="${escapeHtml(s.id_card_photo)}" style="height:36px;border-radius:3px;cursor:pointer;border:1px solid #e2e8f0" onclick="openPhotoPicker('seller-id',0)" title="เปลี่ยนรูปบัตร"></div>`;
  } else {
    wrap.innerHTML += `<div style="margin-top:4px"><button type="button" class="btn btn-sm btn-secondary" onclick="openPhotoPicker('seller-id',0)">📷 เพิ่มรูปบัตร</button></div>`;
  }
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
  pendingSellerIdPhoto = null;
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
  resetSellerPhotoUI();
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
      } else if (activePhotoTarget.type === 'new-item') {
        pendingNewItemPhoto = file;
        showItemPhotoIndicator(file);
      } else if (activePhotoTarget.type === 'seller-id') {
        pendingSellerIdPhoto = file;
        showSellerIdPhotoPreview(file);
      }
    }

    // Close camera
    closeCamera();
    renderCart(); // refresh to show ✓
    updatePhotoUI(); // refresh FAB badge + photo strip

    showUploadToast('ถ่ายรูปสำเร็จ', 1500);
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
  const area = document.getElementById('sellerPhotoArea');
  const icon = document.getElementById('sellerPhotoIcon');
  const title = document.getElementById('sellerPhotoTitle');
  const sub = document.getElementById('sellerPhotoSub');
  const thumb = document.getElementById('sellerPhotoThumb');

  area.classList.add('has-photo');
  icon.textContent = '✅';
  title.textContent = 'ถ่ายรูปบัตรประชาชนแล้ว';
  sub.textContent = 'แตะเพื่อเปลี่ยนรูป | คลิกที่รูปเพื่อลบ';
  thumb.src = URL.createObjectURL(file);
  thumb.style.display = 'block';
}

function resetSellerPhotoUI() {
  const area = document.getElementById('sellerPhotoArea');
  const icon = document.getElementById('sellerPhotoIcon');
  const title = document.getElementById('sellerPhotoTitle');
  const sub = document.getElementById('sellerPhotoSub');
  const thumb = document.getElementById('sellerPhotoThumb');

  area.classList.remove('has-photo');
  icon.innerHTML = '<i class="icon-image"></i>';
  title.textContent = 'เพิ่มรูปถ่ายบัตรประชาชน';
  sub.textContent = 'แตะเพื่อถ่ายรูป หรือเลือกรูป';
  thumb.style.display = 'none';
  if (thumb.src.startsWith('blob:')) URL.revokeObjectURL(thumb.src);
  thumb.src = '';
}

// ── Update Photo Count (inline in cart-summary) + Photo Strip ──────────────────────
function updatePhotoUI() {
  const tempIds = Object.keys(pendingItemPhotos);
  const count = tempIds.length;

  // Inline cart-summary photo count
  const cartPhotoCount = document.getElementById('cartPhotoCount');
  const cartPhotoNum = document.getElementById('cartPhotoNum');
  if (cartPhotoCount && cartPhotoNum) {
    if (count > 0) {
      cartPhotoNum.textContent = count;
      cartPhotoCount.style.display = 'block';
    } else {
      cartPhotoCount.style.display = 'none';
    }
  }

  // Photo strip
  const wrap = document.getElementById('photoStripWrap');
  const strip = document.getElementById('photoStrip');
  const countEl = document.getElementById('photoStripCount');

  if (count > 0) {
    wrap.classList.add('show');
    countEl.innerHTML = `<i class="icon-image"></i> ${count} รูป`;
    // Revoke old blob URLs before re-render
    strip.querySelectorAll('[data-blob-url]').forEach(el => URL.revokeObjectURL(el.dataset.blobUrl));
    strip.innerHTML = tempIds.map(tempId => {
      const file = pendingItemPhotos[tempId];
      const url = URL.createObjectURL(file);
      return `<div class="photo-strip-item">
        <div class="photo-thumb" data-blob-url="${url}" style="background-image:url(${url});background-size:cover;background-position:center" onclick="removePendingPhoto('${tempId}')"></div>
        <span class="photo-strip-remove" onclick="removePendingPhoto('${tempId}')">&times;</span>
      </div>`;
    }).join('');
  } else {
    wrap.classList.remove('show');
    strip.innerHTML = '';
  }
}

// ── Show/hide photo indicator in add-row ────────────────
function showItemPhotoIndicator(file) {
  const indicator = document.getElementById('itemPhotoIndicator');
  const btn = document.getElementById('itemPhotoBtn');
  indicator.textContent = '✓';
  btn.classList.add('has-photo');
}

function resetItemPhotoBtn() {
  pendingNewItemPhoto = null;
  const indicator = document.getElementById('itemPhotoIndicator');
  const btn = document.getElementById('itemPhotoBtn');
  indicator.textContent = '';
  btn.classList.remove('has-photo');
}

// ── Remove pending photo ────────────────────────────────
window.removePendingPhoto = function(tempId) {
  delete pendingItemPhotos[tempId];
  updatePhotoUI();
  renderCart();
};

// ── Upload seller ID card photo ────────────────────────────
async function uploadSellerIdPhoto(sellerId, file) {
  const formData = new FormData();
  formData.append('photo', file);

  try {
    const res = await fetch(`/api/index.php/sellers/photo?id=${sellerId}`, {
      method: 'POST',
      credentials: 'include',
      body: formData,
    });
    const json = await res.json();
    if (json.status === 'success') {
      showUploadToast('อัปโหลดรูปบัตรประชาชนสำเร็จ', 1500);
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
      credentials: 'include',
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
  const sheetCloseBtn = document.getElementById('sheetCloseBtn');
  if (sheetCloseBtn) sheetCloseBtn.addEventListener('click', closePhotoSheet);
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
      } else if (activePhotoTarget.type === 'new-item') {
        pendingNewItemPhoto = file;
        showItemPhotoIndicator(file);
      } else if (activePhotoTarget.type === 'seller-id') {
        pendingSellerIdPhoto = file;
        showSellerIdPhotoPreview(file);
      }
    }

    e.target.value = ''; // reset so same file can be re-selected
    updatePhotoUI();
    showUploadToast('เลือกรูปสำเร็จ', 1500);
  });

  // Item photo button (add-row)
  document.getElementById('itemPhotoBtn').addEventListener('click', () => {
    if (!document.getElementById('itemName').value.trim()) {
      showNotification('กรุณาเลือกสินค้าก่อน', 'error');
      return;
    }
    openPhotoPicker('new-item', 'addrow');
  });

  // Camera controls
  document.getElementById('cameraCaptureBtn').addEventListener('click', capturePhoto);
  document.getElementById('cameraConfirmBtn').addEventListener('click', confirmPhoto);
  document.getElementById('cameraRetryBtn').addEventListener('click', retryPhoto);
  document.getElementById('cameraCloseBtn').addEventListener('click', closeCamera);
  document.getElementById('cameraOverlay').addEventListener('click', (e) => {
    if (e.target === e.currentTarget) closeCamera();
  });

  // ── Signature Modal ──────────────────────────────────────
  function openSignatureModal() {
    const modal = document.getElementById('signatureModal');
    const canvas = document.getElementById('sigCanvas');
    const ctx = canvas.getContext('2d');
    const placeholder = document.getElementById('sigPlaceholder');

    // ถ้ามี signature เก่า แสดงไว้
    if (pendingSignatureDataUrl) {
      const img = new Image();
      img.onload = () => {
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        ctx.drawImage(img, 0, 0);
        placeholder.style.display = 'none';
      };
      img.src = pendingSignatureDataUrl;
    } else {
      ctx.clearRect(0, 0, canvas.width, canvas.height);
      placeholder.style.display = 'block';
    }

    // ซ่อนปุ่มข้ามถ้า cart มีโลหะมีค่า/ทองแดง (Rule B — ต้องเซ็นเสมอ)
    const hasPreciousNow = cart.some(it => it.requires_precious_receipt == 1);
    document.getElementById('sigSkipBtn').style.display = hasPreciousNow ? 'none' : '';

    modal.classList.add('show');
  }

  document.getElementById('sigBtn').addEventListener('click', openSignatureModal);

  // Canvas drawing
  function initSignatureCanvas() {
    const canvas = document.getElementById('sigCanvas');
    const ctx = canvas.getContext('2d');
    const placeholder = document.getElementById('sigPlaceholder');

    function getPos(e) {
      const rect = canvas.getBoundingClientRect();
      const scaleX = canvas.width / rect.width;
      const scaleY = canvas.height / rect.height;
      if (e.touches) {
        return { x: (e.touches[0].clientX - rect.left) * scaleX, y: (e.touches[0].clientY - rect.top) * scaleY };
      }
      return { x: (e.clientX - rect.left) * scaleX, y: (e.clientY - rect.top) * scaleY };
    }

    function startDraw(e) {
      e.preventDefault();
      isDrawing = true;
      placeholder.style.display = 'none';
      const pos = getPos(e);
      ctx.beginPath();
      ctx.moveTo(pos.x, pos.y);
    }

    function draw(e) {
      e.preventDefault();
      if (!isDrawing) return;
      const pos = getPos(e);
      ctx.lineWidth = 2;
      ctx.lineCap = 'round';
      ctx.strokeStyle = '#000';
      ctx.lineTo(pos.x, pos.y);
      ctx.stroke();
      ctx.beginPath();
      ctx.moveTo(pos.x, pos.y);
    }

    function endDraw(e) {
      e.preventDefault();
      isDrawing = false;
      ctx.beginPath();
    }

    // Mouse
    canvas.addEventListener('mousedown', startDraw);
    canvas.addEventListener('mousemove', draw);
    canvas.addEventListener('mouseup', endDraw);
    canvas.addEventListener('mouseleave', endDraw);

    // Touch
    canvas.addEventListener('touchstart', startDraw, { passive: false });
    canvas.addEventListener('touchmove', draw, { passive: false });
    canvas.addEventListener('touchend', endDraw, { passive: false });
  }

  initSignatureCanvas();

  document.getElementById('sigClearBtn').addEventListener('click', () => {
    const canvas = document.getElementById('sigCanvas');
    const ctx = canvas.getContext('2d');
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    document.getElementById('sigPlaceholder').style.display = 'block';
    pendingSignatureDataUrl = null;
    pendingSignatureBlob = null;
  });

  document.getElementById('sigConfirmBtn').addEventListener('click', () => {
    const canvas = document.getElementById('sigCanvas');
    const checkbox = document.getElementById('sigConfirmCheckbox');

    // ตรวจสอบ: วาดหรือ checkbox
    const isEmpty = isCanvasBlank(canvas);
    if (isEmpty && !checkbox.checked) {
      showNotification('กรุณาเซ็น หรือติ๊กยืนยัน', 'error');
      return;
    }

    if (!isEmpty) {
      pendingSignatureDataUrl = canvas.toDataURL('image/png');
      canvas.toBlob((blob) => {
        pendingSignatureBlob = new File([blob], 'signature.png', { type: 'image/png' });
      });
    } else {
      // checkbox fallback
      pendingSignatureDataUrl = '__checkbox_confirm__';
      pendingSignatureBlob = null;
    }

    document.getElementById('signatureModal').classList.remove('show');
    document.getElementById('sigStatus').textContent = '✅ เซ็นแล้ว';
    document.getElementById('sigStatus').style.color = '#22c55e';
    showNotification('ยืนยันลายเซ็นสำเร็จ', 'success');
  });

  document.getElementById('sigSkipBtn').addEventListener('click', () => {
    document.getElementById('signatureModal').classList.remove('show');
  });

  // Close modal on overlay click
  document.getElementById('signatureModal').addEventListener('click', (e) => {
    if (e.target === e.currentTarget) {
      document.getElementById('signatureModal').classList.remove('show');
    }
  });
  document.getElementById('signatureModalClose').addEventListener('click', () => {
    document.getElementById('signatureModal').classList.remove('show');
  });

  function isCanvasBlank(canvas) {
    const ctx = canvas.getContext('2d');
    const data = ctx.getImageData(0, 0, canvas.width, canvas.height).data;
    for (let i = 3; i < data.length; i += 4) {
      if (data[i] !== 0) return false;
    }
    return true;
  }

  // Seller ID card photo — handled via sellerPhotoArea click below

  // FAB Camera Button
  document.getElementById('fabCameraBtn').addEventListener('click', () => {
    const itemCount = Object.keys(pendingItemPhotos).length;
    if (itemCount > 0 || cart.length > 0) {
      openPhotoPicker('item', cart.length > 0 ? cart[cart.length - 1]._tempId : 'new');
    } else {
      openPhotoPicker('new-item', 'new');
    }
  });

  // Seller Photo Area (improved)
  document.getElementById('sellerPhotoArea').addEventListener('click', () => {
    openPhotoPicker('seller-id', 0);
  });
  document.getElementById('sellerPhotoThumb').addEventListener('click', (e) => {
    e.stopPropagation();
    if (confirm('ลบรูปบัตรประชาชน?')) {
      pendingSellerIdPhoto = null;
      resetSellerPhotoUI();
    }
  });
});
