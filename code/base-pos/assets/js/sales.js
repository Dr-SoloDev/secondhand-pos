document.addEventListener('DOMContentLoaded', function() {
  // Initialize sales management
  initSales();

  // Set default dates (current month)
  setDefaultDates();

  // Event listeners
  document.getElementById('saleSearch').addEventListener('input', function() {
    currentPage = 1;
    loadSales();
  });
  document.getElementById('filterSalesBtn').addEventListener('click', function() {
    currentPage = 1;
    loadSales();
  });
  document.getElementById('exportSalesBtn').addEventListener('click', exportSales);
  document.getElementById('printReceiptBtn').addEventListener('click', printReceipt);
  document.getElementById('voidSaleBtn').addEventListener('click', showVoidSaleModal);
  document.getElementById('cancelVoidBtn').addEventListener('click', hideVoidSaleModal);
  document.getElementById('confirmVoidBtn').addEventListener('click', voidSale);

  // Close modals when clicking on X
  document.querySelectorAll('.close-modal').forEach(button => {
    button.addEventListener('click', function() {
      this.closest('.modal').classList.remove('show');
    });
  });
});

// Global variables
let currentPage = 1;
let totalPages = 1;
let itemsPerPage = 20;
let currentSaleId = null;

// Initialize sales management
function initSales() {
  loadSales();
}

// Set default dates (current month)
function setDefaultDates() {
  const today = new Date();
  const firstDay = new Date(today.getFullYear(), today.getMonth(), 1);

  const todayStr = formatDateForInput(today);
  const firstDayStr = formatDateForInput(firstDay);

  document.getElementById('dateFrom').value = firstDayStr;
  document.getElementById('dateTo').value = todayStr;
}

// Format date for input fields (YYYY-MM-DD)
function formatDateForInput(date) {
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, '0');
  const day = String(date.getDate()).padStart(2, '0');
  return `${year}-${month}-${day}`;
}

// Load sales with filters and pagination
async function loadSales() {
  try {
    const dateFrom = document.getElementById('dateFrom').value;
    const dateTo = document.getElementById('dateTo').value;
    const paymentStatus = document.getElementById('paymentStatusFilter').value;
    const paymentMethod = document.getElementById('paymentMethodFilter').value;
    const searchTerm = document.getElementById('saleSearch').value;

    const params = new URLSearchParams({
      page: currentPage,
      limit: itemsPerPage
    });

    if (dateFrom) params.append('date_from', dateFrom);
    if (dateTo) params.append('date_to', dateTo);
    if (paymentStatus) params.append('payment_status', paymentStatus);
    if (paymentMethod) params.append('payment_method', paymentMethod);
    if (searchTerm) params.append('search', searchTerm);

    const response = await apiRequest(`sales/list?${params.toString()}`);

    if (response.status === 'success') {
      renderSalesTable(response.data.sales);

      // Update pagination
      totalPages = response.data.pagination.pages;
      currentPage = response.data.pagination.page;
      renderPagination(response.data.pagination);
    } else {
      showNotification(response.message || 'Failed to load sales', 'error');
    }
  } catch (error) {
    console.error('Error loading sales:', error);
    showNotification('Error loading sales', 'error');
  }
}

