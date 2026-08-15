document.addEventListener('DOMContentLoaded', async function() {
  // Initialize user management
  const initialized = await initUserManagement();
  if (!initialized) return;

  // Event listeners
  document.getElementById('userSearch').addEventListener('input', filterUsers);
  document.getElementById('roleFilter').addEventListener('change', filterUsers);
  document.getElementById('statusFilter').addEventListener('change', filterUsers);
  document.getElementById('addUserBtn').addEventListener('click', showAddUserModal);
  document.getElementById('cancelUser').addEventListener('click', hideUserModal);
  document.getElementById('saveUser').addEventListener('click', saveUser);
  document.getElementById('userActivityFilter')?.addEventListener('change', loadActivityLog);
  document.getElementById('savePassword').addEventListener('click', changePassword);
  document.getElementById('cancelPassword').addEventListener('click', hidePasswordModal);
  document.getElementById('closeResetPassword').addEventListener('click', hideResetPasswordModal);
  document.getElementById('copyPasswordBtn').addEventListener('click', copyPassword);
  document.getElementById('role').addEventListener('change', updateBranchRequirement);

  // Close modals when clicking on X
  document.querySelectorAll('.close-modal').forEach(button => {
    button.addEventListener('click', function() {
      this.closest('.modal').classList.remove('show');
    });
  });
});

// Global variables
let users = [];
let currentPage = 1;
let totalPages = 1;
let itemsPerPage = 10;
let branches = [];

// Initialize user management
async function initUserManagement() {
  try {
    const permissions = await getAppPermissions();
    if (!permissions || !hasAppPermission('actions.users.read', permissions)) {
      window.location.href = `${basePath}/admin/index.html`;
      return false;
    }
    if (!hasAppPermission('actions.users.manage_elevated_roles', permissions)) {
      document.querySelectorAll('#role option[value="admin"], #role option[value="super_manager"], #roleFilter option[value="admin"], #roleFilter option[value="super_manager"]')
        .forEach(option => option.remove());
    }
    if (!hasAppPermission('actions.audit.read', permissions)) {
      document.getElementById('activityLogTable')?.closest('.card')?.remove();
    }

    await Promise.all([loadBranches(), loadUsers()]);

    if (hasAppPermission('actions.audit.read', permissions)) {
      loadActivityLog();
    }
    return true;
  } catch (error) {
    console.error('Failed to initialize user management:', error);
    showNotification('โหลดข้อมูลผู้ใช้ไม่สำเร็จ', 'error');
    return false;
  }
}

async function loadBranches() {
  const response = await apiRequest('branches/active');
  if (response.status !== 'success') return;
  branches = response.data || [];
  const select = document.getElementById('branchId');
  branches.forEach(branch => {
    const option = document.createElement('option');
    option.value = branch.id;
    option.textContent = `${branch.code} - ${branch.name}`;
    select.appendChild(option);
  });
}

// Load users from API
async function loadUsers() {
  try {
    showTableLoading(document.querySelector('#usersTable tbody'), 8, 5);
    const response = await apiRequest('users/all');

    if (response.status === 'success') {
      users = response.data;
      renderUsers(users);
      if (document.getElementById('userActivityFilter')) populateUserActivityFilter();
    } else {
      showNotification(response.message || 'โหลดข้อมูลผู้ใช้ไม่สำเร็จ', 'error');
    }
  } catch (error) {
    console.error('Error loading users:', error);
    showNotification('โหลดข้อมูลผู้ใช้ไม่สำเร็จ', 'error');
  }
}

