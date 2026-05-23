document.addEventListener('DOMContentLoaded', function() {
  // Initialize inventory management
  initInventory();

  // Event listeners
  document.getElementById('productSearch').addEventListener('input', filterProducts);
  document.getElementById('categoryFilter').addEventListener('change', filterProducts);
  document.getElementById('stockFilter').addEventListener('change', filterProducts);
  document.getElementById('addProductBtn').addEventListener('click', showAddProductModal);
  document.getElementById('manageCategories').addEventListener('click', showCategoryModal);
  document.getElementById('saveProduct').addEventListener('click', saveProduct);
  document.getElementById('cancelProduct').addEventListener('click', hideProductModal);
  document.getElementById('saveCategory').addEventListener('click', saveCategory);
  document.getElementById('cancelCategory').addEventListener('click', resetCategoryForm);
  document.getElementById('saveAdjustment').addEventListener('click', saveStockAdjustment);
  document.getElementById('cancelAdjustment').addEventListener('click', hideStockModal);
  document.getElementById('exportInventory').addEventListener('click', exportInventory);

  // Close modals when clicking on X
  document.querySelectorAll('.close-modal').forEach(button => {
    button.addEventListener('click', function() {
      this.closest('.modal').classList.remove('show');
    });
  });
});

// Global variables
let products = [];
let categories = [];

// Initialize inventory management
async function initInventory() {
  try {
    // Fetch categories
    const categoryResponse = await apiRequest('inventory/categories');
    if (categoryResponse.status === 'success') {
      categories = categoryResponse.data;
      renderCategoryDropdowns();
    }

    // Fetch products
    const productResponse = await apiRequest('inventory/products');
    if (productResponse.status === 'success') {
      products = productResponse.data;
      renderProducts(products);
    }

  } catch (error) {
    console.error('Failed to initialize inventory:', error);
    showNotification('Error loading inventory data', 'error');
  }
}

// Render category dropdowns
function renderCategoryDropdowns() {
  const categoryFilter = document.getElementById('categoryFilter');
  const categorySelect = document.getElementById('categoryId');

  // Clear existing options (except the first one)
  while (categoryFilter.options.length > 1) {
    categoryFilter.remove(1);
  }

  while (categorySelect.options.length > 1) {
    categorySelect.remove(1);
  }

  // Add categories to dropdowns
  categories.forEach(category => {
    if (category.status === 'active') {
      const filterOption = document.createElement('option');
      filterOption.value = category.id;
      filterOption.textContent = category.name;
      categoryFilter.appendChild(filterOption);

      const selectOption = document.createElement('option');
      selectOption.value = category.id;
      selectOption.textContent = category.name;
      categorySelect.appendChild(selectOption);
    }
  });
}

// Render products table
function renderProducts(productsToRender) {
  const tableBody = document.querySelector('#inventoryTable tbody');
  tableBody.innerHTML = '';

  if (productsToRender.length === 0) {
    const row = document.createElement('tr');
    row.innerHTML = '<td colspan="8" class="text-center">No products found</td>';
    tableBody.appendChild(row);
    return;
  }

  productsToRender.forEach(product => {
    const row = document.createElement('tr');

    // Determine stock status
    let stockClass = '';
    if (product.quantity <= 0) {
      stockClass = 'text-danger';
    } else if (product.quantity <= product.low_stock_threshold) {
      stockClass = 'text-warning';
    }

    row.innerHTML = `
      <td>${product.sku}</td>
      <td>${product.name}</td>
      <td>${product.category_name || 'Uncategorized'}</td>
      <td>${formatCurrency(product.price)}</td>
      <td>${formatCurrency(product.cost)}</td>
      <td class="${stockClass}">${product.quantity}</td>
      <td>
        <span class="badge ${product.status === 'active' ? 'badge-success' : 'badge-danger'}">
            ${product.status}
        </span>
      </td>
      <td class="actions">
        <button class="btn btn-sm btn-info edit-product" data-id="${product.id}">
            <i class="icon-edit"></i>
        </button>
        <button class="btn btn-sm btn-success adjust-stock" data-id="${product.id}" data-name="${product.name}" data-stock="${product.quantity}">
            <i class="icon-product"></i>
        </button>
        <button class="btn btn-sm btn-danger delete-product" data-id="${product.id}">
            <i class="icon-delete"></i>
        </button>
      </td>
    `;

    tableBody.appendChild(row);
  });

  // Add event listeners for action buttons
  document.querySelectorAll('.edit-product').forEach(button => {
    button.addEventListener('click', function() {
      const productId = this.getAttribute('data-id');
      if (productId) {
        editProduct(productId);
      } else {
        showNotification('Product ID is missing', 'error');
      }
    });
  });

  document.querySelectorAll('.adjust-stock').forEach(button => {
    button.addEventListener('click', function() {
      const productId = this.getAttribute('data-id');
      const productName = this.getAttribute('data-name');
      const currentStock = this.getAttribute('data-stock');
      showStockModal(productId, productName, currentStock);
    });
  });

  document.querySelectorAll('.delete-product').forEach(button => {
    button.addEventListener('click', function() {
      const productId = this.getAttribute('data-id');
      deleteProduct(productId);
    });
  });
}

