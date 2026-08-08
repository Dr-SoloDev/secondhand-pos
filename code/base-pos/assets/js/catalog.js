// ===== แคตตาล็อกสินค้า — แยกจาก inventory.js =====
let catalogItems = [];
let categories = [];
let catalogPermissions = null;

document.addEventListener('DOMContentLoaded', async function() {
  catalogPermissions = await getAppPermissions();
  if (!hasAppPermission('actions.catalog.manage_categories', catalogPermissions)) {
    document.getElementById('manageCategories').style.display = 'none';
  }
  await initCatalog();

  document.getElementById('productSearch').addEventListener('input', filterCatalogItems);
  document.getElementById('categoryFilter').addEventListener('change', filterCatalogItems);
  document.getElementById('stockFilter').addEventListener('change', filterCatalogItems);
  document.getElementById('addProductBtn').addEventListener('click', showAddCatalogModal);
  document.getElementById('manageCategories').addEventListener('click', showCategoryModal);
  document.getElementById('saveProduct').addEventListener('click', saveCatalogItem);
  document.getElementById('cancelProduct').addEventListener('click', hideCatalogModal);
  document.getElementById('saveCategory').addEventListener('click', saveCategory);
  document.getElementById('cancelCategory').addEventListener('click', resetCategoryForm);

  document.querySelectorAll('.close-modal').forEach(button => {
    button.addEventListener('click', function() {
      this.closest('.modal').classList.remove('show');
    });
  });

  // Clear errors on input
  ['sku', 'name', 'priceTier1', 'priceTier2', 'priceTier3'].forEach(id => {
    const field = document.getElementById(id);
    if (field) {
      field.addEventListener('input', () => clearFieldError(id));
    }
  });
});

async function initCatalog() {
  try {
    showTableLoading(document.querySelector('#inventoryTable tbody'), 10, 5);

    const catRes = await apiRequest('inventory/categories');
    if (catRes.status === 'success') {
      categories = catRes.data;
      renderCategoryDropdowns();
    }

    const catalogRes = await apiRequest('purchase-catalog?include_inactive=true');
    if (catalogRes.status === 'success') {
      catalogItems = catalogRes.data;
      renderCatalogItems(catalogItems);
    }
  } catch (error) {
    console.error('Failed to initialize catalog:', error);
    showNotification('โหลดข้อมูลแคตตาล็อกไม่สำเร็จ', 'error');
  }
}

function renderCategoryDropdowns() {
  const categoryFilter = document.getElementById('categoryFilter');
  const categorySelect = document.getElementById('categoryId');

  while (categoryFilter.options.length > 1) categoryFilter.remove(1);
  while (categorySelect.options.length > 1) categorySelect.remove(1);

  categories.forEach(cat => {
    if (cat.status === 'active') {
      const opt1 = document.createElement('option');
      opt1.value = cat.id;
      opt1.textContent = cat.name;
      categoryFilter.appendChild(opt1);

      const opt2 = document.createElement('option');
      opt2.value = cat.id;
      opt2.textContent = cat.name;
      categorySelect.appendChild(opt2);
    }
  });
}