// Render sales table
function renderSalesTable(sales) {
  const tableBody = document.querySelector('#salesTable tbody');
  tableBody.innerHTML = '';

  if (sales.length === 0) {
    const row = document.createElement('tr');
    row.innerHTML = '<td colspan="8" class="text-center">No sales found</td>';
    tableBody.appendChild(row);
    return;
  }

  sales.forEach(sale => {
    const row = document.createElement('tr');

    // Format date and time
    const dateTime = new Date(sale.created_at).toLocaleString();

    // Determine status badge class
    let statusBadgeClass = '';
    switch (sale.payment_status) {
      case 'paid':
        statusBadgeClass = 'badge-success';
        break;
      case 'partial':
        statusBadgeClass = 'badge-warning';
        break;
      case 'pending':
        statusBadgeClass = 'badge-info';
        break;
      case 'voided':
        statusBadgeClass = 'badge-danger';
        break;
    }

    row.innerHTML = `
      <td>${sale.reference_no}</td>
      <td>${dateTime}</td>
      <td>${sale.customer_name || 'Walk-in Customer'}</td>
      <td>${sale.item_count}</td>
      <td>${formatCurrency(sale.grand_total)}</td>
      <td>${capitalizeFirstLetter(sale.payment_method.replace('_', ' '))}</td>
      <td><span class="badge ${statusBadgeClass}">${sale.payment_status}</span></td>
      <td class="actions">
        <button class="btn btn-sm btn-info view-sale" data-id="${sale.id}">
          <i class="icon-preview"></i>
        </button>
        <button class="btn btn-sm btn-secondary print-receipt" data-id="${sale.id}">
          <i class="icon-print"></i>
        </button>
        ${sale.payment_status !== 'voided' ?
        `<button class="btn btn-sm btn-danger void-sale" data-id="${sale.id}">
            <i class="icon-ban"></i>
          </button>` : ''}
      </td>
    `;

    tableBody.appendChild(row);
  });

  // Add event listeners for action buttons
  document.querySelectorAll('.view-sale').forEach(button => {
    button.addEventListener('click', function() {
      const saleId = this.dataset.id;
      viewSaleDetails(saleId);
    });
  });

  document.querySelectorAll('.print-receipt').forEach(button => {
    button.addEventListener('click', function() {
      const saleId = this.dataset.id;
      getSaleDetails(saleId, true);
    });
  });

  document.querySelectorAll('.void-sale').forEach(button => {
    button.addEventListener('click', function() {
      const saleId = this.dataset.id;
      currentSaleId = saleId;
      document.getElementById('voidSaleId').value = saleId;
      document.getElementById('voidSaleModal').classList.add('show');
    });
  });
}

// Render pagination
function renderPagination(pagination) {
  const paginationContainer = document.getElementById('salesPagination');
  paginationContainer.innerHTML = '';

  if (pagination.pages <= 1) {
    return;
  }

  // Create pagination elements
  const prevBtn = document.createElement('button');
  prevBtn.classList.add('pagination-btn');
  prevBtn.disabled = pagination.page === 1;
  prevBtn.innerHTML = '<i class="icon-move_left"></i>';
  prevBtn.addEventListener('click', () => changePage(pagination.page - 1));

  const nextBtn = document.createElement('button');
  nextBtn.classList.add('pagination-btn');
  nextBtn.disabled = pagination.page === pagination.pages;
  nextBtn.innerHTML = '<i class="icon-move_right"></i>';
  nextBtn.addEventListener('click', () => changePage(pagination.page + 1));

  const pageInfo = document.createElement('span');
  pageInfo.classList.add('pagination-info');
  pageInfo.textContent = `Page ${pagination.page} of ${pagination.pages}`;

  paginationContainer.appendChild(prevBtn);
  paginationContainer.appendChild(pageInfo);
  paginationContainer.appendChild(nextBtn);
}

// Change page
function changePage(page) {
  currentPage = page;
  loadSales();
}

// View sale details
async function viewSaleDetails(saleId) {
  try {
    currentSaleId = saleId;
    const sale = await getSaleDetails(saleId);

    if (sale) {
      renderSaleDetails(sale);
      document.getElementById('saleDetailModal').classList.add('show');
    }
  } catch (error) {
    console.error('Error viewing sale details:', error);
    showNotification('Error loading sale details', 'error');
  }
}

// Get sale details
async function getSaleDetails(saleId, printReceipt = false) {
  try {
    const response = await apiRequest(`sales/details?id=${saleId}`);

    if (response.status === 'success') {
      if (printReceipt) {
        printSaleReceipt(response.data);
      }
      return response.data;
    } else {
      showNotification(response.message || 'Failed to load sale details', 'error');
      return null;
    }
  } catch (error) {
    console.error('Error getting sale details:', error);
    showNotification('Error loading sale details', 'error');
    return null;
  }
}

