let currentUser = null;
let branches = [];
let currentBranchId = null;
let currentBranchName = '';
let currentReportMonth = '';

const reportState = {
  summary: null,
  purchaseOrders: [],
  purchaseItems: [],
  saleLots: [],
  inventory: {
    categories: [],
    alerts: [],
    alertSummary: null,
  },
  employees: [],
  tax: null,
};

document.addEventListener('DOMContentLoaded', async () => {
  await initReports();
});

async function initReports() {
  currentUser = await requireAuth();
  if (!currentUser) {
    return;
  }

  if (!['admin', 'manager', 'super_manager'].includes(currentUser.role)) {
    showNotification('หน้านี้สำหรับผู้จัดการสาขาเท่านั้น', 'error');
    window.location.href = 'index.html';
    return;
  }

  if (currentUser.role !== 'admin' && currentUser.role !== 'super_manager') {
    document.querySelectorAll('.admin-only').forEach((el) => {
      el.style.display = 'none';
    });
  }

  const isFullAccess = ['admin', 'super_manager'].includes(currentUser.role);
  currentBranchId = isFullAccess ? '' : (currentUser.branch_id ? String(currentUser.branch_id) : '');
  if (!isFullAccess && !currentBranchId) {
    showNotification('ไม่พบสาขาที่ผูกกับบัญชีนี้', 'error');
    window.location.href = 'index.html';
    return;
  }

  await loadBranchLookup();
  setupBranchSelector();
  setDefaultMonth();
  setDefaultDailyExportDate();
  setDefaultTaxDates();
  bindEvents();
  updateScopeLabels();
  setLoadingState();
  await Promise.all([
    loadMonthlyReports(),
    loadInventoryReport(),
    loadEmployeesReport(),
    loadTaxReport(),
  ]);
}

function bindEvents() {
  document.getElementById('reportMonth')?.addEventListener('change', async function() {
    currentReportMonth = this.value || getCurrentMonthValue();
    setDefaultTaxDates();
    updateScopeLabels();
    await reloadAllReports();
  });

  document.getElementById('reportBranch')?.addEventListener('change', async function() {
    currentBranchId = this.value || '';
    updateCurrentBranchName();
    updateScopeLabels();
    setLoadingState();
    await reloadAllReports();
  });

  document.getElementById('generateTaxReport')?.addEventListener('click', loadTaxReport);

  document.addEventListener('click', handleReportActionClick);
}

async function loadBranchLookup() {
  try {
    const res = await apiRequest('branches/active');
    if (res.status === 'success') {
      branches = normalizeBranchList(res.data);
    }
  } catch (error) {
    console.error('Failed to load branches:', error);
  }

  updateCurrentBranchName();
}

function normalizeBranchList(data) {
  if (Array.isArray(data)) {
    return data;
  }

  if (Array.isArray(data?.items)) {
    return data.items;
  }

  return [];
}

function setupBranchSelector() {
  const select = document.getElementById('reportBranch');
  const field = document.getElementById('reportBranchField');
  if (!select) {
    return;
  }

  const isFullAccess = canViewAllBranches();
  let visibleBranches = isFullAccess
    ? branches
    : branches.filter((branch) => String(branch.id) === currentBranchId);

  if (!isFullAccess && visibleBranches.length === 0 && currentBranchId) {
    visibleBranches = [{
      id: currentBranchId,
      name: currentUser.branch_name || `สาขา ${currentBranchId}`,
    }];
  }

  select.innerHTML = isFullAccess
    ? '<option value="">ทุกสาขา</option>'
    : '';

  visibleBranches.forEach((branch) => {
    select.appendChild(new Option(branch.name || `สาขา ${branch.id}`, String(branch.id)));
  });

  select.value = currentBranchId || '';
  select.disabled = !isFullAccess;
  if (field) {
    field.hidden = false;
  }
  updateCurrentBranchName();
}

function canViewAllBranches() {
  return ['admin', 'super_manager'].includes(currentUser?.role);
}

function updateCurrentBranchName() {
  if (!currentBranchId) {
    currentBranchName = 'ทุกสาขา';
    return;
  }

  const branch = branches.find((item) => String(item.id) === String(currentBranchId));
  currentBranchName = branch?.name || currentUser.branch_name || `สาขา ${currentBranchId}`;
}

function addBranchParam(params) {
  if (currentBranchId) {
    params.set('branch_id', currentBranchId);
  }
  return params;
}

function getScopeSlug() {
  return currentBranchId ? `branch-${currentBranchId}` : 'all-branches';
}

function getReportPeriodText(key) {
  if (key === 'tax') {
    const dateFrom = document.getElementById('taxDateFrom')?.value || getMonthRange(currentReportMonth).dateFrom;
    const dateTo = document.getElementById('taxDateTo')?.value || getMonthRange(currentReportMonth).dateTo;
    return `${formatDisplayDate(dateFrom)} - ${formatDisplayDate(dateTo)}`;
  }

  return getMonthRange(currentReportMonth).label;
}

async function reloadAllReports() {
  await Promise.all([
    loadMonthlyReports(),
    loadInventoryReport(),
    loadEmployeesReport(),
    loadTaxReport(),
  ]);
}

function getCurrentMonthValue() {
  const now = new Date();
  const month = String(now.getMonth() + 1).padStart(2, '0');
  return `${now.getFullYear()}-${month}`;
}

function setDefaultMonth() {
  const monthInput = document.getElementById('reportMonth');
  currentReportMonth = monthInput?.value || getCurrentMonthValue();
  if (monthInput) {
    monthInput.value = currentReportMonth;
  }
}

