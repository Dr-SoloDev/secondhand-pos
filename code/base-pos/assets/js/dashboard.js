document.addEventListener('DOMContentLoaded', function() {
  fetchDashboardData();

  document.getElementById('purchasePeriod').addEventListener('change', function() {
    fetchPurchaseChartData(this.value);
  });
});

async function fetchDashboardData() {
  const statsResponse = await apiRequest('reports/dashboard-stats');
  if (statsResponse.status === 'success') {
    updateDashboardStats(statsResponse.data);
  }

  fetchPurchaseChartData('week');

  const purchaseResponse = await apiRequest('reports/recent-purchases');
  if (purchaseResponse.status === 'success') {
    renderRecentPurchases(purchaseResponse.data);
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