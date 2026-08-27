// financial-summary.js — สรุปธุรกิจ

let branches = [];

async function init() {
  const user = await requireAuth();
  if (!user) return;
  const userNameEl = document.getElementById('userName') || document.getElementById('currentUser');
  if (userNameEl) userNameEl.textContent = user.full_name || user.username || '-';

  if (user.role !== 'admin') {
    document.querySelectorAll('.admin-only').forEach(el => el.style.display = 'none');
  }

  try {
    const res = await apiRequest('branches', 'GET');
    if (res.status === 'success') {
      branches = res.data?.items || res.data || [];
      const sel = document.getElementById('branchFilter');
      const canViewAllBranches = ['admin', 'super_manager'].includes(user.role);
      const visibleBranches = canViewAllBranches
        ? branches
        : branches.filter(b => String(b.id) === String(user.branch_id));

      if (!canViewAllBranches) {
        sel.innerHTML = '';
        sel.disabled = true;
      }

      visibleBranches.forEach(b => sel.appendChild(new Option(b.name, b.id)));
      if (!canViewAllBranches && user.branch_id) {
        sel.value = String(user.branch_id);
      }
    }
  } catch (e) {
    showNotification('โหลดข้อมูลไม่สำเร็จ กรุณาลองใหม่', 'error');
  }

  const now = new Date();
  const currentYear = now.getFullYear() + 543;
  const yearSel = document.getElementById('periodYear');
  for (let y = currentYear; y >= currentYear - 3; y--) {
    yearSel.appendChild(new Option(`พ.ศ. ${y}`, y - 543));
  }
  document.getElementById('periodMonth').value = now.getMonth() + 1;
  document.getElementById('periodType').addEventListener('change', function() {
    document.getElementById('periodMonth').style.display = this.value === 'month' ? '' : 'none';
  });

  loadSummary();
}

async function loadSummary() {
  const periodType = document.getElementById('periodType').value;
  const year       = document.getElementById('periodYear').value;
  const month      = document.getElementById('periodMonth').value;
  const branchId   = document.getElementById('branchFilter').value;

  let params = `period=${periodType}&year=${year}`;
  if (periodType === 'month') params += `&month=${month}`;
  if (branchId) params += `&branch_id=${branchId}`;

  showTableLoading('lotRevenueBody', 7, 5);
  showTableLoading('categoryBody', 4, 5);

  try {
    const [summaryRes, lotsRes, categoryRes] = await Promise.all([
      apiRequest(`financial/summary?${params}`, 'GET'),
      apiRequest(`financial/lot-revenues?${params}`, 'GET'),
      apiRequest(`financial/purchase-by-category?${params}`, 'GET'),
    ]);

    if (summaryRes.status === 'success') renderCards(summaryRes.data);
    else if (summaryRes.message) showNotification('โหลดข้อมูลสรุปไม่สำเร็จ', 'error');
    if (lotsRes.status === 'success')    renderLotTable(lotsRes.data?.items || []);
    else if (lotsRes.message) showNotification('โหลดข้อมูลรายได้ Lot ไม่สำเร็จ', 'error');
    if (categoryRes.status === 'success') renderCategoryTable(categoryRes.data?.items || []);
    else if (categoryRes.message) showNotification('โหลดข้อมูลประเภทสินค้าไม่สำเร็จ', 'error');
  } catch (e) {
    showNotification('โหลดข้อมูลไม่สำเร็จ กรุณาลองใหม่', 'error');
  }
}

