document.addEventListener('DOMContentLoaded', function() {
  // Initialize reports page
  initReports();

  // Set default dates (current month)
  setDefaultDates();

  // Event listeners for report type selection
  document.querySelectorAll('.report-type-item').forEach(item => {
    item.addEventListener('click', function() {
      switchReportType(this.dataset.report);
    });
  });

  // Event listeners for report generation
  document.getElementById('generateSalesReport').addEventListener('click', generateSalesReport);
  document.getElementById('generateProductsReport').addEventListener('click', generateProductsReport);
  document.getElementById('generateInventoryReport').addEventListener('click', generateInventoryReport);
  document.getElementById('generateCashierReport').addEventListener('click', generateCashierReport);
  document.getElementById('generateTaxReport').addEventListener('click', generateTaxReport);

  // Export and print buttons
  document.getElementById('exportReportBtn').addEventListener('click', exportCurrentReport);
  document.getElementById('printReportBtn').addEventListener('click', printCurrentReport);
});

// Global variables
let currentReportType = 'sales';
let categories = [];
let users = [];
let currentReportData = null;

// Initialize reports
async function initReports() {
  try {
    // Load categories for dropdowns
    const categoryResponse = await apiRequest('inventory/categories');
    if (categoryResponse.status === 'success') {
      categories = categoryResponse.data;
      populateCategoryDropdowns();
    }

    // Load users for cashier dropdown
    const userResponse = await apiRequest('users/all');
    if (userResponse.status === 'success') {
      users = userResponse.data.filter(user => ['cashier', 'manager', 'admin'].includes(user.role));
      populateUserDropdown();
    }

    // Generate initial sales report
    generateSalesReport();
  } catch (error) {
    console.error('Failed to initialize reports:', error);
    showNotification('Error loading report data', 'error');
  }
}

// Set default dates (current month)
function setDefaultDates() {
  const today = new Date();
  const firstDay = new Date(today.getFullYear(), today.getMonth(), 1);

  const todayStr = formatDateForInput(today);
  const firstDayStr = formatDateForInput(firstDay);

  // Set sales report dates
  document.getElementById('salesDateFrom').value = firstDayStr;
  document.getElementById('salesDateTo').value = todayStr;

  // Set product sales report dates
  document.getElementById('productsDateFrom').value = firstDayStr;
  document.getElementById('productsDateTo').value = todayStr;

  // Set cashier report dates
  document.getElementById('cashierDateFrom').value = firstDayStr;
  document.getElementById('cashierDateTo').value = todayStr;

  // Set tax report dates
  document.getElementById('taxDateFrom').value = firstDayStr;
  document.getElementById('taxDateTo').value = todayStr;
}

// Format date for input fields (YYYY-MM-DD)
function formatDateForInput(date) {
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, '0');
  const day = String(date.getDate()).padStart(2, '0');
  return `${year}-${month}-${day}`;
}

// Populate category dropdowns
function populateCategoryDropdowns() {
  const productsCategorySelect = document.getElementById('productsCategory');
  const inventoryCategorySelect = document.getElementById('inventoryCategory');

  // Clear existing options (except the first one)
  while (productsCategorySelect.options.length > 1) {
    productsCategorySelect.remove(1);
  }
  while (inventoryCategorySelect.options.length > 1) {
    inventoryCategorySelect.remove(1);
  }

  // Add categories to dropdowns
  categories.forEach(category => {
    if (category.status === 'active') {
      const productOption = document.createElement('option');
      productOption.value = category.id;
      productOption.textContent = category.name;
      productsCategorySelect.appendChild(productOption);

      const inventoryOption = document.createElement('option');
      inventoryOption.value = category.id;
      inventoryOption.textContent = category.name;
      inventoryCategorySelect.appendChild(inventoryOption);
    }
  });
}

