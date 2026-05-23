document.addEventListener('DOMContentLoaded', function() {
  // Fetch dashboard data
  fetchDashboardData();

  // Event listeners for filter changes
  document.getElementById('salesPeriod').addEventListener('change', function() {
    fetchSalesChartData(this.value);
  });
});

// Fetch all dashboard data
async function fetchDashboardData() {
  // Fetch summary statistics
  const statsResponse = await apiRequest('reports/dashboard-stats');
  if (statsResponse.status === 'success') {
    updateDashboardStats(statsResponse.data);
  }

  // Fetch sales chart data (default: week)
  fetchSalesChartData('week');

  // Fetch recent sales
  const salesResponse = await apiRequest('reports/recent-sales');
  if (salesResponse.status === 'success') {
    renderRecentSales(salesResponse.data);
  }

  // Fetch low stock items
  const stockResponse = await apiRequest('inventory/low-stock');
  if (stockResponse.status === 'success') {
    renderLowStockItems(stockResponse.data);
  }
}

// Update dashboard statistics
function updateDashboardStats(stats) {
  document.getElementById('todaySales').textContent = formatCurrency(stats.today_sales);
  document.getElementById('todayOrders').textContent = stats.today_orders;
  document.getElementById('lowStockItems').textContent = stats.low_stock_count;
  document.getElementById('totalCustomers').textContent = stats.total_customers;
}

// Fetch sales chart data based on period
async function fetchSalesChartData(period) {
  const chartResponse = await apiRequest(`reports/sales-chart?period=${period}`);
  if (chartResponse.status === 'success') {
    renderSalesChart(chartResponse.data, period);
  }
}

// Render sales chart
function renderSalesChart(data, period) {
  const chartContainer = document.getElementById('salesChart');
  const canvas = chartContainer.querySelector('canvas');

  // If canvas already exists, destroy previous chart
  if (canvas) {
    const chartInstance = Chart.getChart(canvas);
    if (chartInstance) {
      chartInstance.destroy();
    }
  }

  // Create new canvas
  const newCanvas = document.createElement('canvas');
  chartContainer.innerHTML = '';
  chartContainer.appendChild(newCanvas);

  // Format labels based on period
  let labels = data.labels;
  if (period === 'week') {
    // Convert to day names
    labels = data.labels.map(date => {
      const day = new Date(date).toLocaleDateString('en-US', {weekday: 'short'});
      return day;
    });
  } else if (period === 'month') {
    // Use day of month
    labels = data.labels.map(date => {
      return new Date(date).getDate();
    });
  } else if (period === 'year') {
    // Use month names
    labels = data.labels.map(date => {
      return new Date(date).toLocaleDateString('en-US', {month: 'short'});
    });
  }

  // Create chart
  new Chart(newCanvas, {
    type: 'line',
    data: {
      labels: labels,
      datasets: [{
        label: 'Sales',
        data: data.sales,
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
              return 'Sales: ' + formatCurrency(context.raw);
            }
          }
        }
      }
    }
  });
}

// Render recent sales table
function renderRecentSales(sales) {
  const tableBody = document.querySelector('#recentSalesTable tbody');
  tableBody.innerHTML = '';

  if (sales.length === 0) {
    const row = document.createElement('tr');
    row.innerHTML = '<td colspan="6" class="text-center">No recent sales found</td>';
    tableBody.appendChild(row);
    return;
  }

  sales.forEach(sale => {
    const row = document.createElement('tr');

    const date = new Date(sale.created_at).toLocaleDateString();
    const time = new Date(sale.created_at).toLocaleTimeString();

    row.innerHTML = `
            <td>${sale.reference_no}</td>
            <td>${date} ${time}</td>
            <td>${sale.customer_name || 'Walk-in Customer'}</td>
            <td>${sale.item_count}</td>
            <td>${formatCurrency(sale.grand_total)}</td>
            <td><span class="badge ${sale.payment_status === 'paid' ? 'badge-success' : 'badge-warning'}">${sale.payment_status}</span></td>
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