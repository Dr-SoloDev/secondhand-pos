let settingsPermissions = null;
let currentSettingsBranchId = null;

document.addEventListener('DOMContentLoaded', async function() {
  settingsPermissions = await getAppPermissions();
  if (!settingsPermissions || !hasAppPermission('actions.settings.branch', settingsPermissions)) {
    window.location.href = `${basePath}/admin/index.html`;
    return;
  }

  await setupSettingsScope();
  applySettingsPermissions();

  // Initialize settings page
  await loadSettings();

  // Event listeners for form submissions
  document.getElementById('storeSettingsForm').addEventListener('submit', function(e) {
    e.preventDefault();
    saveStoreSettings();
  });

  document.getElementById('systemSettingsForm').addEventListener('submit', function(e) {
    e.preventDefault();
    saveSystemSettings();
  });

  // Event listeners for backup/restore actions
  document.getElementById('createBackup')?.addEventListener('click', createBackup);
  document.getElementById('restoreBackup')?.addEventListener('click', function() {
    document.getElementById('backupFileInput').click();
  });

  document.getElementById('backupFileInput')?.addEventListener('change', function(e) {
    if (e.target.files.length > 0) {
      showRestoreConfirmation(e.target.files[0]);
    }
  });

  document.getElementById('cancelRestore')?.addEventListener('click', function() {
    document.getElementById('restoreConfirmModal').classList.remove('show');
    document.getElementById('backupFileInput').value = '';
  });

  document.getElementById('confirmRestore')?.addEventListener('click', restoreBackup);

  // Close modal when clicking on X
  document.querySelectorAll('.close-modal').forEach(button => {
    button.addEventListener('click', function() {
      this.closest('.modal').classList.remove('show');
    });
  });

  // Load backup history
  if (hasAppPermission('actions.settings.backup', settingsPermissions)) loadBackupHistory();
});

async function setupSettingsScope() {
  if (hasAppPermission('actions.settings.global', settingsPermissions)) return;
  currentSettingsBranchId = settingsPermissions.branch_id || null;
  if (!settingsPermissions.multi_branch) return;

  const response = await apiRequest('branches/active');
  const branches = response.status === 'success' ? (response.data || []) : [];
  const select = document.createElement('select');
  select.id = 'settingsBranch';
  select.className = 'form-control';
  select.style.maxWidth = '320px';
  branches.forEach(branch => select.appendChild(new Option(branch.name, branch.id)));
  currentSettingsBranchId = branches[0]?.id || null;
  select.value = String(currentSettingsBranchId || '');
  select.addEventListener('change', async () => {
    currentSettingsBranchId = Number(select.value) || null;
    await loadSettings();
  });
  document.querySelector('.page-header')?.appendChild(select);
}

function applySettingsPermissions() {
  const canGlobal = hasAppPermission('actions.settings.global', settingsPermissions);
  ['storeName', 'taxId', 'taxRate', 'currencySymbol', 'scrapLicenseNo', 'dateFormat', 'timeZone', 'language']
    .forEach(id => {
      const field = document.getElementById(id);
      if (field) field.disabled = !canGlobal;
    });
  if (!hasAppPermission('actions.settings.backup', settingsPermissions)) {
    document.getElementById('backupsTable')?.closest('.row')?.remove();
    document.getElementById('backupFileInput')?.remove();
    document.getElementById('restoreConfirmModal')?.remove();
  }
}

function scopedSettingsEndpoint(endpoint) {
  return currentSettingsBranchId ? `${endpoint}?branch_id=${currentSettingsBranchId}` : endpoint;
}

// Load all settings from API
async function loadSettings() {
  try {
    // Fetch store settings
    const storeResponse = await apiRequest(scopedSettingsEndpoint('settings/store'));
    if (storeResponse.status === 'success') {
      populateStoreSettings(storeResponse.data);
    }

    // Fetch system settings
    const systemResponse = await apiRequest(scopedSettingsEndpoint('settings/system'));
    if (systemResponse.status === 'success') {
      populateSystemSettings(systemResponse.data);
    }
  } catch (error) {
    console.error('Failed to load settings:', error);
    showNotification('โหลดการตั้งค่าไม่สำเร็จ', 'error');
  }
}

// Populate store settings form
function populateStoreSettings(settings) {
  document.getElementById('storeName').value = settings.store_name || '';
  document.getElementById('storePhone').value = settings.store_phone || '';
  document.getElementById('storeAddress').value = settings.store_address || '';
  document.getElementById('taxId').value = settings.tax_id || '';
  document.getElementById('scrapLicenseNo').value = settings.scrap_license_no || '';
  document.getElementById('receiptWelcomeMessage').value = settings.receipt_welcome_message || '';
  document.getElementById('taxRate').value = settings.tax_rate || '7.00';
  document.getElementById('currencySymbol').value = settings.currency_symbol || '฿';
  document.getElementById('receiptFooter').value = settings.receipt_footer || '';
}