// Filter products
function filterProducts() {
  const searchTerm = document.getElementById('productSearch').value.toLowerCase();
  const categoryId = document.getElementById('categoryFilter').value;
  const stockFilter = document.getElementById('stockFilter').value;

  let filtered = [...products];

  // Apply search filter
  if (searchTerm) {
    filtered = filtered.filter(product => {
      return product.name.toLowerCase().includes(searchTerm) ||
        product.sku.toLowerCase().includes(searchTerm) ||
        (product.barcode && product.barcode.toLowerCase().includes(searchTerm));
    });
  }

  // Apply category filter
  if (categoryId) {
    filtered = filtered.filter(product => product.category_id === categoryId);
  }

  // Apply stock filter
  if (stockFilter !== 'all') {
    if (stockFilter === 'low') {
      filtered = filtered.filter(product =>
        product.quantity <= product.low_stock_threshold && product.quantity > 0
      );
    } else if (stockFilter === 'out') {
      filtered = filtered.filter(product => product.quantity <= 0);
    }
  }

  renderProducts(filtered);
}

// Show add product modal
function showAddProductModal() {
  // Reset form
  document.getElementById('productForm').reset();
  document.getElementById('productId').value = '';
  document.getElementById('productModalTitle').textContent = 'Add Product';
  document.getElementById('status').value = 'active';

  // Show modal
  document.getElementById('productModal').classList.add('show');
}

// Show edit product modal
async function editProduct(productId) {
  try {
    if (!productId) {
      showNotification('Product ID is required', 'error');
      return;
    }

    console.log("Editing product with ID:", productId); // Debug line

    const response = await apiRequest(`inventory/product?id=${productId}`);

    if (response.status === 'success') {
      const product = response.data;

      console.log("Product data received:", product); // Debug line

      // Fill form fields
      document.getElementById('productId').value = product.id;
      document.getElementById('sku').value = product.sku;
      document.getElementById('barcode').value = product.barcode || '';
      document.getElementById('name').value = product.name;
      document.getElementById('description').value = product.description || '';
      document.getElementById('categoryId').value = product.category_id || '';
      document.getElementById('price').value = product.price;
      document.getElementById('cost').value = product.cost;
      document.getElementById('quantity').value = product.quantity;
      document.getElementById('lowStockThreshold').value = product.low_stock_threshold;
      document.getElementById('status').value = product.status;

      // Update modal title
      document.getElementById('productModalTitle').textContent = 'Edit Product';

      // Show modal
      document.getElementById('productModal').classList.add('show');
    } else {
      showNotification(response.message || 'Failed to load product data', 'error');
    }
  } catch (error) {
    console.error('Error fetching product:', error);
    showNotification('Error loading product data', 'error');
  }
}

// Hide product modal
function hideProductModal() {
  document.getElementById('productModal').classList.remove('show');
}

// Save product (create or update)
async function saveProduct() {
  try {
    const productId = document.getElementById('productId').value;

    // Gather form data
    const productData = {
      sku: document.getElementById('sku').value,
      barcode: document.getElementById('barcode').value,
      name: document.getElementById('name').value,
      description: document.getElementById('description').value,
      category_id: document.getElementById('categoryId').value,
      price: parseFloat(document.getElementById('price').value),
      cost: parseFloat(document.getElementById('cost').value),
      quantity: parseInt(document.getElementById('quantity').value),
      low_stock_threshold: parseInt(document.getElementById('lowStockThreshold').value),
      status: document.getElementById('status').value
    };

    let response;

    if (productId) {
      // Update existing product
      response = await apiRequest(`inventory/product?id=${productId}`, 'PUT', productData);
    } else {
      // Create new product
      response = await apiRequest('inventory/products', 'POST', productData);
    }

    if (response.status === 'success') {
      showNotification(productId ? 'อัปเดตสินค้าสำเร็จ' : 'เพิ่มสินค้าสำเร็จ', 'success');
      hideProductModal();

      // Refresh product list
      const productResponse = await apiRequest('inventory/products');
      if (productResponse.status === 'success') {
        products = productResponse.data;
        renderProducts(products);
      }
    } else {
      showNotification(response.message, 'error');
    }
  } catch (error) {
    console.error('Error saving product:', error);
    showNotification('Error saving product data', 'error');
  }
}