function setDefaultDailyExportDate() {
  const el = document.getElementById('dailyExportDate');
  if (el) el.value = new Date().toISOString().slice(0, 10);
}

function setDefaultTaxDates() {
  const { dateFrom, dateTo } = getMonthRange(currentReportMonth || getCurrentMonthValue());
  const fromInput = document.getElementById('taxDateFrom');
  const toInput = document.getElementById('taxDateTo');
  if (fromInput) fromInput.value = dateFrom;
  if (toInput) toInput.value = dateTo;
  const periodInput = document.getElementById('taxPeriod');
  if (periodInput && !periodInput.value) {
    periodInput.value = 'daily';
  }
}

function getMonthRange(monthValue) {
  const value = monthValue || getCurrentMonthValue();
  const [yearPart, monthPart] = value.split('-').map((part) => parseInt(part, 10));
  const year = Number.isFinite(yearPart) ? yearPart : new Date().getFullYear();
  const monthIndex = Number.isFinite(monthPart) ? monthPart - 1 : new Date().getMonth();

  const start = new Date(year, monthIndex, 1);
  const end = new Date(year, monthIndex + 1, 0);

  return {
    year,
    month: monthIndex + 1,
    dateFrom: formatDateForInput(start),
    dateTo: formatDateForInput(end),
    label: formatMonthLabel(year, monthIndex),
  };
}

function formatDateForInput(date) {
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, '0');
  const day = String(date.getDate()).padStart(2, '0');
  return `${year}-${month}-${day}`;
}

function formatMonthLabel(year, monthIndex) {
  const date = new Date(year, monthIndex, 1);
  return new Intl.DateTimeFormat('th-TH', {
    month: 'long',
    year: 'numeric',
  }).format(date);
}

function updateScopeLabels() {
  const purchaseScope = document.getElementById('purchaseOrdersScope');
  const purchaseItemsScope = document.getElementById('purchaseItemsScope');
  const saleScope = document.getElementById('saleLotsScope');
  const employeesScope = document.getElementById('employeesScope');
  const inventoryScope = document.getElementById('inventoryScope');
  const taxScope = document.getElementById('taxScope');
  const monthRange = getMonthRange(currentReportMonth);

  if (purchaseScope) purchaseScope.textContent = `${currentBranchName} · ${monthRange.label}`;
  if (purchaseItemsScope) purchaseItemsScope.textContent = `${currentBranchName} · ${monthRange.label}`;
  if (saleScope) saleScope.textContent = `${currentBranchName} · ${monthRange.label}`;
  if (employeesScope) employeesScope.textContent = currentBranchName;
  if (inventoryScope) inventoryScope.textContent = currentBranchName;
  if (taxScope) taxScope.textContent = `${currentBranchName} · จากข้อมูลที่เลือก`;
}

function setLoadingState() {
  setSummaryLoading();
  showTableLoading('summaryBreakdownBody', 2, 4);
  showTableLoading('purchaseOrdersBody', 6, 5);
  showTableLoading('purchaseItemsBody', 8, 5);
  showTableLoading('saleLotsBody', 8, 5);
  showTableLoading('inventoryCategoriesBody', 4, 5);
  showTableLoading('inventoryAlertsBody', 4, 5);
  showTableLoading('employeesBody', 6, 5);
  showTableLoading('taxReportBody', 4, 5);
}

function setSummaryLoading() {
  document.getElementById('summaryPurchase').textContent = '...';
  document.getElementById('summaryPurchaseKg').textContent = '...';
  document.getElementById('summaryRevenue').textContent = '...';
  document.getElementById('summaryLots').textContent = '...';
  document.getElementById('summaryExpenses').textContent = '...';
  document.getElementById('summaryExpenseNote').textContent = '...';
  document.getElementById('summaryNetProfit').textContent = '...';
  document.getElementById('summaryMargin').textContent = '...';
}

async function loadMonthlyReports() {
  const range = getMonthRange(currentReportMonth);

  await Promise.all([
    loadBranchSummary(range),
    loadPurchaseOrders(range),
    loadPurchaseItems(range),
    loadSaleLots(range),
  ]);
}

async function loadBranchSummary(range) {
  try {
    const params = addBranchParam(new URLSearchParams({
      period: 'month',
      year: String(range.year),
      month: String(range.month),
    }));
    const res = await apiRequest(`financial/summary?${params.toString()}`);
    if (res.status !== 'success') {
      throw new Error(res.message || 'โหลดสรุปสาขาไม่สำเร็จ');
    }

    const data = res.data || {};
    const summary = normalizeSummaryData(data, range);
    reportState.summary = summary;
    renderBranchSummary(summary);
  } catch (error) {
    console.error('Failed to load branch summary:', error);
    renderErrorRow('summaryBreakdownBody', 2, 'โหลดข้อมูลสรุปไม่สำเร็จ');
  }
}

function normalizeSummaryData(data, range) {
  const totalPurchase = parseFloat(data.total_purchase || 0);
  const totalKg = parseFloat(data.total_kg || 0);
  const totalRevenue = parseFloat(data.total_revenue || 0);
  const totalLots = parseInt(data.total_lots || 0, 10);
  const totalExpenses = parseFloat(data.total_expenses || 0);
  const totalBizExpenses = parseFloat(data.total_biz_expenses || 0);
  const netProfit = totalRevenue - totalPurchase - totalExpenses - totalBizExpenses;
  const margin = totalRevenue > 0 ? (netProfit / totalRevenue) * 100 : 0;

  return {
    periodLabel: range.label,
    totalPurchase,
    totalKg,
    totalRevenue,
    totalLots,
    totalExpenses,
    totalBizExpenses,
    netProfit,
    margin,
  };
}

