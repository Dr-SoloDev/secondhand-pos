// expenses.js — ค่าใช้จ่ายประจำร้าน

let branches = [];

async function init() {
  const user = await requireAuth();
  if (!user) return;
  document.getElementById('userName').textContent = user.username || '-';

  if (user.role !== 'admin') {
    document.querySelectorAll('.admin-only').forEach(el => el.style.display = 'none');
  }

  const res = await apiRequest('branches', 'GET');
  if (res.status === 'success') {
    branches = res.data?.items || res.data || [];
    const sel = document.getElementById('expBranch');
    branches.forEach(b => sel.appendChild(new Option(b.name, b.id)));
  }

  document.getElementById('expDate').value = new Date().toISOString().split('T')[0];

  const now = new Date();
  const currentYear = now.getFullYear() + 543;
  const yearSel = document.getElementById('filterYear');
  for (let y = currentYear; y >= currentYear - 3; y--) {
    yearSel.appendChild(new Option(`พ.ศ. ${y}`, y - 543));
  }
  document.getElementById('filterMonth').value = now.getMonth() + 1;

  loadExpenses();
}

async function loadExpenses() {
  const year     = document.getElementById('filterYear').value;
  const month    = document.getElementById('filterMonth').value;
  const branchId = document.getElementById('filterBranch').value;

  let params = `period=month&year=${year}&month=${month}`;
  if (branchId) params += `&branch_id=${branchId}`;

  const res = await apiRequest(`financial/expenses?${params}`, 'GET');
  if (res.status === 'success') renderTable(res.data?.items || []);
}

function renderTable(items) {
  const tbody = document.getElementById('expenseBody');
  if (!items.length) {
    tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;color:#888;padding:24px">ไม่มีข้อมูล</td></tr>';
    return;
  }
  let total = 0;
  tbody.innerHTML = items.map(e => {
    total += parseFloat(e.amount || 0);
    const branchName = branches.find(b => b.id == e.branch_id)?.name || `สาขา ${e.branch_id}`;
    return `<tr>
      <td>${e.expense_date || '-'}</td>
      <td>${escapeHtml(branchName)}</td>
      <td>${escapeHtml(e.category)}</td>
      <td class="text-right" style="color:var(--color-danger)">${formatCurrency(e.amount)}</td>
      <td style="font-size:12px;color:var(--color-text-light)">${escapeHtml(e.note || '-')}</td>
      <td><button class="btn btn-sm" style="color:var(--color-danger);background:none;border:none;cursor:pointer;font-size:12px" onclick="deleteExpense(${e.id})">ลบ</button></td>
    </tr>`;
  }).join('');
  document.getElementById('totalAmount').textContent = formatCurrency(total);
}

async function saveExpense() {
  const branchId = document.getElementById('expBranch').value;
  const date     = document.getElementById('expDate').value;
  const category = document.getElementById('expCategory').value;
  const amount   = parseFloat(document.getElementById('expAmount').value);
  const note     = document.getElementById('expNote').value.trim();

  if (!branchId || !date || !category || !(amount > 0)) {
    showNotification('กรุณากรอกข้อมูลให้ครบ', 'error');
    return;
  }

  const res = await apiRequest('financial/expenses', 'POST', { branch_id: parseInt(branchId), expense_date: date, category, amount, note });
  if (res.status === 'success') {
    showNotification('บันทึกแล้ว', 'success');
    document.getElementById('expAmount').value = '';
    document.getElementById('expNote').value = '';
    loadExpenses();
  } else {
    showNotification(res.message || 'เกิดข้อผิดพลาด', 'error');
  }
}

async function deleteExpense(id) {
  if (!confirm('ลบรายการนี้?')) return;
  const res = await apiRequest(`financial/expenses?id=${id}`, 'DELETE');
  if (res.status === 'success') {
    showNotification('ลบแล้ว', 'success');
    loadExpenses();
  }
}

function escapeHtml(str) {
  return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

document.addEventListener('DOMContentLoaded', init);
