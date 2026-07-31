let employees = [];
let branches = [];
let currentPage = 1;
let searchTimer = null;

document.addEventListener('DOMContentLoaded', async () => {
  currentUser = await requireAuth();
  if (!currentUser) return;
  document.getElementById('currentUser').textContent = currentUser.full_name || currentUser.username || '-';
  await loadBranches();
  await loadEmployees();
  setupSearch();
});

async function loadBranches() {
  try {
    const res = await apiRequest('branches');
    if (res.status === 'success') {
      branches = res.data || [];
      const selects = document.querySelectorAll('#filterBranch, #empBranch');
      selects.forEach(sel => {
        sel.innerHTML = sel.id === 'filterBranch' ? '<option value="">ทุกสาขา</option>' : '<option value="">-- เลือกสาขา --</option>';
        branches.forEach(b => {
          sel.innerHTML += `<option value="${b.id}">${escapeHtml(b.name)}</option>`;
        });
      });
    }
  } catch (err) {
    showNotification('โหลดข้อมูลสาขาไม่สำเร็จ', 'error');
  }
}

function setupSearch() {
  document.getElementById('searchInput').addEventListener('input', () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => { currentPage = 1; loadEmployees(); }, 400);
  });
  document.getElementById('filterBranch').addEventListener('change', () => { currentPage = 1; loadEmployees(); });
  document.getElementById('filterStatus').addEventListener('change', () => { currentPage = 1; loadEmployees(); });
}

async function loadEmployees() {
  try {
    showTableLoading('employeeBody', 10, 5);
    const params = new URLSearchParams({ page: currentPage, limit: 20 });
    const search = document.getElementById('searchInput').value.trim();
    if (search) params.set('search', search);
    const branchId = document.getElementById('filterBranch').value;
    if (branchId) params.set('branch_id', branchId);
    const status = document.getElementById('filterStatus').value;
    if (status) params.set('status', status);

    const res = await apiRequest(`employees?${params.toString()}`);
    if (res.status !== 'success') {
      showNotification('ไม่สามารถโหลดข้อมูลพนักงานได้', 'error');
      return;
    }

    employees = res.data.items || [];
    renderTable(employees);
    renderPagination(res.data.pagination);
  } catch (err) {
    showNotification('เกิดข้อผิดพลาดในการโหลดข้อมูล', 'error');
  }
}

function renderTable(items) {
  const tbody = document.getElementById('employeeBody');
  if (!items || items.length === 0) {
    tbody.innerHTML = '<tr><td colspan="10" style="text-align:center;color:#888;padding:24px">ไม่มีข้อมูลพนักงาน</td></tr>';
    return;
  }

  const isAdmin = currentUser && currentUser.role === 'admin';

  tbody.innerHTML = items.map(e => {
    const statusBadge = e.status === 'active'
      ? '<span class="badge badge-success">กำลังทำงาน</span>'
      : '<span class="badge badge-secondary">ออกแล้ว</span>';

    return `
      <tr>
        <td><strong>${escapeHtml(e.full_name)}</strong></td>
        <td>${escapeHtml(e.position || '-')}</td>
        <td>${escapeHtml(e.branch_name || '-')}</td>
        <td>${escapeHtml(e.phone || '-')}</td>
        <td class="text-right">${e.salary > 0 ? formatCurrency(e.salary) : '-'}</td>
        <td class="text-right">${e.daily_wage > 0 ? formatCurrency(e.daily_wage) : '-'}</td>
        <td>${escapeHtml(e.social_security_number || '-')}</td>
        <td>${e.start_date || '-'}</td>
        <td>${statusBadge}</td>
        <td>
          <div style="display:flex;gap:4px">
            <button class="btn btn-sm btn-primary" onclick="openEditModal(${e.id})">แก้ไข</button>
            ${isAdmin ? `<button class="btn btn-sm btn-success" onclick="openSalaryModal(${e.id})">จ่ายเงินเดือน</button>` : ''}
            ${isAdmin ? `<button class="btn btn-sm btn-danger" onclick="deleteEmployee(${e.id})">ลบ</button>` : ''}
          </div>
        </td>
      </tr>
    `;
  }).join('');
}