// Render users table
function renderUsers(usersToRender) {
  const tableBody = document.querySelector('#usersTable tbody');
  tableBody.innerHTML = '';

  if (usersToRender.length === 0) {
    const row = document.createElement('tr');
    row.innerHTML = '<td colspan="8" class="text-center">ไม่พบผู้ใช้</td>';
    tableBody.appendChild(row);
    return;
  }

  usersToRender.forEach(user => {
    const row = document.createElement('tr');

    // Format last login date
    const lastLogin = user.last_login ? new Date(user.last_login.replace(' ', 'T')).toLocaleString() : '-';

    row.innerHTML = `
      <td>${escapeHtml(user.username)}</td>
      <td>${escapeHtml(user.full_name)}</td>
      <td>${escapeHtml(user.phone || '-')}</td>
      <td><span class="badge badge-info">${escapeHtml(user.role)}</span></td>
      <td>${escapeHtml(user.branch_name || '-')}</td>
      <td>
        <span class="badge ${user.status === 'active' ? 'badge-success' : 'badge-danger'}">
          ${user.status}
        </span>
      </td>
      <td>${lastLogin}</td>
      <td class="actions">
        <button class="btn btn-sm btn-info edit-user" data-id="${user.id}">
          <i class="icon-edit"></i>
        </button>
        <button class="btn btn-sm btn-warning change-password" data-id="${user.id}" title="เปลี่ยนรหัสผ่าน">
          <i class="icon-password"></i>
        </button>
        <button class="btn btn-sm btn-secondary reset-password" data-id="${user.id}" title="รีเซ็ตรหัสผ่าน">
          <i class="icon-password"></i>
        </button>
        <button class="btn btn-sm btn-danger delete-user" data-id="${user.id}">
          <i class="icon-delete"></i>
        </button>
      </td>
    `;

    tableBody.appendChild(row);
  });

  // Add event listeners for action buttons
  document.querySelectorAll('.edit-user').forEach(button => {
    button.addEventListener('click', function() {
      const userId = this.dataset.id;
      editUser(userId);
    });
  });

  document.querySelectorAll('.change-password').forEach(button => {
    button.addEventListener('click', function() {
      const userId = this.dataset.id;
      showPasswordModal(userId);
    });
  });

  document.querySelectorAll('.reset-password').forEach(button => {
    button.addEventListener('click', function() {
      const userId = this.dataset.id;
      resetPassword(userId);
    });
  });

  document.querySelectorAll('.delete-user').forEach(button => {
    button.addEventListener('click', function() {
      const userId = this.dataset.id;
      deleteUser(userId);
    });
  });
}

// Populate user filter for activity log
function populateUserActivityFilter() {
  const select = document.getElementById('userActivityFilter');

  // Clear existing options (except the first one)
  while (select.options.length > 1) {
    select.remove(1);
  }

  // Add users to the filter
  users.forEach(user => {
    const option = document.createElement('option');
    option.value = user.id;
    option.textContent = `${user.username} (${user.full_name})`;
    select.appendChild(option);
  });
}

// Filter users based on search and filters
function filterUsers() {
  const searchTerm = document.getElementById('userSearch').value.toLowerCase();
  const roleFilter = document.getElementById('roleFilter').value;
  const statusFilter = document.getElementById('statusFilter').value;

  let filtered = [...users];

  // Apply search filter
  if (searchTerm) {
    filtered = filtered.filter(user => {
      return user.username.toLowerCase().includes(searchTerm) ||
        user.full_name.toLowerCase().includes(searchTerm) ||
        String(user.phone || '').toLowerCase().includes(searchTerm);
    });
  }

  // Apply role filter
  if (roleFilter) {
    filtered = filtered.filter(user => user.role === roleFilter);
  }

  // Apply status filter
  if (statusFilter) {
    filtered = filtered.filter(user => user.status === statusFilter);
  }

  renderUsers(filtered);
}

// Show add user modal
function showAddUserModal() {
  // Reset form
  document.getElementById('userForm').reset();
  document.getElementById('userId').value = '';
  document.getElementById('userModalTitle').textContent = 'เพิ่มผู้ใช้';

  // Show password fields and make them required
  const passwordFields = document.querySelectorAll('.password-fields input');
  passwordFields.forEach(field => {
    field.required = true;
    field.disabled = false;
  });
  document.querySelector('.password-fields').style.display = 'flex';
  document.querySelector('.password-fields .form-hint').style.display = 'none';

  // Enable username field
  document.getElementById('username').disabled = false;

  // Set default status to active
  document.getElementById('status').value = 'active';
  updateBranchRequirement();

  // Show modal
  document.getElementById('userModal').classList.add('show');
}

