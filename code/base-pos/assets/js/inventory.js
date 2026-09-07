// ===== สินค้าคงคลัง — Stock Monitoring (แคตตาล็อกย้ายไป catalog.js) =====
let categories = [];
let branches = [];
let selectedCategoryId = null;
let selectedCategoryData = null;
let selectedCategoryItems = [];
let categoryItemsRequestSeq = 0;
let itemSearchTerm = '';

document.addEventListener('DOMContentLoaded', function() {
  initStock();

  document.getElementById('branchFilterStock').addEventListener('change', function() {
    refreshCategoryStock();
  });
  document.getElementById('categoryStockGrid').addEventListener('click', handleCategoryCardClick);
  document.getElementById('categoryStockGrid').addEventListener('keydown', handleCategoryCardKeydown);
  document.getElementById('categoryItemPanel').addEventListener('input', handleCategoryItemSearch);

  // Close modals
  document.querySelectorAll('.close-modal').forEach(button => {
    button.addEventListener('click', function() {
      this.closest('.modal').classList.remove('show');
    });
  });
});

async function refreshCategoryStock() {
  // Re-fetch categories per selected branch (เพราะ categories.stock_kg เป็น global)
  const branchId = document.getElementById('branchFilterStock').value;
  try {
    let url = 'inventory/categories';
    if (branchId !== 'all') url += `?branch_id=${branchId}`;

    const catRes = await apiRequest(url);
    if (catRes.status === 'success') {
      categories = catRes.data;
      normalizeSelectedCategory();
      renderCategoryStock();
      renderStockSummary();
      await loadSelectedCategoryItems(true);
    }
  } catch (error) {
    console.error('Failed to load categories:', error);
    showNotification('โหลดข้อมูลสต็อกตามสาขาไม่สำเร็จ', 'error');
  }
}

async function initStock() {
  try {
    // PRD cashier: ล็อคสาขาตัวเอง
    try {
      const u = JSON.parse(localStorage.getItem('posUser') || '{}');
      if (u.role === 'cashier' && u.branch_id) {
        setTimeout(() => {
          const sel = document.getElementById('branchFilterStock');
          if (sel) { sel.value = String(u.branch_id); sel.style.display = 'none'; const label = sel.closest('.form-group'); if(label) label.style.display='none'; }
        }, 100);
      }
    } catch(e) {}
    const branchRes = await apiRequest('branches');
    if (branchRes.status === 'success') {
      branches = branchRes.data;
      renderBranchDropdown();
    }

    const catRes = await apiRequest('inventory/categories');
    if (catRes.status === 'success') {
      categories = catRes.data;
      normalizeSelectedCategory();
      renderCategoryStock();
      renderStockSummary();
      await loadSelectedCategoryItems(true);
    }

    renderStockAlerts();
  } catch (error) {
    console.error('Failed to initialize stock:', error);
    showNotification('โหลดข้อมูลคลังสินค้าไม่สำเร็จ', 'error');
  }
}

function renderBranchDropdown() {
  const select = document.getElementById('branchFilterStock');
  if (!select) return;
  while (select.options.length > 1) {
    select.remove(1);
  }
  const activeBranches = branches.filter(b => b.status === 'active');
  activeBranches.forEach(b => {
    const opt = document.createElement('option');
    opt.value = b.id;
    opt.textContent = `${b.code} - ${b.name}`;
    select.appendChild(opt);
  });
}

let thresholdMode = false;

function toggleThresholdMode() {
  thresholdMode = !thresholdMode;
  document.getElementById('thresholdForm').classList.toggle('show', thresholdMode);
  renderCategoryStock();
}