function renderBranchSummary(summary) {
  document.getElementById('summaryPurchase').textContent = formatCurrency(summary.totalPurchase);
  document.getElementById('summaryPurchaseKg').textContent = `${summary.totalKg.toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 3 })} กก.`;
  document.getElementById('summaryRevenue').textContent = formatCurrency(summary.totalRevenue);
  document.getElementById('summaryLots').textContent = `${summary.totalLots.toLocaleString('th-TH')} Lot`;
  document.getElementById('summaryExpenses').textContent = formatCurrency(summary.totalExpenses + summary.totalBizExpenses);
  document.getElementById('summaryExpenseNote').textContent = `Lot ${formatCurrency(summary.totalExpenses)} + สาขา ${formatCurrency(summary.totalBizExpenses)}`;

  const profitValue = document.getElementById('summaryNetProfit');
  profitValue.textContent = `${summary.netProfit >= 0 ? '+' : ''}${formatCurrency(summary.netProfit)}`;
  profitValue.className = `report-kpi-value ${summary.netProfit >= 0 ? 'report-kpi-income' : 'report-kpi-expense'}`;
  document.getElementById('summaryMargin').textContent = `margin ${summary.margin.toFixed(1)}%`;

  const rows = [
    ['ยอดรับซื้อของ', formatCurrency(summary.totalPurchase)],
    ['น้ำหนักรับซื้อ', `${summary.totalKg.toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 3 })} กก.`],
    ['รายรับขาย Lot', formatCurrency(summary.totalRevenue)],
    ['ค่าใช้จ่ายต่อ Lot', formatCurrency(summary.totalExpenses)],
    ['ค่าใช้จ่ายประจำสาขา', formatCurrency(summary.totalBizExpenses)],
    ['กำไรสุทธิ', `${summary.netProfit >= 0 ? '+' : ''}${formatCurrency(summary.netProfit)}`],
  ];

  renderTableBody('summaryBreakdownBody', rows.map(([label, value]) => `
    <tr>
      <td>${escapeHtml(label)}</td>
      <td class="text-right ${label === 'กำไรสุทธิ' ? (summary.netProfit >= 0 ? 'report-kpi-income' : 'report-kpi-expense') : ''}">${escapeHtml(value)}</td>
    </tr>
  `).join(''));
}

async function loadPurchaseOrders(range) {
  try {
    const params = new URLSearchParams({
      date_from: range.dateFrom,
      date_to: range.dateTo,
      limit: '1000',
    });
    addBranchParam(params);
    const res = await apiRequest(`purchase-orders?${params.toString()}`);
    if (res.status !== 'success') {
      throw new Error(res.message || 'โหลดข้อมูลรับซื้อไม่สำเร็จ');
    }

    const items = res.data?.items || [];
    reportState.purchaseOrders = items;
    renderPurchaseOrders(items, res.data?.pagination || null, range);
  } catch (error) {
    console.error('Failed to load purchase orders:', error);
    renderErrorRow('purchaseOrdersBody', 6, 'โหลดข้อมูลรับซื้อไม่สำเร็จ');
  }
}

function renderPurchaseOrders(items, pagination, range) {
  const count = items.length;
  const total = items.reduce((sum, item) => sum + parseFloat(item.total_amount || 0), 0);
  const sellers = new Set(items.map((item) => item.seller_name || item.seller_id || item.seller_id_card).filter(Boolean));
  const avg = count > 0 ? total / count : 0;

  document.getElementById('purchaseOrdersCount').textContent = count.toLocaleString('th-TH');
  document.getElementById('purchaseOrdersTotal').textContent = formatCurrency(total);
  document.getElementById('purchaseOrdersAverage').textContent = `เฉลี่ย ${formatCurrency(avg)}`;
  document.getElementById('purchaseOrdersSellers').textContent = sellers.size.toLocaleString('th-TH');

  const note = pagination && pagination.total > count
    ? `${count.toLocaleString('th-TH')} จาก ${pagination.total.toLocaleString('th-TH')} รายการ`
    : `${count.toLocaleString('th-TH')} รายการในช่วง ${range.label}`;
  document.getElementById('purchaseOrdersScope').textContent = `${currentBranchName} · ${note}`;

  if (count === 0) {
    renderErrorRow('purchaseOrdersBody', 6, 'ไม่มีข้อมูลรับซื้อในช่วงที่เลือก');
    return;
  }

  renderTableBody('purchaseOrdersBody', items.map((item) => {
    const statusClass = getStatusClass(item.status);
    return `
      <tr>
        <td style="font-family:monospace">${escapeHtml(item.reference_no || `PO-${item.id}`)}</td>
        <td>${escapeHtml(formatDisplayDate(item.created_at || item.sale_date))}</td>
        <td>${escapeHtml(item.seller_name || '-')}</td>
        <td class="text-right">${formatCurrency(item.total_amount || 0)}</td>
        <td>${escapeHtml(formatPaymentMethod(item.payment_method))}</td>
        <td><span class="report-badge ${statusClass.className}">${escapeHtml(statusClass.label)}</span></td>
      </tr>
    `;
  }).join(''));
}

async function loadPurchaseItems(range) {
  try {
    const params = new URLSearchParams({
      date_from: range.dateFrom,
      date_to: range.dateTo,
    });
    addBranchParam(params);
    const res = await apiRequest(`reports/purchase-items?${params.toString()}`);
    if (res.status !== 'success') {
      throw new Error(res.message || 'โหลดสรุปการรับซื้อแยกสินค้าไม่สำเร็จ');
    }

    const data = res.data || {};
    const items = data.items || [];
    reportState.purchaseItems = items;
    renderPurchaseItems(data, range);
  } catch (error) {
    console.error('Failed to load purchase items:', error);
    renderErrorRow('purchaseItemsBody', 8, 'โหลดสรุปการรับซื้อแยกสินค้าไม่สำเร็จ');
  }
}