function renderCatalogItems(items) {
  const tableBody = document.querySelector('#inventoryTable tbody');
  tableBody.innerHTML = '';

  if (items.length === 0) {
    tableBody.innerHTML = '<tr><td colspan="10" class="text-center">ไม่มีรายการสินค้าในแคตตาล็อก</td></tr>';
    return;
  }

  items.forEach(item => {
    const tiers = item.tier_prices || [];
    const tier1 = tiers[0] || {};
    const tier2 = tiers[1] || {};
    const tier3 = tiers[2] || {};

    const row = document.createElement('tr');
    row.innerHTML = `
      <td>${escapeHtml(item.code)}</td>
      <td>${escapeHtml(item.name)}</td>
      <td>${escapeHtml(item.category_name || 'ไม่มีหมวด')}</td>
      <td>${tier1.price != null ? formatCurrency(tier1.price) : '-'}</td>
      <td>${tier2.price != null ? formatCurrency(tier2.price) : '-'}</td>
      <td>${tier3.price != null ? formatCurrency(tier3.price) : '-'}</td>
      <td>${escapeHtml(item.default_unit || 'กก.')}</td>
      <td>
        <span class="badge ${item.is_active ? 'badge-success' : 'badge-danger'}">
          ${item.is_active ? 'active' : 'inactive'}
        </span>
      </td>
      <td class="actions">
        <button class="btn btn-sm btn-info edit-catalog" data-id="${item.id}"><i class="icon-edit"></i></button>
        ${hasAppPermission('actions.catalog.delete', catalogPermissions)
          ? `<button class="btn btn-sm btn-danger delete-catalog" data-id="${item.id}"><i class="icon-delete"></i></button>`
          : ''}
      </td>
    `;
    tableBody.appendChild(row);
  });

  document.querySelectorAll('.edit-catalog').forEach(btn => {
    btn.addEventListener('click', function() {
      editCatalogItem(this.getAttribute('data-id'));
    });
  });

  document.querySelectorAll('.delete-catalog').forEach(btn => {
    btn.addEventListener('click', function() {
      deleteCatalogItem(this.getAttribute('data-id'));
    });
  });
}

function filterCatalogItems() {
  const searchTerm = document.getElementById('productSearch').value.toLowerCase();
  const categoryId = document.getElementById('categoryFilter').value;
  const stockFilter = document.getElementById('stockFilter').value;

  let filtered = [...catalogItems];

  if (searchTerm) {
    filtered = filtered.filter(item =>
      item.name.toLowerCase().includes(searchTerm) ||
      item.code.toLowerCase().includes(searchTerm)
    );
  }

  if (categoryId) {
    filtered = filtered.filter(item => String(item.category_id) === categoryId);
  }

  if (stockFilter !== 'all') {
    if (stockFilter === 'active') {
      filtered = filtered.filter(item => item.is_active);
    } else if (stockFilter === 'inactive') {
      filtered = filtered.filter(item => !item.is_active);
    }
  }

  renderCatalogItems(filtered);
}

// ===== Add / Edit Catalog Item =====
function showAddCatalogModal() {
  document.getElementById('productForm').reset();
  document.getElementById('productId').value = '';
  document.getElementById('productModalTitle').textContent = 'เพิ่มรายการในแคตตาล็อก';
  document.getElementById('sku').value = '';
  document.getElementById('priceTier1').value = '';
  document.getElementById('priceTier2').value = '';
  document.getElementById('priceTier3').value = '';
  document.getElementById('productModal').classList.add('show');
}

async function editCatalogItem(itemId) {
  try {
    const res = await apiRequest(`purchase-catalog/item?id=${itemId}`);
    if (res.status !== 'success' || !res.data) {
      showNotification('ไม่พบรายการ', 'error');
      return;
    }
    const item = res.data;
    const tiers = item.tier_prices || [];

    document.getElementById('productId').value = item.id;
    document.getElementById('sku').value = item.code || '';
    document.getElementById('name').value = item.name || '';
    document.getElementById('categoryId').value = item.category_id || '';
    document.getElementById('priceTier1').value = tiers[0] && tiers[0].price != null ? tiers[0].price : '';
    document.getElementById('priceTier2').value = tiers[1] && tiers[1].price != null ? tiers[1].price : '';
    document.getElementById('priceTier3').value = tiers[2] && tiers[2].price != null ? tiers[2].price : '';
    document.getElementById('status').value = item.is_active ? 'active' : 'inactive';
    document.getElementById('productModalTitle').textContent = 'แก้ไขรายการแคตตาล็อก';
    document.getElementById('productModal').classList.add('show');
  } catch (error) {
    console.error('Error fetching catalog item:', error);
    showNotification('โหลดข้อมูลสินค้าไม่สำเร็จ', 'error');
  }
}

function hideCatalogModal() {
  document.getElementById('productModal').classList.remove('show');
}

