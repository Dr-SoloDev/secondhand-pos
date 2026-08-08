let auditPage = 1;
const auditLimit = 25;

document.addEventListener('DOMContentLoaded', async () => {
  const permissions = await getAppPermissions();
  if (!permissions || !hasAppPermission('actions.audit.read', permissions)) return;
  document.getElementById('auditFilterForm').addEventListener('submit', event => {
    event.preventDefault();
    auditPage = 1;
    loadAuditLogs();
  });
  document.getElementById('auditReset').addEventListener('click', resetAuditFilters);
  document.getElementById('auditExport').addEventListener('click', exportAuditLogs);
  await Promise.all([loadAuditBranches(), loadAuditUsers()]);
  await loadAuditLogs();
});

function auditParams(includePagination = true) {
  const mapping = {
    start_datetime: 'auditStart', end_datetime: 'auditEnd', role: 'auditRole',
    branch_id: 'auditBranch', user_id: 'auditUser', module: 'auditModule',
    action: 'auditAction', entity: 'auditEntity', outcome: 'auditOutcome', keyword: 'auditKeyword'
  };
  const params = new URLSearchParams();
  for (const [key, id] of Object.entries(mapping)) {
    const value = document.getElementById(id)?.value.trim();
    if (value) params.set(key, value);
  }
  if (includePagination) {
    params.set('page', String(auditPage));
    params.set('limit', String(auditLimit));
  }
  return params;
}

async function loadAuditBranches() {
  const response = await apiRequest('branches/active');
  if (response.status !== 'success') return;
  const select = document.getElementById('auditBranch');
  for (const branch of response.data || []) {
    select.add(new Option(`${branch.code} - ${branch.name}`, branch.id));
  }
}

async function loadAuditUsers() {
  const response = await apiRequest('users/all');
  if (response.status !== 'success') return;
  const select = document.getElementById('auditUser');
  for (const user of response.data || []) {
    select.add(new Option(`${user.username} (${user.full_name})`, user.id));
  }
}

async function loadAuditLogs() {
  const tbody = document.querySelector('#auditTable tbody');
  showTableLoading(tbody, 7, 5);
  const response = await apiRequest(`audit-logs?${auditParams().toString()}`);
  if (response.status !== 'success') {
    tbody.innerHTML = '<tr><td colspan="7" class="text-center">ไม่สามารถโหลดข้อมูลได้</td></tr>';
    return;
  }
  renderAuditLogs(response.data.logs || []);
  renderAuditPagination(response.data.pagination || {});
}

function renderAuditLogs(logs) {
  const tbody = document.querySelector('#auditTable tbody');
  if (!logs.length) {
    tbody.innerHTML = '<tr><td colspan="7" class="text-center">ไม่พบข้อมูล</td></tr>';
    return;
  }
  tbody.innerHTML = logs.map(log => {
    const details = JSON.stringify({ before: log.before_json, after: log.after_json }, null, 2);
    const hasDetails = log.before_json !== null || log.after_json !== null;
    return `<tr>
      <td>${escapeHtml(formatAuditDate(log.created_at))}</td>
      <td>${escapeHtml(log.username || String(log.actor_id || 'system'))}</td>
      <td>${escapeHtml(log.actor_role || '-')} / ${escapeHtml(String(log.actor_branch_id || '-'))}</td>
      <td>${escapeHtml(log.module || '-')}<br><strong>${escapeHtml(log.action || '-')}</strong></td>
      <td>${escapeHtml(log.entity_type || '-')}<br>${escapeHtml(log.entity_id || '-')}</td>
      <td class="audit-outcome-${escapeHtml(log.outcome || 'success')}">${escapeHtml(log.outcome || 'success')}</td>
      <td class="audit-details">${escapeHtml(log.description || log.reason || '-')}${hasDetails ? `<details><summary>Before / After</summary><pre class="audit-json">${escapeHtml(details)}</pre></details>` : ''}</td>
    </tr>`;
  }).join('');
}

function renderAuditPagination(pagination) {
  const container = document.getElementById('auditPagination');
  const pages = Number(pagination.pages || 0);
  const page = Number(pagination.page || 1);
  container.innerHTML = '';
  const previous = document.createElement('button');
  previous.className = 'pagination-btn';
  previous.textContent = 'ก่อนหน้า';
  previous.disabled = page <= 1;
  previous.addEventListener('click', () => { auditPage = page - 1; loadAuditLogs(); });
  const info = document.createElement('span');
  info.className = 'pagination-info';
  info.textContent = `หน้า ${page} / ${Math.max(pages, 1)} (${pagination.total || 0} รายการ)`;
  const next = document.createElement('button');
  next.className = 'pagination-btn';
  next.textContent = 'ถัดไป';
  next.disabled = pages === 0 || page >= pages;
  next.addEventListener('click', () => { auditPage = page + 1; loadAuditLogs(); });
  container.append(previous, info, next);
}

function resetAuditFilters() {
  document.getElementById('auditFilterForm').reset();
  auditPage = 1;
  loadAuditLogs();
}

function exportAuditLogs() {
  window.location.href = `${apiPath}/audit-logs/export?${auditParams(false).toString()}`;
}

function formatAuditDate(value) {
  if (!value) return '-';
  const date = new Date(value.replace(' ', 'T'));
  return Number.isNaN(date.getTime()) ? value : date.toLocaleString('th-TH');
}
