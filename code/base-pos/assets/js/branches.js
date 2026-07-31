// branches.js - จัดการสาขา
let branches = [];
let currentUser = null;

document.addEventListener('DOMContentLoaded', async () => {
  currentUser = await requireAuth();
  if (!currentUser) return;
  document.getElementById('currentUser').textContent = currentUser.full_name || currentUser.username || '-';
  loadBranches();
});

async function loadBranches() {
  try {
    showTableLoading('branchesTableBody', 7, 5);
    const res = await apiRequest('branches');
    if (res.status === 'success') {
      branches = res.data || [];
      renderBranchesTable();
    } else {
      showNotification('ไม่สามารถโหลดข้อมูลสาขาได้', 'error');
    }
  } catch (err) {
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

  const saveBtn = document.querySelector('#branchModal .btn-primary');
  setButtonLoading(saveBtn, true);
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
    showNotification('เกิดข้อผิดพลาดในการบันทึกข้อมูล', 'error');
  } finally {
    setButtonLoading(saveBtn, false);
  }
}

function escapeHtml(text) {
  if (!text) return '';
  const div = document.createElement('div');
  div.textContent = text;
  return div.innerHTML;
}

function showNotification(message, type = 'info') {
  if (typeof window.appShowNotification === 'function') {
    window.appShowNotification(message, type);
  }
}

// Logout
document.getElementById('logoutBtn')?.addEventListener('click', (e) => {
  e.preventDefault();
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
