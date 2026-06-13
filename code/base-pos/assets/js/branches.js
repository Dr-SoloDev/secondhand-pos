// branches.js - จัดการสาขา
let branches = [];
let currentUser = null;

document.addEventListener('DOMContentLoaded', async () => {
  await checkAuth();
  loadBranches();
});

async function checkAuth() {
  const token = localStorage.getItem('posToken');
  if (!token) {
    window.location.href = '../index.html';
    return;
  }
  try {
    const res = await apiRequest('auth/verify', 'POST', { token });
    if (res.status === 'success') {
      // API ส่งกลับมาเป็น res.data.user ไม่ใช่ res.data
      currentUser = res.data.user || res.data;
      document.getElementById('currentUser').textContent = currentUser.username;
    } else {
      console.error('checkAuth - failed:', res);
      localStorage.removeItem('posToken');
      window.location.href = '../index.html';
    }
  } catch (err) {
    console.error('checkAuth - error:', err);
    localStorage.removeItem('posToken');
    window.location.href = '../index.html';
  }
}

async function loadBranches() {
  try {
    const res = await apiRequest('branches');
    if (res.status === 'success') {
      branches = res.data || [];
      renderBranchesTable();
    } else {
      showNotification('ไม่สามารถโหลดข้อมูลสาขาได้', 'error');
    }
  } catch (err) {
    console.error('Load branches error:', err);
    showNotification('เกิดข้อผิดพลาดในการโหลดข้อมูล', 'error');
  }
}

function renderBranchesTable() {
  const tbody = document.getElementById('branchesTableBody');
  if (!branches || branches.length === 0) {
    tbody.innerHTML = '<tr><td colspan="7" class="text-center">ไม่มีข้อมูลสาขา</td></tr>';
    return;
  }

  tbody.innerHTML = branches.map(b => {
    const statusBadge = b.status === 'active'
      ? '<span class="badge badge-success">เปิดใช้งาน</span>'
      : '<span class="badge badge-secondary">ปิดใช้งาน</span>';

    const isAdmin = currentUser && currentUser.role === 'admin';

    const editBtn = isAdmin
      ? `<button class="btn btn-sm btn-primary" onclick="openEditModal(${b.id})">
           <i class="icon-edit"></i> แก้ไข
         </button>`
      : '<span class="text-muted">ไม่มีสิทธิ์</span>';

    return `
      <tr>
        <td>${escapeHtml(b.code)}</td>
        <td><strong>${escapeHtml(b.name)}</strong></td>
        <td>${escapeHtml(b.address || '-')}</td>
        <td>${escapeHtml(b.phone || '-')}</td>
        <td>${escapeHtml(b.manager_name || '-')}</td>
        <td>${statusBadge}</td>
        <td>${editBtn}</td>
      </tr>
    `;
  }).join('');
}

function openAddModal() {
  document.getElementById('branchId').value = '';
  document.getElementById('branchCode').value = '';
  document.getElementById('branchForm').reset();
  document.getElementById('branchCode').removeAttribute('disabled');
  document.getElementById('modalTitle').textContent = 'เพิ่มสาขาใหม่';
  document.getElementById('branchModal').classList.add('show');
}

function openEditModal(branchId) {
  const branch = branches.find(b => b.id === branchId);
  if (!branch) {
    showNotification('ไม่พบข้อมูลสาขา', 'error');
    return;
  }

  document.getElementById('branchId').value = branch.id;
  document.getElementById('branchCode').value = branch.code;
  document.getElementById('branchCode').setAttribute('disabled', 'disabled');
  document.getElementById('branchName').value = branch.name;
  document.getElementById('branchAddress').value = branch.address || '';
  document.getElementById('branchPhone').value = branch.phone || '';
  document.getElementById('branchManager').value = branch.manager_name || '';

  document.getElementById('modalTitle').textContent = 'แก้ไขข้อมูลสาขา';
  document.getElementById('branchModal').classList.add('show');
}

function closeBranchModal() {
  document.getElementById('branchModal').classList.remove('show');
  document.getElementById('branchForm').reset();
}

async function saveBranch() {
  const branchId = document.getElementById('branchId').value;
  const branchName = document.getElementById('branchName').value.trim();

  if (!branchName) {
    showNotification('กรุณากรอกชื่อสาขา', 'error');
    return;
  }

  const data = {
    name: branchName,
    address: document.getElementById('branchAddress').value.trim() || null,
    phone: document.getElementById('branchPhone').value.trim() || null,
    manager_name: document.getElementById('branchManager').value.trim() || null,
  };

  try {
    const res = branchId
      ? await apiRequest(`branches/branch?id=${branchId}`, 'PUT', data)
      : await apiRequest('branches', 'POST', {
          code: document.getElementById('branchCode').value.trim(),
          ...data,
        });
    if (res.status === 'success') {
      showNotification('บันทึกข้อมูลสาขาสำเร็จ', 'success');
      closeBranchModal();
      loadBranches();
    } else {
      showNotification(res.message || 'ไม่สามารถบันทึกข้อมูลได้', 'error');
    }
  } catch (err) {
    console.error('Save branch error:', err);
    showNotification('เกิดข้อผิดพลาดในการบันทึกข้อมูล', 'error');
  }
}

function escapeHtml(text) {
  if (!text) return '';
  const div = document.createElement('div');
  div.textContent = text;
  return div.innerHTML;
}

function showNotification(message, type = 'info') {
  const container = document.getElementById('notification-container');
  const notif = document.createElement('div');
  notif.className = `notification notification-${type}`;
  notif.textContent = message;
  container.appendChild(notif);
  setTimeout(() => notif.classList.add('show'), 10);
  setTimeout(() => {
    notif.classList.remove('show');
    setTimeout(() => notif.remove(), 300);
  }, 3000);
}

// Logout
document.getElementById('logoutBtn')?.addEventListener('click', (e) => {
  e.preventDefault();
  localStorage.removeItem('posToken');
  localStorage.removeItem('posUser');
  window.location.href = '../index.html';
});

// User dropdown
document.querySelector('.user-dropdown-toggle')?.addEventListener('click', function(e) {
  e.stopPropagation();
  this.parentElement.classList.toggle('active');
});

document.addEventListener('click', () => {
  document.querySelector('.user-dropdown')?.classList.remove('active');
});