function renderCategoryStock() {
  const container = document.getElementById('categoryStockGrid');
  if (!container) return;

  // Backend already returns per-branch data via ?branch_id=X
  const filtered = getActiveCategories();
  const meta = document.getElementById('categoryPaneMeta');
  if (meta) meta.textContent = `${filtered.length.toLocaleString('th-TH')} หมวด`;

  if (filtered.length === 0) {
    container.innerHTML = '<div class="inv-empty">ไม่มีหมวดหมู่</div>';
    selectedCategoryId = null;
    selectedCategoryData = null;
    selectedCategoryItems = [];
    renderCategoryDetailEmpty('ไม่มีหมวดหมู่');
    return;
  }

  const maxStock = Math.max(...filtered.map(c => toNumber(c.stock_kg)), 1);
  container.innerHTML = filtered.map(c => {
    const kg = toNumber(c.stock_kg);
    const threshold = toNumber(c.alert_threshold);
    const isAlert = threshold > 0 && kg <= threshold;
    const pct = Math.min(100, (kg / maxStock) * 100);
    const catId = c.id || '';
    const isActive = String(catId) === String(selectedCategoryId);

    const thresholdInput = thresholdMode && catId ? `
      <div class="inv-threshold-row">
        <input type="number" min="0" step="0.001" placeholder="ตั้ง alert (กก.)"
          value="${c.alert_threshold != null ? escapeAttr(c.alert_threshold) : ''}"
          class="inv-threshold-input"
          onchange="saveThreshold(${catId}, this.value)">
      </div>` : '';

    const alertBadge = isAlert && !thresholdMode
      ? `<span class="inv-alert-badge">⚠ ใกล้หมด</span>` : '';

    const kgClass = kg <= 0 ? 'danger' : isAlert ? 'warning' : pct < 20 ? 'warning' : 'success';
    const barClass = kgClass;

    return `
      <div class="inv-cat-card${isAlert ? ' alert' : ''}${isActive ? ' active' : ''}${catId ? ' inv-clickable' : ''}"
        ${catId ? ` data-category-id="${catId}" role="button" tabindex="0" aria-pressed="${isActive ? 'true' : 'false'}"` : ''}>
        <div class="inv-cat-card-header">
          <div class="inv-cat-name">${escapeHtml(c.name)}${alertBadge}</div>
          ${catId ? '<span class="inv-cat-arrow">›</span>' : ''}
        </div>
        <div class="inv-cat-kg ${kgClass}">${formatKg(kg)}</div>
        <div class="inv-cat-unit">กก.</div>
        <div class="inv-progress-bar">
          <div class="inv-progress-fill ${barClass}" style="width:${pct}%"></div>
        </div>
        ${thresholdInput}
      </div>`;
  }).join('');
}

async function saveThreshold(categoryId, value) {
  const threshold = value === '' ? null : parseFloat(value);
  const res = await apiRequest('inventory/set-threshold', 'POST', { category_id: categoryId, threshold });
  if (res.status === 'success') {
    const cat = categories.find(c => c.id == categoryId);
    if (cat) cat.alert_threshold = threshold;
    renderCategoryStock();
  } else {
    showNotification('บันทึก threshold ไม่สำเร็จ', 'error');
  }
}

/**
 * Click handler: select category → show item-level stock in detail pane.
 */
async function handleCategoryCardClick(e) {
  if (e.target.closest('input, button, select, textarea, a')) return;

  const card = e.target.closest('.inv-cat-card.inv-clickable');
  if (!card) return;

  await selectCategory(card.dataset.categoryId, true);
}

function handleCategoryCardKeydown(e) {
  if (e.target.closest('input, button, select, textarea, a')) return;
  if (e.key !== 'Enter' && e.key !== ' ') return;

  e.preventDefault();
  const card = e.target.closest('.inv-cat-card.inv-clickable');
  if (!card) return;

  selectCategory(card.dataset.categoryId, true);
}

async function selectCategory(categoryId, shouldScroll) {
  if (!categoryId) return;

  const nextId = String(categoryId);
  const hasChanged = selectedCategoryId !== nextId;
  selectedCategoryId = nextId;
  itemSearchTerm = '';
  renderCategoryStock();

  if (hasChanged || !selectedCategoryData) {
    await loadSelectedCategoryItems(true);
  } else {
    renderCategoryDetail(selectedCategoryData);
  }

  if (shouldScroll && window.matchMedia('(max-width: 900px)').matches) {
    document.getElementById('categoryItemPanel')?.scrollIntoView({ block: 'start', behavior: 'smooth' });
  }
}