// Populate system settings form
function populateSystemSettings(settings) {
  document.getElementById('lowStockThreshold').value = settings.low_stock_threshold || '10';
  document.getElementById('dateFormat').value = settings.date_format || 'Y-m-d';
  document.getElementById('timeZone').value = settings.time_zone || 'Asia/Bangkok';
  document.getElementById('language').value = settings.language || 'en';
}

// Save store settings
async function saveStoreSettings() {
  const saveBtn = document.querySelector('#storeSettingsForm button[type="submit"]');
  setButtonLoading(saveBtn, true);
  try {
    const form = document.getElementById('storeSettingsForm');
    const formData = new FormData(form);

    const settingsData = {
      store_phone: formData.get('store_phone'),
      store_address: formData.get('store_address'),
      receipt_welcome_message: formData.get('receipt_welcome_message'),
      receipt_footer: formData.get('receipt_footer')
    };
    if (hasAppPermission('actions.settings.global', settingsPermissions)) {
      settingsData.store_name = formData.get('store_name');
      settingsData.tax_id = formData.get('tax_id');
      settingsData.scrap_license_no = formData.get('scrap_license_no');
      settingsData.tax_rate = formData.get('tax_rate');
      settingsData.currency_symbol = formData.get('currency_symbol');
    } else {
      settingsData.branch_id = currentSettingsBranchId;
    }

    const response = await apiRequest('settings/store', 'POST', settingsData);

    if (response.status === 'success') {
      showNotification('บันทึกข้อมูลร้านค้าสำเร็จ', 'success');
    } else {
      showNotification(response.message || 'บันทึกข้อมูลร้านค้าไม่สำเร็จ', 'error');
    }
  } catch (error) {
    console.error('Error saving store settings:', error);
    showNotification('บันทึกข้อมูลร้านค้าไม่สำเร็จ', 'error');
  } finally {
    setButtonLoading(saveBtn, false);
  }
}

// Save system settings
async function saveSystemSettings() {
  const saveBtn = document.querySelector('#systemSettingsForm button[type="submit"]');
  setButtonLoading(saveBtn, true);
  try {
    const form = document.getElementById('systemSettingsForm');
    const formData = new FormData(form);

    const settingsData = { low_stock_threshold: formData.get('low_stock_threshold') };
    if (hasAppPermission('actions.settings.global', settingsPermissions)) {
      settingsData.date_format = formData.get('date_format');
      settingsData.time_zone = formData.get('time_zone');
      settingsData.language = formData.get('language');
    } else {
      settingsData.branch_id = currentSettingsBranchId;
    }

    const response = await apiRequest('settings/system', 'POST', settingsData);

    if (response.status === 'success') {
      showNotification('บันทึกค่าระบบสำเร็จ', 'success');
    } else {
      showNotification(response.message || 'บันทึกค่าระบบไม่สำเร็จ', 'error');
    }
  } catch (error) {
    console.error('Error saving system settings:', error);
    showNotification('บันทึกค่าระบบไม่สำเร็จ', 'error');
  } finally {
    setButtonLoading(saveBtn, false);
  }
}

// Create database backup
async function createBackup() {
  const backupBtn = document.getElementById('createBackup');
  setButtonLoading(backupBtn, true, 'กำลังสำรอง...');
  try {
    showNotification('กำลังสร้างข้อมูลสำรอง...', 'info');

    const response = await apiRequest('settings/backup/create', 'POST');

    if (response.status === 'success') {
      showNotification('สร้างข้อมูลสำรองสำเร็จ', 'success');
      loadBackupHistory();
    } else {
      showNotification(response.message || 'สร้างข้อมูลสำรองไม่สำเร็จ', 'error');
    }
  } catch (error) {
    console.error('Error creating backup:', error);
    showNotification('สร้างข้อมูลสำรองไม่สำเร็จ', 'error');
  } finally {
    setButtonLoading(backupBtn, false);
  }
}

// Show restore confirmation modal
function showRestoreConfirmation(file) {
  document.getElementById('restoreConfirmModal').classList.add('show');
}

// Restore database from backup
async function restoreBackup() {
  try {
    const fileInput = document.getElementById('backupFileInput');
    if (!fileInput.files || fileInput.files.length === 0) {
      showNotification('กรุณาเลือกไฟล์สำรอง', 'error');
      return;
    }

    const file = fileInput.files[0];
    const formData = new FormData();
    formData.append('backup_file', file);

    showNotification('กำลังกู้คืนข้อมูล...', 'info');

    // Using fetch directly for file upload
    const response = await fetch(`${apiPath}/settings/backup/restore`, {
      method: 'POST',
      body: formData
    });

    const result = await response.json();

    if (result.status === 'success') {
      showNotification('กู้คืนข้อมูลสำเร็จ', 'success');
      // Hide modal
      document.getElementById('restoreConfirmModal').classList.remove('show');
      // Reset file input
      fileInput.value = '';

      // Reload the page after a short delay
      setTimeout(() => {
        window.location.reload();
      }, 2000);
    } else {
      showNotification(result.message || 'กู้คืนข้อมูลไม่สำเร็จ', 'error');
    }
  } catch (error) {
    console.error('Error restoring backup:', error);
    showNotification('กู้คืนข้อมูลไม่สำเร็จ', 'error');
  }
}

