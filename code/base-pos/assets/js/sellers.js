// sellers.js - จัดการผู้ขาย
let sellers = [];
let currentSeller = null;

// โหลดข้อมูลเมื่อเปิดหน้า
document.addEventListener('DOMContentLoaded', function() {
    checkAuth();
    loadSellers();
    setupIdCardFormatter();
});

/**
 * โหลดรายการผู้ขาย
 */
async function loadSellers() {
    const includeBlacklisted = document.getElementById('showBlacklisted').checked;
    const url = `${API_URL}/sellers${includeBlacklisted ? '?include_blacklisted=true' : ''}`;

    try {
        const response = await fetch(url, {
            headers: {
                'Authorization': `Bearer ${getToken()}`
            }
        });

        const result = await response.json();

        if (result.success) {
            sellers = result.data;
            renderSellersTable();
        } else {
            showError(result.message);
        }
    } catch (error) {
        showError('เกิดข้อผิดพลาดในการโหลดข้อมูล');
        console.error(error);
    }
}

/**
 * แสดงตารางผู้ขาย
 */
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

/**
 * ค้นหาผู้ขาย
 */
async function searchSellers() {
    const keyword = document.getElementById('searchInput').value.trim();

    if (keyword.length < 2) {
        showError('กรุณากรอกคำค้นหาอย่างน้อย 2 ตัวอักษร');
        return;
    }

    try {
        const response = await fetch(`${API_URL}/sellers/search?q=${encodeURIComponent(keyword)}`, {
            headers: {
                'Authorization': `Bearer ${getToken()}`
            }
        });

        const result = await response.json();

        if (result.success) {
            sellers = result.data;
            renderSellersTable();
        } else {
            showError(result.message);
        }
    } catch (error) {
        showError('เกิดข้อผิดพลาดในการค้นหา');
        console.error(error);
    }
}

/**
 * เปิด Modal เพิ่มผู้ขาย
 */
function openAddSellerModal() {
    currentSeller = null;
    document.getElementById('modalTitle').textContent = 'เพิ่มผู้ขาย';
    document.getElementById('sellerForm').reset();
    document.getElementById('sellerId').value = '';
    document.getElementById('sellerModal').style.display = 'block';
}

/**
 * แก้ไขผู้ขาย
 */
function editSeller(id) {
    currentSeller = sellers.find(s => s.id === id);
    
    if (!currentSeller) {
        showError('ไม่พบข้อมูลผู้ขาย');
        return;
    }

    document.getElementById('modalTitle').textContent = 'แก้ไขข้อมูลผู้ขาย';
    document.getElementById('sellerId').value = currentSeller.id;
    document.getElementById('fullName').value = currentSeller.full_name;
    document.getElementById('phone').value = currentSeller.phone || '';
    document.getElementById('idCard').value = formatIdCard(currentSeller.id_card);
    document.getElementById('address').value = currentSeller.address || '';
    document.getElementById('notes').value = currentSeller.notes || '';
    document.getElementById('isBlacklisted').checked = currentSeller.is_blacklisted == 1;

    document.getElementById('sellerModal').style.display = 'block';
}

/**
 * ดูรายละเอียดผู้ขาย
 */
function viewSeller(id) {
    const seller = sellers.find(s => s.id === id);
    
    if (!seller) {
        showError('ไม่พบข้อมูลผู้ขาย');
        return;
    }

    const info = `
        ชื่อ: ${seller.full_name}
        เบอร์โทร: ${seller.phone || '-'}
        บัตรประชาชน: ${formatIdCard(seller.id_card)}
        ที่อยู่: ${seller.address || '-'}
        
        จำนวนครั้งที่ขาย: ${seller.total_transactions} ครั้ง
        ยอดรวม: ${formatNumber(seller.total_amount)} บาท
        ขายล่าสุด: ${seller.last_transaction_at ? formatDate(seller.last_transaction_at) : '-'}
        
        สถานะ: ${seller.is_blacklisted ? 'Blacklist' : 'ปกติ'}
        ${seller.notes ? '\nหมายเหตุ: ' + seller.notes : ''}
    `;

    alert(info);
}

/**
 * บันทึกผู้ขาย (เพิ่ม/แก้ไข)
 */