// Show edit user modal
async function editUser(userId) {
  try {
    const response = await apiRequest(`users/user?id=${userId}`);

    if (response.status === 'success') {
      const user = response.data;

      // Fill form fields
      document.getElementById('userId').value = user.id;
      document.getElementById('username').value = user.username;
      document.getElementById('username').disabled = true; // Username cannot be changed
      document.getElementById('phone').value = user.phone || '';
      document.getElementById('fullName').value = user.full_name;
      document.getElementById('role').value = user.role;
      document.getElementById('branchId').value = user.branch_id || '';
      document.getElementById('status').value = user.status;
      updateBranchRequirement();

      // Hide password fields
      document.querySelector('.password-fields').style.display = 'none';
      const passwordFields = document.querySelectorAll('.password-fields input');
      passwordFields.forEach(field => {
        field.required = false;
        field.disabled = true;
      });

      // Update modal title
      document.getElementById('userModalTitle').textContent = 'แก้ไขผู้ใช้';

      // Show modal
      document.getElementById('userModal').classList.add('show');
    } else {
      showNotification(response.message || 'โหลดรายละเอียดผู้ใช้ไม่สำเร็จ', 'error');
    }
  } catch (error) {
    console.error('Error loading user details:', error);
    showNotification('โหลดรายละเอียดผู้ใช้ไม่สำเร็จ', 'error');
  }
}

// Hide user modal
function hideUserModal() {
  document.getElementById('userModal').classList.remove('show');
}

// Save user (create or update)
async function saveUser() {
  try {
    const userId = document.getElementById('userId').value;
    const isNewUser = !userId;

    // Validate form
    const form = document.getElementById('userForm');
    if (!form.checkValidity()) {
      form.reportValidity();
      return;
    }

    // Validate passwords match for new users
    if (isNewUser) {
      const password = document.getElementById('password').value;
      const confirmPassword = document.getElementById('confirmPassword').value;

      if (password !== confirmPassword) {
        showNotification('รหัสผ่านไม่ตรงกัน', 'error');
        return;
      }
      if (password.length < 8) {
        showNotification('รหัสผ่านต้องมีอย่างน้อย 8 ตัวอักษร', 'error');
        return;
      }
    }

    // Gather form data
    const userData = {
      username: document.getElementById('username').value,
      phone: document.getElementById('phone').value,
      full_name: document.getElementById('fullName').value,
      role: document.getElementById('role').value,
      status: document.getElementById('status').value,
      branch_id: document.getElementById('branchId').value || null
    };

    // Add password for new users
    if (isNewUser) {
      userData.password = document.getElementById('password').value;
    }

    let response;

    if (isNewUser) {
      // Create new user
      response = await apiRequest('users', 'POST', userData);
    } else {
      // Update existing user
      response = await apiRequest(`users/user?id=${userId}`, 'PUT', userData);
    }

    if (response.status === 'success') {
      showNotification(isNewUser ? 'เพิ่มผู้ใช้สำเร็จ' : 'อัปเดตผู้ใช้สำเร็จ', 'success');
      hideUserModal();
      await loadUsers(); // Reload users
    } else {
      showNotification(response.message || 'บันทึกผู้ใช้ไม่สำเร็จ', 'error');
    }
  } catch (error) {
    console.error('Error saving user:', error);
    showNotification('บันทึกผู้ใช้ไม่สำเร็จ', 'error');
  }
}

function updateBranchRequirement() {
  const role = document.getElementById('role').value;
  document.getElementById('branchId').required = role === 'manager' || role === 'cashier';
}

// Delete user
async function deleteUser(userId) {
  // Don't allow deletion of self
  const userJson = localStorage.getItem('posUser');
  if (userJson) {
    const currentUser = JSON.parse(userJson);
    if (currentUser.id == userId) {
      showNotification('ไม่สามารถลบบัญชีของตนเองได้', 'error');
      return;
    }
  }

  if (confirm('แน่ใจหรือไม่ที่จะลบผู้ใช้นี้?')) {
    try {
      const response = await apiRequest(`users/user?id=${userId}`, 'DELETE');

      if (response.status === 'success') {
        showNotification('ลบผู้ใช้สำเร็จ', 'success');
        await loadUsers(); // Reload users
      } else {
        showNotification(response.message || 'ลบผู้ใช้ไม่สำเร็จ', 'error');
      }
    } catch (error) {
      console.error('Error deleting user:', error);
      showNotification('ลบผู้ใช้ไม่สำเร็จ', 'error');
    }
  }
}

// Show password change modal
function showPasswordModal(userId) {
  document.getElementById('passwordForm').reset();
  document.getElementById('passwordUserId').value = userId;
  document.getElementById('passwordModal').classList.add('show');
}

// Hide password change modal
function hidePasswordModal() {
  document.getElementById('passwordModal').classList.remove('show');
}

