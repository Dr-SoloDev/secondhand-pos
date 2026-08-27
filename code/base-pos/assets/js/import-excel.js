/**
 * import-excel.js — จัดการนำเข้าข้อมูลจาก Excel
 */

let currentUser = null;
let currentImportType = 'purchase_items';
let selectedFile = null;
let currentJobId = null;

document.addEventListener('DOMContentLoaded', async () => {
  await initImport();
});

async function initImport() {
  currentUser = await requireAuth();
  if (!currentUser) return;

  // ตรวจสอบสิทธิ์
  if (!hasAppPermission('actions.reports.full', window.appPermissions)) {
    showNotification('ไม่มีสิทธิ์เข้าถึงหน้านี้', 'error');
    window.location.href = 'index.html';
    return;
  }

  await loadBranches();
  setupUploadZone();
  loadHistory();
}

// ===== Branch Selector =====
async function loadBranches() {
  try {
    const res = await apiRequest('branches/active');
    if (res.status === 'success') {
      const branches = Array.isArray(res.data) ? res.data : (res.data?.items || []);
      const select = document.getElementById('importBranch');
      
      if (!window.appPermissions?.multi_branch && currentUser.branch_id) {
        // Non-admin: ล็อคสาขา
        const branch = branches.find(b => String(b.id) === String(currentUser.branch_id));
        select.innerHTML = `<option value="${currentUser.branch_id}">${branch?.name || 'สาขา ' + currentUser.branch_id}</option>`;
        select.disabled = true;
      } else {
        select.innerHTML = '<option value="">— เลือกสาขา —</option>' +
          branches.map(b => `<option value="${b.id}">${b.name}</option>`).join('');
        select.value = currentUser.branch_id || '';
      }
    }
  } catch (error) {
    console.error('Failed to load branches:', error);
  }
}

// ===== Import Type Selector =====
function selectImportType(el) {
  document.querySelectorAll('.import-type-card').forEach(card => card.classList.remove('active'));
  el.classList.add('active');
  currentImportType = el.dataset.type;
  
  // ล้างไฟล์ที่เลือก
  clearSelectedFile();
  
  // แสดงคำแนะนำตามประเภท
  updateImportHints();
}

function updateImportHints() {
  const hints = {
    purchase_items: 'วันที่, สินค้า, หมวด, น้ำหนัก, ราคา/กก., ยอดรวม, หน่วย',
    sale_lots: 'ผู้ซื้อ, วันที่ขาย, สินค้า, น้ำหนัก, ราคา/กก.',
    sellers: 'ชื่อ-นามสกุล, เบอร์โทร, เลขบัตรประชาชน, ที่อยู่',
  };
  // Could add hint display to UI
}

// ===== Upload Zone =====
function setupUploadZone() {
  const zone = document.getElementById('uploadZone');
  const input = document.getElementById('excelFileInput');

  zone.addEventListener('click', () => input.click());

  zone.addEventListener('dragover', (e) => {
    e.preventDefault();
    zone.classList.add('dragover');
  });

  zone.addEventListener('dragleave', () => {
    zone.classList.remove('dragover');
  });

  zone.addEventListener('drop', (e) => {
    e.preventDefault();
    zone.classList.remove('dragover');
    const files = e.dataTransfer.files;
    if (files.length > 0) handleFileSelect(files[0]);
  });

  input.addEventListener('change', (e) => {
    if (e.target.files.length > 0) handleFileSelect(e.target.files[0]);
  });
}

function handleFileSelect(file) {
  // ตรวจสอบประเภทไฟล์
  const ext = file.name.split('.').pop().toLowerCase();
  if (!['xlsx', 'xls'].includes(ext)) {
    showNotification('อนุญาตเฉพาะไฟล์ .xlsx หรือ .xls เท่านั้น', 'error');
    return;
  }

  // ตรวจสอบขนาดไฟล์
  if (file.size > 10 * 1024 * 1024) {
    showNotification('ไฟล์มีขนาดใหญ่เกินไป (สูงสุด 10MB)', 'error');
    return;
  }

  selectedFile = file;

  // แสดงข้อมูลไฟล์
  document.getElementById('selectedFileName').textContent = file.name;
  document.getElementById('selectedFileSize').textContent = formatFileSize(file.size);
  document.getElementById('selectedFileInfo').style.display = 'block';
  document.getElementById('uploadZone').style.display = 'none';

  // อัปโหลดและ preview
  uploadFile(file);
}

function clearSelectedFile() {
  selectedFile = null;
  document.getElementById('selectedFileInfo').style.display = 'none';
  document.getElementById('uploadZone').style.display = 'block';
  document.getElementById('previewCard').style.display = 'none';
  document.getElementById('excelFileInput').value = '';
  document.getElementById('startImportBtn').disabled = true;
  currentJobId = null;
}

function formatFileSize(bytes) {
  if (bytes === 0) return '0 Bytes';
  const k = 1024;
  const sizes = ['Bytes', 'KB', 'MB'];
  const i = Math.floor(Math.log(bytes) / Math.log(k));
  return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
}

