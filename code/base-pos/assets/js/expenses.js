// บันทึกค่าใช้จ่ายทันที; รายการ pending เดิมแสดงแบบอ่านอย่างเดียว

let branches = [];
let currentUser = null;
let expenseItems = [];

const EXPENSE_STATUS = {
  pending: ['ค้างจากระบบเดิม', 'badge-warning'],
  approved: ['บันทึกแล้ว', 'badge-success'],
  rejected: ['ปฏิเสธ', 'badge-danger'],
  cancelled: ['ยกเลิกคำขอ', 'badge-secondary'],
};

async function init() {
  currentUser = await requireAuth();
  if (!currentUser) return;

  const branchRes = await apiRequest('branches', 'GET');
  if (branchRes.status !== 'success') {
    showNotification(branchRes.message || 'โหลดข้อมูลสาขาไม่สำเร็จ', 'error');
    return;
  }

  branches = branchRes.data?.items || branchRes.data || [];
  setupBranchSelectors();

  const today = new Date();
  document.getElementById('expDate').value = formatLocalDate(today);
  document.getElementById('expDate').max = formatLocalDate(today);

  const currentYear = today.getFullYear() + 543;
  const yearSelect = document.getElementById('filterYear');
  for (let year = currentYear; year >= currentYear - 3; year -= 1) {
    yearSelect.appendChild(new Option(`พ.ศ. ${year}`, year - 543));
  }
  document.getElementById('filterMonth').value = String(today.getMonth() + 1);

  ['filterStatus', 'filterMonth', 'filterYear', 'filterBranch'].forEach((id) => {
    document.getElementById(id).addEventListener('change', loadExpenses);
  });
  await loadExpenses();
}

function setupBranchSelectors() {
  const requestSelect = document.getElementById('expBranch');
  const filterSelect = document.getElementById('filterBranch');
  const hasMultiBranchAccess = ['admin', 'super_manager'].includes(currentUser.role);

  branches.forEach((branch) => {
    requestSelect.appendChild(new Option(branch.name, branch.id));
    filterSelect.appendChild(new Option(branch.name, branch.id));
  });

  if (!hasMultiBranchAccess) {
    const branchId = String(currentUser.branch_id || '');
    requestSelect.value = branchId;
    requestSelect.disabled = true;
    filterSelect.value = branchId;
    filterSelect.disabled = true;
  }
}

async function loadExpenses() {
  const year = document.getElementById('filterYear').value;
  const month = document.getElementById('filterMonth').value;
  const branchId = document.getElementById('filterBranch').value;

  const params = new URLSearchParams({ period: 'month', year, month });
  if (branchId) params.set('branch_id', branchId);

  showTableLoading('expenseBody', 9, 5);
  const response = await apiRequest(`financial/expenses?${params.toString()}`, 'GET');
  if (response.status !== 'success') {
    showNotification(response.message || 'โหลดค่าใช้จ่ายไม่สำเร็จ', 'error');
    return;
  }

  expenseItems = response.data?.items || [];
  renderTable();
}

function renderTable() {
  const statusFilter = document.getElementById('filterStatus').value;
  const items = statusFilter
    ? expenseItems.filter((expense) => expense.status === statusFilter)
    : expenseItems;
  const tbody = document.getElementById('expenseBody');

  if (!items.length) {
    tbody.innerHTML = '<tr><td colspan="9" class="expense-empty">ไม่มีข้อมูล</td></tr>';
    document.getElementById('totalAmount').textContent = formatCurrency(0);
    return;
  }

  const approvedTotal = items.reduce((sum, expense) => (
    expense.status === 'approved' ? sum + Number(expense.amount || 0) : sum
  ), 0);

  tbody.innerHTML = items.map((expense) => {
    const [statusText, statusClass] = EXPENSE_STATUS[expense.status] || [expense.status || '-', 'badge-secondary'];
    const paymentText = expense.payment_method === 'bank_transfer' ? 'โอนธนาคาร' : 'เงินสด';
    const requesterText = escapeHtml(expense.requested_by_name || '-');
    const approverText = expense.approved_by_name ? escapeHtml(expense.approved_by_name) : '-';
    const peopleText = expense.status === 'pending'
      ? `ข้อมูลเดิม<br>ผู้บันทึก ${requesterText}`
      : `บันทึกโดย ${approverText !== '-' ? approverText : requesterText}`;
    const detailLines = [expense.note, expense.review_note].filter(Boolean).map(escapeHtml);

    return `<tr>
      <td>${escapeHtml(expense.expense_date || '-')}</td>
      <td>${escapeHtml(expense.branch_name || branchName(expense.branch_id))}</td>
      <td>${escapeHtml(expense.category || '-')}</td>
      <td>${escapeHtml(expense.beneficiary_name || '-')}</td>
      <td>${paymentText}</td>
      <td class="text-right">${formatCurrency(expense.amount)}</td>
      <td><span class="badge ${statusClass}">${escapeHtml(statusText)}</span></td>
      <td class="expense-person-cell">${peopleText}</td>
      <td class="expense-detail-cell">${detailLines.length ? detailLines.join('<br>') : '-'}</td>
    </tr>`;
  }).join('');

  document.getElementById('totalAmount').textContent = formatCurrency(approvedTotal);
}

async function saveExpense() {
  const payload = {
    branch_id: Number(document.getElementById('expBranch').value),
    expense_date: document.getElementById('expDate').value,
    category: document.getElementById('expCategory').value,
    amount: Number(document.getElementById('expAmount').value),
    payment_method: document.getElementById('expPaymentMethod').value,
    beneficiary_name: document.getElementById('expBeneficiary').value.trim(),
    note: document.getElementById('expNote').value.trim() || null,
  };

  if (!payload.branch_id || !payload.expense_date || !payload.category || !(payload.amount > 0) || !payload.beneficiary_name) {
    showNotification('กรุณากรอกข้อมูลที่จำเป็นให้ครบ', 'error');
    return;
  }

  const saveButton = document.getElementById('saveExpenseBtn');
  setButtonLoading(saveButton, true);
  try {
    const response = await apiRequest('financial/expenses', 'POST', payload);
    if (response.status !== 'success') {
      showNotification(response.message || 'บันทึกค่าใช้จ่ายไม่สำเร็จ', 'error');
      return;
    }

    showNotification('บันทึกค่าใช้จ่ายแล้ว', 'success');
    document.getElementById('expAmount').value = '';
    document.getElementById('expBeneficiary').value = '';
    document.getElementById('expNote').value = '';
    await loadExpenses();
  } finally {
    setButtonLoading(saveButton, false);
  }
}

function branchName(id) {
  return branches.find((branch) => Number(branch.id) === Number(id))?.name || `สาขา ${id}`;
}

function formatLocalDate(date) {
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, '0');
  const day = String(date.getDate()).padStart(2, '0');
  return `${year}-${month}-${day}`;
}

function escapeHtml(value) {
  return String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

document.addEventListener('DOMContentLoaded', init);