function renderPurchaseItems(data, range) {
  const items = Array.isArray(data?.items) ? data.items : [];
  const totals = data?.totals || {};
  const count = items.length;
  const totalBills = parseInt(totals.total_bills || 0, 10);
  const totalWeight = parseFloat(totals.total_net_quantity || 0);
  const totalAmount = parseFloat(totals.total_amount || 0);

  document.getElementById('purchaseItemsCount').textContent = count.toLocaleString('th-TH');
  document.getElementById('purchaseItemsBills').textContent = totalBills.toLocaleString('th-TH');
  document.getElementById('purchaseItemsTotal').textContent = formatCurrency(totalAmount);
  document.getElementById('purchaseItemsWeight').textContent = `${totalWeight.toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 3 })} กก.`;
  document.getElementById('purchaseItemsScope').textContent = `${currentBranchName} · ${range.label}`;

  if (count === 0) {
    renderErrorRow('purchaseItemsBody', 8, 'ไม่มีข้อมูลรับซื้อแยกสินค้าในช่วงที่เลือก');
    return;
  }

  renderTableBody('purchaseItemsBody', items.map((item) => `
    <tr>
      <td>${escapeHtml(formatDisplayDate(item.purchase_date || ''))}</td>
      <td>${escapeHtml(item.item_name || '-')}</td>
      <td>${escapeHtml(item.category_name || '-')}</td>
      <td class="text-right">${parseFloat(item.net_quantity || 0).toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 3 })}</td>
      <td class="text-right">${formatCurrency(item.weighted_avg_unit_price || 0)}</td>
      <td class="text-right">${formatCurrency(item.total_amount || 0)}</td>
      <td>${escapeHtml(item.unit || '-')}</td>
      <td class="text-right">${parseInt(item.bill_count || 0, 10).toLocaleString('th-TH')}</td>
    </tr>
  `).join(''));
}

async function loadSaleLots(range) {
  try {
    const params = new URLSearchParams({
      date_from: range.dateFrom,
      date_to: range.dateTo,
      limit: '1000',
    });
    addBranchParam(params);
    const res = await apiRequest(`sale-lots?${params.toString()}`);
    if (res.status !== 'success') {
      throw new Error(res.message || 'โหลดข้อมูลขาย Lot ไม่สำเร็จ');
    }

    const items = res.data?.items || [];
    reportState.saleLots = items;
    renderSaleLots(items, res.data?.pagination || null, range);
  } catch (error) {
    console.error('Failed to load sale lots:', error);
    renderErrorRow('saleLotsBody', 8, 'โหลดข้อมูลขาย Lot ไม่สำเร็จ');
  }
}

function renderSaleLots(items, pagination, range) {
  const count = items.length;
  const revenue = items.reduce((sum, item) => sum + parseFloat(item.actual_revenue || 0), 0);
  const cost = items.reduce((sum, item) => sum + parseFloat(item.total_cost || 0), 0);
  const profit = revenue - cost;
  const avg = count > 0 ? revenue / count : 0;

  document.getElementById('saleLotsCount').textContent = count.toLocaleString('th-TH');
  document.getElementById('saleLotsRevenue').textContent = formatCurrency(revenue);
  document.getElementById('saleLotsAverage').textContent = `เฉลี่ย ${formatCurrency(avg)}`;
  document.getElementById('saleLotsProfit').textContent = `${profit >= 0 ? '+' : ''}${formatCurrency(profit)}`;
  document.getElementById('saleLotsProfit').className = `report-kpi-value ${profit >= 0 ? 'report-kpi-income' : 'report-kpi-expense'}`;
  document.getElementById('saleLotsCost').textContent = `ต้นทุน ${formatCurrency(cost)}`;

  const note = pagination && pagination.total > count
    ? `${count.toLocaleString('th-TH')} จาก ${pagination.total.toLocaleString('th-TH')} รายการ`
    : `${count.toLocaleString('th-TH')} Lot ในช่วง ${range.label}`;
  document.getElementById('saleLotsScope').textContent = `${currentBranchName} · ${note}`;

  if (count === 0) {
    renderErrorRow('saleLotsBody', 8, 'ไม่มีข้อมูลขาย Lot ในช่วงที่เลือก');
    return;
  }

  renderTableBody('saleLotsBody', items.map((item) => {
    const revenueValue = parseFloat(item.actual_revenue || 0);
    const costValue = parseFloat(item.total_cost || 0);
    const profitValue = revenueValue - costValue;
    const statusClass = getStatusClass(item.status);

    return `
      <tr>
        <td style="font-family:monospace">${escapeHtml(item.reference_no || `SL-${item.id}`)}</td>
        <td>${escapeHtml(formatDisplayDate(item.sale_date || item.created_at))}</td>
        <td>${escapeHtml(item.buyer_name || '-')}</td>
        <td class="text-right">${formatCurrency(item.total_amount || 0)}</td>
        <td class="text-right">${revenueValue > 0 ? formatCurrency(revenueValue) : '<span class="report-muted">ยังไม่บันทึก</span>'}</td>
        <td class="text-right">${formatCurrency(costValue)}</td>
        <td class="text-right ${profitValue >= 0 ? 'report-kpi-income' : 'report-kpi-expense'}">${profitValue >= 0 ? '+' : ''}${formatCurrency(profitValue)}</td>
        <td><span class="report-badge ${statusClass.className}">${escapeHtml(statusClass.label)}</span></td>
      </tr>
    `;
  }).join(''));
}

