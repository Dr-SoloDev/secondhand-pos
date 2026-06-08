let sellers = [];
let currentSeller = null;

document.addEventListener('DOMContentLoaded', function() {
    loadSellers();
    setupIdCardFormatter();
    document.getElementById('isBlacklisted').addEventListener('change', function() {
        document.getElementById('blacklistReasonGroup').style.display = this.checked ? '' : 'none';
    });
});

async function loadSellers() {
    const includeBlacklisted = document.getElementById('showBlacklisted').checked;
    const query = includeBlacklisted ? '?include_blacklisted=true' : '';
    const result = await apiRequest(`sellers${query}`);

    if (result.status === 'success') {
        sellers = result.data;
        renderSellersTable();
    } else {
        showNotification(result.message || 'เกิดข้อผิดพลาดในการโหลดข้อมูล', 'error');
    }
}

function renderSellersTable() {
    const tbody = document.getElementById('sellersTableBody');

    if (sellers.length === 0) {
        tbody.innerHTML = '<tr><td colspan="9" class="text-center">ไม่พบข้อมูลผู้ขาย</td></tr>';
        return;
    }

    tbody.innerHTML = sellers.map(seller => {
        const statusBadge = seller.is_blacklisted
            ? '<span class="badge badge-danger">Blacklist</span>'
            : '<span class="badge badge-success">ปกติ</span>';

        const lastTransaction = seller.last_transaction_at
            ? formatDate(seller.last_transaction_at)
            : '-';

        return `
            <tr>
                <td>${seller.id}</td>
                <td>${escapeHtml(seller.full_name)}</td>
                <td>${escapeHtml(seller.phone || '-')}</td>
                <td>${formatIdCard(seller.id_card)}</td>
                <td class="text-center">${seller.total_transactions}</td>
                <td class="text-right">${formatNumber(seller.total_amount)}</td>
                <td>${lastTransaction}</td>
                <td>${statusBadge}</td>
                <td>
                    <button class="btn-sm btn-info" onclick="viewSeller(${seller.id})" title="ดูรายละเอียด">👁️</button>
                    <button class="btn-sm btn-warning" onclick="editSeller(${seller.id})" title="แก้ไข">✏️</button>
                    ${seller.is_blacklisted
                        ? `<button class="btn-sm btn-success" onclick="unblacklistSeller(${seller.id})" title="ยกเลิก Blacklist">✓</button>`
                        : `<button class="btn-sm btn-danger" onclick="confirmBlacklist(${seller.id})" title="Blacklist">🚫</button>`
                    }
                </td>
            </tr>
        `;
    }).join('');
}

async function searchSellers() {
    const keyword = document.getElementById('searchInput').value.trim();

    if (keyword.length < 2) {
        showNotification('กรุณากรอกคำค้นหาอย่างน้อย 2 ตัวอักษร', 'error');
        return;
    }

    const result = await apiRequest(`sellers/search?q=${encodeURIComponent(keyword)}`);

    if (result.status === 'success') {
        sellers = result.data;
        renderSellersTable();
    } else {
        showNotification(result.message || 'เกิดข้อผิดพลาดในการค้นหา', 'error');
    }
}

function openAddSellerModal() {
    currentSeller = null;
    document.getElementById('modalTitle').textContent = 'เพิ่มผู้ขาย';
    document.getElementById('sellerForm').reset();
    document.getElementById('sellerId').value = '';
    document.getElementById('sellerModal').classList.add('show');
}

function editSeller(id) {
    currentSeller = sellers.find(s => s.id === id);

    if (!currentSeller) {
        showNotification('ไม่พบข้อมูลผู้ขาย', 'error');
        return;
    }

    document.getElementById('modalTitle').textContent = 'แก้ไขข้อมูลผู้ขาย';
    document.getElementById('sellerId').value = currentSeller.id;
    document.getElementById('fullName').value = currentSeller.full_name;
    document.getElementById('phone').value = currentSeller.phone || '';
    document.getElementById('idCard').value = formatIdCard(currentSeller.id_card);
    document.getElementById('address').value = currentSeller.address || '';
    document.getElementById('notes').value = currentSeller.notes || '';
    const bl = currentSeller.is_blacklisted == 1;
    document.getElementById('isBlacklisted').checked = bl;
    document.getElementById('blacklistReason').value = currentSeller.blacklist_reason || '';
    document.getElementById('blacklistReasonGroup').style.display = bl ? '' : 'none';

    document.getElementById('sellerModal').classList.add('show');
}

async function viewSeller(id) {
    const seller = sellers.find(s => s.id === id);
    if (!seller) return;

    // เปิด modal ก่อน แล้วโหลด history
    document.getElementById('viewSellerName').textContent = seller.full_name;
    document.getElementById('viewSellerIdCard').textContent = formatIdCard(seller.id_card);
    document.getElementById('viewSellerPhone').textContent = seller.phone || '-';
    document.getElementById('viewSellerAddress').textContent = seller.address || '-';
    document.getElementById('viewSellerStats').textContent =
        `มาขาย ${seller.total_transactions} ครั้ง · ยอดรวม ${formatNumber(seller.total_amount)} บาท · ล่าสุด ${seller.last_transaction_at ? formatDate(seller.last_transaction_at) : '-'}`;

    const blacklistBadge = document.getElementById('viewSellerBlacklist');
    if (seller.is_blacklisted) {
        blacklistBadge.style.display = '';
        blacklistBadge.textContent = `⛔ Blacklist: ${seller.blacklist_reason || 'ไม่ได้ระบุเหตุผล'}`;
    } else {
        blacklistBadge.style.display = 'none';
    }

    document.getElementById('viewSellerHistoryBody').innerHTML =
        '<tr><td colspan="4" style="text-align:center;color:#888">กำลังโหลด...</td></tr>';
    document.getElementById('viewSellerModal').classList.add('show');

    const res = await apiRequest(`sellers/history?id=${id}`);
    const tbody = document.getElementById('viewSellerHistoryBody');
    const items = res.status === 'success' ? (res.data.items || []) : [];
    if (!items.length) {
        tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;color:#888">ยังไม่มีประวัติ</td></tr>';
        return;
    }
    tbody.innerHTML = items.map(po => `<tr>
        <td style="font-size:12px;font-family:monospace">${escapeHtml(po.reference_no)}</td>
        <td>${formatDate(po.created_at)}</td>
        <td>${escapeHtml(po.branch_name || '-')}</td>
        <td class="text-right"><strong>${formatNumber(po.total_amount)}</strong></td>
    </tr>`).join('');
}

