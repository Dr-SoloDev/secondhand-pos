// financial-summary.js — สรุปธุรกิจ (admin only)

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
    const sel = document.getElementById('branchFilter');
    const expBranch = document.getElementById('expBranch');
    branches.forEach(b => {
      const o1 = new Option(b.name, b.id);
      const o2 = new Option(b.name, b.id);
      sel.appendChild(o1);
      expBranch.appendChild(o2);
    });
  }

  const now = new Date();
  const currentYear = now.getFullYear() + 543;
  const yearSel = document.getElementById('periodYear');
  for (let y = currentYear; y >= currentYear - 3; y--) {
    yearSel.appendChild(new Option(`พ.ศ. ${y}`, y - 543));
  }
  document.getElementById('periodMonth').value = now.getMonth() + 1;
  document.getElementById('expDate').value = now.toISOString().split('T')[0];

  document.getElementById('periodType').addEventListener('change', function() {
    document.getElementById('periodMonth').style.display = this.value === 'month' ? '' : 'none';
  });

  loadSummary();
}

async function loadSummary() {
  const periodType = document.getElementById('periodType').value;
  const year       = document.getElementById('periodYear').value;
  const month      = document.getElementById('periodMonth').value;
  const branchId   = document.getElementById('branchFilter').value;

  let params = `period=${periodType}&year=${year}`;
  if (periodType === 'month') params += `&month=${month}`;
  if (branchId) params += `&branch_id=${branchId}`;

  const [summaryRes, lotsRes, categoryRes, expRes] = await Promise.all([
    apiRequest(`financial/summary?${params}`, 'GET'),
    apiRequest(`financial/lot-revenues?${params}`, 'GET'),
    apiRequest(`financial/purchase-by-category?${params}`, 'GET'),
    apiRequest(`financial/expenses?${params}`, 'GET'),
  ]);

  if (summaryRes.status === 'success') renderCards(summaryRes.data);
  if (lotsRes.status === 'success')    renderLotTable(lotsRes.data?.items || []);
  if (categoryRes.status === 'success') renderCategoryTable(categoryRes.data?.items || []);
  if (expRes.status === 'success')     renderExpenseTable(expRes.data?.items || []);
}

function renderCards(data) {
  const totalPurchase   = parseFloat(data.total_purchase || 0);
  const totalRevenue    = parseFloat(data.total_revenue  || 0);
  const totalLotExp     = parseFloat(data.total_expenses || 0);
  const totalBizExp     = parseFloat(data.total_biz_expenses || 0);
  const netProfit       = totalRevenue - totalPurchase - totalLotExp - totalBizExp;
  const margin          = totalRevenue > 0 ? ((netProfit / totalRevenue) * 100).toFixed(1) : 0;

  document.getElementById('totalPurchase').textContent    = formatCurrency(totalPurchase);
  document.getElementById('totalPurchaseKg').textContent  = `${parseFloat(data.total_kg || 0).toLocaleString('th-TH')} กก.`;
  document.getElementById('totalRevenue').textContent     = formatCurrency(totalRevenue);
  document.getElementById('totalLots').textContent        = `${data.total_lots || 0} Lot`;
  document.getElementById('totalExpenses').textContent    = formatCurrency(totalLotExp);
  document.getElementById('totalBizExpenses').textContent = formatCurrency(totalBizExp);

  const profitEl = document.getElementById('netProfit');
  profitEl.textContent = (netProfit >= 0 ? '+' : '') + formatCurrency(netProfit);
  profitEl.className = 'value ' + (netProfit >= 0 ? 'income' : 'expense');
  document.getElementById('profitMargin').textContent = `margin ${margin}%`;
}

