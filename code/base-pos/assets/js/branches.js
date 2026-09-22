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
    showTableLoading('branchesTableBody', 8, 5);
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
    tbody.innerHTML = '<tr><td colspan="8" class="text-center">ไม่มีข้อมูลสาขา</td></tr>';
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

    const printServer = b.print_server_host
      ? `<span class="badge badge-info" title="เครื่องพิมพ์ความร้อนของสาขานี้">🖨️ ${escapeHtml(b.print_server_host)}${b.print_server_port ? ':' + escapeHtml(b.print_server_port) : ''}</span>`
      : '<span class="text-muted">ยังไม่ตั้งค่า</span>';

    const scaleMode = b.scale_mode || 'disabled';
    const scaleBadge = scaleMode === 'auto'
      ? `<span class="badge badge-success" title="ตาชั่ง Tiger TI-01 — auto">⚖️ auto${b.scale_device_count ? ` (${b.scale_device_count})` : ''}</span>`
      : (scaleMode === 'required' ? '<span class="badge badge-warning">⚖️ required</span>' : '<span class="text-muted">⚪ คีย์มือ</span>');

    return `
      <tr>
        <td>${escapeHtml(b.code)}</td>
        <td><strong>${escapeHtml(b.name)}</strong></td>
        <td>${escapeHtml(b.address || '-')}</td>
        <td>${escapeHtml(b.phone || '-')}</td>
        <td>${escapeHtml(b.manager_name || '-')}</td>
        <td>${statusBadge}</td>
        <td>${printServer}<br>${scaleBadge}</td>
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
  document.getElementById('printServerHost').value = branch.print_server_host || '';
  document.getElementById('printServerPort').value = branch.print_server_port || '';
  document.getElementById('branchScaleMode').value = branch.scale_mode || 'disabled';
  const devList = document.getElementById('scaleDevicesList');
  if (devList) {
    const devs = branch.scale_devices || [];
    devList.innerHTML = devs.length
      ? devs.map(d => `⚖️ ${escapeHtml(d.code)} — ${escapeHtml(d.model)} ${d.serial_no ? '('+escapeHtml(d.serial_no)+')' : ''} [${d.port||'auto'} @${d.baud_rate}]`).join('<br>')
      : '<span style="color:#999">ยังไม่มีเครื่องชั่ง — เพิ่มได้ที่ API /scale/devices (Phase 2)</span>';
  }

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

  const scaleMode = document.getElementById('branchScaleMode')?.value || 'disabled';
  const data = {
    name: branchName,
    address: document.getElementById('branchAddress').value.trim() || null,
    phone: document.getElementById('branchPhone').value.trim() || null,
    manager_name: document.getElementById('branchManager').value.trim() || null,
    scale_mode: scaleMode,
  };

  const printServerHost = document.getElementById('printServerHost').value.trim();
  const printServerPort = document.getElementById('printServerPort').value.trim();

  const saveBtn = document.querySelector('#branchModal .btn-primary');
  setButtonLoading(saveBtn, true);
  try {
    let res;
    if (branchId) {
      res = await apiRequest(`branches/branch?id=${branchId}`, 'PUT', data);
      if (res.status === 'success') {
        // บันทึก print server ของสาขา (แยก endpoint เก็บใน branch_settings)
        res = await apiRequest(`branches/branch/print-server?id=${branchId}`, 'PUT', {
          print_server_host: printServerHost || '',
          print_server_port: printServerPort || '',
        });
      }
    } else {
      res = await apiRequest('branches', 'POST', {
        code: document.getElementById('branchCode').value.trim(),
        ...data,
      });
    }
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