function normalizeSelectedCategory() {
  const active = getActiveCategories();
  if (active.length === 0) {
    selectedCategoryId = null;
    selectedCategoryData = null;
    selectedCategoryItems = [];
    return null;
  }

  const stillExists = active.some(c => String(c.id) === String(selectedCategoryId));
  if (!selectedCategoryId || !stillExists) {
    selectedCategoryId = String(active[0].id);
  }

  return getSelectedCategory();
}

function getActiveCategories() {
  return categories.filter(c => c.status === 'active');
}

function getSelectedCategory() {
  return getActiveCategories().find(c => String(c.id) === String(selectedCategoryId)) || null;
}

/**
 * Fetch item-level stock from API and render the dedicated detail pane.
 */
async function loadSelectedCategoryItems(resetSearch) {
  const panel = document.getElementById('categoryItemPanel');
  const category = getSelectedCategory();
  if (!panel) return;

  if (!category || !selectedCategoryId) {
    selectedCategoryData = null;
    selectedCategoryItems = [];
    renderCategoryDetailEmpty('ไม่มีหมวดหมู่');
    return;
  }

  if (resetSearch) itemSearchTerm = '';

  const requestId = ++categoryItemsRequestSeq;
  renderCategoryDetailLoading(category);

  const branchId = document.getElementById('branchFilterStock').value;
  const params = new URLSearchParams({ category_id: selectedCategoryId });
  if (branchId !== 'all') params.set('branch_id', branchId);

  try {
    const res = await apiRequest(`inventory/category-items?${params}`);
    if (requestId !== categoryItemsRequestSeq) return;

    if (res.status !== 'success') {
      selectedCategoryData = null;
      selectedCategoryItems = [];
      renderCategoryDetailError();
      return;
    }

    selectedCategoryData = res.data || {};
    selectedCategoryItems = Array.isArray(selectedCategoryData.items)
      ? selectedCategoryData.items.map(normalizeItemStock)
      : [];
    renderCategoryDetail(selectedCategoryData);
  } catch (err) {
    if (requestId !== categoryItemsRequestSeq) return;
    console.error('Load items failed:', err);
    selectedCategoryData = null;
    selectedCategoryItems = [];
    renderCategoryDetailError();
  }
}

function renderCategoryDetailLoading(category) {
  const panel = document.getElementById('categoryItemPanel');
  if (!panel) return;

  panel.innerHTML = `
    <div class="inv-detail-header">
      <div>
        <div class="inv-detail-eyebrow">${escapeHtml(getSelectedBranchLabel())}</div>
        <h3 class="inv-detail-title">${escapeHtml(category.name)}</h3>
      </div>
    </div>
    <div class="inv-item-loading">กำลังโหลด...</div>`;
}

function renderCategoryDetailError() {
  const panel = document.getElementById('categoryItemPanel');
  if (!panel) return;
  selectedCategoryItems = [];
  panel.innerHTML = '<div class="inv-item-error">โหลดข้อมูลไม่สำเร็จ</div>';
}

function renderCategoryDetailEmpty(title) {
  const panel = document.getElementById('categoryItemPanel');
  if (!panel) return;

  panel.innerHTML = `
    <div class="inv-detail-empty">
      <div class="inv-detail-empty-icon"><i class="icon-product"></i></div>
      <h3>${escapeHtml(title || 'ยังไม่ได้เลือกหมวดหมู่')}</h3>
      <p>รายการสต็อกจะแสดงตรงนี้</p>
    </div>`;
}