function renderExpenseTable(items) {
  const tbody = document.getElementById('bizExpenseBody');
  if (!items.length) {
    tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;color:#888;padding:24px">ไม่มีข้อมูล</td></tr>';
    return;
  }
  tbody.innerHTML = items.map(e => {
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
}

function toggleExpenseForm() {
  const wrap = document.getElementById('expenseFormWrap');
  wrap.style.display = wrap.style.display === 'none' ? '' : 'none';
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
    loadSummary();
  } else {
    showNotification(res.message || 'เกิดข้อผิดพลาด', 'error');
  }
}

async function deleteExpense(id) {
  if (!confirm('ลบรายการนี้?')) return;
  const res = await apiRequest(`financial/expenses?id=${id}`, 'DELETE');
  if (res.status === 'success') {
    showNotification('ลบแล้ว', 'success');
    loadSummary();
  }
}

function renderLotTable(items) {
  const tbody = document.getElementById('lotRevenueBody');
  if (!items.length) {
    tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;color:#888;padding:24px">ไม่มีข้อมูล</td></tr>';
    return;
  }
  tbody.innerHTML = items.map(lot => {
    const revenue = parseFloat(lot.actual_revenue || 0);
    const cost    = parseFloat(lot.total_cost || 0);
    const profit  = revenue - cost;
    const cls     = profit >= 0 ? 'profit-positive' : 'profit-negative';
    const branchName = branches.find(b => b.id == lot.branch_id)?.name || `สาขา ${lot.branch_id}`;
    return `<tr>
      <td style="font-family:monospace;font-size:12px">${escapeHtml(lot.reference_no || '')}</td>
      <td>${lot.actual_revenue_date || lot.sale_date || '-'}</td>
      <td>${escapeHtml(branchName)}</td>
      <td class="text-right" style="color:var(--color-danger)">${formatCurrency(cost)}</td>
      <td class="text-right" style="color:var(--color-success)">${revenue > 0 ? formatCurrency(revenue) : '<span style="color:#aaa;font-size:12px">ยังไม่บันทึก</span>'}</td>
      <td style="font-size:12px;color:var(--color-text-light)">${escapeHtml(lot.actual_revenue_note || '-')}</td>
      <td class="text-right"><span class="${cls}">${profit >= 0 ? '+' : ''}${formatCurrency(profit)}</span></td>
    </tr>`;
  }).join('');
}

function renderCategoryTable(items) {
  const tbody = document.getElementById('categoryBody');
  if (!items.length) {
    tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;color:#888;padding:24px">ไม่มีข้อมูล</td></tr>';
    return;
  }
  tbody.innerHTML = items.map(row => `<tr>
    <td>${escapeHtml(row.category_name || '-')}</td>
    <td class="text-right">${parseFloat(row.total_kg || 0).toLocaleString('th-TH', {minimumFractionDigits: 2})}</td>
    <td class="text-right">${row.total_items || 0}</td>
    <td class="text-right" style="color:var(--color-danger)">${formatCurrency(row.total_amount)}</td>
  </tr>`).join('');
}

function escapeHtml(str) {
  return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

async function exportCsv(type) {
  const periodType = document.getElementById('periodType').value;
  const year       = document.getElementById('periodYear').value;
  const month      = document.getElementById('periodMonth').value;
  const branchId   = document.getElementById('branchFilter').value;
  let params = `type=${type}&period=${periodType}&year=${year}`;
  if (periodType === 'month') params += `&month=${month}`;
  if (branchId) params += `&branch_id=${branchId}`;

  const token = localStorage.getItem('posToken');
  const res = await fetch(`${window.apiPath}/financial/export?${params}`, {
    headers: { 'Authorization': `Bearer ${token}` }
  });
  if (!res.ok) { showNotification('export ไม่สำเร็จ', 'error'); return; }
  const blob = await res.blob();
  const cd = res.headers.get('Content-Disposition') || '';
  const filename = cd.match(/filename="([^"]+)"/)?.[1] || `export_${type}.csv`;
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = filename;
  document.body.appendChild(a);
  a.click();
  a.remove();
  URL.revokeObjectURL(a.href);
}

document.addEventListener('DOMContentLoaded', init);
document.getElementById('logoutBtn')?.addEventListener('click', (e) => {
  e.preventDefault();
  localStorage.removeItem('posToken');
  localStorage.removeItem('posUser');
  window.location.href = '../index.html';
});