// Populate user dropdown
function populateUserDropdown() {
  const cashierSelect = document.getElementById('cashierUser');

  // Clear existing options (except the first one)
  while (cashierSelect.options.length > 1) {
    cashierSelect.remove(1);
  }

  // Add users to dropdown
  users.forEach(user => {
    const option = document.createElement('option');
    option.value = user.id;
    option.textContent = `${user.username} (${user.full_name})`;
    cashierSelect.appendChild(option);
  });
}

// Switch between report types
function switchReportType(reportType) {
  currentReportType = reportType;

  // Update active state in report type selection
  document.querySelectorAll('.report-type-item').forEach(item => {
    if (item.dataset.report === reportType) {
      item.classList.add('active');
    } else {
      item.classList.remove('active');
    }
  });

  // Hide all report sections
  document.querySelectorAll('.report-section').forEach(section => {
    section.style.display = 'none';
  });

  // Show selected report section
  document.getElementById(`${reportType}Report`).style.display = 'block';

  // Generate report based on type
  switch (reportType) {
    case 'sales':
      generateSalesReport();
      break;
    case 'products':
      generateProductsReport();
      break;
    case 'inventory':
      generateInventoryReport();
      break;
    case 'cashier':
      generateCashierReport();
      break;
    case 'tax':
      generateTaxReport();
      break;
  }
}

// Generate sales report
async function generateSalesReport() {
  try {
    const dateFrom = document.getElementById('salesDateFrom').value;
    const dateTo = document.getElementById('salesDateTo').value;
    const groupBy = document.getElementById('salesGroupBy').value;

    if (!dateFrom || !dateTo) {
      showNotification('Please select date range', 'error');
      return;
    }

    showNotification('Generating sales report...', 'info');

    const response = await apiRequest(`reports/sales-report?date_from=${dateFrom}&date_to=${dateTo}&group_by=${groupBy}`);

    if (response.status === 'success') {
      currentReportData = response.data;
      renderSalesReport(response.data);
    } else {
      showNotification(response.message || 'Failed to generate sales report', 'error');
    }
  } catch (error) {
    console.error('Error generating sales report:', error);
    showNotification('Error generating sales report', 'error');
  }
}

// Render sales report
function renderSalesReport(data) {
  // Update summary values
  document.getElementById('totalOrders').textContent = data.totals.total_orders;
  document.getElementById('totalSales').textContent = formatCurrency(data.totals.total_sales);
  document.getElementById('totalDiscounts').textContent = formatCurrency(data.totals.total_discounts);
  document.getElementById('totalTax').textContent = formatCurrency(data.totals.total_tax);

  // Render table
  const tableBody = document.querySelector('#salesReportTable tbody');
  tableBody.innerHTML = '';

  if (data.report_data.length === 0) {
    const row = document.createElement('tr');
    row.innerHTML = '<td colspan="6" class="text-center">No data available for the selected period</td>';
    tableBody.appendChild(row);
  } else {
    data.report_data.forEach(row => {
      const tr = document.createElement('tr');
      tr.innerHTML = `
        <td>${row.label}</td>
        <td>${row.order_count}</td>
        <td>${formatCurrency(row.total_amount)}</td>
        <td>${formatCurrency(row.discount_amount)}</td>
        <td>${formatCurrency(row.tax_amount)}</td>
        <td>${formatCurrency(row.grand_total)}</td>
      `;
      tableBody.appendChild(tr);
    });
  }

  // Render chart
  renderSalesChart(data);

  showNotification('Sales report generated successfully', 'success');
}

