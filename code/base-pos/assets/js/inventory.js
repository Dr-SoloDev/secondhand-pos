// ===== สินค้าคงคลัง — Stock Monitoring (แคตตาล็อกย้ายไป catalog.js) =====
let categories = [];
let branches = [];

document.addEventListener('DOMContentLoaded', function() {
  initStock();

  document.getElementById('branchFilterStock').addEventListener('change', renderCategoryStock);

  // Close modals
  document.querySelectorAll('.close-modal').forEach(button => {
    button.addEventListener('click', function() {
      this.closest('.modal').classList.remove('show');
    });
  });
});

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
  document.getElementById('thresholdForm').style.display = thresholdMode ? '' : 'none';
  renderCategoryStock();
}

function renderCategoryStock() {
  const container = document.getElementById('categoryStockGrid');
  if (!container) return;

  const branchFilter = document.getElementById('branchFilterStock').value;
  let filtered = categories.filter(c => c.status === 'active');

  if (branchFilter === 'all') {
    const grouped = {};
    filtered.forEach(c => {
      if (!grouped[c.name]) grouped[c.name] = 0;
      grouped[c.name] += parseFloat(c.stock_kg || 0);
    });
    filtered = Object.keys(grouped).map(name => ({
      name: name,
      stock_kg: grouped[name]
    }));
  } else {
    filtered = filtered.filter(c => c.branch_id == branchFilter);
  }

  if (filtered.length === 0) {
    container.innerHTML = '<div style="color:#94a3b8;text-align:center;padding:12px">ไม่มีหมวดหมู่</div>';
    return;
  }

  const maxStock = Math.max(...filtered.map(c => parseFloat(c.stock_kg || 0)), 1);
  container.innerHTML = filtered.map(c => {
    const kg = parseFloat(c.stock_kg || 0);
    const threshold = parseFloat(c.alert_threshold || 0);
    const isAlert = threshold > 0 && kg <= threshold;
    const pct = Math.min(100, (kg / maxStock) * 100);
    let barColor = 'var(--color-success)';
    if (kg <= 0) barColor = 'var(--color-danger)';
    else if (isAlert) barColor = 'var(--color-warning)';
    else if (pct < 20) barColor = 'var(--color-warning)';

    const thresholdInput = thresholdMode && c.id ? `
      <div style="margin-top:8px;display:flex;align-items:center;gap:6px">
        <input type="number" min="0" step="0.001" placeholder="ตั้ง alert (กก.)"
          value="${c.alert_threshold != null ? c.alert_threshold : ''}"
          style="width:100%;padding:4px 6px;border:1px solid #e2e8f0;border-radius:4px;font-size:12px"
          onchange="saveThreshold(${c.id}, this.value)">
      </div>` : '';

    const alertBadge = isAlert && !thresholdMode
      ? `<span style="font-size:10px;background:#fef3c7;color:#92400e;padding:2px 6px;border-radius:99px;margin-left:4px">⚠ ใกล้หมด</span>` : '';

    return `
      <div style="background:#fff;border:1px solid ${isAlert ? '#fcd34d' : '#e2e8f0'};border-radius:10px;padding:14px">
        <div style="font-size:13px;font-weight:600;color:#1e293b;margin-bottom:6px">${escapeHtml(c.name)}${alertBadge}</div>
        <div style="font-size:22px;font-weight:700;color:${barColor}">${kg.toLocaleString('th-TH', {minimumFractionDigits:2, maximumFractionDigits:2})}</div>
        <div style="font-size:11px;color:#94a3b8">กก.</div>
        <div style="margin-top:8px;height:4px;background:#f1f5f9;border-radius:4px;overflow:hidden">
          <div style="height:100%;width:${pct}%;background:${barColor};border-radius:4px;transition:width 0.3s"></div>
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
  } else {
    showNotification('บันทึก threshold ไม่สำเร็จ', 'error');
  }
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
