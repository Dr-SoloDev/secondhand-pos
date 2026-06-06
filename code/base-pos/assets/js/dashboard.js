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
  const statsResponse = await apiRequest('reports/dashboard-stats');
  if (statsResponse.status === 'success') {
    updateDashboardStats(statsResponse.data);
  }

  fetchPurchaseChartData('week');
  fetchSalelotChartData('week');

  const branchResponse = await apiRequest('branches/summary');
  if (branchResponse.status === 'success') {
    renderBranchSummary(branchResponse.data);
  }

  const purchaseResponse = await apiRequest('reports/recent-purchases');
  if (purchaseResponse.status === 'success') {
    renderRecentPurchases(purchaseResponse.data);
  }

  const salelotResponse = await apiRequest('reports/recent-sale-lots');
  if (salelotResponse.status === 'success') {
    renderRecentSaleLots(salelotResponse.data);
  }

  const stockResponse = await apiRequest('inventory/low-stock');
  if (stockResponse.status === 'success') {
    renderLowStockItems(stockResponse.data);
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

async function fetchPurchaseChartData(period) {
  const chartResponse = await apiRequest(`reports/purchase-chart?period=${period}`);
  if (chartResponse.status === 'success') {
    renderPurchaseChart(chartResponse.data, period);
  }
}

function renderPurchaseChart(data, period) {
  const chartContainer = document.getElementById('purchaseChart');
  const canvas = chartContainer.querySelector('canvas');

  if (canvas) {
    const chartInstance = Chart.getChart(canvas);
    if (chartInstance) {
      chartInstance.destroy();
    }
  }

  const newCanvas = document.createElement('canvas');
  chartContainer.innerHTML = '';
  chartContainer.appendChild(newCanvas);

  let labels = data.labels;
  if (period === 'week') {
    labels = data.labels.map(date => {
      const day = new Date(date).toLocaleDateString('th-TH', {weekday: 'short'});
      return day;
    });
  } else if (period === 'month') {
    labels = data.labels.map(date => {
      return new Date(date).getDate();
    });
  } else if (period === 'year') {
    labels = data.labels.map(date => {
      return new Date(date).toLocaleDateString('th-TH', {month: 'short'});
    });
  }

  new Chart(newCanvas, {
    type: 'line',
    data: {
      labels: labels,
      datasets: [{
        label: 'ยอดรับซื้อ',
        data: data.purchases,
        backgroundColor: 'rgba(37, 117, 252, 0.1)',
        borderColor: '#2575fc',
        borderWidth: 2,
        tension: 0.3,
        fill: true
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      scales: {
        y: {
          beginAtZero: true,
          ticks: {
            callback: function(value) {
              return '฿' + value;
            }
          }
        }
      },
      plugins: {
        tooltip: {
          callbacks: {
            label: function(context) {
              return 'ยอดรับซื้อ: ' + formatCurrency(context.raw);
            }
          }
        }
      }
    }
  });
}

function renderRecentPurchases(purchases) {
  const tableBody = document.querySelector('#recentPOTable tbody');
  tableBody.innerHTML = '';

  if (purchases.length === 0) {
    const row = document.createElement('tr');
    row.innerHTML = '<td colspan="6" class="text-center">ยังไม่มีรายการรับซื้อ</td>';
    tableBody.appendChild(row);
    return;
  }

  purchases.forEach(po => {
    const row = document.createElement('tr');

    const date = new Date(po.created_at).toLocaleDateString('th-TH');
    const time = new Date(po.created_at).toLocaleTimeString('th-TH');

    let badgeClass = 'badge-secondary';
    let statusText = po.status;
    if (po.status === 'completed') {
      badgeClass = 'badge-success';
      statusText = 'สำเร็จ';
    } else if (po.status === 'draft') {
      badgeClass = 'badge-warning';
      statusText = 'ร่าง';
    } else if (po.status === 'cancelled') {
      badgeClass = 'badge-danger';
      statusText = 'ยกเลิก';
    }

    row.innerHTML = `
            <td>${po.reference_no}</td>
            <td>${date} ${time}</td>
            <td>${po.seller_name || '-'}</td>
            <td>${po.branch_name || '-'}</td>
            <td>${formatCurrency(po.total_amount)}</td>
            <td><span class="badge ${badgeClass}">${statusText}</span></td>
        `;

    tableBody.appendChild(row);
  });
}

async function fetchSalelotChartData(period) {
  const chartResponse = await apiRequest(`reports/sale-lot-chart?period=${period}`);
  if (chartResponse.status === 'success') {
    renderSalelotDashboardChart(chartResponse.data, period);
  }
}

function renderSalelotDashboardChart(data, period) {
  const chartContainer = document.getElementById('salelotDashboardChart');
  const canvas = chartContainer.querySelector('canvas');
  if (canvas) {
    const chartInstance = Chart.getChart(canvas);
    if (chartInstance) chartInstance.destroy();
  }
  const newCanvas = document.createElement('canvas');
  chartContainer.innerHTML = '';
  chartContainer.appendChild(newCanvas);

  let labels = data.labels;
  if (period === 'week') {
    labels = data.labels.map(d => new Date(d).toLocaleDateString('th-TH', {weekday: 'short'}));
  } else if (period === 'month') {
    labels = data.labels.map(d => new Date(d).getDate());
  } else if (period === 'year') {
    labels = data.labels.map(d => new Date(d).toLocaleDateString('th-TH', {month: 'short'}));
  }

  new Chart(newCanvas, {
    type: 'bar',
    data: {
      labels: labels,
      datasets: [
        {
          label: 'ยอดขาย',
          data: data.amounts,
          backgroundColor: 'rgba(52, 152, 219, 0.7)',
          borderColor: '#3498db',
          borderWidth: 2
        },
        {
          label: 'กำไร',
          data: data.profits,
          backgroundColor: 'rgba(46, 204, 113, 0.7)',
          borderColor: '#2ecc71',
          borderWidth: 2
        }
      ]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      scales: {
        y: {
          beginAtZero: true,
          ticks: { callback: v => formatCurrency(v) }
        }
      },
      plugins: {
        tooltip: {
          callbacks: {
            label: ctx => ctx.dataset.label + ': ' + formatCurrency(ctx.raw)
          }
        }
      }
    }
  });
}

function renderRecentSaleLots(lots) {
  const tableBody = document.querySelector('#recentSaleLotsTable tbody');
  tableBody.innerHTML = '';

  if (lots.length === 0) {
    const row = document.createElement('tr');
    row.innerHTML = '<td colspan="7" class="text-center">ยังไม่มีรายการขาย Lot</td>';
    tableBody.appendChild(row);
    return;
  }

  lots.forEach(lot => {
    const row = document.createElement('tr');
    const date = new Date(lot.sale_date).toLocaleDateString('th-TH');

    let badgeClass = 'badge-secondary';
    let statusText = lot.status;
    if (lot.status === 'confirmed') {
      badgeClass = 'badge-success';
      statusText = 'ยืนยันแล้ว';
    } else if (lot.status === 'draft') {
      badgeClass = 'badge-warning';
      statusText = 'ร่าง';
    } else if (lot.status === 'cancelled') {
      badgeClass = 'badge-danger';
      statusText = 'ยกเลิก';
    }

    row.innerHTML = `
      <td>${lot.reference_no}</td>
      <td>${date}</td>
      <td>${lot.buyer_name || '-'}</td>
      <td>${lot.branch_name || '-'}</td>
      <td>${formatCurrency(lot.total_amount)}</td>
      <td>${formatCurrency(lot.profit)}</td>
      <td><span class="badge ${badgeClass}">${statusText}</span></td>
    `;
    tableBody.appendChild(row);
  });
}

// Render low stock items table
function renderLowStockItems(items) {
  const tableBody = document.querySelector('#lowStockTable tbody');
  tableBody.innerHTML = '';

  if (items.length === 0) {
    const row = document.createElement('tr');
    row.innerHTML = '<td colspan="6" class="text-center">No low stock items</td>';
    tableBody.appendChild(row);
    return;
  }

  items.forEach(item => {
    const row = document.createElement('tr');

    // Determine stock status class
    let stockClass = 'text-warning';
    if (item.quantity <= 0) {
      stockClass = 'text-danger';
    }

    row.innerHTML = `
            <td>${item.sku}</td>
            <td>${item.name}</td>
            <td>${item.category_name}</td>
            <td class="${stockClass}">${item.quantity}</td>
            <td>${item.low_stock_threshold}</td>
            <td>
                <a href="inventory-edit.html?id=${item.id}" class="btn btn-sm btn-info">
                    <i class="icon-edit"></i>
                </a>
            </td>
        `;

    tableBody.appendChild(row);
  });
}
function renderBranchSummary(branches) {
  const grid = document.getElementById('branchSummaryGrid');
  if (!branches || branches.length === 0) {
    grid.innerHTML = '<p style="color:var(--color-gray-500)">ยังไม่มีข้อมูลสาขา</p>';
    return;
  }
  grid.innerHTML = branches.map(b => {
    const pendingTotal = parseInt(b.pending_po_count || 0) + parseInt(b.pending_salelot_count || 0);
    const pendingHtml = pendingTotal > 0
      ? `<span class="branch-pending-badge">รอดำเนินการ ${pendingTotal}</span>` : '';
    return `
    <div class="branch-card">
      <div class="branch-card-header">
        <span class="branch-card-name">${escapeHtml(b.name)}</span>
        <span class="branch-card-code">${escapeHtml(b.code)}</span>
      </div>
      <div class="branch-card-stats">
        <div class="branch-stat">
          <div class="branch-stat-value">${formatCurrency(b.today_purchase_amount)}</div>
          <div class="branch-stat-label">รับซื้อวันนี้</div>
        </div>
        <div class="branch-stat">
          <div class="branch-stat-value">${parseInt(b.today_purchase_count)}</div>
          <div class="branch-stat-label">ใบรับซื้อวันนี้</div>
        </div>
        <div class="branch-stat">
          <div class="branch-stat-value">${formatCurrency(b.month_purchase_amount)}</div>
          <div class="branch-stat-label">รับซื้อเดือนนี้</div>
        </div>
        <div class="branch-stat">
          <div class="branch-stat-value">${parseFloat(b.total_stock_kg || 0).toLocaleString('th-TH', {maximumFractionDigits:1})} กก.</div>
          <div class="branch-stat-label">สต็อกรวม</div>
        </div>
      </div>
      <div class="branch-card-meta">
        ${b.manager_name ? `<span>👤 ${escapeHtml(b.manager_name)}</span>` : ''}
        ${pendingHtml}
      </div>
    </div>`;
  }).join('');
}