function renderPagination(p) {
  const el = document.getElementById('pagination');
  if (!p || p.pages <= 1) { el.innerHTML = ''; return; }

  let html = '';
  for (let i = 1; i <= p.pages; i++) {
    html += `<button class="btn btn-sm ${i === p.page ? 'btn-primary' : 'btn-secondary'}" onclick="goToPage(${i})">${i}</button>`;
  }
  el.innerHTML = html;
}

function goToPage(page) {
  currentPage = page;
  loadEmployees();
}

function openAddModal() {
  document.getElementById('employeeId').value = '';
  document.getElementById('employeeForm').reset();
  document.getElementById('empStatus').value = 'active';
  document.getElementById('empSSORate').value = '5';
  document.getElementById('modalTitle').textContent = 'เพิ่มพนักงาน';
  document.getElementById('employeeModal').classList.add('show');
}

function openEditModal(id) {
  const emp = employees.find(e => e.id === id);
  if (!emp) { showNotification('ไม่พบข้อมูลพนักงาน', 'error'); return; }

  document.getElementById('employeeId').value = emp.id;
  document.getElementById('empBranch').value = emp.branch_id;
  document.getElementById('empName').value = emp.full_name;
  document.getElementById('empPosition').value = emp.position || '';
  document.getElementById('empPhone').value = emp.phone || '';
  document.getElementById('empIdCard').value = emp.id_card || '';
  document.getElementById('empAddress').value = emp.address || '';
  document.getElementById('empSalary').value = emp.salary || '';
  document.getElementById('empDailyWage').value = emp.daily_wage || '';
  document.getElementById('empSSONumber').value = emp.social_security_number || '';
  document.getElementById('empSSORate').value = emp.social_security_rate || '5';
  document.getElementById('empStartDate').value = emp.start_date || '';
  document.getElementById('empEndDate').value = emp.end_date || '';
  document.getElementById('empStatus').value = emp.status || 'active';
  document.getElementById('empNotes').value = emp.notes || '';

  document.getElementById('modalTitle').textContent = 'แก้ไขข้อมูลพนักงาน';
  document.getElementById('employeeModal').classList.add('show');
}

function closeModal() {
  document.getElementById('employeeModal').classList.remove('show');
}

async function saveEmployee() {
  const id = document.getElementById('employeeId').value;
  const branchId = document.getElementById('empBranch').value;
  const name = document.getElementById('empName').value.trim();

  if (!branchId) { showNotification('กรุณาเลือกสาขา', 'error'); return; }
  if (!name) { showNotification('กรุณากรอกชื่อ-นามสกุล', 'error'); return; }

  const data = {
    branch_id: branchId,
    full_name: name,
    position: document.getElementById('empPosition').value.trim() || null,
    phone: document.getElementById('empPhone').value.trim() || null,
    id_card: document.getElementById('empIdCard').value.trim() || null,
    address: document.getElementById('empAddress').value.trim() || null,
    salary: parseFloat(document.getElementById('empSalary').value) || 0,
    daily_wage: parseFloat(document.getElementById('empDailyWage').value) || 0,
    social_security_number: document.getElementById('empSSONumber').value.trim() || null,
    social_security_rate: parseFloat(document.getElementById('empSSORate').value) || 5,
    start_date: document.getElementById('empStartDate').value || null,
    end_date: document.getElementById('empEndDate').value || null,
    status: document.getElementById('empStatus').value,
    notes: document.getElementById('empNotes').value.trim() || null,
  };

  const saveBtn = document.querySelector('#employeeModal .btn-primary');
  setButtonLoading(saveBtn, true);
  try {
    const res = id
      ? await apiRequest(`employees/employee?id=${id}`, 'PUT', data)
      : await apiRequest('employees', 'POST', data);

    if (res.status === 'success') {
      showNotification('บันทึกข้อมูลพนักงานสำเร็จ', 'success');
      closeModal();
      loadEmployees();
    } else {
      showNotification(res.message || 'ไม่สามารถบันทึกข้อมูลได้', 'error');
    }
  } catch (err) {
    showNotification('เกิดข้อผิดพลาดในการบันทึกข้อมูล', 'error');
  } finally {
    setButtonLoading(saveBtn, false);
  }
}