// ===== Upload & Validate =====
async function uploadFile(file) {
  const branchId = document.getElementById('importBranch').value;
  if (!branchId) {
    showNotification('กรุณาเลือกสาขาก่อน', 'error');
    clearSelectedFile();
    return;
  }

  const formData = new FormData();
  formData.append('excel_file', file);
  formData.append('import_type', currentImportType);

  try {
    showNotification('กำลังอัปโหลดไฟล์...', 'info');

    const response = await fetch(`${apiPath}/import/upload`, {
      method: 'POST',
      body: formData,
      credentials: 'include',
    });

    const result = await response.json();

    if (result.status === 'success') {
      currentJobId = result.data.job_id;
      showNotification('อัปโหลดไฟล์สำเร็จ', 'success');

      // Validate file
      await validateFile(currentJobId);
    } else {
      showNotification(result.message || 'อัปโหลดไม่สำเร็จ', 'error');
      clearSelectedFile();
    }
  } catch (error) {
    console.error('Upload failed:', error);
    showNotification('อัปโหลดไม่สำเร็จ: ' + error.message, 'error');
    clearSelectedFile();
  }
}

async function validateFile(jobId) {
  try {
    const res = await apiRequest('import/validate', 'POST', { job_id: jobId });
    
    if (res.status === 'success' && res.data.valid) {
      // แสดง preview
      showPreview(res.data);
      document.getElementById('startImportBtn').disabled = false;
    } else {
      showNotification('ไฟล์ไม่ถูกต้อง: ' + (res.data?.error || 'ไม่ทราบสาเหตุ'), 'error');
      clearSelectedFile();
    }
  } catch (error) {
    console.error('Validation failed:', error);
    showNotification('ตรวจสอบไฟล์ไม่สำเร็จ', 'error');
    clearSelectedFile();
  }
}

function showPreview(data) {
  const card = document.getElementById('previewCard');
  const header = document.getElementById('previewHeader');
  const body = document.getElementById('previewBody');

  // แสดง header
  header.innerHTML = (data.headers || []).map(h => `<th>${escapeHtml(String(h))}</th>`).join('');

  // แสดงข้อมูล 3 แถวแรก
  body.innerHTML = (data.sample_data || []).map(row => 
    '<tr>' + row.map(cell => `<td>${escapeHtml(String(cell ?? ''))}</td>`).join('') + '</tr>'
  ).join('');

  card.style.display = 'block';
}

// ===== Start Import =====
async function startImport() {
  if (!currentJobId) {
    showNotification('ไม่พบข้อมูลไฟล์', 'error');
    return;
  }

  const btn = document.getElementById('startImportBtn');
  btn.disabled = true;
  btn.textContent = '⏳ กำลังนำเข้า...';

  // แสดงสถานะ
  showImportStatus('processing', 'กำลังนำเข้าข้อมูล...', 'กรุณารอสักครู่ ระบบกำลังประมวลผลข้อมูล');

  try {
    const res = await apiRequest('import/process', 'POST', { job_id: currentJobId });

    if (res.status === 'success') {
      const data = res.data;
      const msg = `นำเข้าสำเร็จ ${data.success_rows} รายการ` + 
        (data.failed_rows > 0 ? ` (ล้มเหลว ${data.failed_rows} รายการ)` : '');
      
      showImportStatus('completed', '✅ ' + msg, buildErrorSummary(data.errors));
      showNotification(msg, 'success');
      
      // โหลดประวัติใหม่
      loadHistory();
    } else {
      showImportStatus('failed', '❌ นำเข้าไม่สำเร็จ', res.message);
      showNotification(res.message || 'นำเข้าไม่สำเร็จ', 'error');
    }
  } catch (error) {
    console.error('Import failed:', error);
    showImportStatus('failed', '❌ นำเข้าไม่สำเร็จ', error.message);
    showNotification('นำเข้าไม่สำเร็จ', 'error');
  }

  btn.textContent = '🚀 เริ่มนำเข้าข้อมูล';
  btn.disabled = false;
}

function showImportStatus(type, title, detail) {
  const el = document.getElementById('importStatus');
  el.className = 'import-status ' + type;
  el.style.display = 'block';
  
  document.getElementById('importStatusTitle').textContent = title;
  document.getElementById('importStatusDetail').textContent = detail || '';
  
  const errorList = document.getElementById('importErrorList');
  errorList.innerHTML = '';
}

function buildErrorSummary(errors) {
  if (!errors || errors.length === 0) return '';
  
  const list = document.getElementById('importErrorList');
  list.innerHTML = errors.slice(0, 20).map(e => 
    `<div class="error-item">แถว ${e.row}: ${escapeHtml(e.message)}</div>`
  ).join('');
  
  if (errors.length > 20) {
    list.innerHTML += `<div class="error-item">... และอีก ${errors.length - 20} รายการ</div>`;
  }
  
  return '';
}