async function loadInventoryReport() {
  try {
    const params = new URLSearchParams();
    addBranchParam(params);
    const query = params.toString();
    const suffix = query ? `?${query}` : '';
    const [categoriesRes, alertsRes] = await Promise.all([
      apiRequest(`inventory/categories${suffix}`),
      apiRequest(`inventory/stock-alerts${suffix}`),
    ]);

    if (categoriesRes.status !== 'success') {
      throw new Error(categoriesRes.message || 'โหลดข้อมูลคลังไม่สำเร็จ');
    }

    const categories = Array.isArray(categoriesRes.data) ? categoriesRes.data : [];
    const alerts = alertsRes.status === 'success' ? (alertsRes.data?.items || []) : [];
    const alertSummary = alertsRes.status === 'success' ? alertsRes.data?.summary || null : null;

    reportState.inventory = {
      categories,
      alerts,
      alertSummary,
    };
    renderInventory(categories, alerts, alertSummary);
  } catch (error) {
    console.error('Failed to load inventory report:', error);
    renderErrorRow('inventoryCategoriesBody', 4, 'โหลดข้อมูลคลังไม่สำเร็จ');
    renderErrorRow('inventoryAlertsBody', 4, 'โหลดข้อมูลแจ้งเตือนไม่สำเร็จ');
  }
}

function renderInventory(categories, alerts, alertSummary) {
  const count = categories.length;
  const totalKg = categories.reduce((sum, item) => sum + parseFloat(item.stock_kg || 0), 0);
  const lowCount = alertSummary?.below_threshold ?? alerts.length;
  const zeroCount = alertSummary?.zero_stock ?? 0;

  document.getElementById('inventoryCategoriesCount').textContent = count.toLocaleString('th-TH');
  document.getElementById('inventoryTotalKg').textContent = totalKg.toLocaleString('th-TH', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 3,
  });
  document.getElementById('inventoryLowCount').textContent = lowCount.toLocaleString('th-TH');
  document.getElementById('inventoryScope').textContent = currentBranchName;
  document.getElementById('inventoryStockSummary').textContent = `${zeroCount} หมดสต็อก · ${lowCount} ใกล้หมด`;

  if (count === 0) {
    renderErrorRow('inventoryCategoriesBody', 4, 'ไม่มีข้อมูลคลัง');
  } else {
    renderTableBody('inventoryCategoriesBody', categories.map((item) => {
      const stockKg = parseFloat(item.stock_kg || 0);
      const threshold = item.alert_threshold === null || item.alert_threshold === undefined
        ? null
        : parseFloat(item.alert_threshold);
      const status = getInventoryStatus(stockKg, threshold);
      return `
        <tr>
          <td>${escapeHtml(item.name || '-')}</td>
          <td class="text-right">${stockKg.toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 3 })}</td>
          <td class="text-right">${threshold === null || Number.isNaN(threshold) ? '-' : threshold.toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 3 })}</td>
          <td><span class="report-badge ${status.className}">${escapeHtml(status.label)}</span></td>
        </tr>
      `;
    }).join(''));
  }

  if (!alerts || alerts.length === 0) {
    renderErrorRow('inventoryAlertsBody', 4, 'ไม่มีรายการแจ้งเตือน');
    return;
  }

  renderTableBody('inventoryAlertsBody', alerts.map((item) => {
    const stockKg = parseFloat(item.stock_kg || 0);
    const threshold = item.alert_threshold === null || item.alert_threshold === undefined
      ? 0
      : parseFloat(item.alert_threshold);
    const status = getInventoryStatus(stockKg, threshold);
    return `
      <tr>
        <td>${escapeHtml(item.item_name || '-')}</td>
        <td>${escapeHtml(item.category_name || '-')}</td>
        <td class="text-right">${stockKg.toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 3 })}</td>
        <td class="text-right">${threshold.toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 3 })}</td>
      </tr>
    `;
  }).join(''));
}

async function loadEmployeesReport() {
  try {
    const params = new URLSearchParams({ limit: '500' });
    addBranchParam(params);
    const res = await apiRequest(`employees?${params.toString()}`);
    if (res.status !== 'success') {
      throw new Error(res.message || 'โหลดข้อมูลพนักงานไม่สำเร็จ');
    }

    const items = res.data?.items || [];
    reportState.employees = items;
    renderEmployees(items);
  } catch (error) {
    console.error('Failed to load employees report:', error);
    renderErrorRow('employeesBody', 6, 'โหลดข้อมูลพนักงานไม่สำเร็จ');
  }
}

