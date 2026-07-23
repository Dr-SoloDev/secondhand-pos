// ===== สินค้าคงคลัง — Stock Monitoring (แคตตาล็อกย้ายไป catalog.js) =====
let categories = [];
let branches = [];

document.addEventListener('DOMContentLoaded', function() {
  initStock();

  document.getElementById('branchFilterStock').addEventListener('change', function() {
    refreshCategoryStock();
  });
  document.getElementById('categoryStockGrid').addEventListener('click', handleCategoryCardClick);

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
      renderCategoryStock();
    }
  } catch (error) {
    console.error('Failed to load categories:', error);
    showNotification('โหลดข้อมูลสต็อกตามสาขาไม่สำเร็จ', 'error');
  }
}

async function initStock() {
  try {
    const branchRes = await apiRequest('branches');
    if (branchRes.status === 'success') {
      branches = branchRes.data;
      renderBranchDropdown();
    }

    const catRes = await apiRequest('inventory/categories');
    if (catRes.status === 'success') {
      categories = catRes.data;
      renderCategoryStock();
      renderStockSummary();
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
  const filtered = categories.filter(c => c.status === 'active');

  if (filtered.length === 0) {
    container.innerHTML = '<div class="inv-empty">ไม่มีหมวดหมู่</div>';
    return;
  }

  const maxStock = Math.max(...filtered.map(c => parseFloat(c.stock_kg || 0)), 1);
  container.innerHTML = filtered.map(c => {
    const kg = parseFloat(c.stock_kg || 0);
    const threshold = parseFloat(c.alert_threshold || 0);
    const isAlert = threshold > 0 && kg <= threshold;
    const pct = Math.min(100, (kg / maxStock) * 100);
    const catId = c.id || '';

    const thresholdInput = thresholdMode && catId ? `
      <div class="inv-threshold-row">
        <input type="number" min="0" step="0.001" placeholder="ตั้ง alert (กก.)"
          value="${c.alert_threshold != null ? c.alert_threshold : ''}"
          class="inv-threshold-input"
          onchange="saveThreshold(${catId}, this.value)">
      </div>` : '';

    const alertBadge = isAlert && !thresholdMode
      ? `<span class="inv-alert-badge">⚠ ใกล้หมด</span>` : '';

    const kgClass = kg <= 0 ? 'danger' : isAlert ? 'warning' : pct < 20 ? 'warning' : 'success';
    const barClass = kgClass;

    return `
      <div class="inv-cat-card${isAlert ? ' alert' : ''}${catId ? ' inv-clickable' : ''}"${catId ? ` data-category-id="${catId}"` : ''}>
        <div class="inv-cat-card-header">
          <div class="inv-cat-name">${escapeHtml(c.name)}${alertBadge}</div>
          ${catId ? '<span class="inv-expand-icon">▼</span>' : ''}
        </div>
        <div class="inv-cat-kg ${kgClass}">${kg.toLocaleString('th-TH', {minimumFractionDigits:2, maximumFractionDigits:2})}</div>
        <div class="inv-cat-unit">กก.</div>
        <div class="inv-progress-bar">
          <div class="inv-progress-fill ${barClass}" style="width:${pct}%"></div>
        </div>
        ${thresholdInput}
        ${catId ? '<div class="inv-item-table-wrap"></div>' : ''}
      </div>`;
  }).join('');
}

async function saveThreshold(categoryId, value) {
  const threshold = value === '' ? null : parseFloat(value);
  const res = await apiRequest('inventory/set-threshold', 'POST', { category_id: categoryId, threshold });
  if (res.status === 'success') {
    const cat = categories.find(c => c.id == categoryId);
    if (cat) cat.alert_threshold = threshold;
  } else {
    showNotification('บันทึก threshold ไม่สำเร็จ', 'error');
  }
}

/**
 * Click handler: expand/collapse category card → show item-level stock
 */
async function handleCategoryCardClick(e) {
  const card = e.target.closest('.inv-cat-card.inv-clickable');
  if (!card) return;

  const catId = card.dataset.categoryId;
  if (!catId) return;

  const wrap = card.querySelector('.inv-item-table-wrap');
  if (!wrap) return;

  if (card.classList.contains('inv-expanded')) {
    // Collapse
    card.classList.remove('inv-expanded');
    return;
  }

  // Expand + fetch
  card.classList.add('inv-expanded');

  if (!wrap.dataset.loaded) {
    wrap.innerHTML = '<div class="inv-item-loading">กำลังโหลด...</div>';
    try {
      await loadCategoryItems(card, catId, wrap);
      wrap.dataset.loaded = '1';
    } catch (err) {
      wrap.innerHTML = '<div class="inv-item-error">โหลดข้อมูลไม่สำเร็จ</div>';
      console.error('Load items failed:', err);
    }
  }
}

/**
 * Fetch item-level stock from API and render table
 */
async function loadCategoryItems(card, catId, wrap) {
  const branchId = document.getElementById('branchFilterStock').value;
  const params = new URLSearchParams({ category_id: catId });
  if (branchId !== 'all') params.set('branch_id', branchId);

  const res = await apiRequest(`inventory/category-items?${params}`);
  if (res.status !== 'success') {
    wrap.innerHTML = '<div class="inv-item-error">โหลดข้อมูลไม่สำเร็จ</div>';
    return;
  }

  const data = res.data;
  if (!data.items || data.items.length === 0) {
    wrap.innerHTML = '<div class="inv-item-empty">ไม่มีรายการในหมวดนี้</div>';
    return;
  }

  wrap.innerHTML = renderItemTable(data.items);
}

/**
 * Render item-level stock table HTML
 */
function renderItemTable(items) {
  const maxKg = Math.max(...items.map(i => i.stock_kg), 1);

  return `<table class="inv-item-table">
    <thead>
      <tr>
        <th class="inv-item-col-num">#</th>
        <th class="inv-item-col-name">รายการ</th>
        <th class="inv-item-col-kg">สต็อก (กก.)</th>
        <th class="inv-item-col-bar"></th>
        <th class="inv-item-col-price">ราคาล่าสุด/กก.</th>
        <th class="inv-item-col-count">ครั้ง</th>
      </tr>
    </thead>
    <tbody>
      ${items.map((item, i) => {
        const pct = Math.min(100, (item.stock_kg / maxKg) * 100);
        const barClass = item.stock_kg <= 0 ? 'danger' : pct < 20 ? 'warning' : 'success';
        return `<tr>
          <td class="inv-item-col-num">${i + 1}</td>
          <td class="inv-item-col-name">${escapeHtml(item.item_name)}</td>
          <td class="inv-item-col-kg">${item.stock_kg.toLocaleString('th-TH', {minimumFractionDigits:2, maximumFractionDigits:2})}</td>
          <td class="inv-item-col-bar">
            <div class="inv-item-stock-bar">
              <div class="inv-item-stock-fill ${barClass}" style="width:${pct}%"></div>
            </div>
          </td>
          <td class="inv-item-col-price">${item.latest_unit_price > 0 ? item.latest_unit_price.toLocaleString('th-TH', {minimumFractionDigits:2, maximumFractionDigits:2}) : '-'}</td>
          <td class="inv-item-col-count">${item.purchase_count}</td>
        </tr>`;
      }).join('')}
    </tbody>
  </table>`;
}

function renderStockSummary() {
  const active = categories.filter(c => c.status === 'active');
  const totalKg = active.reduce((sum, c) => sum + parseFloat(c.stock_kg || 0), 0);
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

function showNotification(message, type) {
  if (typeof window.appShowNotification === 'function') {
    window.appShowNotification(message, type || 'info');
  }
}