// Render sale details in modal
function renderSaleDetails(sale) {
  // Sale header information
  document.getElementById('saleReference').textContent = sale.reference_no;
  document.getElementById('saleDate').textContent = new Date(sale.created_at).toLocaleString();
  document.getElementById('saleCustomer').textContent = sale.customer_name || 'Walk-in Customer';
  document.getElementById('saleCashier').textContent = sale.user_full_name || sale.user_name;

  // Sale status
  const statusElem = document.getElementById('saleStatus');
  statusElem.textContent = sale.payment_status;
  statusElem.className = '';

  // Add status color
  switch (sale.payment_status) {
    case 'paid':
      statusElem.classList.add('text-success');
      break;
    case 'partial':
      statusElem.classList.add('text-warning');
      break;
    case 'pending':
      statusElem.classList.add('text-info');
      break;
    case 'voided':
      statusElem.classList.add('text-danger');
      break;
  }

  document.getElementById('salePaymentMethod').textContent = capitalizeFirstLetter(sale.payment_method.replace('_', ' '));

  // Sale items
  const tableBody = document.querySelector('#saleItemsTable tbody');
  tableBody.innerHTML = '';

  sale.items.forEach(item => {
    const row = document.createElement('tr');
    row.innerHTML = `
      <td>${item.product_name}</td>
      <td>${item.sku}</td>
      <td>${item.quantity}</td>
      <td>${formatCurrency(item.unit_price)}</td>
      <td>${formatCurrency(item.discount)}</td>
      <td>${formatCurrency(item.total)}</td>
    `;
    tableBody.appendChild(row);
  });

  // Sale summary
  document.getElementById('saleSubtotal').textContent = formatCurrency(sale.total_amount);
  document.getElementById('saleDiscount').textContent = formatCurrency(sale.discount_amount);
  document.getElementById('saleTax').textContent = formatCurrency(sale.tax_amount);
  document.getElementById('saleTotal').textContent = formatCurrency(sale.grand_total);

  // Sale notes
  document.getElementById('saleNotes').textContent = sale.notes || '-';

  // Toggle void button visibility based on status
  const voidBtn = document.getElementById('voidSaleBtn');
  voidBtn.style.display = sale.payment_status === 'voided' ? 'none' : 'inline-block';
}

// Show void sale modal
function showVoidSaleModal() {
  document.getElementById('voidReason').value = '';
  document.getElementById('voidSaleModal').classList.add('show');
}

// Hide void sale modal
function hideVoidSaleModal() {
  document.getElementById('voidSaleModal').classList.remove('show');
}

// Void sale
async function voidSale() {
  try {
    const saleId = document.getElementById('voidSaleId').value;
    const reason = document.getElementById('voidReason').value.trim();

    if (!reason) {
      showNotification('Please provide a reason for voiding this sale', 'error');
      return;
    }

    const response = await apiRequest(`sales/void?id=${saleId}`, 'POST', {reason});

    if (response.status === 'success') {
      showNotification('Sale voided successfully', 'success');
      hideVoidSaleModal();
      document.getElementById('saleDetailModal').classList.remove('show');
      loadSales(); // Reload sales table
    } else {
      showNotification(response.message || 'Failed to void sale', 'error');
    }
  } catch (error) {
    console.error('Error voiding sale:', error);
    showNotification('Error voiding sale', 'error');
  }
}