function renderEmployees(items) {
  const total = items.length;
  const active = items.filter((item) => item.status === 'active').length;
  const inactive = items.filter((item) => item.status !== 'active').length;
  const salaryTotal = items.reduce((sum, item) => sum + parseFloat(item.salary || 0), 0);

  document.getElementById('employeesTotal').textContent = total.toLocaleString('th-TH');
  document.getElementById('employeesActive').textContent = active.toLocaleString('th-TH');
  document.getElementById('employeesInactive').textContent = inactive.toLocaleString('th-TH');
  document.getElementById('employeesSalaryTotal').textContent = formatCurrency(salaryTotal);

  if (total === 0) {
    renderErrorRow('employeesBody', 6, 'ไม่มีข้อมูลพนักงานในสาขานี้');
    return;
  }

  renderTableBody('employeesBody', items.map((item) => {
    const statusClass = item.status === 'active'
      ? 'report-badge-success'
      : 'report-badge-warning';
    const statusLabel = item.status === 'active' ? 'กำลังทำงาน' : 'ออกแล้ว';

    return `
      <tr>
        <td>${escapeHtml(item.full_name || '-')}</td>
        <td>${escapeHtml(item.position || '-')}</td>
        <td>${escapeHtml(item.phone || '-')}</td>
        <td class="text-right">${parseFloat(item.salary || 0) > 0 ? formatCurrency(item.salary) : '-'}</td>
        <td class="text-right">${parseFloat(item.daily_wage || 0) > 0 ? formatCurrency(item.daily_wage) : '-'}</td>
        <td><span class="report-badge ${statusClass}">${statusLabel}</span></td>
      </tr>
    `;
  }).join(''));
}

async function loadTaxReport() {
  try {
    const dateFrom = document.getElementById('taxDateFrom')?.value || getMonthRange(currentReportMonth).dateFrom;
    const dateTo = document.getElementById('taxDateTo')?.value || getMonthRange(currentReportMonth).dateTo;
    const period = document.getElementById('taxPeriod')?.value || 'daily';

    const params = new URLSearchParams({
      date_from: dateFrom,
      date_to: dateTo,
      period,
    });
    addBranchParam(params);

    const res = await apiRequest(`reports/tax-report?${params.toString()}`);
    if (res.status !== 'success') {
      throw new Error(res.message || 'โหลดรายงานภาษีไม่สำเร็จ');
    }

    const data = res.data || { periods: [], totals: {} };
    reportState.tax = data;
    renderTaxReport(data);
  } catch (error) {
    console.error('Failed to load tax report:', error);
    renderErrorRow('taxReportBody', 4, 'โหลดรายงานภาษีไม่สำเร็จ');
  }
}

function renderTaxReport(data) {
  const periods = Array.isArray(data.periods) ? data.periods : [];
  const taxableSales = parseFloat(data.totals?.taxable_sales || 0);
  const taxCollected = parseFloat(data.totals?.tax_collected || 0);
  const taxRate = taxableSales > 0 ? (taxCollected / taxableSales) * 100 : 0;

  document.getElementById('taxableSales').textContent = formatCurrency(taxableSales);
  document.getElementById('taxCollected').textContent = formatCurrency(taxCollected);
  document.getElementById('taxRate').textContent = `${taxRate.toFixed(1)}%`;
  document.getElementById('taxScope').textContent = `${currentBranchName} · จากข้อมูลที่เลือก`;

  if (periods.length === 0) {
    renderErrorRow('taxReportBody', 4, 'ไม่มีข้อมูลภาษีในช่วงที่เลือก');
    return;
  }

  renderTableBody('taxReportBody', periods.map((item) => `
    <tr>
      <td>${escapeHtml(item.period || '-')}</td>
      <td class="text-right">${formatCurrency(item.taxable_sales || 0)}</td>
      <td class="text-right">${formatCurrency(item.tax_collected || 0)}</td>
      <td class="text-right">${formatCurrency((parseFloat(item.taxable_sales || 0) + parseFloat(item.tax_collected || 0)))}</td>
    </tr>
  `).join(''));
}

function handleReportActionClick(event) {
  const button = event.target.closest('[data-report-action]');
  if (!button) {
    return;
  }

  const action = button.dataset.reportAction;
  const key = button.dataset.reportKey;
  if (!action || !key) {
    return;
  }

  event.preventDefault();

  if (action === 'export') {
    exportReportCard(key);
  } else if (action === 'print') {
    printReportCard(key);
  }
}

// Daily Excel export
document.addEventListener('click', async (e) => {
  const btn = e.target.closest('#btnExportDaily');
  if (!btn) return;

  e.preventDefault();
  const dateVal = document.getElementById('dailyExportDate').value || new Date().toISOString().slice(0, 10);
  const payload = { date: dateVal };
  if (currentBranchId) {
    payload.branch_id = currentBranchId;
  }

  try {
    const res = await apiRequest('purchase-orders/daily-export', 'POST', payload);
    if (res.status === 'success') {
      const blob = new Blob([res.data.html], { type: 'application/vnd.ms-excel' });
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = res.data.filename;
      a.click();
      URL.revokeObjectURL(url);
      showNotification('ส่งออกเรียบร้อย', 'success');
    } else {
      showNotification(res.message || 'ส่งออกไม่สำเร็จ', 'error');
    }
  } catch (err) {
    console.error('Export daily failed:', err);
    showNotification('ส่งออกไม่สำเร็จ', 'error');
  }
});

