// Expense request and approval workflow.

let branches = [];
let currentUser = null;
let expenseItems = [];
let reviewExpenseId = null;

const EXPENSE_STATUS = {
  pending: ['รออนุมัติ', 'badge-warning'],
  approved: ['อนุมัติแล้ว', 'badge-success'],
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

  showTableLoading('expenseBody', 10, 5);
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
    tbody.innerHTML = '<tr><td colspan="10" class="expense-empty">ไม่มีข้อมูล</td></tr>';
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
    const detailLines = [expense.note, expense.review_note].filter(Boolean).map(escapeHtml);

    return `<tr>
      <td>${escapeHtml(expense.expense_date || '-')}</td>
      <td>${escapeHtml(expense.branch_name || branchName(expense.branch_id))}</td>
      <td>${escapeHtml(expense.category || '-')}</td>
      <td>${escapeHtml(expense.beneficiary_name || '-')}</td>
      <td>${paymentText}</td>
      <td class="text-right">${formatCurrency(expense.amount)}</td>
      <td><span class="badge ${statusClass}">${escapeHtml(statusText)}</span></td>
      <td class="expense-person-cell">ขอโดย ${requesterText}<br>พิจารณาโดย ${approverText}</td>
      <td class="expense-detail-cell">${detailLines.length ? detailLines.join('<br>') : '-'}</td>
      <td><div class="expense-actions">${renderExpenseActions(expense)}</div></td>
    </tr>`;
  }).join('');

  document.getElementById('totalAmount').textContent = formatCurrency(approvedTotal);
}

function renderExpenseActions(expense) {
  if (expense.status !== 'pending') return '';

  const userId = Number(currentUser.user_id || currentUser.id);
  const isRequester = Number(expense.requested_by) === userId;
  if (isRequester) {
    return `<button class="btn btn-sm btn-secondary" type="button" onclick="cancelExpense(${Number(expense.id)})">ยกเลิกคำขอ</button>`;
  }

  if (!canApproveAmount(Number(expense.amount))) return '';
  return `<button class="btn btn-sm btn-primary" type="button" onclick="openExpenseReviewModal(${Number(expense.id)})">พิจารณา</button>`;
}

function canApproveAmount(amount) {
  const role = currentUser.role;
  if (amount <= 500) return ['manager', 'super_manager', 'admin'].includes(role);
  if (amount <= 5000) return ['super_manager', 'admin'].includes(role);
  return role === 'admin';
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
      showNotification(response.message || 'ส่งคำขอไม่สำเร็จ', 'error');
      return;
    }

    showNotification('ส่งคำขอรายจ่ายแล้ว', 'success');
    document.getElementById('expAmount').value = '';
    document.getElementById('expBeneficiary').value = '';
    document.getElementById('expNote').value = '';
    await loadExpenses();
  } finally {
    setButtonLoading(saveButton, false);
  }
}

async function cancelExpense(id) {
  if (!confirm('ยืนยันการยกเลิกคำขอนี้?')) return;
  const response = await apiRequest(`financial/expenses?id=${id}`, 'DELETE');
  if (response.status === 'success') {
    showNotification('ยกเลิกคำขอแล้ว', 'success');
    await loadExpenses();
  } else {
    showNotification(response.message || 'ยกเลิกคำขอไม่สำเร็จ', 'error');
  }
}

function openExpenseReviewModal(id) {
  const expense = expenseItems.find((item) => Number(item.id) === Number(id));
  if (!expense) return;

  reviewExpenseId = Number(id);
  document.getElementById('expenseReviewSummary').innerHTML = `
    <strong>${escapeHtml(expense.category)}</strong> ${formatCurrency(expense.amount)}<br>
    ผู้รับเงิน: ${escapeHtml(expense.beneficiary_name || '-')}<br>
    วิธีจ่าย: ${expense.payment_method === 'bank_transfer' ? 'โอนธนาคาร' : 'เงินสด'}
  `;
  document.getElementById('expenseReviewNote').value = '';
  document.getElementById('expenseReviewError').textContent = '';
  document.getElementById('expenseReviewModal').classList.add('show');
  document.getElementById('expenseReviewNote').focus();
}

function closeExpenseReviewModal() {
  reviewExpenseId = null;
  document.getElementById('expenseReviewModal').classList.remove('show');
}

async function submitExpenseReview(action) {
  if (!reviewExpenseId) return;
  const note = document.getElementById('expenseReviewNote').value.trim();
  const errorElement = document.getElementById('expenseReviewError');
  if (action === 'reject' && !note) {
    errorElement.textContent = 'กรุณาระบุเหตุผลที่ปฏิเสธ';
    return;
  }

  const button = document.getElementById(action === 'approve' ? 'approveExpenseBtn' : 'rejectExpenseBtn');
  setButtonLoading(button, true);
  try {
    const response = await apiRequest(`financial/expenses/${action}`, 'POST', {
      id: reviewExpenseId,
      review_note: note || null,
    });
    if (response.status !== 'success') {
      errorElement.textContent = response.message || 'บันทึกผลการพิจารณาไม่สำเร็จ';
      return;
    }

    showNotification(action === 'approve' ? 'อนุมัติและบันทึกการจ่ายแล้ว' : 'ปฏิเสธคำขอแล้ว', 'success');
    closeExpenseReviewModal();
    await loadExpenses();
  } finally {
    setButtonLoading(button, false);
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