// Delete product
async function deleteProduct(productId) {
  if (confirm('แน่ใจหรือไม่ที่จะลบสินค้านี้?')) {
    try {
      const response = await apiRequest(`inventory/product?id=${productId}`, 'DELETE');

      if (response.status === 'success') {
        showNotification('ลบสินค้าสำเร็จ', 'success');

        // Refresh product list
        const productResponse = await apiRequest('inventory/products');
        if (productResponse.status === 'success') {
          products = productResponse.data;
          renderProducts(products);
        }
      } else {
        showNotification(response.message, 'error');
      }
    } catch (error) {
      console.error('Error deleting product:', error);
      showNotification('Error deleting product', 'error');
    }
  }
}

// Show stock adjustment modal
function showStockModal(productId, productName, currentStock) {
  document.getElementById('stockProductId').value = productId;
  document.getElementById('stockProductName').textContent = productName;
  document.getElementById('currentStock').textContent = currentStock;
  document.getElementById('adjustmentType').value = 'add';
  document.getElementById('adjustmentQuantity').value = '';
  document.getElementById('adjustmentNotes').value = '';

  document.getElementById('stockModal').classList.add('show');
}

// Hide stock adjustment modal
function hideStockModal() {
  document.getElementById('stockModal').classList.remove('show');
}

// Save stock adjustment
async function saveStockAdjustment() {
  try {
    const productId = document.getElementById('stockProductId').value;
    const adjustmentType = document.getElementById('adjustmentType').value;
    const quantityInput = parseInt(document.getElementById('adjustmentQuantity').value);
    const notes = document.getElementById('adjustmentNotes').value;

    if (isNaN(quantityInput) || quantityInput < 0) {
      showNotification('Please enter a valid quantity', 'error');
      return;
    }

    let quantity, type;
    const currentStock = parseInt(document.getElementById('currentStock').textContent);

    switch (adjustmentType) {
      case 'add':
        quantity = currentStock + quantityInput;
        type = 'adjustment';
        break;
      case 'subtract':
        if (currentStock < quantityInput) {
          showNotification('Cannot subtract more than current stock', 'error');
          return;
        }
        quantity = currentStock - quantityInput;
        type = 'adjustment';
        break;
      case 'set':
        quantity = quantityInput;
        type = 'adjustment';
        break;
    }

    const adjustmentData = {
      product_id: productId,
      type: type,
      quantity: quantity,
      notes: `${adjustmentType} stock: ${quantityInput} - ${notes}`
    };

    const response = await apiRequest('inventory/transactions', 'POST', adjustmentData);

    if (response.status === 'success') {
      showNotification('Stock adjustment saved successfully', 'success');
      hideStockModal();

      // Refresh product list
      const productResponse = await apiRequest('inventory/products');
      if (productResponse.status === 'success') {
        products = productResponse.data;
        renderProducts(products);
      }
    } else {
      showNotification(response.message, 'error');
    }
  } catch (error) {
    console.error('Error adjusting stock:', error);
    showNotification('Error saving stock adjustment', 'error');
  }
}

// Show category management modal
function showCategoryModal() {
  document.getElementById('categoryForm').reset();
  document.getElementById('categoryId').value = '';
  document.getElementById('categoryModalTitle').textContent = 'Manage Categories';
  renderCategoryList();
  document.getElementById('categoryModal').classList.add('show');
}

// Render category list in modal
function renderCategoryList() {
  const categoryList = document.getElementById('categoryList');
  categoryList.innerHTML = '';

  categories.forEach(category => {
    const categoryItem = document.createElement('div');
    categoryItem.className = 'category-item';

    categoryItem.innerHTML = `
            <div class="category-name">${category.name}</div>
            <div class="category-actions">
                <button class="btn-icon edit-category" data-id="${category.id}">
                    <i class="icon-edit"></i>
                </button>
                <button class="btn-icon delete-category" data-id="${category.id}">
                    <i class="icon-delete"></i>
                </button>
            </div>
        `;

    categoryList.appendChild(categoryItem);
  });

  // Add event listeners for category actions
  document.querySelectorAll('.edit-category').forEach(button => {
    button.addEventListener('click', function() {
      const categoryId = this.dataset.id;
      editCategory(categoryId);
    });
  });

  document.querySelectorAll('.delete-category').forEach(button => {
    button.addEventListener('click', function() {
      const categoryId = this.dataset.id;
      deleteCategory(categoryId);
    });
  });
}