// Export sales to CSV
// Export sales to CSV
function exportSales() {
  try {
    // แสดง notification กำลังทำงาน
    showNotification('Creating sales export...', 'info');

    // รับค่าจากฟิลเตอร์ต่างๆ
    const dateFrom = document.getElementById('dateFrom').value;
    const dateTo = document.getElementById('dateTo').value;
    const paymentStatus = document.getElementById('paymentStatusFilter').value;
    const paymentMethod = document.getElementById('paymentMethodFilter').value;
    const searchTerm = document.getElementById('saleSearch').value;

    // สร้างพารามิเตอร์สำหรับ API request
    const params = new URLSearchParams();
    if (dateFrom) params.append('date_from', dateFrom);
    if (dateTo) params.append('date_to', dateTo);
    if (paymentStatus) params.append('payment_status', paymentStatus);
    if (paymentMethod) params.append('payment_method', paymentMethod);
    if (searchTerm) params.append('search', searchTerm);

    // ดึงข้อมูลจาก API โดยไม่มีการจำกัดจำนวนรายการ
    params.append('limit', 1000); // ตั้งค่าให้สูงเพื่อให้ได้ข้อมูลมากที่สุด

    // เรียกใช้ API เพื่อดึงข้อมูล
    apiRequest(`sales/list?${params.toString()}`)
      .then(response => {
        if (response.status === 'success') {
          // สร้างเนื้อหา CSV
          let csvContent = 'Reference #,Date,Customer,Items,Subtotal,Discount,Tax,Total,Payment Method,Status,Cashier,Notes\n';

          // เพิ่มข้อมูลแต่ละแถว
          response.data.sales.forEach(sale => {
            // แก้ไขค่าที่อาจมีเครื่องหมาย comma เพื่อป้องกันปัญหากับ CSV
            const escapeCSV = (text) => {
              // ถ้าเป็น string และมีเครื่องหมาย " หรือ , ให้ครอบด้วย " และแทนที่ " เป็น ""
              if (typeof text === 'string') {
                if (text.includes('"') || text.includes(',')) {
                  return `"${text.replace(/"/g, '""')}"`;
                }
              }
              return text;
            };

            csvContent += [
              escapeCSV(sale.reference_no),
              escapeCSV(sale.created_at),
              escapeCSV(sale.customer_name || 'Walk-in Customer'),
              sale.item_count,
              sale.total_amount,
              sale.discount_amount,
              sale.tax_amount,
              sale.grand_total,
              escapeCSV(sale.payment_method),
              escapeCSV(sale.payment_status),
              escapeCSV(sale.user_name),
              escapeCSV(sale.notes || '')
            ].join(',') + '\n';
          });

          // สร้างชื่อไฟล์
          const filename = `sales_export_${formatDateForFilename(new Date())}.csv`;

          // สร้าง Blob และ download link
          const blob = new Blob([csvContent], {type: 'text/csv;charset=utf-8;'});
          const url = URL.createObjectURL(blob);
          const link = document.createElement('a');
          link.setAttribute('href', url);
          link.setAttribute('download', filename);
          link.style.visibility = 'hidden';

          // เพิ่ม link ลงใน document และคลิกเพื่อดาวน์โหลด
          document.body.appendChild(link);
          link.click();
          document.body.removeChild(link);

          // แสดง notification เมื่อสำเร็จ
          showNotification('Sales data exported successfully', 'success');
        } else {
          showNotification(response.message || 'Failed to retrieve sales data', 'error');
        }
      })
      .catch(error => {
        console.error('Error exporting sales:', error);
        showNotification('Error exporting sales data', 'error');
      });

  } catch (error) {
    console.error('Error in export process:', error);
    showNotification('Error processing export', 'error');
  }
}

// Helper function to format date for filename (YYYY-MM-DD)
function formatDateForFilename(date) {
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, '0');
  const day = String(date.getDate()).padStart(2, '0');
  return `${year}-${month}-${day}`;
}

// Print receipt
async function printReceipt() {
  if (!currentSaleId) {
    showNotification('No sale selected', 'error');
    return;
  }

  try {
    const sale = await getSaleDetails(currentSaleId);
    if (sale) {
      printSaleReceipt(sale);
    }
  } catch (error) {
    console.error('Error printing receipt:', error);
    showNotification('Error printing receipt', 'error');
  }
}

