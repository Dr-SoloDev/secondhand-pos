document.addEventListener('DOMContentLoaded', function() {
  // Initialize POS
  initPOS();

  // Event listeners
  document.getElementById('productSearch').addEventListener('input', filterProducts);
  document.getElementById('scanBarcode').addEventListener('click', scanBarcode);
  document.getElementById('cancelOrder').addEventListener('click', cancelOrder);
  document.getElementById('checkoutBtn').addEventListener('click', showPaymentModal);
  document.getElementById('discountInput').addEventListener('input', updateOrderSummary);
  document.getElementById('amountPaid').addEventListener('input', calculateChange);
  document.getElementById('paymentMethod').addEventListener('change', toggleCashPaymentFields);
  document.getElementById('cancelPayment').addEventListener('click', hidePaymentModal);
  document.getElementById('completePayment').addEventListener('click', processPayment);
  document.getElementById('printReceiptBtn').addEventListener('click', printReceipt);
  document.getElementById('newOrderBtn').addEventListener('click', startNewOrder);

  // Close modals when clicking on X
  document.querySelectorAll('.close-modal').forEach(button => {
    button.addEventListener('click', function() {
      this.closest('.modal').classList.remove('show');
    });
  });
});

// Cart state
let cart = [];
let categories = [];
let products = [];
let customers = [];

// Initialize POS
async function initPOS() {
  try {
    // Fetch categories
    const categoryResponse = await apiRequest('inventory/categories');
    if (categoryResponse.status === 'success') {
      categories = categoryResponse.data;
      renderCategories();
    }

    // Fetch products
    const productResponse = await apiRequest('inventory/products');
    if (productResponse.status === 'success') {
      products = productResponse.data;
      renderProducts(products);
    }

    // Fetch customers
    const customerResponse = await apiRequest('customers');
    if (customerResponse.status === 'success') {
      customers = customerResponse.data;
      renderCustomers();
    }

    // Initialize empty cart
    updateOrderSummary();

  } catch (error) {
    console.error('Failed to initialize POS:', error);
    showNotification('Error initializing POS system', 'error');
  }
}

// Render categories filter
function renderCategories() {
  const container = document.querySelector('.category-filter');

  categories.forEach(category => {
    const button = document.createElement('button');
    button.className = 'btn btn-sm';
    button.textContent = category.name;
    button.dataset.category = category.id;
    button.addEventListener('click', function() {
      // Remove active class from all buttons
      document.querySelectorAll('.category-filter .btn').forEach(btn => {
        btn.classList.remove('active');
      });

      // Add active class to clicked button
      this.classList.add('active');

      // Filter products
      filterProductsByCategory(category.id);
    });

    container.appendChild(button);
  });
}

// Filter products by category
function filterProductsByCategory(categoryId) {
  if (categoryId === 'all') {
    renderProducts(products);
  } else {
    const filtered = products.filter(product => product.category_id == categoryId);
    renderProducts(filtered);
  }
}

// Render products grid
function renderProducts(productsToRender) {
  const grid = document.getElementById('productGrid');
  grid.innerHTML = '';

  if (productsToRender.length === 0) {
    grid.innerHTML = '<div class="no-products">ไม่พบสินค้า</div>';
    return;
  }

  productsToRender.forEach(product => {
    if (product.status !== 'active') return;

    // สร้าง DOM element แยกเพื่อประสิทธิภาพที่ดีขึ้น
    const item = document.createElement('div');
    item.className = 'product-item';

    // เพิ่ม data attributes สำหรับความสะดวกในการเข้าถึงข้อมูล
    item.dataset.id = product.id;
    item.dataset.sku = product.sku;
    item.dataset.name = product.name;
    item.dataset.price = product.price;

    // แสดงสถานะสินค้าหมดสต็อก
    let outOfStockClass = '';
    let stockLabel = '';
    if (product.quantity <= 0) {
      outOfStockClass = 'out-of-stock';
      stockLabel = '<span class="stock-label">หมด</span>';
    }

    item.innerHTML = `
      <div class="product-img ${outOfStockClass}">
        <i class="icon-product"></i>
        ${stockLabel}
      </div>
      <div class="product-details">
        <div class="product-name">${product.name}</div>
        <div class="product-price">${formatCurrency(product.price)}</div>
      </div>
    `;

    // ให้เพิ่มเฉพาะสินค้าที่มีสต็อก
    if (product.quantity > 0) {
      item.addEventListener('click', function() {
        addToCart(product);
      });
    } else {
      item.addEventListener('click', function() {
        showNotification('สินค้าหมด', 'warning');
      });
    }

    grid.appendChild(item);
  });
}