function renderCategoryDetail(data) {
  const panel = document.getElementById('categoryItemPanel');
  const selectedCategory = getSelectedCategory();
  if (!panel || !selectedCategory) return;

  const category = data.category || selectedCategory;
  const items = selectedCategoryItems;
  const totalStockKg = data.total_stock_kg != null
    ? toNumber(data.total_stock_kg)
    : items.reduce((sum, item) => sum + item.stock_kg, 0);
  const totalItems = data.total_items != null ? toNumber(data.total_items) : items.length;

  panel.innerHTML = `
    <div class="inv-detail-header">
      <div>
        <div class="inv-detail-eyebrow">${escapeHtml(getSelectedBranchLabel())}</div>
        <h3 class="inv-detail-title">${escapeHtml(category.name || selectedCategory.name)}</h3>
      </div>
      <div class="inv-detail-tools">
        <input type="search" id="categoryItemSearch" class="form-control inv-item-search"
          placeholder="ค้นหารายการ" value="${escapeAttr(itemSearchTerm)}" autocomplete="off">
      </div>
    </div>
    <div class="inv-detail-summary">
      <div class="inv-detail-stat">
        <span>คงเหลือรวม</span>
        <strong>${formatKg(totalStockKg)} กก.</strong>
      </div>
      <div class="inv-detail-stat">
        <span>รายการที่แสดง</span>
        <strong id="categoryItemCount">${totalItems.toLocaleString('th-TH')}</strong>
      </div>
      <div class="inv-detail-stat">
        <span>มูลค่าที่แสดง</span>
        <strong id="categoryStockValue">${formatMoney(sumEstimatedValue(items))}</strong>
      </div>
    </div>
    <div id="categoryItemTableWrap" class="inv-item-list-shell"></div>`;

  updateCategoryItemTable();
}

function handleCategoryItemSearch(e) {
  if (e.target.id !== 'categoryItemSearch') return;
  itemSearchTerm = e.target.value;
  updateCategoryItemTable();
}

function updateCategoryItemTable() {
  const wrap = document.getElementById('categoryItemTableWrap');
  if (!wrap) return;

  const filteredItems = getFilteredCategoryItems();
  const count = document.getElementById('categoryItemCount');
  const value = document.getElementById('categoryStockValue');
  if (count) count.textContent = filteredItems.length.toLocaleString('th-TH');
  if (value) value.textContent = formatMoney(sumEstimatedValue(filteredItems));

  if (selectedCategoryItems.length === 0) {
    wrap.innerHTML = '<div class="inv-item-empty">ไม่มีรายการในหมวดนี้</div>';
    return;
  }

  if (filteredItems.length === 0) {
    wrap.innerHTML = '<div class="inv-item-empty">ไม่พบรายการที่ค้นหา</div>';
    return;
  }

  wrap.innerHTML = renderItemTable(filteredItems);
}

function getFilteredCategoryItems() {
  const term = itemSearchTerm.trim().toLowerCase();
  const sorted = [...selectedCategoryItems].sort((a, b) => {
    const nameA = String(a.item_name || '');
    const nameB = String(b.item_name || '');
    return b.stock_kg - a.stock_kg || nameA.localeCompare(nameB, 'th');
  });
  if (!term) return sorted;

  return sorted.filter(item => String(item.item_name || '').toLowerCase().includes(term));
}

/**
 * Render item-level stock table HTML.
 */
function renderItemTable(items) {
  const maxKg = Math.max(...items.map(i => i.stock_kg), 1);

  return `<div class="inv-item-list">
    ${items.map((item, i) => {
      const pct = Math.min(100, (item.stock_kg / maxKg) * 100);
      const barClass = item.stock_kg <= 0 ? 'danger' : pct < 20 ? 'warning' : 'success';
      const estimatedValue = item.stock_kg * item.latest_unit_price;
      return `
        <article class="inv-item-row">
          <div class="inv-item-head">
            <div class="inv-item-index">${i + 1}</div>
            <div class="inv-item-name">${escapeHtml(item.item_name)}</div>
          </div>
          <div class="inv-item-stats">
            <div class="inv-item-stat">
              <span>คงเหลือ</span>
              <strong>${formatKg(item.stock_kg)} กก.</strong>
            </div>
            <div class="inv-item-stat">
              <span>ราคาล่าสุด/กก.</span>
              <strong>${item.latest_unit_price > 0 ? formatMoney(item.latest_unit_price) : '-'}</strong>
            </div>
            <div class="inv-item-stat">
              <span>มูลค่าโดยประมาณ</span>
              <strong>${estimatedValue > 0 ? formatMoney(estimatedValue) : '-'}</strong>
            </div>
          </div>
          <div class="inv-item-progress">
            <div class="inv-item-stock-bar" aria-hidden="true">
              <div class="inv-item-stock-fill ${barClass}" style="width:${pct}%"></div>
            </div>
            <div class="inv-item-progress-label">${pct.toLocaleString('th-TH', { maximumFractionDigits: 0 })}%</div>
          </div>
        </article>`;
    }).join('')}
  </div>`;
}