function exportReportCard(key) {
  const monthLabel = getMonthRange(currentReportMonth).label;
  const scopeSlug = getScopeSlug();
  const filename = `report-${key}-${scopeSlug}-${currentReportMonth || getCurrentMonthValue()}.csv`;
  let csv = '';

  switch (key) {
    case 'summary':
      csv = buildCsv([
        ['รายการ', 'จำนวน'],
        ['ยอดรับซื้อของ', formatCurrency(reportState.summary?.totalPurchase || 0)],
        ['น้ำหนักรับซื้อ', `${(reportState.summary?.totalKg || 0).toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 3 })} กก.`],
        ['รายรับขาย Lot', formatCurrency(reportState.summary?.totalRevenue || 0)],
        ['ค่าใช้จ่ายต่อ Lot', formatCurrency(reportState.summary?.totalExpenses || 0)],
        ['ค่าใช้จ่ายประจำสาขา', formatCurrency(reportState.summary?.totalBizExpenses || 0)],
        ['กำไรสุทธิ', `${(reportState.summary?.netProfit || 0) >= 0 ? '+' : ''}${formatCurrency(reportState.summary?.netProfit || 0)}`],
        ['ช่วงรายงาน', monthLabel],
        ['สาขา', currentBranchName],
      ]);
      break;
    case 'purchaseOrders':
      csv = buildCsv([
        ['สาขา', currentBranchName],
        ['ช่วงรายงาน', monthLabel],
        [],
        ['เลขที่', 'วันที่', 'ผู้ขาย', 'ยอดรวม', 'วิธีจ่าย', 'สถานะ'],
        ...reportState.purchaseOrders.map((item) => [
          item.reference_no || '',
          formatDisplayDate(item.created_at || item.sale_date),
          item.seller_name || '',
          item.total_amount || 0,
          formatPaymentMethod(item.payment_method),
          getStatusLabel(item.status),
        ]),
      ]);
      break;
    case 'purchaseItems':
      csv = buildCsv([
        ['สาขา', currentBranchName],
        ['ช่วงรายงาน', monthLabel],
        [],
        ['วันที่', 'สินค้า', 'หมวด', 'จำนวนสุทธิ', 'ราคาเฉลี่ย/หน่วย', 'ยอดรวม', 'หน่วย', 'จำนวนบิล'],
        ...reportState.purchaseItems.map((item) => [
          formatDisplayDate(item.purchase_date || ''),
          item.item_name || '',
          item.category_name || '',
          item.net_quantity || 0,
          item.weighted_avg_unit_price || 0,
          item.total_amount || 0,
          item.unit || '',
          item.bill_count || 0,
        ]),
      ]);
      break;
    case 'saleLots':
      csv = buildCsv([
        ['สาขา', currentBranchName],
        ['ช่วงรายงาน', monthLabel],
        [],
        ['เลขที่', 'วันที่', 'ผู้ซื้อ', 'ยอดขาย', 'รายรับจริง', 'ต้นทุน', 'กำไร', 'สถานะ'],
        ...reportState.saleLots.map((item) => {
          const actualRevenue = parseFloat(item.actual_revenue || 0);
          const cost = parseFloat(item.total_cost || 0);
          const profit = actualRevenue - cost;
          return [
            item.reference_no || '',
            formatDisplayDate(item.sale_date || item.created_at),
            item.buyer_name || '',
            item.total_amount || 0,
            actualRevenue > 0 ? actualRevenue : '',
            cost,
            profit,
            getStatusLabel(item.status),
          ];
        }),
      ]);
      break;
    case 'inventory':
      csv = buildCsv([
        ['สาขา', currentBranchName],
        ['ช่วงรายงาน', monthLabel],
        [],
        ['หมวดหมู่', 'สต็อก (กก.)', 'เกณฑ์แจ้งเตือน', 'สถานะ'],
        ...reportState.inventory.categories.map((item) => {
          const stockKg = parseFloat(item.stock_kg || 0);
          const threshold = item.alert_threshold === null || item.alert_threshold === undefined ? '' : item.alert_threshold;
          const status = getInventoryStatus(stockKg, threshold === '' ? null : parseFloat(threshold));
          return [
            item.name || '',
            stockKg.toFixed(3),
            threshold === '' ? '-' : threshold,
            status.label,
          ];
        }),
        [],
        ['รายการแจ้งเตือน', 'หมวด', 'สต็อก (กก.)', 'เกณฑ์'],
        ...reportState.inventory.alerts.map((item) => [
          item.item_name || '',
          item.category_name || '',
          parseFloat(item.stock_kg || 0).toFixed(3),
          item.alert_threshold ?? 0,
        ]),
      ]);
      break;
    case 'employees':
      csv = buildCsv([
        ['สาขา', currentBranchName],
        ['ช่วงรายงาน', monthLabel],
        [],
        ['ชื่อ-นามสกุล', 'ตำแหน่ง', 'เบอร์โทร', 'เงินเดือน', 'ค่าแรงรายวัน', 'สถานะ'],
        ...reportState.employees.map((item) => [
          item.full_name || '',
          item.position || '',
          item.phone || '',
          item.salary || 0,
          item.daily_wage || 0,
          getStatusLabel(item.status),
        ]),
      ]);
      break;
    case 'tax':
      csv = buildCsv([
        ['สาขา', currentBranchName],
        ['ช่วงรายงาน', getReportPeriodText('tax')],
        [],
        ['รอบระยะเวลา', 'ยอดขายที่ต้องเสียภาษี', 'ภาษีที่เก็บ', 'รวม'],
        ...(Array.isArray(reportState.tax?.periods) ? reportState.tax.periods : []).map((item) => [
          item.period || '',
          item.taxable_sales || 0,
          item.tax_collected || 0,
          (parseFloat(item.taxable_sales || 0) + parseFloat(item.tax_collected || 0)),
        ]),
      ]);
      break;
    default:
      showNotification('ไม่พบข้อมูลสำหรับส่งออก', 'error');
      return;
  }

  downloadCsv(filename, csv);
}