async function saveCatalogItem() {
  try {
    const itemId = document.getElementById('productId').value;
    const tiers = [
      { label: 'บิล 1', price: parseFloat(document.getElementById('priceTier1').value) || 0 },
      { label: 'บิล 2', price: parseFloat(document.getElementById('priceTier2').value) || 0 },
      { label: 'บิล 3', price: parseFloat(document.getElementById('priceTier3').value) || 0 },
    ];

    clearAllErrors();

    if (tiers[0].price > tiers[1].price || tiers[1].price > tiers[2].price) {
      showFieldError('priceTier1', 'ราคาต้องเรียงจากน้อยไปมาก');
      showFieldError('priceTier2', 'ราคาต้องเรียงจากน้อยไปมาก');
      showFieldError('priceTier3', 'ราคาต้องเรียงจากน้อยไปมาก');
      showNotification('ราคาต้องเรียงจากน้อยไปมาก: บิล 1 ≤ บิล 2 ≤ บิล 3', 'error');
      return;
    }

    const payload = {
      code: document.getElementById('sku').value,
      name: document.getElementById('name').value,
      category_id: document.getElementById('categoryId').value || null,
      tier_prices: tiers,
      is_active: document.getElementById('status').value === 'active' ? 1 : 0,
      default_unit: 'กก.',
    };

    if (!payload.code || !payload.name) {
      if (!payload.code) showFieldError('sku', 'กรุณาระบุรหัสสินค้า');
      if (!payload.name) showFieldError('name', 'กรุณาระบุชื่อสินค้า');
      showNotification('กรุณาระบุรหัสและชื่อสินค้า', 'error');
      return;
    }

    let res;
    if (itemId) {
      res = await apiRequest(`purchase-catalog/item?id=${itemId}`, 'PUT', payload);
    } else {
      res = await apiRequest('purchase-catalog', 'POST', payload);
    }

    if (res.status === 'success') {
      showNotification(itemId ? 'อัปเดตรายการสำเร็จ' : 'เพิ่มรายการสำเร็จ', 'success');
      hideCatalogModal();
      const catalogRes = await apiRequest('purchase-catalog?include_inactive=true');
      if (catalogRes.status === 'success') {
        catalogItems = catalogRes.data;
        renderCatalogItems(catalogItems);
      }
    } else {
      showNotification(res.message || 'เกิดข้อผิดพลาด', 'error');
    }
  } catch (error) {
    console.error('Error saving catalog item:', error);
    showNotification('บันทึกรายการแคตตาล็อกไม่สำเร็จ', 'error');
  }
}

async function deleteCatalogItem(itemId) {
  if (!confirm('แน่ใจหรือไม่ที่จะลบรายการนี้ออกจากแคตตาล็อก?')) return;
  try {
    const res = await apiRequest(`purchase-catalog/item?id=${itemId}`, 'DELETE');
    if (res.status === 'success') {
      showNotification('ลบรายการสำเร็จ', 'success');
      const catalogRes = await apiRequest('purchase-catalog?include_inactive=true');
      if (catalogRes.status === 'success') {
        catalogItems = catalogRes.data;
        renderCatalogItems(catalogItems);
      }
    } else {
      showNotification(res.message || 'เกิดข้อผิดพลาด', 'error');
    }
  } catch (error) {
    console.error('Error deleting catalog item:', error);
    showNotification('ลบรายการแคตตาล็อกไม่สำเร็จ', 'error');
  }
}

// ===== Category Management =====
function showCategoryModal() {
  document.getElementById('categoryForm').reset();
  document.getElementById('editCategoryId').value = '';
  document.getElementById('categoryModalTitle').textContent = 'จัดการหมวดหมู่';
  renderCategoryList();
  document.getElementById('categoryModal').classList.add('show');
}

function renderCategoryList() {
  const categoryList = document.getElementById('categoryList');
  categoryList.innerHTML = '';
  categories.forEach(cat => {
    const item = document.createElement('div');
    item.className = 'category-item';
    item.innerHTML = `
      <div class="category-name">${escapeHtml(cat.name)}</div>
      <div class="category-actions">
        ${hasAppPermission('actions.catalog.manage_categories', catalogPermissions)
          ? `<button class="btn-icon edit-category" data-id="${cat.id}"><i class="icon-edit"></i></button>`
          : ''}
        ${hasAppPermission('actions.catalog.delete', catalogPermissions)
          ? `<button class="btn-icon delete-category" data-id="${cat.id}"><i class="icon-delete"></i></button>`
          : ''}
      </div>`;
    categoryList.appendChild(item);
  });

  document.querySelectorAll('.edit-category').forEach(btn => {
    btn.addEventListener('click', function() { editCategory(this.dataset.id); });
  });
  document.querySelectorAll('.delete-category').forEach(btn => {
    btn.addEventListener('click', function() { deleteCategory(this.dataset.id); });
  });
}