function renderCards(data) {
  const totalPurchase   = parseFloat(data.total_purchase || 0);
  const totalRevenue    = parseFloat(data.total_revenue  || 0);
  const totalLotExp     = parseFloat(data.total_expenses || 0);
  const totalBizExp     = parseFloat(data.total_biz_expenses || 0);
  const netProfit       = totalRevenue - totalPurchase - totalLotExp - totalBizExp;
  const margin          = totalRevenue > 0 ? ((netProfit / totalRevenue) * 100).toFixed(1) : 0;

  document.getElementById('totalPurchase').textContent    = formatCurrency(totalPurchase);
  document.getElementById('totalPurchaseKg').textContent  = `${parseFloat(data.total_kg || 0).toLocaleString('th-TH')} กก.`;
  document.getElementById('totalRevenue').textContent     = formatCurrency(totalRevenue);
  document.getElementById('totalLots').textContent        = `${data.total_lots || 0} Lot`;
  document.getElementById('totalExpenses').textContent    = formatCurrency(totalLotExp);
  document.getElementById('totalBizExpenses').textContent = formatCurrency(totalBizExp);

  const profitEl = document.getElementById('netProfit');
  profitEl.textContent = (netProfit >= 0 ? '+' : '') + formatCurrency(netProfit);
  profitEl.className = 'value ' + (netProfit >= 0 ? 'income' : 'expense');
  document.getElementById('profitMargin').textContent = `margin ${margin}%`;
}

function renderLotTable(items) {
  const tbody = document.getElementById('lotRevenueBody');
  items = [...items].sort((a, b) => {
    const aDate = a.actual_revenue_date || a.sale_date || '';
    const bDate = b.actual_revenue_date || b.sale_date || '';
    return bDate.localeCompare(aDate);
  });
  if (!items.length) {
    tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;color:#888;padding:24px">ไม่มีข้อมูล</td></tr>';
    return;
  }
  tbody.innerHTML = items.map(lot => {
    const revenue = parseFloat(lot.actual_revenue || 0);
    const cost    = parseFloat(lot.total_cost || 0);
    const profit  = revenue - cost;
    const cls     = profit >= 0 ? 'profit-positive' : 'profit-negative';
    const branchName = branches.find(b => b.id == lot.branch_id)?.name || `สาขา ${lot.branch_id}`;
    return `<tr>
      <td style="font-family:monospace;font-size:12px">${escapeHtml(lot.reference_no || '')}</td>
      <td>${lot.actual_revenue_date || lot.sale_date || '-'}</td>
      <td>${escapeHtml(branchName)}</td>
      <td class="text-right" style="color:var(--color-danger)">${formatCurrency(cost)}</td>
      <td class="text-right" style="color:var(--color-success)">${revenue > 0 ? formatCurrency(revenue) : '<span style="color:#aaa;font-size:12px">ยังไม่บันทึก</span>'}</td>
      <td style="font-size:12px;color:var(--color-text-light)">${escapeHtml(lot.actual_revenue_note || '-')}</td>
      <td class="text-right"><span class="${cls}">${profit >= 0 ? '+' : ''}${formatCurrency(profit)}</span></td>
    </tr>`;
  }).join('');
}

function renderCategoryTable(items) {
  const tbody = document.getElementById('categoryBody');
  if (!items.length) {
    tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;color:#888;padding:24px">ไม่มีข้อมูล</td></tr>';
    return;
  }
  tbody.innerHTML = items.map(row => `<tr>
    <td>${escapeHtml(row.category_name || '-')}</td>
    <td class="text-right">${parseFloat(row.total_kg || 0).toLocaleString('th-TH', {minimumFractionDigits: 2})}</td>
    <td class="text-right">${row.total_items || 0}</td>
    <td class="text-right" style="color:var(--color-danger)">${formatCurrency(row.total_amount)}</td>
  </tr>`).join('');
}

function escapeHtml(str) {
  return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

async function exportCsv(type) {
  const periodType = document.getElementById('periodType').value;
  const year       = document.getElementById('periodYear').value;
  const month      = document.getElementById('periodMonth').value;
  const branchId   = document.getElementById('branchFilter').value;
  let params = `type=${type}&period=${periodType}&year=${year}`;
  if (periodType === 'month') params += `&month=${month}`;
  if (branchId) params += `&branch_id=${branchId}`;

  const res = await fetch(`${window.apiPath}/financial/export?${params}`, {
    credentials: 'include'
  });
  if (!res.ok) { showNotification('export ไม่สำเร็จ', 'error'); return; }
  const blob = await res.blob();
  const cd = res.headers.get('Content-Disposition') || '';
  const filename = cd.match(/filename="([^"]+)"/)?.[1] || `export_${type}.csv`;
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = filename;
  document.body.appendChild(a);
  a.click();
  a.remove();
  URL.revokeObjectURL(a.href);
}