// Filter products by search term
function filterProducts() {
  const searchTerm = document.getElementById('productSearch').value.toLowerCase();

  if (searchTerm === '') {
    renderProducts(products);
    return;
  }

  const filtered = products.filter(product => {
    return product.name.toLowerCase().includes(searchTerm) ||
      product.sku.toLowerCase().includes(searchTerm) ||
      (product.barcode && product.barcode.includes(searchTerm));
  });

  renderProducts(filtered);
}

// Barcode scanner simulation
function scanBarcode() {
  const barcode = prompt('Enter barcode:');

  if (!barcode) return;

  const product = products.find(p => p.barcode === barcode);

  if (product) {
    addToCart(product);
  } else {
    showNotification('Product not found', 'error');
  }
}

// Add product to cart
function addToCart(product) {
  // Check if product is already in cart
  const existingItemIndex = cart.findIndex(item => item.id === product.id);

  if (existingItemIndex !== -1) {
    // Update quantity if already in cart
    cart[existingItemIndex].quantity += 1;
  } else {
    // Add new item to cart
    cart.push({
      id: product.id,
      sku: product.sku,
      name: product.name,
      price: product.price,
      quantity: 1
    });
  }

  // Update UI
  renderCart();
  updateOrderSummary();
  showNotification(`${product.name} เพิ่มในตะกร้าแล้ว`, 'success');
}

// Render cart items
function renderCart() {
  const container = document.getElementById('orderItems');
  container.innerHTML = '';

  if (cart.length === 0) {
    container.innerHTML = `
          <div class="empty-order">
              <i class="icon-cart"></i>
              <p>No items in cart</p>
          </div>
      `;
    return;
  }

  cart.forEach((item, index) => {
    const itemElement = document.createElement('div');
    itemElement.className = 'order-item';

    itemElement.innerHTML = `
          <div class="item-name">${item.name}</div>
          <div class="item-quantity">
              <button class="qty-btn decrease" data-index="${index}">-</button>
              <span>${item.quantity}</span>
              <button class="qty-btn increase" data-index="${index}">+</button>
          </div>
          <div class="item-price">${formatCurrency(item.price * item.quantity)}</div>
          <div class="item-actions">
              <button class="btn-icon remove-item" data-index="${index}">
                  <i class="icon-delete"></i>
              </button>
          </div>
      `;

    container.appendChild(itemElement);
  });

  // Add event listeners to quantity buttons
  container.querySelectorAll('.qty-btn.decrease').forEach(button => {
    button.addEventListener('click', function() {
      const index = parseInt(this.dataset.index);
      decreaseQuantity(index);
    });
  });

  container.querySelectorAll('.qty-btn.increase').forEach(button => {
    button.addEventListener('click', function() {
      const index = parseInt(this.dataset.index);
      increaseQuantity(index);
    });
  });

  container.querySelectorAll('.remove-item').forEach(button => {
    button.addEventListener('click', function() {
      const index = parseInt(this.dataset.index);
      removeCartItem(index);
    });
  });
}

// Increase item quantity
function increaseQuantity(index) {
  cart[index].quantity += 1;
  renderCart();
  updateOrderSummary();
}

// Decrease item quantity
function decreaseQuantity(index) {
  if (cart[index].quantity > 1) {
    cart[index].quantity -= 1;
  } else {
    removeCartItem(index);
  }

  renderCart();
  updateOrderSummary();
}

// Remove item from cart
function removeCartItem(index) {
  cart.splice(index, 1);
  renderCart();
  updateOrderSummary();
}