function editCategory(categoryId) {
  const cat = categories.find(c => c.id === categoryId);
  if (cat) {
    document.getElementById('editCategoryId').value = cat.id;
    document.getElementById('categoryName').value = cat.name;
    document.getElementById('categoryDescription').value = cat.description || '';
  }
}

function resetCategoryForm() {
  document.getElementById('categoryForm').reset();
  document.getElementById('editCategoryId').value = '';
}

async function saveCategory() {
  try {
    const categoryId = document.getElementById('editCategoryId').value;
    const categoryName = document.getElementById('categoryName').value;
    const categoryDescription = document.getElementById('categoryDescription').value;
    if (!categoryName) {
      showNotification('กรุณากรอกชื่อหมวดหมู่', 'error');
      return;
    }
    const payload = { name: categoryName, description: categoryDescription };
    let res;
    if (categoryId) {
      res = await apiRequest(`inventory/category?id=${categoryId}`, 'PUT', payload);
    } else {
      res = await apiRequest('inventory/categories', 'POST', payload);
    }
    if (res.status === 'success') {
      showNotification(categoryId ? 'อัปเดตหมวดหมู่สำเร็จ' : 'เพิ่มหมวดหมู่สำเร็จ', 'success');
      resetCategoryForm();
      const catRes = await apiRequest('inventory/categories');
      if (catRes.status === 'success') {
        categories = catRes.data;
        renderCategoryList();
        renderCategoryDropdowns();
      }
    } else {
      showNotification(res.message, 'error');
    }
  } catch (error) {
    console.error('Error saving category:', error);
    showNotification('บันทึกหมวดหมู่ไม่สำเร็จ', 'error');
  }
}

async function deleteCategory(categoryId) {
  if (!confirm('แน่ใจหรือไม่ที่จะลบหมวดหมู่นี้?')) return;
  try {
    const res = await apiRequest(`inventory/category?id=${categoryId}`, 'DELETE');
    if (res.status === 'success') {
      showNotification('ลบหมวดหมู่สำเร็จ', 'success');
      const catRes = await apiRequest('inventory/categories');
      if (catRes.status === 'success') {
        categories = catRes.data;
        renderCategoryList();
        renderCategoryDropdowns();
      }
    } else {
      showNotification(res.message, 'error');
    }
  } catch (error) {
    console.error('Error deleting category:', error);
    showNotification('ลบหมวดหมู่ไม่สำเร็จ', 'error');
  }
}

// ===== Helpers =====
function escapeHtml(s) {
  if (s == null) return '';
  return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

function showNotification(message, type) {
  if (typeof window.appShowNotification === 'function') {
    window.appShowNotification(message, type || 'info');
  }
}

function showFieldError(fieldId, message) {
  const field = document.getElementById(fieldId);
  if (!field) return;
  field.classList.add('form-control-error');
  let errorDiv = field.nextElementSibling;
  if (!errorDiv || !errorDiv.classList.contains('form-error-message')) {
    errorDiv = document.createElement('div');
    errorDiv.className = 'form-error-message';
    field.parentNode.insertBefore(errorDiv, field.nextSibling);
  }
  errorDiv.textContent = message;
}

function clearFieldError(fieldId) {
  const field = document.getElementById(fieldId);
  if (!field) return;
  field.classList.remove('form-control-error');
  const errorDiv = field.nextElementSibling;
  if (errorDiv && errorDiv.classList.contains('form-error-message')) {
    errorDiv.remove();
  }
}

function clearAllErrors() {
  document.querySelectorAll('.form-control-error').forEach(el => el.classList.remove('form-control-error'));
  document.querySelectorAll('.form-error-message').forEach(el => el.remove());
}