async function saveSeller() {
    const sellerId = document.getElementById('sellerId').value;
    const fullName = document.getElementById('fullName').value.trim();
    const phone = document.getElementById('phone').value.trim();
    const idCard = document.getElementById('idCard').value.replace(/[^0-9]/g, '');
    const address = document.getElementById('address').value.trim();
    const notes = document.getElementById('notes').value.trim();
    const isBlacklisted = document.getElementById('isBlacklisted').checked ? 1 : 0;

    if (!fullName) {
        showNotification('กรุณากรอกชื่อ-นามสกุล', 'error');
        return;
    }

    if (!phone && !idCard) {
        showNotification('กรุณากรอกเบอร์โทรศัพท์ หรือ เลขบัตรประชาชน', 'error');
        return;
    }

    if (idCard && idCard.length !== 13) {
        showNotification('เลขบัตรประชาชนต้องเป็น 13 หลัก', 'error');
        return;
    }

    const data = {
        full_name: fullName,
        phone: phone || null,
        id_card: idCard || null,
        address: address || null,
        notes: notes || null,
        is_blacklisted: isBlacklisted,
        blacklist_reason: isBlacklisted ? (document.getElementById('blacklistReason').value.trim() || null) : null,
    };

    const isEdit = sellerId !== '';

    if (isEdit) {
        data.id = parseInt(sellerId);
    }

    const endpoint = isEdit ? 'sellers/seller' : 'sellers';
    const method = isEdit ? 'PUT' : 'POST';

    const result = await apiRequest(endpoint, method, data);

    if (result.status === 'success') {
        showNotification(isEdit ? 'แก้ไขข้อมูลสำเร็จ' : 'เพิ่มผู้ขายสำเร็จ', 'success');
        closeSellerModal();
        loadSellers();
    } else {
        showNotification(result.message || 'เกิดข้อผิดพลาดในการบันทึกข้อมูล', 'error');
    }
}

function confirmBlacklist(id) {
    const seller = sellers.find(s => s.id === id);
    if (!seller) return;

    const reason = prompt(`ยืนยัน Blacklist: ${seller.full_name}\n\nกรุณาระบุเหตุผล:`);
    if (reason === null) return;

    blacklistSeller(id, reason);
}

async function blacklistSeller(id, reason) {
    const result = await apiRequest('sellers/blacklist', 'POST', { id, reason });

    if (result.status === 'success') {
        showNotification('Blacklist ผู้ขายสำเร็จ', 'success');
        loadSellers();
    } else {
        showNotification(result.message || 'เกิดข้อผิดพลาด', 'error');
    }
}

async function unblacklistSeller(id) {
    if (!confirm('ยืนยันการยกเลิก Blacklist?')) return;

    const result = await apiRequest('sellers/unblacklist', 'POST', { id });

    if (result.status === 'success') {
        showNotification('ยกเลิก Blacklist สำเร็จ', 'success');
        loadSellers();
    } else {
        showNotification(result.message || 'เกิดข้อผิดพลาด', 'error');
    }
}

function closeSellerModal() {
    document.getElementById('sellerModal').classList.remove('show');
    document.getElementById('sellerForm').reset();
    currentSeller = null;
}

function formatIdCard(idCard) {
    if (!idCard) return '-';
    const cleaned = idCard.replace(/[^0-9]/g, '');
    if (cleaned.length !== 13) return idCard;
    return `${cleaned[0]}-${cleaned.substr(1, 4)}-${cleaned.substr(5, 5)}-${cleaned.substr(10, 2)}-${cleaned[12]}`;
}

function setupIdCardFormatter() {
    const idCardInput = document.getElementById('idCard');
    idCardInput.addEventListener('input', function(e) {
        let value = e.target.value.replace(/[^0-9]/g, '');
        if (value.length > 13) value = value.substr(0, 13);
        if (value.length >= 1) {
            let formatted = value[0];
            if (value.length >= 2) formatted += '-' + value.substr(1, 4);
            if (value.length >= 6) formatted += '-' + value.substr(5, 5);
            if (value.length >= 11) formatted += '-' + value.substr(10, 2);
            if (value.length >= 13) formatted += '-' + value[12];
            e.target.value = formatted;
        }
    });
}

function formatDate(dateString) {
    if (!dateString) return '-';
    const date = new Date(dateString);
    return date.toLocaleDateString('th-TH', {
        year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit'
    });
}

function formatNumber(num) {
    if (!num) return '0.00';
    return parseFloat(num).toLocaleString('th-TH', {
        minimumFractionDigits: 2, maximumFractionDigits: 2
    });
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

window.onclick = function(event) {
    const modal = document.getElementById('sellerModal');
    if (event.target === modal) closeSellerModal();
}

document.getElementById('searchInput').addEventListener('keypress', function(e) {
    if (e.key === 'Enter') searchSellers();
});