// Update order summary calculations
function updateOrderSummary() {
  const subtotal = cart.reduce((sum, item) => sum + (item.price * item.quantity), 0);
  const discount = parseFloat(document.getElementById('discountInput').value) || 0;
  const taxRate = 0.07; // 7% tax
  const tax = (subtotal - discount) * taxRate;
  const total = subtotal - discount + tax;

  document.getElementById('subtotal').textContent = formatCurrency(subtotal);
  document.getElementById('tax').textContent = formatCurrency(tax);
  document.getElementById('total').textContent = formatCurrency(total);

  // Update payment modal total too if it's open
  const paymentTotalElement = document.getElementById('paymentTotal');
  if (paymentTotalElement) {
    paymentTotalElement.textContent = formatCurrency(total);
  }

  // Enable/disable checkout button
  const checkoutBtn = document.getElementById('checkoutBtn');
  checkoutBtn.disabled = cart.length === 0;
}

// Cancel current order
function cancelOrder() {
  if (cart.length === 0) return;

  if (confirm('Are you sure you want to cancel this order?')) {
    cart = [];
    renderCart();
    updateOrderSummary();
    document.getElementById('discountInput').value = 0;
    showNotification('Order cancelled', 'info');
  }
}

// Render customer dropdown
function renderCustomers() {
  const select = document.getElementById('customerSelect');

  customers.forEach(customer => {
    const option = document.createElement('option');
    option.value = customer.id;
    option.textContent = customer.name;
    select.appendChild(option);
  });
}

// Show payment modal
function showPaymentModal() {
  if (cart.length === 0) {
    showNotification('กรุณาเพิ่มสินค้าก่อนชำระเงิน', 'error');
    return;
  }

  const subtotal = cart.reduce((sum, item) => sum + (item.price * item.quantity), 0);
  const discount = parseFloat(document.getElementById('discountInput').value) || 0;
  const taxRate = 0.07; // 7% tax
  const tax = (subtotal - discount) * taxRate;
  const total = subtotal - discount + tax;

  document.getElementById('paymentTotal').textContent = formatCurrency(total);
  document.getElementById('amountPaid').value = total.toFixed(2);
  document.getElementById('changeAmount').textContent = formatCurrency(0);
  document.getElementById('paymentNotes').value = '';

  document.getElementById('paymentModal').classList.add('show');
}

// Hide payment modal
function hidePaymentModal() {
  document.getElementById('paymentModal').classList.remove('show');
}

// Calculate change
function calculateChange() {
  const total = cart.reduce((sum, item) => sum + (item.price * item.quantity), 0);
  const discount = parseFloat(document.getElementById('discountInput').value) || 0;
  const taxRate = 0.07;
  const tax = (total - discount) * taxRate;
  const finalTotal = total - discount + tax;

  const amountPaid = parseFloat(document.getElementById('amountPaid').value) || 0;
  const change = amountPaid - finalTotal;

  document.getElementById('changeAmount').textContent = formatCurrency(Math.max(0, change));

  // Enable/disable complete payment button
  const completePaymentBtn = document.getElementById('completePayment');
  completePaymentBtn.disabled = amountPaid < finalTotal;
}

// Toggle cash payment fields based on payment method
function toggleCashPaymentFields() {
  const paymentMethod = document.getElementById('paymentMethod').value;
  const cashGroup = document.getElementById('cashPaymentGroup');

  if (paymentMethod === 'cash') {
    cashGroup.style.display = 'block';
    calculateChange();
  } else {
    cashGroup.style.display = 'none';
    document.getElementById('completePayment').disabled = false;
  }
}

// Process payment
async function processPayment() {
  try {
    const subtotal = cart.reduce((sum, item) => sum + (item.price * item.quantity), 0);
    const discount = parseFloat(document.getElementById('discountInput').value) || 0;
    const taxRate = 0.07;
    const tax = (subtotal - discount) * taxRate;
    const total = subtotal - discount + tax;

    const paymentMethod = document.getElementById('paymentMethod').value;
    const customerId = document.getElementById('customerSelect').value;
    const notes = document.getElementById('paymentNotes').value;

    // Prepare sale data
    const saleData = {
      customer_id: customerId,
      items: cart.map(item => ({
        product_id: item.id,
        quantity: item.quantity,
        unit_price: item.price
      })),
      discount_amount: discount,
      tax_amount: tax,
      payment_method: paymentMethod,
      notes: notes
    };

    // Submit to API
    const response = await apiRequest('sales/create', 'POST', saleData);

    if (response.status === 'success') {
      hidePaymentModal();
      showReceipt(response.data);
      showNotification('รายการขายสำเร็จ', 'success');
    } else {
      showNotification(response.message || 'Payment processing failed', 'error');
    }

  } catch (error) {
    console.error('Payment processing error:', error);
    showNotification('An error occurred during payment processing', 'error');
  }
}