// Load backup history
async function loadBackupHistory() {
  try {
    showTableLoading(document.querySelector('#backupsTable tbody'), 4, 4);
    const response = await apiRequest('settings/backup/history');

    if (response.status === 'success') {
      renderBackupHistory(response.data);
    } else {
      showNotification(response.message || 'โหลดประวัติสำรองไม่สำเร็จ', 'error');
    }
  } catch (error) {
    console.error('Error loading backup history:', error);
    showNotification('โหลดประวัติสำรองไม่สำเร็จ', 'error');
  }
}

// Render backup history table
function renderBackupHistory(backups) {
  const tableBody = document.querySelector('#backupsTable tbody');
  tableBody.innerHTML = '';

  if (backups.length === 0) {
    const row = document.createElement('tr');
    row.innerHTML = '<td colspan="4" class="text-center">No backups available</td>';
    tableBody.appendChild(row);
    return;
  }

  backups.forEach(backup => {
    const row = document.createElement('tr');

    const date = new Date(backup.created_at.replace(' ', 'T')).toLocaleString();
    const size = formatFileSize(backup.size);

    row.innerHTML = `
      <td>${backup.filename}</td>
      <td>${date}</td>
      <td>${size}</td>
      <td class="actions">
        <button class="btn btn-sm btn-info download-backup" data-filename="${backup.filename}">
          <i class="icon-download"></i>
        </button>
        <button class="btn btn-sm btn-danger delete-backup" data-filename="${backup.filename}">
          <i class="icon-delete"></i>
        </button>
      </td>
    `;

    tableBody.appendChild(row);
  });

  // Add event listeners for action buttons
  document.querySelectorAll('.download-backup').forEach(button => {
    button.addEventListener('click', function() {
      const filename = this.dataset.filename;
      downloadBackup(filename);
    });
  });

  document.querySelectorAll('.delete-backup').forEach(button => {
    button.addEventListener('click', function() {
      const filename = this.dataset.filename;
      deleteBackup(filename);
    });
  });
}

// Download a backup file
async function downloadBackup(filename) {
  try {
    // แสดงการแจ้งเตือนว่ากำลังดาวน์โหลด
    showNotification('กำลังดาวน์โหลด...', 'info');

    // สร้าง URL สำหรับดาวน์โหลด
    const downloadUrl = `${apiPath}/settings/backup/download?filename=${filename}`;

    // ใช้ fetch API พร้อมส่ง Authorization header
    const response = await fetch(downloadUrl, {
      credentials: 'include'
    });

    if (!response.ok) {
      // หากมีข้อผิดพลาด
      const errorData = await response.json();
      throw new Error(errorData.message || 'Download failed');
    }

    // รับข้อมูลเป็น Blob
    const blob = await response.blob();

    // สร้าง Object URL
    const url = window.URL.createObjectURL(blob);

    // สร้าง Element a สำหรับดาวน์โหลด
    const a = document.createElement('a');
    a.style.display = 'none';
    a.href = url;
    a.download = filename;

    // เพิ่ม Element เข้าไปใน DOM
    document.body.appendChild(a);

    // คลิกลิงก์เพื่อดาวน์โหลด
    a.click();

    // Cleanup
    window.URL.revokeObjectURL(url);
    document.body.removeChild(a);

    showNotification('ดาวน์โหลดสำเร็จ', 'success');
  } catch (error) {
    console.error('Error downloading backup:', error);
    showNotification('ดาวน์โหลดไม่สำเร็จ: ' + error.message, 'error');
  }
}

// Delete a backup file
async function deleteBackup(filename) {
  if (confirm('แน่ใจหรือไม่ที่จะลบข้อมูลสำรองนี้?')) {
    try {
      const response = await apiRequest('settings/backup/delete', 'POST', {filename});

      if (response.status === 'success') {
        showNotification('ลบข้อมูลสำรองสำเร็จ', 'success');
        loadBackupHistory();
      } else {
        showNotification(response.message || 'ลบข้อมูลสำรองไม่สำเร็จ', 'error');
      }
    } catch (error) {
      console.error('Error deleting backup:', error);
      showNotification('ลบข้อมูลสำรองไม่สำเร็จ', 'error');
    }
  }
}

// Format file size
function formatFileSize(bytes) {
  if (bytes === 0) return '0 Bytes';

  const k = 1024;
  const sizes = ['Bytes', 'KB', 'MB', 'GB'];
  const i = Math.floor(Math.log(bytes) / Math.log(k));

  return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
}
