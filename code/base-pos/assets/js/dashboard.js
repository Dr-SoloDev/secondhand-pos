let allBranches = [];
let currentBranchMode = 'all';

document.addEventListener('DOMContentLoaded', function() {
  fetchDashboardData();
  document.getElementById('purchasePeriod').addEventListener('change', function() {
    fetchPurchaseChartData(this.value);
  });
  document.getElementById('salelotPeriod').addEventListener('change', function() {
    fetchSalelotChartData(this.value);
  });
});

async function fetchDashboardData() {
  const [statsRes, branchRes, purchaseRes, salelotRes, stockRes] = await Promise.all([
    apiRequest('reports/dashboard-stats'),
    apiRequest('branches/summary'),
    apiRequest('reports/recent-purchases'),
    apiRequest('reports/recent-sale-lots'),
    apiRequest('inventory/low-stock'),
  ]);

  if (statsRes.status === 'success') updateDashboardStats(statsRes.data);
  if (branchRes.status === 'success') {
    allBranches = branchRes.data || [];
    buildBranchSingleButtons(allBranches);
    renderBranchSummary(allBranches, 'all');
  }
  if (purchaseRes.status === 'success') renderRecentPurchases(purchaseRes.data);
  if (salelotRes.status === 'success') renderRecentSaleLots(salelotRes.data);
  if (stockRes.status === 'success') renderLowStockItems(stockRes.data);

  fetchPurchaseChartData('week');
  fetchSalelotChartData('week');
}

function buildBranchSingleButtons(branches) {
  const wrap = document.getElementById('branchSingleButtons');
  if (!wrap) return;
  wrap.innerHTML = branches.map(b =>
    `<button class="btn btn-sm btn-secondary" data-mode="single-${b.id}" onclick="setBranchMode('single-${b.id}')">${escapeHtml(b.name)}</button>`
  ).join('');
}

function setBranchMode(mode) {
  currentBranchMode = mode;
  document.querySelectorAll('#branchModeButtons .btn').forEach(btn => {
    btn.classList.toggle('btn-primary', btn.dataset.mode === mode);
    btn.classList.toggle('btn-secondary', btn.dataset.mode !== mode);
  });
  if (mode === 'all') {
    renderBranchSummary(allBranches, 'all');
  } else if (mode === 'side') {
    renderBranchSummary(allBranches, 'side');
  } else if (mode.startsWith('single-')) {
    const id = parseInt(mode.replace('single-', ''));
    renderBranchSummary(allBranches.filter(b => b.id === id), 'all');
  }
}

function updateDashboardStats(stats) {
  document.getElementById('todayPurchases').textContent = formatCurrency(stats.today_purchases);
  document.getElementById('todayPO').textContent = stats.today_po_count;
  document.getElementById('totalSellers').textContent = stats.total_sellers;
  document.getElementById('pendingPO').textContent = stats.pending_po;
  document.getElementById('todaySaleLotAmount').textContent = formatCurrency(stats.today_salelot_amount);
  document.getElementById('monthSaleLotProfit').textContent = formatCurrency(stats.month_salelot_profit);
  document.getElementById('pendingSaleLots').textContent = stats.pending_salelots;
  document.getElementById('lowStockCount').textContent = stats.low_stock_count;
}