async function deleteEmployee(id) {
  if (!confirm('คุณแน่ใจหรือไม่ที่จะลบข้อมูลพนักงานนี้?')) return;

  try {
    const res = await apiRequest(`employees/employee?id=${id}`, 'DELETE');
    if (res.status === 'success') {
      showNotification('ลบข้อมูลพนักงานสำเร็จ', 'success');
      loadEmployees();
    } else {
      showNotification(res.message || 'ไม่สามารถลบข้อมูลได้', 'error');
    }
  } catch (err) {
    showNotification('เกิดข้อผิดพลาด', 'error');
  }
}

function openSalaryModal(id) {
  const emp = employees.find(e => e.id === id);
  if (!emp) { showNotification('ไม่พบข้อมูลพนักงาน', 'error'); return; }

  document.getElementById('salaryEmployeeId').value = emp.id;
  document.getElementById('salaryEmpName').textContent = `${emp.full_name}${emp.position ? ' (' + emp.position + ')' : ''}`;

  const now = new Date();
  document.getElementById('salaryMonth').value = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`;

  if (emp.salary > 0) {
    document.getElementById('salaryAmount').value = emp.salary;
    if (emp.social_security_rate > 0 && emp.social_security_number) {
      const ssoEmployee = emp.salary * (emp.social_security_rate / 100);
      document.getElementById('salaryAmount').value = (emp.salary - ssoEmployee).toFixed(2);
      document.getElementById('salarySSOAmount').value = ssoEmployee.toFixed(2);
    }
  } else {
    document.getElementById('salaryAmount').value = '';
    document.getElementById('salarySSOAmount').value = '';
  }

  document.getElementById('salaryModalTitle').textContent = 'บันทึกเงินเดือน';
  document.getElementById('salaryModal').classList.add('show');
}

function fillSalaryAmount() {
  const emp = employees.find(e => e.id == document.getElementById('salaryEmployeeId').value);
  if (emp && emp.salary > 0) {
    let amount = emp.salary;
    if (emp.social_security_rate > 0 && emp.social_security_number) {
      const ssoEmployee = emp.salary * (emp.social_security_rate / 100);
      amount = emp.salary - ssoEmployee;
    }
    document.getElementById('salaryAmount').value = amount.toFixed(2);
  }
}

function closeSalaryModal() {
  document.getElementById('salaryModal').classList.remove('show');
}

async function saveSalaryExpense() {
  const empId = document.getElementById('salaryEmployeeId').value;
  const month = document.getElementById('salaryMonth').value;
  const amount = parseFloat(document.getElementById('salaryAmount').value);
  const ssoAmount = parseFloat(document.getElementById('salarySSOAmount').value) || 0;

  if (!empId) { showNotification('กรุณาเลือกพนักงาน', 'error'); return; }
  if (!month) { showNotification('กรุณาเลือกเดือน', 'error'); return; }
  if (!amount || amount <= 0) { showNotification('กรุณากรอกจำนวนเงิน', 'error'); return; }

  const salaryDate = month + '-01';

  const saveBtn = document.querySelector('#salaryModal .btn-primary');
  setButtonLoading(saveBtn, true);
  try {
    const res = await apiRequest('employees/salary-expense', 'POST', {
      employee_id: empId,
      salary_date: salaryDate,
      amount: amount,
    });

    if (res.status === 'success') {
      showNotification('บันทึกค่าใช้จ่ายเงินเดือนสำเร็จ', 'success');

      if (ssoAmount > 0) {
        await apiRequest('employees/sso-expense', 'POST', {
          employee_id: empId,
          salary_date: salaryDate,
          amount: ssoAmount,
        });
      }

      closeSalaryModal();
      loadEmployees();
    } else {
      showNotification(res.message || 'ไม่สามารถบันทึกค่าใช้จ่ายได้', 'error');
    }
  } catch (err) {
    showNotification('เกิดข้อผิดพลาด', 'error');
  } finally {
    setButtonLoading(saveBtn, false);
  }
}

function formatCurrency(n) {
  if (n == null || isNaN(n)) return '฿0.00';
  return '฿' + Number(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function escapeHtml(text) {
  if (!text) return '';
  const div = document.createElement('div');
  div.textContent = text;
  return div.innerHTML;
}

function showNotification(message, type) {
  if (typeof window.appShowNotification === 'function') {
    window.appShowNotification(message, type || 'info');
  }
}

// Logout
document.getElementById('logoutBtn')?.addEventListener('click', (e) => {
  e.preventDefault();
  if (typeof logout === 'function') { logout(); return; }
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