// Print sale receipt
function printSaleReceipt(sale) {
  // Generate items HTML
  let itemsHtml = '';
  sale.items.forEach(item => {
    itemsHtml += `
      <tr>
        <td>${item.product_name}</td>
        <td>${item.quantity}</td>
        <td>${formatCurrency(item.unit_price)}</td>
        <td>${formatCurrency(item.total)}</td>
      </tr>
    `;
  });

  // Create print window
  const printWindow = window.open('', '_blank');

  // Generate receipt HTML
  printWindow.document.write(`
    <!DOCTYPE html>
    <html>
    <head>
      <title>Receipt #${sale.reference_no}</title>
      <style>
        body {
          font-family: 'Courier New', monospace;
          font-size: 12px;
          margin: 0;
          padding: 20px;
          width: 80mm;
          margin: 0 auto;
        }
        .receipt-header {
          text-align: center;
          margin-bottom: 20px;
        }
        .receipt-header h2 {
          margin: 0;
          font-size: 16px;
        }
        .receipt-header p {
          margin: 5px 0;
        }
        .receipt-info {
          margin-bottom: 15px;
        }
        .receipt-info p {
          margin: 3px 0;
        }
        .receipt-table {
          width: 100%;
          border-collapse: collapse;
          margin-bottom: 15px;
        }
        .receipt-table th, .receipt-table td {
          text-align: left;
          padding: 3px 0;
        }
        .receipt-table th {
          border-bottom: 1px dashed #000;
        }
        .receipt-table td:last-child, .receipt-table th:last-child {
          text-align: right;
        }
        .receipt-summary {
          margin-top: 10px;
        }
        .summary-row {
          display: flex;
          justify-content: space-between;
          margin-bottom: 5px;
        }
        .total-row {
          font-weight: 400;
          border-top: 1px dashed #000;
          padding-top: 5px;
          margin-top: 5px;
        }
        .receipt-footer {
          text-align: center;
          margin-top: 20px;
          padding-top: 10px;
          border-top: 1px dashed #000;
        }
        .receipt-footer p {
          margin: 5px 0;
        }
        .voided-stamp {
          position: absolute;
          top: 50%;
          left: 50%;
          transform: translate(-50%, -50%) rotate(-30deg);
          font-size: 48px;
          opacity: 0.5;
          color: #dc3545;
          border: 5px solid #dc3545;
          padding: 10px;
          border-radius: 10px;
          font-weight: 400;
          pointer-events: none;
        }
      </style>
    </head>
    <body>
      <div class="receipt-header">
        <h2>My POS Store</h2>
        <p>123 Main Street, Anytown</p>
        <p>Tel: 123-456-7890</p>
      </div>

      <div class="receipt-info">
        <p><strong>Receipt #:</strong> ${sale.reference_no}</p>
        <p><strong>Date:</strong> ${new Date(sale.created_at).toLocaleString()}</p>
        <p><strong>Cashier:</strong> ${sale.user_full_name || sale.user_name}</p>
        <p><strong>Customer:</strong> ${sale.customer_name || 'Walk-in Customer'}</p>
      </div>

      <table class="receipt-table">
        <thead>
          <tr>
            <th>Item</th>
            <th>Qty</th>
            <th>Price</th>
            <th>Total</th>
          </tr>
        </thead>
        <tbody>
          ${itemsHtml}
        </tbody>
      </table>

      <div class="receipt-summary">
        <div class="summary-row">
          <div>Subtotal:</div>
          <div>${formatCurrency(sale.total_amount)}</div>
        </div>
        <div class="summary-row">
          <div>Discount:</div>
          <div>${formatCurrency(sale.discount_amount)}</div>
        </div>
        <div class="summary-row">
          <div>Tax:</div>
          <div>${formatCurrency(sale.tax_amount)}</div>
        </div>
        <div class="summary-row total-row">
          <div>Total:</div>
          <div>${formatCurrency(sale.grand_total)}</div>
        </div>
        <div class="summary-row">
          <div>Payment Method:</div>
          <div>${capitalizeFirstLetter(sale.payment_method.replace('_', ' '))}</div>
        </div>
      </div>

      <div class="receipt-footer">
        <p>Thank you for your purchase!</p>
        <p>${new Date().getFullYear()} My POS Store</p>
      </div>

      ${sale.payment_status === 'voided' ? '<div class="voided-stamp">VOIDED</div>' : ''}
    </body>
    </html>
  `);

  printWindow.document.close();

  // Print after a small delay to ensure content is loaded
  setTimeout(() => {
    printWindow.print();
  }, 500);
}

// Capitalize first letter of each word
function capitalizeFirstLetter(string) {
  return string.split(' ')
    .map(word => word.charAt(0).toUpperCase() + word.slice(1))
    .join(' ');
}