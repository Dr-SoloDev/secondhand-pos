// sellers.js — จัดการผู้ขาย
let sellersData = [];

document.addEventListener('DOMContentLoaded', () => {
  loadSellers();

  document.getElementById('addSellerBtn').addEventListener('click', () => openSellerModal());
  document.getElementById('saveSellerBtn').addEventListener('click', saveSeller);
  document.querySelectorAll('#sellerModal .close-modal').forEach(btn => {
    btn.addEventListener('click', () => closeSellerModal());
  });

  let searchTimeout;
  document.getElementById('sellerSearch').addEventListener('input', (e) => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => loadSellers(e.target.value), 300);
  });
});

async function loadSellers(search = '') {
  const url = search ? `sellers?search=${encodeURIComponent(search)}&limit=100` : 'sellers?limit=100';
  const res = await apiRequest(url);
  if (res.status !== 'success') {
    showNotification('โหลดข้อมูลผู้ขายไม่สำเร็จ', 'error');
    return;
  }
  sellersData = res.data.items || [];
  renderTable(sellersData);
}

function renderTable(items) {
  const tbody = document.querySelector('#sellersTable tbody');
  if (items.length === 0) {
    tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;color:#888">ยังไม่มีผู้ขาย</td></tr>';
    return;
  }
  tbody.innerHTML = items.map(s => `
    <tr>
      <td>${escapeHtml(s.full_name)}</td>
      <td>${maskIdCard(s.id_card)}</td>
      <td>${escapeHtml(s.phone || '-')}</td>
      <td>${s.total_transactions || 0}</td>
      <td>${formatCurrency(s.total_amount || 0)}</td>
      <td>${s.is_blacklisted == 1
        ? '<span style="color:#d32f2f;font-weight:bold">Blacklist</span>'
        : '<span style="color:#388e3c">ปกติ</span>'}</td>
      <td>
        <button class="btn btn-sm btn-secondary" onclick="editSeller(${s.id})">แก้ไข</button>
      </td>
    </tr>
  `).join('');
}

function escapeHtml(s) {
  if (s == null) return '';
  return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

function maskIdCard(id) {
  if (!id) return '-';
  if (id.length !== 13) return id;
  return id.substring(0, 1) + '-' + id.substring(1, 5) + '-XXXXX-' + id.substring(10, 12) + '-' + id.substring(12);
}

function openSellerModal(seller = null) {
  document.getElementById('sellerModalTitle').textContent = seller ? 'แก้ไขผู้ขาย' : 'เพิ่มผู้ขาย';
  document.getElementById('sellerId').value = seller?.id || '';
  document.getElementById('fullName').value = seller?.full_name || '';
  document.getElementById('idCard').value = seller?.id_card || '';
  document.getElementById('sellerPhone').value = seller?.phone || '';
  document.getElementById('sellerAddress').value = seller?.address || '';
  document.getElementById('sellerNotes').value = seller?.notes || '';
  document.getElementById('isBlacklisted').checked = seller?.is_blacklisted == 1;
  document.getElementById('idCard').disabled = !!seller; // ห้ามแก้บัตร ปชช หลังบันทึก
  document.getElementById('sellerModal').classList.add('show');
}

function closeSellerModal() {
  document.getElementById('sellerModal').classList.remove('show');
}

window.editSeller = function(id) {
  const s = sellersData.find(x => x.id == id);
  if (s) openSellerModal(s);
};

async function saveSeller() {
  const id = document.getElementById('sellerId').value;
  const payload = {
    full_name: document.getElementById('fullName').value.trim(),
    id_card: document.getElementById('idCard').value.replace(/\D/g, ''),
    phone: document.getElementById('sellerPhone').value.trim(),
    address: document.getElementById('sellerAddress').value.trim(),
    notes: document.getElementById('sellerNotes').value.trim(),
    is_blacklisted: document.getElementById('isBlacklisted').checked ? 1 : 0,
  };

  if (!payload.full_name) {
    showNotification('กรุณากรอกชื่อ-นามสกุล', 'error');
    return;
  }
  if (payload.id_card && payload.id_card.length !== 13) {
    showNotification('เลขบัตรประชาชนต้องเป็น 13 หลัก', 'error');
    return;
  }

  const res = id
    ? await apiRequest(`sellers/seller?id=${id}`, 'PUT', payload)
    : await apiRequest('sellers', 'POST', payload);

  if (res.status === 'success') {
    showNotification(id ? 'อัปเดตผู้ขายสำเร็จ' : 'เพิ่มผู้ขายสำเร็จ', 'success');
    closeSellerModal();
    loadSellers();
  } else {
    showNotification(res.message || 'บันทึกไม่สำเร็จ', 'error');
  }
}