// Render sales chart
function renderSalesChart(data) {
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

  // Prepare data for chart
  const labels = data.report_data.map(row => row.label);
  const salesData = data.report_data.map(row => row.grand_total);

  // Create chart
  new Chart(newCanvas, {
    type: 'bar',
    data: {
      labels: labels,
      datasets: [{
        label: 'Sales',
        data: salesData,
        backgroundColor: 'rgba(37, 117, 252, 0.7)',
        borderColor: '#2575fc',
        borderWidth: 1
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
              return formatCurrency(value);
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

// Generate product sales report
async function generateProductsReport() {
  try {
    const dateFrom = document.getElementById('productsDateFrom').value;
    const dateTo = document.getElementById('productsDateTo').value;
    const categoryId = document.getElementById('productsCategory').value;

    if (!dateFrom || !dateTo) {
      showNotification('Please select date range', 'error');
      return;
    }

    showNotification('Generating product sales report...', 'info');

    const params = new URLSearchParams({
      date_from: dateFrom,
      date_to: dateTo,
      limit: 50
    });

    if (categoryId) {
      params.append('category_id', categoryId);
    }

    const response = await apiRequest(`reports/product-sales?${params.toString()}`);

    if (response.status === 'success') {
      currentReportData = response.data;
      renderProductsReport(response.data);
    } else {
      showNotification(response.message || 'Failed to generate product sales report', 'error');
    }
  } catch (error) {
    console.error('Error generating product sales report:', error);
    showNotification('Error generating product sales report', 'error');
  }
}

// Render product sales report
function renderProductsReport(data) {
  // Find top product and calculate totals
  let topProduct = '-';
  let totalQty = 0;
  let totalRev = 0;

  if (data.length > 0) {
    const sorted = [...data].sort((a, b) => b.total_sales - a.total_sales);
    topProduct = sorted[0].name;

    data.forEach(product => {
      totalQty += product.quantity_sold;
      totalRev += product.total_sales;
    });
  }

  // Update summary values
  document.getElementById('topProduct').textContent = topProduct;
  document.getElementById('totalQuantity').textContent = totalQty;
  document.getElementById('totalRevenue').textContent = formatCurrency(totalRev);

  // Render table
  const tableBody = document.querySelector('#productsReportTable tbody');
  tableBody.innerHTML = '';

  if (data.length === 0) {
    const row = document.createElement('tr');
    row.innerHTML = '<td colspan="6" class="text-center">No data available for the selected period</td>';
    tableBody.appendChild(row);
  } else {
    data.forEach(product => {
      const tr = document.createElement('tr');
      tr.innerHTML = `
        <td>${product.sku}</td>
        <td>${product.name}</td>
        <td>${product.category_name || 'Uncategorized'}</td>
        <td>${product.quantity_sold}</td>
        <td>${formatCurrency(product.total_sales)}</td>
        <td>${product.order_count}</td>
      `;
      tableBody.appendChild(tr);
    });
  }

  // Render chart
  renderProductsChart(data);

  showNotification('Product sales report generated successfully', 'success');
}

// Render products chart
function renderProductsChart(data) {
  const chartContainer = document.getElementById('productsChart');
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

  // Prepare data for chart (top 10 products by sales)
  const sortedData = [...data].sort((a, b) => b.total_sales - a.total_sales).slice(0, 10);
  const labels = sortedData.map(product => product.name);
  const salesData = sortedData.map(product => product.total_sales);

  // Create chart
  new Chart(newCanvas, {
    type: 'bar',
    data: {
      labels: labels,
      datasets: [{
        label: 'Revenue',
        data: salesData,
        backgroundColor: 'rgba(46, 204, 113, 0.7)',
        borderColor: '#2ecc71',
        borderWidth: 1
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      indexAxis: 'y',
      scales: {
        x: {
          beginAtZero: true,
          ticks: {
            callback: function(value) {
              return formatCurrency(value);
            }
          }
        }
      },
      plugins: {
        tooltip: {
          callbacks: {
            label: function(context) {
              return 'Revenue: ' + formatCurrency(context.raw);
            }
          }
        },
        legend: {
          display: false
        }
      }
    }
  });
}

// Generate inventory report
async function generateInventoryReport() {
  try {
    const categoryId = document.getElementById('inventoryCategory').value;
    const stockStatus = document.getElementById('inventoryStatus').value;

    showNotification('Generating inventory report...', 'info');

    const params = new URLSearchParams();

    if (categoryId) {
      params.append('category_id', categoryId);
    }

    if (stockStatus !== 'all') {
      params.append('stock_status', stockStatus);
    }

    const response = await apiRequest(`reports/inventory-report?${params.toString()}`);

    if (response.status === 'success') {
      currentReportData = response.data;
      renderInventoryReport(response.data);
    } else {
      showNotification(response.message || 'Failed to generate inventory report', 'error');
    }
  } catch (error) {
    console.error('Error generating inventory report:', error);
    showNotification('Error generating inventory report', 'error');
  }
}

// Render inventory report
function renderInventoryReport(data) {
  // Update summary values
  document.getElementById('totalProducts').textContent = data.totals.total_items;
  document.getElementById('inventoryQuantity').textContent = data.totals.total_quantity;
  document.getElementById('inventoryValue').textContent = formatCurrency(data.totals.total_value);
  document.getElementById('lowStockCount').textContent = data.totals.low_stock_count;

  // Render table
  const tableBody = document.querySelector('#inventoryReportTable tbody');
  tableBody.innerHTML = '';

  if (data.inventory.length === 0) {
    const row = document.createElement('tr');
    row.innerHTML = '<td colspan="8" class="text-center">No inventory data available</td>';
    tableBody.appendChild(row);
  } else {
    data.inventory.forEach(item => {
      const tr = document.createElement('tr');

      // Determine stock status
      let stockStatus = 'Normal';
      let statusClass = '';

      if (item.quantity <= 0) {
        stockStatus = 'Out of Stock';
        statusClass = 'text-danger';
      } else if (item.quantity <= item.low_stock_threshold) {
        stockStatus = 'Low Stock';
        statusClass = 'text-warning';
      }

      tr.innerHTML = `
      <td>${item.sku}</td>
      <td>${item.name}</td>
      <td>${item.category_name || 'Uncategorized'}</td>
      <td class="${statusClass}">${item.quantity}</td>
      <td>${item.low_stock_threshold}</td>
      <td>${formatCurrency(item.cost)}</td>
      <td>${formatCurrency(item.inventory_value)}</td>
      <td class="${statusClass}">${stockStatus}</td>
    `;
      tableBody.appendChild(tr);
    });
  }

  // Render chart
  renderInventoryChart(data);

  showNotification('Inventory report generated successfully', 'success');
}

// Render inventory chart
function renderInventoryChart(data) {
  const chartContainer = document.getElementById('inventoryChart');
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

  // Group by category and calculate total quantity per category
  const categoryData = {};
  data.inventory.forEach(item => {
    const category = item.category_name || 'Uncategorized';
    if (!categoryData[category]) {
      categoryData[category] = 0;
    }
    categoryData[category] += item.quantity;
  });

  const categories = Object.keys(categoryData);
  const quantities = Object.values(categoryData);

  // Create chart
  new Chart(newCanvas, {
    type: 'pie',
    data: {
      labels: categories,
      datasets: [{
        data: quantities,
        backgroundColor: [
          'rgba(52, 152, 219, 0.7)',
          'rgba(155, 89, 182, 0.7)',
          'rgba(52, 73, 94, 0.7)',
          'rgba(230, 126, 34, 0.7)',
          'rgba(231, 76, 60, 0.7)',
          'rgba(241, 196, 15, 0.7)',
          'rgba(46, 204, 113, 0.7)',
          'rgba(26, 188, 156, 0.7)',
          'rgba(127, 140, 141, 0.7)'
        ],
        borderWidth: 1
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: {
          position: 'right'
        },
        tooltip: {
          callbacks: {
            label: function(context) {
              const label = context.label || '';
              const value = context.raw || 0;
              const total = context.dataset.data.reduce((acc, val) => acc + val, 0);
              const percentage = Math.round((value / total) * 100);
              return `${label}: ${value} units (${percentage}%)`;
            }
          }
        }
      }
    }
  });
}

// Generate cashier performance report
async function generateCashierReport() {
  try {
    const dateFrom = document.getElementById('cashierDateFrom').value;
    const dateTo = document.getElementById('cashierDateTo').value;
    const userId = document.getElementById('cashierUser').value;

    if (!dateFrom || !dateTo) {
      showNotification('Please select date range', 'error');
      return;
    }

    showNotification('Generating cashier performance report...', 'info');

    const params = new URLSearchParams({
      date_from: dateFrom,
      date_to: dateTo
    });

    if (userId) {
      params.append('user_id', userId);
    }

    const response = await apiRequest(`reports/cashier-performance?${params.toString()}`);

    if (response.status === 'success') {
      currentReportData = response.data;
      renderCashierReport(response.data);
    } else {
      showNotification(response.message || 'Failed to generate cashier report', 'error');
    }
  } catch (error) {
    console.error('Error generating cashier report:', error);
    showNotification('Error generating cashier report', 'error');
  }
}

// Render cashier performance report
function renderCashierReport(data) {
  // Render table
  const tableBody = document.querySelector('#cashierReportTable tbody');
  tableBody.innerHTML = '';

  if (data.cashiers.length === 0) {
    const row = document.createElement('tr');
    row.innerHTML = '<td colspan="6" class="text-center">No data available for the selected period</td>';
    tableBody.appendChild(row);
  } else {
    data.cashiers.forEach(cashier => {
      const tr = document.createElement('tr');

      // Calculate average order value
      const avgOrderValue = cashier.total_sales / (cashier.order_count || 1);

      tr.innerHTML = `
      <td>${cashier.full_name} (${cashier.username})</td>
      <td>${cashier.order_count}</td>
      <td>${cashier.items_sold}</td>
      <td>${formatCurrency(cashier.total_sales)}</td>
      <td>${formatCurrency(avgOrderValue)}</td>
      <td>${cashier.cancelled_orders}</td>
    `;
      tableBody.appendChild(tr);
    });
  }

  // Render chart
  renderCashierChart(data);

  showNotification('Cashier performance report generated successfully', 'success');
}

// Render cashier chart
function renderCashierChart(data) {
  const chartContainer = document.getElementById('cashierChart');
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

  // Prepare data for chart
  const labels = data.cashiers.map(cashier => cashier.username);
  const salesData = data.cashiers.map(cashier => cashier.total_sales);
  const ordersData = data.cashiers.map(cashier => cashier.order_count);

  // Create chart
  new Chart(newCanvas, {
    type: 'bar',
    data: {
      labels: labels,
      datasets: [
        {
          label: 'Sales Amount',
          data: salesData,
          backgroundColor: 'rgba(52, 152, 219, 0.7)',
          borderColor: '#3498db',
          borderWidth: 1,
          yAxisID: 'y'
        },
        {
          label: 'Order Count',
          data: ordersData,
          backgroundColor: 'rgba(46, 204, 113, 0.7)',
          borderColor: '#2ecc71',
          borderWidth: 1,
          yAxisID: 'y1'
        }
      ]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      scales: {
        y: {
          beginAtZero: true,
          position: 'left',
          title: {
            display: true,
            text: 'Sales Amount'
          },
          ticks: {
            callback: function(value) {
              return formatCurrency(value);
            }
          }
        },
        y1: {
          beginAtZero: true,
          position: 'right',
          title: {
            display: true,
            text: 'Order Count'
          },
          grid: {
            drawOnChartArea: false
          }
        }
      }
    }
  });
}

// Generate tax report
async function generateTaxReport() {
  try {
    const dateFrom = document.getElementById('taxDateFrom').value;
    const dateTo = document.getElementById('taxDateTo').value;
    const taxPeriod = document.getElementById('taxPeriod').value;

    if (!dateFrom || !dateTo) {
      showNotification('Please select date range', 'error');
      return;
    }

    showNotification('Generating tax report...', 'info');

    const params = new URLSearchParams({
      date_from: dateFrom,
      date_to: dateTo,
      period: taxPeriod
    });

    const response = await apiRequest(`reports/tax-report?${params.toString()}`);

    if (response.status === 'success') {
      currentReportData = response.data;
      renderTaxReport(response.data);
    } else {
      showNotification(response.message || 'Failed to generate tax report', 'error');
    }
  } catch (error) {
    console.error('Error generating tax report:', error);
    showNotification('Error generating tax report', 'error');
  }
}

// Render tax report
function renderTaxReport(data) {
  // Update summary values
  document.getElementById('taxableSales').textContent = formatCurrency(data.totals.taxable_sales);
  document.getElementById('taxCollected').textContent = formatCurrency(data.totals.tax_collected);

  // Calculate average tax rate
  const avgTaxRate = (data.totals.tax_collected / data.totals.taxable_sales) * 100 || 0;
  document.getElementById('taxRate').textContent = avgTaxRate.toFixed(2) + '%';

  // Render table
  const tableBody = document.querySelector('#taxReportTable tbody');
  tableBody.innerHTML = '';

  if (data.periods.length === 0) {
    const row = document.createElement('tr');
    row.innerHTML = '<td colspan="4" class="text-center">No data available for the selected period</td>';
    tableBody.appendChild(row);
  } else {
    data.periods.forEach(period => {
      const tr = document.createElement('tr');
      tr.innerHTML = `
      <td>${period.period}</td>
      <td>${formatCurrency(period.taxable_sales)}</td>
      <td>${formatCurrency(period.tax_collected)}</td>
      <td>${formatCurrency(period.taxable_sales + period.tax_collected)}</td>
    `;
      tableBody.appendChild(tr);
    });
  }

  showNotification('Tax report generated successfully', 'success');
}

// Export current report
function exportCurrentReport() {
  if (!currentReportData) {
    showNotification('Please generate a report first', 'error');
    return;
  }

  let filename, csvContent;

  switch (currentReportType) {
    case 'sales':
      filename = `sales_report_${formatDateForFilename(new Date())}.csv`;
      csvContent = generateSalesReportCSV(currentReportData);
      break;
    case 'products':
      filename = `product_sales_report_${formatDateForFilename(new Date())}.csv`;
      csvContent = generateProductsReportCSV(currentReportData);
      break;
    case 'inventory':
      filename = `inventory_report_${formatDateForFilename(new Date())}.csv`;
      csvContent = generateInventoryReportCSV(currentReportData);
      break;
    case 'cashier':
      filename = `cashier_performance_report_${formatDateForFilename(new Date())}.csv`;
      csvContent = generateCashierReportCSV(currentReportData);
      break;
    case 'tax':
      filename = `tax_report_${formatDateForFilename(new Date())}.csv`;
      csvContent = generateTaxReportCSV(currentReportData);
      break;
  }

  // Create download link
  const blob = new Blob([csvContent], {type: 'text/csv;charset=utf-8;'});
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.setAttribute('href', url);
  link.setAttribute('download', filename);
  link.style.visibility = 'hidden';
  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);
}

// Print current report
function printCurrentReport() {
  if (!currentReportData) {
    showNotification('Please generate a report first', 'error');
    return;
  }

  // Get the report section to print
  const reportSection = document.getElementById(`${currentReportType}Report`);

  // Create a new window for printing
  const printWindow = window.open('', '_blank');
  const reportTitle = document.querySelector(`.report-type-item[data-report="${currentReportType}"] span`).textContent;

  // Generate printer-friendly content
  printWindow.document.write(`
  <!DOCTYPE html>
  <html>
  <head>
    <title>${reportTitle}</title>
    <style>
      body { font-family: Arial, sans-serif; margin: 20px; }
      h1 { text-align: center; margin-bottom: 20px; }
      table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
      th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
      th { background-color: #f2f2f2; }
      .summary { display: flex; flex-wrap: wrap; margin-bottom: 20px; }
      .summary-item { margin-right: 30px; margin-bottom: 10px; }
      .summary-label { font-weight: 400; }
      .text-danger { color: #dc3545; }
      .text-warning { color: #ffc107; }
      .text-center { text-align: center; }
      @media print {
        @page { size: landscape; }
      }
    </style>
  </head>
  <body>
    <h1>${reportTitle}</h1>
    <div class="report-date">
      Generated on: ${new Date().toLocaleString()}
    </div>
    ${reportSection.querySelector('.report-summary') ?
      `<div class="summary">
        ${reportSection.querySelector('.report-summary').innerHTML}
      </div>` : ''}
    <div class="table-container">
      ${reportSection.querySelector('.data-table').outerHTML}
    </div>
  </body>
  </html>
`);

  printWindow.document.close();

  // Add a small delay to ensure content is loaded
  setTimeout(() => {
    printWindow.print();
    // Don't close the window after printing to allow user to see the preview
  }, 500);
}

// Generate CSV content for sales report
function generateSalesReportCSV(data) {
  let csvContent = 'Period,Orders,Sales,Discounts,Tax,Total\n';

  data.report_data.forEach(row => {
    csvContent += `${row.label},${row.order_count},${row.total_amount},${row.discount_amount},${row.tax_amount},${row.grand_total}\n`;
  });

  // Add totals row
  csvContent += `Total,${data.totals.total_orders},${data.totals.total_sales},${data.totals.total_discounts},${data.totals.total_tax},${data.totals.total_grand}\n`;

  return csvContent;
}

// Generate CSV content for product sales report
function generateProductsReportCSV(data) {
  let csvContent = 'SKU,Product,Category,Quantity Sold,Revenue,Orders\n';

  data.forEach(product => {
    csvContent += `${product.sku},"${product.name.replace(/"/g, '""')}",${product.category_name || 'Uncategorized'},${product.quantity_sold},${product.total_sales},${product.order_count}\n`;
  });

  return csvContent;
}

// Generate CSV content for inventory report
function generateInventoryReportCSV(data) {
  let csvContent = 'SKU,Product,Category,Quantity,Low Stock Threshold,Unit Cost,Value,Status\n';

  data.inventory.forEach(item => {
    let stockStatus = 'Normal';

    if (item.quantity <= 0) {
      stockStatus = 'Out of Stock';
    } else if (item.quantity <= item.low_stock_threshold) {
      stockStatus = 'Low Stock';
    }

    csvContent += `${item.sku},"${item.name.replace(/"/g, '""')}",${item.category_name || 'Uncategorized'},${item.quantity},${item.low_stock_threshold},${item.cost},${item.inventory_value},${stockStatus}\n`;
  });

  return csvContent;
}

// Generate CSV content for cashier report
function generateCashierReportCSV(data) {
  let csvContent = 'Cashier,Orders,Items Sold,Total Sales,Average Order Value,Cancelled Orders\n';

  data.cashiers.forEach(cashier => {
    const avgOrderValue = cashier.total_sales / (cashier.order_count || 1);

    csvContent += `${cashier.full_name} (${cashier.username}),${cashier.order_count},${cashier.items_sold},${cashier.total_sales},${avgOrderValue.toFixed(2)},${cashier.cancelled_orders}\n`;
  });

  return csvContent;
}

// Generate CSV content for tax report
function generateTaxReportCSV(data) {
  let csvContent = 'Period,Taxable Sales,Tax Collected,Total\n';

  data.periods.forEach(period => {
    csvContent += `${period.period},${period.taxable_sales},${period.tax_collected},${period.taxable_sales + period.tax_collected}\n`;
  });

  // Add totals row
  csvContent += `Total,${data.totals.taxable_sales},${data.totals.tax_collected},${data.totals.taxable_sales + data.totals.tax_collected}\n`;

  return csvContent;
}

// Format date for filename (YYYY-MM-DD)
function formatDateForFilename(date) {
  return date.toISOString().split('T')[0];
}