document.addEventListener('DOMContentLoaded', init);
document.getElementById('logoutBtn')?.addEventListener('click', (e) => {
  e.preventDefault();
  localStorage.removeItem('posUser');
  window.location.href = '../index.html';
});

// === Chart Instances ===
let trendChart = null;
let profitChart = null;
let branchChart = null;

// === Monthly Trend Chart ===
function drawTrendChart(data) {
  const ctx = document.getElementById('trendChart').getContext('2d');
  if (trendChart) trendChart.destroy();

  const months = data.map(d => d.month_th);
  trendChart = new Chart(ctx, {
    type: 'line',
    data: {
      labels: months,
      datasets: [
        {
          label: 'รายรับขาย Lot',
          data: data.map(d => d.revenue),
          borderColor: '#059669',
          backgroundColor: 'rgba(5,150,105,0.1)',
          fill: true,
          tension: 0.3,
        },
        {
          label: 'รายจ่ายรับซื้อ',
          data: data.map(d => d.purchase),
          borderColor: '#dc2626',
          backgroundColor: 'rgba(220,38,38,0.1)',
          fill: true,
          tension: 0.3,
        },
      ],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: { legend: { position: 'top' } },
      scales: {
        y: { beginAtZero: true, ticks: { callback: v => '฿' + (v >= 1000000 ? (v/1000000).toFixed(1)+'M' : v.toLocaleString()) } },
      },
    },
  });
}

// === Profit Chart ===
function drawProfitChart(data) {
  const ctx = document.getElementById('profitChart').getContext('2d');
  if (profitChart) profitChart.destroy();

  const months = data.map(d => d.month_th);
  const profits = data.map(d => d.profit);
  const colors = profits.map(v => v >= 0 ? '#059669' : '#dc2626');

  profitChart = new Chart(ctx, {
    type: 'bar',
    data: {
      labels: months,
      datasets: [{
        label: 'กำไร (บาท)',
        data: profits,
        backgroundColor: colors,
        borderRadius: 4,
      }],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: { legend: { display: false } },
      scales: {
        y: { ticks: { callback: v => '฿' + (v >= 1000000 ? (v/1000000).toFixed(1)+'M' : v.toLocaleString()) } },
      },
    },
  });
}

// === Branch Comparison Chart ===
function drawBranchChart(data) {
  const ctx = document.getElementById('branchChart').getContext('2d');
  if (branchChart) branchChart.destroy();

  const names = data.map(d => d.branch_name);
  branchChart = new Chart(ctx, {
    type: 'bar',
    data: {
      labels: names,
      datasets: [
        {
          label: 'รายรับ',
          data: data.map(d => d.revenue),
          backgroundColor: '#059669',
          borderRadius: 4,
        },
        {
          label: 'รายจ่าย',
          data: data.map(d => d.purchase),
          backgroundColor: '#dc2626',
          borderRadius: 4,
        },
        {
          label: 'กำไร',
          data: data.map(d => d.profit),
          backgroundColor: '#d97706',
          borderRadius: 4,
        },
      ],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: { legend: { position: 'top' } },
      scales: {
        y: { ticks: { callback: v => '฿' + (v >= 1000000 ? (v/1000000).toFixed(1)+'M' : v.toLocaleString()) } },
      },
    },
  });
}

// === Top Sellers ===
async function loadTopSellers() {
  try {
    const params = buildPeriodParams();
    const res = await apiRequest(`financial/top-sellers?${params}`, 'GET');
    if (res.status === 'success') renderTopSellers(res.data?.items || []);
  } catch (e) {
    console.error('Top sellers load failed:', e);
  }
}

function renderTopSellers(items) {
  const tbody = document.getElementById('topSellersBody');
  if (!items.length) {
    tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;color:#888">ไม่มีข้อมูล</td></tr>';
    return;
  }
  tbody.innerHTML = items.map((item, i) => `<tr>
    <td>${i + 1}</td>
    <td>${escapeHtml(item.seller_name || '-')}</td>
    <td>${item.bill_count || 0}</td>
    <td class="text-right" style="color:var(--color-danger)">${formatCurrency(parseFloat(item.total_amount || 0))}</td>
  </tr>`).join('');
}