// Show receipt
function showReceipt(saleData) {
  const receiptContainer = document.getElementById('receipt');
  const date = new Date(saleData.created_at).toLocaleDateString();
  const time = new Date(saleData.created_at).toLocaleTimeString();

  let itemsHtml = '';
  saleData.items.forEach(item => {
    itemsHtml += `
          <tr>
              <td>${item.product_name}</td>
              <td>${item.quantity}</td>
              <td>${formatCurrency(item.unit_price)}</td>
              <td>${formatCurrency(item.total)}</td>
          </tr>
      `;
  });

  receiptContainer.innerHTML = `
      <div class="receipt-header">
          <h3>${saleData.store_name || 'My POS Store'}</h3>
          <p>${saleData.store_address || ''}</p>
          <p>Tel: ${saleData.store_phone || ''}</p>
      </div>

      <div class="receipt-info">
          <p><strong>Receipt #:</strong> ${saleData.reference_no}</p>
          <p><strong>Date:</strong> ${date} ${time}</p>
          <p><strong>Cashier:</strong> ${saleData.cashier_name}</p>
          <p><strong>Customer:</strong> ${saleData.customer_name}</p>
      </div>

      <table class="receipt-items">
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
              <div class="summary-label">Subtotal</div>
              <div class="summary-value">${formatCurrency(saleData.total_amount)}</div>
          </div>
          <div class="summary-row">
              <div class="summary-label">Discount</div>
              <div class="summary-value">${formatCurrency(saleData.discount_amount)}</div>
          </div>
          <div class="summary-row">
              <div class="summary-label">Tax</div>
              <div class="summary-value">${formatCurrency(saleData.tax_amount)}</div>
          </div>
          <div class="summary-row total-row">
              <div class="summary-label">Total</div>
              <div class="summary-value">${formatCurrency(saleData.grand_total)}</div>
          </div>
          <div class="summary-row">
              <div class="summary-label">Payment Method</div>
              <div class="summary-value">${saleData.payment_method.toUpperCase()}</div>
          </div>
      </div>

      <div class="receipt-footer">
          <p>${saleData.receipt_footer || 'Thank you for shopping with us!'}</p>
      </div>
  `;

  document.getElementById('receiptModal').classList.add('show');
}

// Print receipt
function printReceipt() {
  const receiptContent = document.getElementById('receipt').innerHTML;
  const printWindow = window.open('', '_blank');

  printWindow.document.write(`
      <!DOCTYPE html>
      <html>
      <head>
          <title>ใบเสร็จ</title>
          <style>
              body { font-family: 'Courier New', monospace; font-size: 12px; }
              .receipt-header { text-align: center; margin-bottom: 20px; }
              .receipt-header h3 { margin: 0; font-size: 16px; }
              .receipt-info { margin-bottom: 20px; }
              .receipt-items { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
              .receipt-items th, .receipt-items td { text-align: left; padding: 5px; }
              .receipt-items th { border-bottom: 1px solid #000; }
              .summary-row { display: flex; justify-content: space-between; margin-bottom: 5px; }
              .total-row { font-weight: 400; border-top: 1px solid #000; padding-top: 5px; }
              .receipt-footer { text-align: center; margin-top: 20px; border-top: 1px dashed #000; padding-top: 10px; }
          </style>
      </head>
      <body>
          ${receiptContent}
      </body>
      </html>
  `);

  printWindow.document.close();
  printWindow.focus();
  printWindow.print();
  printWindow.close();
}

// Start new order after payment
function startNewOrder() {
  cart = [];
  renderCart();
  updateOrderSummary();
  document.getElementById('discountInput').value = 0;
  document.getElementById('receiptModal').classList.remove('show');
  document.getElementById('customerSelect').value = 1; // Reset to default customer
}

// Show notification
function showNotification(message, type = 'info') {
  // You can implement a notification system here
  // For simplicity, we'll use alert for now
  alert(message);
}