async function saveSeller() {
    const sellerId = document.getElementById('sellerId').value;
    const fullName = document.getElementById('fullName').value.trim();
    const phone = document.getElementById('phone').value.trim();
    const idCard = document.getElementById('idCard').value.replace(/[^0-9]/g, '');
    const address = document.getElementById('address').value.trim();
    const notes = document.getElementById('notes').value.trim();
    const isBlacklisted = document.getElementById('isBlacklisted').checked ? 1 : 0;

    // Validate
    if (!fullName) {
        showError('กรุณากรอกชื่อ-นามสกุล');
        return;
    }

    if (!phone && !idCard) {
        showError('กรุณากรอกเบอร์โทรศัพท์ หรือ เลขบัตรประชาชน');
        return;
    }

    if (idCard && idCard.length !== 13) {
        showError('เลขบัตรประชาชนต้องเป็น 13 หลัก');
        return;
    }

    const data = {
        full_name: fullName,
        phone: phone || null,
        id_card: idCard || null,
        address: address || null,
        notes: notes || null,
        is_blacklisted: isBlacklisted
    };

    const isEdit = sellerId !== '';
    const url = isEdit ? `${API_URL}/sellers/seller` : `${API_URL}/sellers`;
    const method = isEdit ? 'PUT' : 'POST';

    if (isEdit) {
        data.id = parseInt(sellerId);
    }

    try {
        const response = await fetch(url, {
            method: method,
            headers: {
                'Content-Type': 'application/json',
                'Authorization': `Bearer ${getToken()}`
            },
            body: JSON.stringify(data)
        });

        const result = await response.json();

        if (result.success) {
            showSuccess(isEdit ? 'แก้ไขข้อมูลสำเร็จ' : 'เพิ่มผู้ขายสำเร็จ');
            closeSellerModal();
            loadSellers();
        } else {
            showError(result.message);
        }
    } catch (error) {
        showError('เกิดข้อผิดพลาดในการบันทึกข้อมูล');
        console.error(error);
    }
}

/**
 * ยืนยัน Blacklist
 */
function confirmBlacklist(id) {
    const seller = sellers.find(s => s.id === id);
    
    if (!seller) return;

    const reason = prompt(`ยืนยัน Blacklist: ${seller.full_name}\n\nกรุณาระบุเหตุผล:`);
    
    if (reason === null) return; // ยกเลิก
    
    blacklistSeller(id, reason);
}

/**
 * Blacklist ผู้ขาย
 */
async function blacklistSeller(id, reason) {
    try {
        const response = await fetch(`${API_URL}/sellers/blacklist`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': `Bearer ${getToken()}`
            },
            body: JSON.stringify({ id, reason })
        });

        const result = await response.json();

        if (result.success) {
            showSuccess('Blacklist ผู้ขายสำเร็จ');
            loadSellers();
        } else {
            showError(result.message);
        }
    } catch (error) {
        showError('เกิดข้อผิดพลาด');
        console.error(error);
    }
}

/**
 * ยกเลิก Blacklist
 */
async function unblacklistSeller(id) {
    if (!confirm('ยืนยันการยกเลิก Blacklist?')) return;

    try {
        const response = await fetch(`${API_URL}/sellers/unblacklist`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': `Bearer ${getToken()}`
            },
            body: JSON.stringify({ id })
        });

        const result = await response.json();

        if (result.success) {
            showSuccess('ยกเลิก Blacklist สำเร็จ');
            loadSellers();
        } else {
            showError(result.message);
        }
    } catch (error) {
        showError('เกิดข้อผิดพลาด');
        console.error(error);
    }
}

/**
 * ปิด Modal
 */
function closeSellerModal() {
    document.getElementById('sellerModal').style.display = 'none';
    document.getElementById('sellerForm').reset();
    currentSeller = null;
}

/**
 * Format เลขบัตรประชาชน X-XXXX-XXXXX-XX-X
 */
function formatIdCard(idCard) {
    if (!idCard) return '-';
    const cleaned = idCard.replace(/[^0-9]/g, '');
    if (cleaned.length !== 13) return idCard;
    return `${cleaned[0]}-${cleaned.substr(1, 4)}-${cleaned.substr(5, 5)}-${cleaned.substr(10, 2)}-${cleaned[12]}`;
}

/**
 * Auto-format บัตรประชาชนขณะพิมพ์
 */
function setupIdCardFormatter() {
    const idCardInput = document.getElementById('idCard');
    
    idCardInput.addEventListener('input', function(e) {
        let value = e.target.value.replace(/[^0-9]/g, '');
        
        if (value.length > 13) {
            value = value.substr(0, 13);
        }
        
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

/**
 * Format วันที่
 */
function formatDate(dateString) {
    if (!dateString) return '-';
    const date = new Date(dateString);
    return date.toLocaleDateString('th-TH', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    });
}

/**
 * Format ตัวเลข
 */
function formatNumber(num) {
    if (!num) return '0.00';
    return parseFloat(num).toLocaleString('th-TH', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
}

/**
 * Escape HTML
 */
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// ปิด modal เมื่อคลิกนอก modal
window.onclick = function(event) {
    const modal = document.getElementById('sellerModal');
    if (event.target === modal) {
        closeSellerModal();
    }
}

// Enter key ในช่องค้นหา
document.getElementById('searchInput').addEventListener('keypress', function(e) {
    if (e.key === 'Enter') {
        searchSellers();
    }
});