function renderBranchSummary(branches, mode) {
  const grid = document.getElementById('branchSummaryGrid');
  if (!branches || branches.length === 0) {
    grid.innerHTML = '<p style="color:#888">ยังไม่มีข้อมูลสาขา</p>';
    return;
  }

  // หา max สำหรับ highlight
  const maxToday = Math.max(...branches.map(b => parseFloat(b.today_purchase_amount || 0)));

  if (mode === 'side') {
    grid.style.display = 'grid';
    grid.style.gridTemplateColumns = `repeat(${Math.min(branches.length, 4)}, 1fr)`;
    grid.style.gap = '12px';
  } else {
    grid.style.display = '';
    grid.style.gridTemplateColumns = '';
    grid.style.gap = '';
  }

  grid.innerHTML = branches.map(b => {
    const todayAmt = parseFloat(b.today_purchase_amount || 0);
    const isTop = branches.length > 1 && todayAmt > 0 && todayAmt === maxToday;
    const pendingTotal = parseInt(b.pending_po_count || 0) + parseInt(b.pending_salelot_count || 0);
    const pendingHtml = pendingTotal > 0
      ? `<span style="background:#fef3c7;color:#92400e;font-size:11px;padding:2px 8px;border-radius:99px">รอ ${pendingTotal}</span>` : '';
    const topHtml = isTop
      ? `<span style="background:#dcfce7;color:#166534;font-size:11px;padding:2px 8px;border-radius:99px">⭐ สูงสุด</span>` : '';

    return `<div class="branch-card${isTop ? ' branch-card-top' : ''}">
      <div class="branch-card-header">
        <span class="branch-card-name">${escapeHtml(b.name)}</span>
        <span class="branch-card-code">${escapeHtml(b.code)}</span>
      </div>
      <div class="branch-card-stats">
        <div class="branch-stat">
          <div class="branch-stat-value${isTop ? ' text-success' : ''}">${formatCurrency(todayAmt)}</div>
          <div class="branch-stat-label">รับซื้อวันนี้</div>
        </div>
        <div class="branch-stat">
          <div class="branch-stat-value">${parseInt(b.today_purchase_count || 0)}</div>
          <div class="branch-stat-label">ใบวันนี้</div>
        </div>
        <div class="branch-stat">
          <div class="branch-stat-value">${formatCurrency(b.month_purchase_amount)}</div>
          <div class="branch-stat-label">เดือนนี้</div>
        </div>
        <div class="branch-stat">
          <div class="branch-stat-value">${parseFloat(b.total_stock_kg || 0).toLocaleString('th-TH', {maximumFractionDigits:1})}</div>
          <div class="branch-stat-label">สต็อก (กก.)</div>
        </div>
      </div>
      <div class="branch-card-meta" style="display:flex;gap:4px;flex-wrap:wrap">
        ${b.manager_name ? `<span style="font-size:12px;color:#666">👤 ${escapeHtml(b.manager_name)}</span>` : ''}
        ${pendingHtml}${topHtml}
      </div>
    </div>`;
  }).join('');
}

async function fetchPurchaseChartData(period) {
  const res = await apiRequest(`reports/purchase-chart?period=${period}`);
  if (res.status === 'success') renderPurchaseChart(res.data, period);
}

function renderPurchaseChart(data, period) {
  const container = document.getElementById('purchaseChart');
  const old = container.querySelector('canvas');
  if (old) { const c = Chart.getChart(old); if (c) c.destroy(); }
  const canvas = document.createElement('canvas');
  container.innerHTML = '';
  container.appendChild(canvas);

  const labels = data.labels.map(d => {
    if (period === 'week') return new Date(d).toLocaleDateString('th-TH', {weekday:'short'});
    if (period === 'month') return new Date(d).getDate();
    return new Date(d).toLocaleDateString('th-TH', {month:'short'});
  });

  new Chart(canvas, {
    type: 'line',
    data: { labels, datasets: [{ label: 'ยอดรับซื้อ', data: data.purchases,
      backgroundColor: 'rgba(37,117,252,0.1)', borderColor: '#2575fc', borderWidth: 2, tension: 0.3, fill: true }] },
    options: { responsive: true, maintainAspectRatio: false,
      scales: { y: { beginAtZero: true, ticks: { callback: v => '฿'+v.toLocaleString() } } },
      plugins: { tooltip: { callbacks: { label: ctx => 'ยอดรับซื้อ: ' + formatCurrency(ctx.raw) } } } }
  });
}

async function fetchSalelotChartData(period) {
  const res = await apiRequest(`reports/sale-lot-chart?period=${period}`);
  if (res.status === 'success') renderSalelotDashboardChart(res.data, period);
}