function printReportCard(key) {
  const cardMap = {
    summary: 'branchSummaryCard',
    purchaseOrders: 'purchaseOrdersCard',
    purchaseItems: 'purchaseItemsCard',
    saleLots: 'saleLotsCard',
    inventory: 'inventoryCard',
    employees: 'employeesCard',
    tax: 'taxCard',
  };

  const card = document.getElementById(cardMap[key]);
  if (!card) {
    showNotification('ไม่พบข้อมูลสำหรับพิมพ์', 'error');
    return;
  }

  const title = card.querySelector('.card-title')?.textContent || 'รายงาน';
  const clone = card.cloneNode(true);
  clone.querySelectorAll('.report-card-actions').forEach((node) => node.remove());

  const printWindow = window.open('', '_blank');
  if (!printWindow) {
    showNotification('ไม่สามารถเปิดหน้าพิมพ์ได้', 'error');
    return;
  }

  printWindow.document.write(`
    <html lang="th">
    <head>
      <meta charset="UTF-8">
      <meta name="viewport" content="width=device-width, initial-scale=1.0">
      <title>${escapeHtml(title)}</title>
      <style>
        body { font-family: 'IBM Plex Sans Thai', sans-serif; margin: 24px; color: #1e293b; }
        h1 { margin: 0 0 8px; font-size: 22px; }
        .meta { margin-bottom: 16px; font-size: 13px; color: #64748b; }
        .card { border: 1px solid #e2e8f0; border-radius: 8px; padding: 16px; }
        .report-kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 10px; margin-bottom: 16px; }
        .report-kpi { border: 1px solid #e2e8f0; border-radius: 6px; padding: 12px; }
        .report-kpi-label { font-size: 11px; color: #64748b; margin-bottom: 6px; }
        .report-kpi-value { font-size: 18px; font-weight: 700; }
        .report-kpi-sub { font-size: 11px; color: #64748b; margin-top: 4px; }
        .table-container { overflow: visible; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th, td { border-bottom: 1px solid #e2e8f0; padding: 8px 10px; text-align: left; font-size: 12px; vertical-align: top; }
        th { background: #f8fafc; }
        .text-right { text-align: right; }
        .report-badge { display: inline-flex; padding: 2px 8px; border-radius: 999px; font-size: 11px; }
        .report-badge-success { background: #d1fae5; color: #047857; }
        .report-badge-warning { background: #fef3c7; color: #b45309; }
        .report-badge-danger { background: #fee2e2; color: #b91c1c; }
        .report-empty-cell { text-align: center; color: #64748b; padding: 20px !important; }
      </style>
    </head>
    <body>
      <h1>${escapeHtml(title)}</h1>
      <div class="meta">สาขา: ${escapeHtml(currentBranchName)} · ช่วงรายงาน: ${escapeHtml(getReportPeriodText(key))}</div>
      ${clone.outerHTML}
    </body>
    </html>
  `);
  printWindow.document.close();
  setTimeout(() => printWindow.print(), 400);
}

function buildCsv(rows) {
  return rows.map((row) => {
    if (!Array.isArray(row) || row.length === 0) {
      return '';
    }

    return row.map(escapeCsvValue).join(',');
  }).join('\n');
}

function escapeCsvValue(value) {
  const text = value === null || value === undefined ? '' : String(value);
  return `"${text.replace(/"/g, '""')}"`;
}

function downloadCsv(filename, csv) {
  const blob = new Blob(['\uFEFF', csv], { type: 'text/csv;charset=utf-8;' });
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = filename;
  document.body.appendChild(link);
  link.click();
  link.remove();
  URL.revokeObjectURL(url);
}

function renderTableBody(targetId, html) {
  const body = document.getElementById(targetId);
  if (!body) {
    return;
  }
  body.innerHTML = html;
}

function renderErrorRow(targetId, columns, message) {
  const body = document.getElementById(targetId);
  if (!body) {
    return;
  }

  body.innerHTML = `<tr><td colspan="${columns}" class="report-empty-cell">${escapeHtml(message)}</td></tr>`;
}

function getInventoryStatus(stockKg, threshold) {
  if (stockKg <= 0) {
    return { label: 'หมดสต็อก', className: 'report-badge-danger' };
  }

  if (threshold !== null && Number.isFinite(threshold) && threshold > 0 && stockKg <= threshold) {
    return { label: 'ใกล้หมด', className: 'report-badge-warning' };
  }

  return { label: 'ปกติ', className: 'report-badge-success' };
}

function getStatusClass(status) {
  const normalized = String(status || '').toLowerCase();
  if (normalized === 'confirmed' || normalized === 'completed' || normalized === 'active') {
    return { label: getStatusLabel(normalized), className: 'report-badge-success' };
  }

  if (normalized === 'draft' || normalized === 'pending') {
    return { label: getStatusLabel(normalized), className: 'report-badge-warning' };
  }

  if (normalized === 'cancelled' || normalized === 'inactive') {
    return { label: getStatusLabel(normalized), className: 'report-badge-danger' };
  }

  return { label: getStatusLabel(normalized), className: 'report-badge-warning' };
}

function getStatusLabel(status) {
  const normalized = String(status || '').toLowerCase();
  const map = {
    confirmed: 'ยืนยันแล้ว',
    completed: 'สำเร็จ',
    draft: 'แบบร่าง',
    pending: 'รอดำเนินการ',
    cancelled: 'ยกเลิก',
    active: 'กำลังทำงาน',
    inactive: 'ออกแล้ว',
    paid: 'ชำระแล้ว',
    cash: 'เงินสด',
    bank_transfer: 'โอนธนาคาร',
  };

  return map[normalized] || status || '-';
}

function formatPaymentMethod(value) {
  return getStatusLabel(value);
}

function formatDisplayDate(value) {
  if (!value) {
    return '-';
  }

  const normalized = String(value).includes('T') ? String(value) : `${String(value).slice(0, 10)}T00:00:00`;
  const date = new Date(normalized);
  if (Number.isNaN(date.getTime())) {
    return String(value).slice(0, 10);
  }

  return date.toLocaleDateString('th-TH', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
  });
}