// Change user password
async function changePassword() {
  try {
    const userId = document.getElementById('passwordUserId').value;
    const newPassword = document.getElementById('newPassword').value;
    const confirmNewPassword = document.getElementById('confirmNewPassword').value;

    // Validate form
    const form = document.getElementById('passwordForm');
    if (!form.checkValidity()) {
      form.reportValidity();
      return;
    }

    // Validate passwords match
    if (newPassword !== confirmNewPassword) {
      showNotification('รหัสผ่านไม่ตรงกัน', 'error');
      return;
    }

    const response = await apiRequest('users/change-password', 'POST', {
      user_id: userId,
      new_password: newPassword
    });

    if (response.status === 'success') {
      showNotification('เปลี่ยนรหัสผ่านสำเร็จ', 'success');
      hidePasswordModal();
    } else {
      showNotification(response.message || 'เปลี่ยนรหัสผ่านไม่สำเร็จ', 'error');
    }
  } catch (error) {
    console.error('Error changing password:', error);
    showNotification('เปลี่ยนรหัสผ่านไม่สำเร็จ', 'error');
  }
}

// Reset user password and display the generated temporary password once.
async function resetPassword(userId) {
  if (!confirm('รีเซ็ตรหัสผ่านผู้ใช้นี้และสร้างรหัสผ่านชั่วคราวใหม่?')) {
    return;
  }

  try {
    const response = await apiRequest('users/reset-password', 'POST', { user_id: userId });

    if (response.status === 'success') {
      const data = response.data;
      document.getElementById('resetPasswordUserName').textContent = data.full_name || data.username;
      document.getElementById('tempPassword').value = data.temporary_password;
      document.getElementById('resetPasswordModal').classList.add('show');
    } else {
      showNotification(response.message || 'รีเซ็ตรหัสผ่านไม่สำเร็จ', 'error');
    }
  } catch (error) {
    console.error('Error resetting password:', error);
    showNotification('รีเซ็ตรหัสผ่านไม่สำเร็จ', 'error');
  }
}

function hideResetPasswordModal() {
  document.getElementById('resetPasswordModal').classList.remove('show');
}

async function copyPassword() {
  const passwordField = document.getElementById('tempPassword');
  try {
    await navigator.clipboard.writeText(passwordField.value);
    showNotification('คัดลอกรหัสผ่านเรียบร้อยแล้ว', 'success');
  } catch {
    passwordField.select();
    document.execCommand('copy');
    showNotification('คัดลอกรหัสผ่านเรียบร้อยแล้ว', 'success');
  }
}

// Load activity log
async function loadActivityLog() {
  try {
    showTableLoading(document.querySelector('#activityLogTable tbody'), 5, 5);
    const userId = document.getElementById('userActivityFilter').value;

    const params = new URLSearchParams({
      page: currentPage,
      limit: itemsPerPage
    });

    if (userId) {
      params.append('user_id', userId);
    }

    const response = await apiRequest(`users/activity-log?${params.toString()}`);

    if (response.status === 'success') {
      renderActivityLog(response.data.logs);
      totalPages = response.data.pagination.pages;
      renderPagination(response.data.pagination);
    } else {
      showNotification(response.message || 'โหลดบันทึกกิจกรรมไม่สำเร็จ', 'error');
    }
  } catch (error) {
    console.error('Error loading activity log:', error);
    showNotification('โหลดบันทึกกิจกรรมไม่สำเร็จ', 'error');
  }
}

// Render activity log table
function renderActivityLog(logs) {
  const tableBody = document.querySelector('#activityLogTable tbody');
  tableBody.innerHTML = '';

  if (logs.length === 0) {
    const row = document.createElement('tr');
    row.innerHTML = '<td colspan="5" class="text-center">ไม่พบบันทึกกิจกรรม</td>';
    tableBody.appendChild(row);
    return;
  }

  logs.forEach(log => {
    const row = document.createElement('tr');

    // Format date
    const date = new Date(log.created_at.replace(' ', 'T')).toLocaleString();

    [
      log.username,
      log.action,
      log.description || '-',
      log.ip_address,
      date
    ].forEach(value => {
      const cell = document.createElement('td');
      cell.textContent = value == null ? '' : String(value);
      row.appendChild(cell);
    });

    tableBody.appendChild(row);
  });
}

// Render pagination
function renderPagination(pagination) {
  const paginationContainer = document.getElementById('activityPagination');
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

// Change activity log page
function changePage(page) {
  currentPage = page;
  loadActivityLog();
}