function renderSalelotDashboardChart(data, period) {
  const container = document.getElementById('salelotDashboardChart');
  const old = container.querySelector('canvas');
  if (old) { const c = Chart.getChart(old); if (c) c.destroy(); }
  const canvas = document.createElement('canvas');
  container.innerHTML = '';
  container.appendChild(canvas);

  const labels = data.labels.map(d => {
    if (period === 'week') return new Date(d).toLocaleDateString('th-TH', {weekday:'short'});
    if (period === 'month') return new Date(d).getDate();
    return new Date(d).toLocaleDateString('th-TH', {month:'short'});
  });

  new Chart(canvas, {
    type: 'bar',
    data: { labels, datasets: [
      { label: 'ยอดขาย', data: data.amounts, backgroundColor: 'rgba(52,152,219,0.7)', borderColor: '#3498db', borderWidth: 2 },
      { label: 'กำไร',   data: data.profits, backgroundColor: 'rgba(46,204,113,0.7)', borderColor: '#2ecc71', borderWidth: 2 }
    ]},
    options: { responsive: true, maintainAspectRatio: false,
      scales: { y: { beginAtZero: true, ticks: { callback: v => formatCurrency(v) } } },
      plugins: { tooltip: { callbacks: { label: ctx => ctx.dataset.label + ': ' + formatCurrency(ctx.raw) } } } }
  });
}

function renderRecentPurchases(purchases) {
  const tbody = document.querySelector('#recentPOTable tbody');
  if (!purchases.length) { tbody.innerHTML = '<tr><td colspan="6" class="text-center">ยังไม่มีรายการรับซื้อ</td></tr>'; return; }
  tbody.innerHTML = purchases.map(po => {
    const date = new Date(po.created_at).toLocaleString('th-TH', {dateStyle:'short', timeStyle:'short'});
    const badge = po.status === 'completed' ? 'badge-success' : po.status === 'draft' ? 'badge-warning' : 'badge-danger';
    const label = po.status === 'completed' ? 'สำเร็จ' : po.status === 'draft' ? 'ร่าง' : 'ยกเลิก';
    return `<tr>
      <td>${escapeHtml(po.reference_no)}</td>
      <td>${date}</td>
      <td>${escapeHtml(po.seller_name || '-')}</td>
      <td>${escapeHtml(po.branch_name || '-')}</td>
      <td class="text-right">${formatCurrency(po.total_amount)}</td>
      <td><span class="badge ${badge}">${label}</span></td>
    </tr>`;
  }).join('');
}

function renderRecentSaleLots(lots) {
  const tbody = document.querySelector('#recentSaleLotsTable tbody');
  if (!lots.length) { tbody.innerHTML = '<tr><td colspan="7" class="text-center">ยังไม่มีรายการขาย Lot</td></tr>'; return; }
  tbody.innerHTML = lots.map(lot => {
    const date = new Date(lot.sale_date).toLocaleDateString('th-TH');
    const badge = lot.status === 'confirmed' ? 'badge-success' : lot.status === 'draft' ? 'badge-warning' : 'badge-danger';
    const label = lot.status === 'confirmed' ? 'ยืนยันแล้ว' : lot.status === 'draft' ? 'ร่าง' : 'ยกเลิก';
    return `<tr>
      <td>${escapeHtml(lot.reference_no)}</td>
      <td>${date}</td>
      <td>${escapeHtml(lot.buyer_name || '-')}</td>
      <td>${escapeHtml(lot.branch_name || '-')}</td>
      <td class="text-right">${formatCurrency(lot.total_amount)}</td>
      <td class="text-right">${formatCurrency(lot.profit)}</td>
      <td><span class="badge ${badge}">${label}</span></td>
    </tr>`;
  }).join('');
}

function renderLowStockItems(items) {
  const tbody = document.querySelector('#lowStockTable tbody');
  if (!items.length) { tbody.innerHTML = '<tr><td colspan="6" class="text-center">ไม่มีสินค้าใกล้หมด</td></tr>'; return; }
  tbody.innerHTML = items.map(item => `<tr>
    <td>${escapeHtml(item.sku)}</td>
    <td>${escapeHtml(item.name)}</td>
    <td>${escapeHtml(item.category_name)}</td>
    <td class="${item.quantity <= 0 ? 'text-danger' : 'text-warning'}">${item.quantity}</td>
    <td>${item.low_stock_threshold}</td>
    <td><a href="inventory.html" class="btn btn-sm btn-info">ดู</a></td>
  </tr>`).join('');
}