function renderStockSummary() {
  const active = getActiveCategories();
  const totalKg = active.reduce((sum, c) => sum + toNumber(c.stock_kg), 0);
  const catCount = active.length;

  const cards = document.querySelectorAll('#stockSummaryGrid .stat-card');
  if (cards.length < 3) return;
  cards[0].querySelector('.stat-value').textContent =
    totalKg.toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' กก.';
  cards[1].querySelector('.stat-value').textContent = catCount;
  cards.forEach(el => el.classList.remove('loading'));
}

async function renderStockAlerts() {
  const container = document.getElementById('stockAlertsContainer');
  const badge = document.getElementById('alertCountBadge');
  const cards = document.querySelectorAll('#stockSummaryGrid .stat-card');
  if (!container) return;
  try {
    const res = await apiRequest('inventory/stock-alerts');
    if (res.status === 'success' && res.data.items && res.data.items.length > 0) {
      const items = res.data.items;
      if (badge) badge.textContent = items.length;
      if (cards.length >= 3) {
        cards[2].querySelector('.stat-value').textContent = items.length;
        cards[2].classList.remove('loading');
      }
      container.innerHTML = `<div class="table-container"><table class="data-table">
        <thead><tr><th>หมวดหมู่</th><th>สต็อก (กก.)</th><th>เกณฑ์แจ้งเตือน</th><th>สถานะ</th></tr></thead>
        <tbody>${items.map(item => `
          <tr>
            <td>${escapeHtml(item.name)}</td>
            <td>${parseFloat(item.stock_kg).toLocaleString('th-TH', {minimumFractionDigits:2, maximumFractionDigits:2})}</td>
            <td>${parseFloat(item.alert_threshold).toLocaleString('th-TH', {minimumFractionDigits:2, maximumFractionDigits:2})}</td>
            <td><span class="badge badge-danger">⚠ ใกล้หมด</span></td>
          </tr>`).join('')}</tbody>
      </table></div>`;
    } else {
      container.innerHTML = '<div class="empty-state"><i class="icon-check"></i><h3>สต็อกปกติ</h3><p>ไม่มีสินค้าใกล้หมดในขณะนี้</p></div>';
      if (badge) badge.textContent = '0';
      if (cards.length >= 3) {
        cards[2].querySelector('.stat-value').textContent = '0';
        cards[2].classList.remove('loading');
      }
    }
  } catch (error) {
    container.innerHTML = '<div class="chart-placeholder">โหลดข้อมูลไม่สำเร็จ</div>';
    if (cards.length >= 3) cards[2].classList.remove('loading');
  }
}

function escapeHtml(s) {
  if (s == null) return '';
  return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

function escapeAttr(s) {
  return escapeHtml(s);
}

function toNumber(value) {
  const n = parseFloat(value);
  return Number.isFinite(n) ? n : 0;
}

function formatKg(value) {
  return toNumber(value).toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function formatMoney(value) {
  return toNumber(value).toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function normalizeItemStock(item) {
  return {
    ...item,
    stock_kg: toNumber(item.stock_kg),
    latest_unit_price: toNumber(item.latest_unit_price),
  };
}

function sumEstimatedValue(items) {
  return items.reduce((sum, item) => sum + (item.stock_kg * item.latest_unit_price), 0);
}

function getSelectedBranchLabel() {
  const select = document.getElementById('branchFilterStock');
  if (!select || select.value === 'all') return 'รวมทุกสาขา';
  return select.selectedOptions?.[0]?.textContent || 'สาขาที่เลือก';
}

function showNotification(message, type) {
  if (typeof window.appShowNotification === 'function') {
    window.appShowNotification(message, type || 'info');
  }
}