// Edit category
function editCategory(categoryId) {
  const category = categories.find(c => c.id === categoryId);

  if (category) {
    document.getElementById('categoryId').value = category.id;
    document.getElementById('categoryName').value = category.name;
    document.getElementById('categoryDescription').value = category.description || '';
  }
}

// Reset category form
function resetCategoryForm() {
  document.getElementById('categoryForm').reset();
  document.getElementById('categoryId').value = '';
}

// Save category (create or update)
async function saveCategory() {
  try {
    const categoryId = document.getElementById('categoryId').value;
    const categoryName = document.getElementById('categoryName').value;
    const categoryDescription = document.getElementById('categoryDescription').value;

    if (!categoryName) {
      showNotification('Category name is required', 'error');
      return;
    }

    const categoryData = {
      name: categoryName,
      description: categoryDescription
    };

    let response;

    if (categoryId) {
      // Update existing category
      response = await apiRequest(`inventory/category?id=${categoryId}`, 'PUT', categoryData);
    } else {
      // Create new category
      response = await apiRequest('inventory/categories', 'POST', categoryData);
    }

    if (response.status === 'success') {
      showNotification(categoryId ? 'อัปเดตหมวดหมู่สำเร็จ' : 'เพิ่มหมวดหมู่สำเร็จ', 'success');
      resetCategoryForm();

      // Refresh category list
      const categoryResponse = await apiRequest('inventory/categories');
      if (categoryResponse.status === 'success') {
        categories = categoryResponse.data;
        renderCategoryList();
        renderCategoryDropdowns();
      }
    } else {
      showNotification(response.message, 'error');
    }
  } catch (error) {
    console.error('Error saving category:', error);
    showNotification('Error saving category', 'error');
  }
}

// Delete category
async function deleteCategory(categoryId) {
  if (confirm('แน่ใจหรือไม่ที่จะลบหมวดหมู่นี้?')) {
    try {
      const response = await apiRequest(`inventory/category?id=${categoryId}`, 'DELETE');

      if (response.status === 'success') {
        showNotification('ลบหมวดหมู่สำเร็จ', 'success');

        // Refresh category list
        const categoryResponse = await apiRequest('inventory/categories');
        if (categoryResponse.status === 'success') {
          categories = categoryResponse.data;
          renderCategoryList();
          renderCategoryDropdowns();
        }
      } else {
        showNotification(response.message, 'error');
      }
    } catch (error) {
      console.error('Error deleting category:', error);
      showNotification('Error deleting category', 'error');
    }
  }
}

// Export inventory to CSV
function exportInventory() {
  // Get filtered products
  const searchTerm = document.getElementById('productSearch').value.toLowerCase();
  const categoryId = document.getElementById('categoryFilter').value;
  const stockFilter = document.getElementById('stockFilter').value;

  let filtered = [...products];

  // Apply filters
  if (searchTerm) {
    filtered = filtered.filter(product => {
      return product.name.toLowerCase().includes(searchTerm) ||
        product.sku.toLowerCase().includes(searchTerm) ||
        (product.barcode && product.barcode.toLowerCase().includes(searchTerm));
    });
  }

  if (categoryId) {
    filtered = filtered.filter(product => product.category_id === categoryId);
  }

  if (stockFilter !== 'all') {
    if (stockFilter === 'low') {
      filtered = filtered.filter(product =>
        product.quantity <= product.low_stock_threshold && product.quantity > 0
      );
    } else if (stockFilter === 'out') {
      filtered = filtered.filter(product => product.quantity <= 0);
    }
  }

  // Create CSV content
  let csvContent = 'SKU,Name,Category,Price,Cost,Stock,Status\n';

  filtered.forEach(product => {
    const row = [
      product.sku,
      `"${product.name.replace(/"/g, '""')}"`,
      `"${(product.category_name || 'Uncategorized').replace(/"/g, '""')}"`,
      product.price,
      product.cost,
      product.quantity,
      product.status
    ];
    csvContent += row.join(',') + '\n';
  });

  // Create download link
  const blob = new Blob([csvContent], {type: 'text/csv;charset=utf-8;'});
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');

  link.setAttribute('href', url);
  link.setAttribute('download', `inventory_export_${new Date().toISOString().split('T')[0]}.csv`);
  link.style.visibility = 'hidden';

  document.body.appendChild(link);
  link.click();
  document.body.removeChild(link);
}

// Show notification
function showNotification(message, type = 'info') {
  // You can implement a notification system here
  // For simplicity, we'll use alert for now
  alert(message);
}