// === Top Buyers ===
async function loadTopBuyers() {
  try {
    const params = buildPeriodParams();
    const res = await apiRequest(`financial/top-buyers?${params}`, 'GET');
    if (res.status === 'success') renderTopBuyers(res.data?.items || []);
  } catch (e) {
    console.error('Top buyers load failed:', e);
  }
}

function renderTopBuyers(items) {
  const tbody = document.getElementById('topBuyersBody');
  if (!items.length) {
    tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;color:#888">ไม่มีข้อมูล</td></tr>';
    return;
  }
  tbody.innerHTML = items.map((item, i) => `<tr>
    <td>${i + 1}</td>
    <td>${escapeHtml(item.buyer_name || '-')}</td>
    <td>${item.lot_count || 0}</td>
    <td class="text-right" style="color:var(--color-success)">${formatCurrency(parseFloat(item.total_revenue || 0))}</td>
  </tr>`).join('');
}

// === Build period params string ===
function buildPeriodParams() {
  const periodType = document.getElementById('periodType').value;
  const year       = document.getElementById('periodYear').value;
  const month      = document.getElementById('periodMonth').value;
  const branchId   = document.getElementById('branchFilter').value;
  let params = `period=${periodType}&year=${year}`;
  if (periodType === 'month') params += `&month=${month}`;
  if (branchId) params += `&branch_id=${branchId}`;
  return params;
}

// === Export Excel ===
async function exportExcel(type) {
  const params = buildPeriodParams();
  const url = `${window.apiPath}/financial/export-excel?type=${type}&${params}`;
  const a = document.createElement('a');
  a.href = url;
  a.click();
  showNotification('กำลังดาวน์โหลด...', 'success');
}

// === Print Report ===
function printReport() {
  const cards = document.querySelectorAll('.card');

  const printWindow = window.open('', '_blank');
  if (!printWindow) {
    showNotification('เปิดหน้าต่างพิมพ์ไม่สำเร็จ กรุณาอนุญาต popup', 'error');
    return;
  }

  // ✅ Safe: Write static HTML shell only
  printWindow.document.write(`
    <!DOCTYPE html>
    <html lang="th">
    <head>
      <meta charset="utf-8">
      <title>รายงานสรุปธุรกิจ</title>
      <link rel="stylesheet" href="../assets/css/main.css">
      <style>
        @media print {
          body { margin: 0; }
          .card { break-inside: avoid; }
          h1 { text-align: center; }
        }
      </style>
    </head>
    <body></body>
    </html>
  `);
  printWindow.document.close();

  // ✅ Safe: Use DOM API to set content
  const body = printWindow.document.body;

  const h1 = printWindow.document.createElement('h1');
  h1.textContent = 'รายงานสรุปธุรกิจ — รักษ์สะอาดรีไซเคิล';
  body.appendChild(h1);

  // Import page header
  const pageHeader = document.querySelector('.page-header');
  if (pageHeader) {
    const safeHeader = printWindow.document.importNode(pageHeader, true);
    body.appendChild(safeHeader);
  }

  // Import cards
  cards.forEach(card => {
    const safeCard = printWindow.document.importNode(card, true);
    body.appendChild(safeCard);
  });

  setTimeout(() => printWindow.print(), 500);
}

// === Override loadSummary to also load charts and Top 5 ===
const _origLoadSummary = loadSummary;
loadSummary = async function() {
  await _origLoadSummary();
  await loadCharts();
  await loadTopSellers();
  await loadTopBuyers();
};

// === Load Charts ===
async function loadCharts() {
  try {
    const params = buildPeriodParams();

    // Monthly trend
    const trendRes = await apiRequest(`financial/monthly-trend?${params}`, 'GET');
    if (trendRes.status === 'success' && trendRes.data?.trend) {
      drawTrendChart(trendRes.data.trend);
      drawProfitChart(trendRes.data.trend);
    }

    // Branch comparison
    const branchRes = await apiRequest(`financial/branch-comparison?${params}`, 'GET');
    if (branchRes.status === 'success' && branchRes.data?.comparison) {
      drawBranchChart(branchRes.data.comparison);
    }
  } catch (e) {
    console.error('Charts load failed:', e);
  }
}