// ===== History Pagination State =====
let currentPage = 1;
let itemsPerPage = 20;
let totalPages = 1;
let totalItems = 0;
let searchQuery = '';
let filterStatus = '';

// ===== History =====
async function loadHistory(page = 1) {
  const tbody = document.getElementById('historyBody');
  
  try {
    // Build URL with pagination, search, filter
    let url = `import/history?page=${page}&limit=${itemsPerPage}`;
    if (searchQuery) url += `&search=${encodeURIComponent(searchQuery)}`;
    if (filterStatus) url += `&status=${filterStatus}`;
    
    const res = await apiRequest(url);
    
    if (res.status !== 'success') {
      tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;color:#888;padding:24px">โหลดประวัติไม่สำเร็จ</td></tr>';
      return;
    }

    const { items, pagination } = res.data;
    currentPage = pagination.page;
    totalPages = pagination.total_pages;
    totalItems = pagination.total;

    if (!items || items.length === 0) {
      tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;color:#888;padding:24px">ยังไม่มีประวัติการนำเข้า</td></tr>';
      renderPagination();
      renderItemsInfo();
      return;
    }

    const typeLabels = {
      purchase_items: '📦 รายการรับซื้อ',
      sale_lots: '🏷️ รายการขาย Lot',
      sellers: '👤 ข้อมูลผู้ขาย',
    };

    const statusLabels = {
      pending: '<span class="badge badge-warning">รอดำเนินการ</span>',
      processing: '<span class="badge badge-info">กำลังประมวลผล</span>',
      completed: '<span class="badge badge-success">สำเร็จ</span>',
      failed: '<span class="badge badge-danger">ล้มเหลว</span>',
    };

    tbody.innerHTML = items.map(job => `
      <tr>
        <td>${escapeHtml(formatDisplayDate(job.created_at))}</td>
        <td>${escapeHtml(job.filename)}</td>
        <td>${typeLabels[job.import_type] || job.import_type}</td>
        <td>${escapeHtml(job.user_name || '-')}</td>
        <td class="text-right">${(job.total_rows || 0).toLocaleString('th-TH')}</td>
        <td class="text-right" style="color:var(--color-success)">${(job.success_rows || 0).toLocaleString('th-TH')}</td>
        <td class="text-right" style="color:var(--color-danger)">${(job.failed_rows || 0).toLocaleString('th-TH')}</td>
        <td>${statusLabels[job.status] || job.status}</td>
      </tr>
    `).join('');

    // Render pagination
    renderPagination();
    renderItemsInfo();

  } catch (error) {
    console.error('Failed to load history:', error);
    tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;color:#888;padding:24px">โหลดประวัติไม่สำเร็จ</td></tr>';
  }
}

// ===== Pagination UI =====
function renderPagination() {
  const container = document.getElementById('historyPagination');
  if (!container) return;
  
  if (totalPages <= 1) {
    container.innerHTML = '';
    return;
  }
  
  let html = '<div class="pagination">';
  
  // ปุ่ม ก่อนหน้า
  html += `<button class="pagination-btn" ${currentPage <= 1 ? 'disabled' : ''} onclick="loadHistory(${currentPage - 1})">◀ ก่อนหน้า</button>`;
  
  // หมายเลขหน้า
  for (let i = 1; i <= totalPages; i++) {
    if (i === 1 || i === totalPages || (i >= currentPage - 2 && i <= currentPage + 2)) {
      html += `<button class="pagination-btn ${i === currentPage ? 'active' : ''}" onclick="loadHistory(${i})">${i}</button>`;
    } else if (i === currentPage - 3 || i === currentPage + 3) {
      html += '<span class="pagination-dots">...</span>';
    }
  }
  
  // ปุ่ม ถัดไป
  html += `<button class="pagination-btn" ${currentPage >= totalPages ? 'disabled' : ''} onclick="loadHistory(${currentPage + 1})">ถัดไป ▶</button>`;
  
  html += '</div>';
  container.innerHTML = html;
}

function renderItemsInfo() {
  const el = document.getElementById('historyItemsInfo');
  if (!el) return;
  
  if (totalItems === 0) {
    el.textContent = '';
    return;
  }
  
  const start = (currentPage - 1) * itemsPerPage + 1;
  const end = Math.min(currentPage * itemsPerPage, totalItems);
  
  el.textContent = `แสดง ${start}-${end} จาก ${totalItems} รายการ`;
}

// ===== Search & Filter =====
function handleSearch(query) {
  searchQuery = query;
  loadHistory(1); // กลับหน้า 1
}

function handleFilterStatus(status) {
  filterStatus = status;
  loadHistory(1);
}

function handleItemsPerPage(limit) {
  itemsPerPage = limit;
  loadHistory(1);
}

// ===== Clear All =====
function clearAll() {
  clearSelectedFile();
  document.getElementById('importStatus').style.display = 'none';
}
