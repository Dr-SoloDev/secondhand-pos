let sellers = [];
let currentSeller = null;
let pendingSellerIdPhoto = null; // File | null

document.addEventListener('DOMContentLoaded', function() {
    loadSellers();
    setupIdCardFormatter();
    document.getElementById('isBlacklisted').addEventListener('change', function() {
        document.getElementById('blacklistReasonGroup').classList.toggle('show', this.checked);
    });

    // Photo upload for seller ID card
    initSellerPhotoUpload();
});

async function loadSellers() {
    const includeBlacklisted = document.getElementById('showBlacklisted').checked;
    const query = includeBlacklisted ? '?include_blacklisted=true' : '';
    showTableLoading('sellersTableBody', 9, 5);
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
        const keyword = document.getElementById('searchInput').value.trim();
        const msg = keyword.length >= 2
            ? `ไม่พบผู้ขายที่ค้นหา "${escapeHtml(keyword)}"`
            : 'ยังไม่มีข้อมูลผู้ขาย';
        tbody.innerHTML = `<tr><td colspan="9" class="text-center">${msg}</td></tr>`;
        return;
    }

    tbody.innerHTML = sellers.map(seller => {
        const statusBadge = seller.is_blacklisted
            ? `<span class="badge badge-danger" title="${escapeHtml(seller.blacklist_reason || 'ไม่ได้ระบุเหตุผล')}">⚠️ บัญชีดำ</span>`
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
                <td class="text-center">${seller.total_transactions ?? '-'}</td>
                <td class="text-right">${formatNumber(seller.total_amount)}</td>
                <td>${lastTransaction}</td>
                <td>${statusBadge}</td>
                <td>
                    <button class="btn-sm btn-info" onclick="viewSeller(${seller.id})" title="ดูรายละเอียด"><i class="icon-search"></i></button>
                    <a class="btn-sm btn-secondary" href="seller-history.html?id=${seller.id}" title="ประวัติการขาย" style="display:inline-flex;align-items:center;text-decoration:none"><i class="icon-report"></i></a>
                    <button class="btn-sm btn-warning" onclick="editSeller(${seller.id})" title="แก้ไข"><i class="icon-edit"></i></button>
                    ${seller.is_blacklisted
                        ? `<button class="btn-sm btn-success" onclick="unblacklistSeller(${seller.id})" title="ยกเลิกบัญชีดำ">✓</button>`
                        : `<button class="btn-sm btn-danger" onclick="confirmBlacklist(${seller.id})" title="ขึ้นบัญชีดำ">×</button>`
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

    const includeBlacklisted = document.getElementById('showBlacklisted').checked;
    const result = await apiRequest(`sellers/search?q=${encodeURIComponent(keyword)}${includeBlacklisted ? '&include_blacklisted=true' : ''}`);

    if (result.status === 'success') {
        sellers = (result.data || []).map(s => ({
            ...s,
            full_name: s.name,
            id_card: s.national_id,
            total_transactions: s.total_transactions ?? 0,
            total_amount: s.total_amount ?? 0,
            last_transaction_at: s.last_transaction_at ?? null,
        }));
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
    document.getElementById('blacklistReasonGroup').classList.remove('show');
    const pdpaCheck = document.getElementById('pdpaConsent');
    pdpaCheck.checked = false;
    pdpaCheck.disabled = false;
    document.getElementById('pdpaConsentText').textContent =
        'ยินยอมให้ร้านเก็บข้อมูลส่วนบุคคลและรูปบัตรประชาชน เพื่อปฏิบัติตามกฎหมายรับซื้อของเก่า (ม.357) เท่านั้น';
    resetSellerPhoto();
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
    document.getElementById('vehiclePlate').value = currentSeller.vehicle_plate || '';
    const vt = currentSeller.vehicle_type || '';
    document.querySelectorAll('input[name="vehicleType"]').forEach(r => r.checked = r.value === vt);
    const bl = currentSeller.is_blacklisted == 1;
    document.getElementById('isBlacklisted').checked = bl;
    document.getElementById('blacklistReason').value = currentSeller.blacklist_reason || '';
    document.getElementById('blacklistReasonGroup').classList.toggle('show', bl);

    const pdpaCheck = document.getElementById('pdpaConsent');
    pdpaCheck.checked = true;
    pdpaCheck.disabled = true;
    document.getElementById('pdpaConsentText').textContent = 'ให้ความยินยอมแล้ว';

    // Show existing ID card photo if available
    resetSellerPhoto();
    if (currentSeller.id_card_photo) {
        const thumb = document.getElementById('sellerPhotoThumb');
        const area = document.getElementById('sellerPhotoArea');
        thumb.src = currentSeller.id_card_photo;
        thumb.style.display = 'block';
        area.classList.add('has-photo');
        document.getElementById('sellerPhotoIcon').style.display = 'none';
        document.querySelector('#sellerPhotoArea .seller-photo-text').style.display = 'none';
    }

    document.getElementById('sellerModal').classList.add('show');
}

async function viewSeller(id) {
    // Show modal with loading state — clear stale data first
    document.getElementById('viewSellerName').textContent = 'กำลังโหลด...';
    document.getElementById('viewSellerBlacklistBadge').style.display = 'none';
    document.getElementById('viewSellerStats').textContent = '';
    document.getElementById('viewSellerIdCard').textContent = '-';
    document.getElementById('viewSellerPhone').textContent = '-';
    document.getElementById('viewSellerVehicle').textContent = '-';
    document.getElementById('viewSellerAddress').textContent = '-';
    document.getElementById('viewSellerNotes').textContent = '-';
    document.getElementById('viewSellerTransactionList').innerHTML =
        '<div class="seller-view-loading">กำลังโหลด...</div>';
    document.getElementById('viewSellerModal').classList.add('show');

    const res = await apiRequest(`sellers/data-center?id=${id}`);
    if (res.status !== 'success') {
        document.getElementById('viewSellerName').textContent = 'เกิดข้อผิดพลาด';
        document.getElementById('viewSellerTransactionList').innerHTML =
            '<div class="seller-view-error">ไม่สามารถโหลดข้อมูลได้</div>';
        showNotification(res.message || 'เกิดข้อผิดพลาดในการโหลดข้อมูล', 'error');
        return;
    }

    const { seller, transactions, summary } = res.data;

    // ---- HEADER ----
    document.getElementById('viewSellerName').textContent = seller.full_name;

    const badge = document.getElementById('viewSellerBlacklistBadge');
    if (seller.is_blacklisted) {
        badge.style.display = 'inline-block';
        badge.textContent = `⛔ ${seller.blacklist_reason || 'ไม่ระบุเหตุผล'}`;
    } else {
        badge.style.display = 'none';
    }

    // ---- INFO GRID ----
    document.getElementById('viewSellerIdCard').textContent = formatIdCard(seller.id_card) || '-';
    document.getElementById('viewSellerPhone').textContent = seller.phone || '-';
    document.getElementById('viewSellerVehicle').textContent = seller.vehicle_plate || '-';
    document.getElementById('viewSellerVehicleType').textContent = seller.vehicle_type || '-';
    document.getElementById('viewSellerAddress').textContent = seller.address || '-';
    document.getElementById('viewSellerNotes').textContent = seller.notes || '-';

    // PDPA
    const pdpaEl = document.getElementById('viewSellerPdpa');
    if (seller.pdpa_consented_at) {
        const d = safeDate(seller.pdpa_consented_at);
        if (d && !isNaN(d.getTime())) {
            pdpaEl.textContent = `✓ ${String(d.getDate()).padStart(2,'0')}/${String(d.getMonth()+1).padStart(2,'0')}/${d.getFullYear()}`;
        } else {
            pdpaEl.textContent = '✓ ยินยอม';
        }
        pdpaEl.className = 'pdpa-consented';
    } else {
        pdpaEl.textContent = '-';
        pdpaEl.className = '';
    }

    // ---- ID CARD PHOTO ----
    const photoImg = document.getElementById('sellerDcIdPhoto');
    const noPhoto = document.getElementById('sellerDcNoPhoto');
    if (seller.id_card_photo) {
        photoImg.src = seller.id_card_photo;
        photoImg.style.display = 'block';
        noPhoto.style.display = 'none';
    } else {
        photoImg.style.display = 'none';
        noPhoto.style.display = 'block';
    }

    // ---- STATS BAR ----
    document.getElementById('viewSellerStats').textContent =
        `🛒 มาขาย ${summary.total_pos} ครั้ง · ${summary.total_items_sold} รายการ · ยอดรวม ${formatNumber(summary.total_amount)} บาท` +
        (summary.first_transaction ? ` · ตั้งแต่ ${formatDate(summary.first_transaction)}` : '') +
        (summary.last_transaction ? ` ถึง ${formatDate(summary.last_transaction)}` : '');

    // ---- BLACKLIST INFO ----
    const blBar = document.getElementById('viewSellerBlacklist');
    if (seller.is_blacklisted) {
        blBar.classList.add('show');
        blBar.textContent = `⛔ บัญชีดำ: ${seller.blacklist_reason || 'ไม่ได้ระบุเหตุผล'}`;
    } else {
        blBar.classList.remove('show');
    }

    // ---- TRANSACTION LIST ----
    const safeTransactions = transactions || [];
    document.getElementById('viewSellerPoCount').textContent = `(${safeTransactions.length} รายการ)`;

    const listEl = document.getElementById('viewSellerTransactionList');
    if (!safeTransactions.length) {
        listEl.innerHTML = '<div class="seller-view-empty">ยังไม่มีประวัติการขาย</div>';
        return;
    }

    listEl.innerHTML = transactions.map((po, idx) => renderPoCard(po)).join('');
}

function renderPoCard(po) {
    const statusBadge = po.status !== 'completed'
        ? `<span class="badge badge-warning" style="font-size:10px;padding:1px 6px">${escapeHtml(po.status)}</span>`
        : '';

    // Items table
    let itemsHtml = '';
    if (po.items && po.items.length) {
        itemsHtml = `
            <table class="po-items-table">
                <thead>
                    <tr>
                        <th>รายการ <span class="item-meta">(📸 ถ้ามีรูป)</span></th>
                        <th class="col-qty">จำนวน</th>
                        <th class="col-price">ราคา/หน่วย</th>
                        <th class="col-total">รวม</th>
                    </tr>
                </thead>
                <tbody>
                    ${po.items.map(item => {
                        const qty = parseFloat(item.quantity);
                        const ded = parseFloat(item.weight_deduction || 0);
                        const qtyDisplay = ded > 0
                            ? `${qty.toFixed(3)} <span class="qty-deduction">(หัก ${ded.toFixed(3)})</span>`
                            : qty.toFixed(3);

                        // Per-item photo thumbnails
                        const itemPhotosHtml = (item.photos && item.photos.length)
                            ? `<div class="item-photo-thumbs">
                                ${item.photos.map(p =>
                                    `<img src="${escapeHtml(p.photo_path)}" class="item-photo-thumb" onclick="expandPhoto(this)" title="รูปสินค้าชิ้นนี้">`
                                ).join('')}
                               </div>`
                            : (item.photo_path
                                ? `<div class="item-photo-thumbs"><img src="${escapeHtml(item.photo_path)}" class="item-photo-thumb" onclick="expandPhoto(this)" title="รูปสินค้าชิ้นนี้"></div>`
                                : '');

                        return `<tr>
                            <td class="item-name">
                                ${escapeHtml(item.item_name)}
                                ${item.category_name ? `<span class="item-meta"> · ${escapeHtml(item.category_name)}</span>` : ''}
                                ${item.notes ? `<div class="item-notes">${escapeHtml(item.notes)}</div>` : ''}
                                ${itemPhotosHtml}
                            </td>
                            <td class="text-center">${qtyDisplay} ${escapeHtml(item.unit)}</td>
                            <td class="text-right">${formatNumber(item.unit_price)}</td>
                            <td class="text-right"><strong>${formatNumber(item.total_price)}</strong></td>
                        </tr>`;
                    }).join('')}
                </tbody>
            </table>`;
    }

    // Photos gallery (only PO-level photos; item photos shown inline above)
    const poLevelPhotos = (po.photos || []).filter(p => !p.item_id);
    let photosHtml = '';
    if (poLevelPhotos.length) {
        photosHtml = `
            <div class="po-photo-gallery">
                <span class="po-photo-gallery-label">📸 รูปรวมของบิลนี้</span>
                ${poLevelPhotos.map(p =>
                    `<img src="${escapeHtml(p.photo_path)}" onclick="expandPhoto(this)" title="คลิกดูรูปใหญ่">`
                ).join('')}
            </div>`;
    }

    const notesHtml = po.notes
        ? `<div class="po-card-notes">📝 ${escapeHtml(po.notes)}</div>`
        : '';

    return `
        <div class="po-card">
            <div class="po-card-header" onclick="togglePoCard(this)">
                <span class="po-card-ref">${escapeHtml(po.reference_no)}</span>
                <span class="po-card-date">${formatDate(po.created_at)}</span>
                <span class="po-card-branch">
                    ${escapeHtml(po.branch_name)}${po.processed_by ? ' · ' + escapeHtml(po.processed_by) : ''}
                </span>
                <span class="po-card-amount">${formatNumber(po.total_amount)}</span>
                ${statusBadge}
                <span class="po-card-toggle">▶</span>
            </div>
            <div class="po-card-body">
                ${itemsHtml}
                ${photosHtml}
                ${notesHtml}
            </div>
        </div>`;
}

function togglePoCard(headerEl) {
    const body = headerEl.nextElementSibling;
    const icon = headerEl.querySelector('.po-card-toggle');
    const isOpen = body.classList.contains('open');
    body.classList.toggle('open');
    icon.classList.toggle('open');
}

function expandPhoto(imgEl) {
    const lb = document.getElementById('photoLightbox');
    const lbImg = document.getElementById('lightboxImg');
    if (imgEl && imgEl.src) {
        lbImg.src = imgEl.src;
        lb.classList.add('show');
    }
}

function closeLightbox(event) {
    if (event) event.stopPropagation();
    document.getElementById('photoLightbox').classList.remove('show');
}

function closeViewSellerModal() {
    document.getElementById('viewSellerModal').classList.remove('show');
    // Reset transaction list for next open
    document.getElementById('viewSellerTransactionList').innerHTML = '';
}

function safeDate(str) {
    if (!str) return null;
    const d = new Date(str.replace(' ', 'T'));
    return isNaN(d.getTime()) ? null : d;
}

async function saveSeller() {
    const sellerId = document.getElementById('sellerId').value;
    const isEdit = sellerId !== '';
    const fullName = document.getElementById('fullName').value.trim();
    const phone = document.getElementById('phone').value.trim();
    const idCard = document.getElementById('idCard').value.replace(/[^0-9]/g, '');
    const address = document.getElementById('address').value.trim();
    const notes = document.getElementById('notes').value.trim();
    const isBlacklisted = document.getElementById('isBlacklisted').checked ? 1 : 0;
    const pdpaConsent = document.getElementById('pdpaConsent').checked;

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

    if (!isEdit && !pdpaConsent) {
        showNotification('กรุณายินยอมให้เก็บข้อมูลก่อน', 'error');
        return;
    }

    const vehiclePlate = document.getElementById('vehiclePlate').value.trim();
    const vehicleType = document.querySelector('input[name="vehicleType"]:checked')?.value || '';
    const data = {
        full_name: fullName,
        phone: phone || null,
        id_card: idCard || null,
        address: address || null,
        notes: notes || null,
        vehicle_plate: vehiclePlate || null,
        vehicle_type: vehicleType || null,
        is_blacklisted: isBlacklisted,
        blacklist_reason: isBlacklisted ? (document.getElementById('blacklistReason').value.trim() || null) : null,
        pdpa_consent: pdpaConsent,
    };

    if (isEdit) {
        data.id = parseInt(sellerId, 10);
    }

    const endpoint = isEdit ? 'sellers/seller' : 'sellers';
    const method = isEdit ? 'PUT' : 'POST';

    const saveBtn = document.querySelector('#sellerModal .btn-primary');
    setButtonLoading(saveBtn, true);
    try {
        const result = await apiRequest(endpoint, method, data);

        if (result.status === 'success') {
            const savedId = isEdit ? parseInt(sellerId, 10) : result.data?.id;
            if (pendingSellerIdPhoto && savedId) {
                const fd = new FormData();
                fd.append('photo', pendingSellerIdPhoto);
                await fetch(`${window.apiPath}/sellers/photo?id=${savedId}`, {
                    method: 'POST',
                    credentials: 'include',
                    body: fd,
                });
                pendingSellerIdPhoto = null;
            }
            showNotification(isEdit ? 'แก้ไขข้อมูลสำเร็จ' : 'เพิ่มผู้ขายสำเร็จ', 'success');
            closeSellerModal();
            loadSellers();
        } else {
            showNotification(result.message || 'เกิดข้อผิดพลาดในการบันทึกข้อมูล', 'error');
        }
    } finally {
        setButtonLoading(saveBtn, false);
    }
}

function confirmBlacklist(id) {
    const seller = sellers.find(s => s.id === id);
    if (!seller) return;

    // ใช้ Modal แทน prompt()
    const overlay = document.createElement('div');
    overlay.className = 'blacklist-modal-overlay';
    overlay.innerHTML = `
        <div class="modal-content blacklist-modal-inner">
            <div class="modal-header">
                <h3>⚠️ ยืนยันบัญชีดำ</h3>
                <button class="close-modal" onclick="this.closest('.blacklist-modal-overlay').remove()">&times;</button>
            </div>
            <div class="modal-body">
                <p class="blacklist-modal-body">ผู้ขาย: <strong>${escapeHtml(seller.full_name)}</strong></p>
                <label class="blacklist-modal-label" for="blacklistReasonConfirm">เหตุผลที่ขึ้นบัญชีดำ <span class="blacklist-modal-required">*</span></label>
                <textarea id="blacklistReasonConfirm" class="form-control" rows="3" placeholder="ระบุเหตุผล..." style="margin-top:4px;"></textarea>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="this.closest('.blacklist-modal-overlay').remove()">ยกเลิก</button>
                <button class="btn btn-danger" id="confirmBlacklistBtn">ยืนยันบัญชีดำ</button>
            </div>
        </div>
    `;
    document.body.appendChild(overlay);

    document.getElementById('confirmBlacklistBtn').addEventListener('click', function() {
        const reason = document.getElementById('blacklistReasonConfirm').value.trim();
        if (!reason) {
            showNotification('กรุณาระบุเหตุผล', 'error');
            return;
        }
        overlay.remove();
        blacklistSeller(id, reason);
    });
}

let _blacklistInProgress = false;

async function blacklistSeller(id, reason) {
    if (_blacklistInProgress) return;
    _blacklistInProgress = true;
    try {
        const result = await apiRequest('sellers/blacklist', 'POST', { id, reason });
        if (result.status === 'success') {
            showNotification('ขึ้นบัญชีดำผู้ขายสำเร็จ', 'success');
            loadSellers();
        } else {
            showNotification(result.message || 'เกิดข้อผิดพลาด', 'error');
        }
    } finally {
        _blacklistInProgress = false;
    }
}

async function unblacklistSeller(id) {
    if (_blacklistInProgress) return;
    const seller = sellers.find(s => s.id === id);
    const sellerName = seller ? seller.full_name : `#${id}`;
    showUnblacklistModal(id, sellerName);
}

function showUnblacklistModal(sellerId, sellerName) {
    const overlay = document.createElement('div');
    overlay.className = 'blacklist-modal-overlay';
    overlay.innerHTML = `
        <div class="modal-content blacklist-modal-inner">
            <div class="modal-header">
                <h3>✓ ยืนยันยกเลิกบัญชีดำ</h3>
                <button class="close-modal" onclick="this.closest('.blacklist-modal-overlay').remove()">&times;</button>
            </div>
            <div class="modal-body">
                <p>ยืนยันการยกเลิกบัญชีดำผู้ขาย: <strong>${escapeHtml(sellerName)}</strong>?</p>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="this.closest('.blacklist-modal-overlay').remove()">ยกเลิก</button>
                <button class="btn btn-success" id="confirmUnblacklistBtn">ยืนยัน</button>
            </div>
        </div>
    `;
    document.body.appendChild(overlay);

    document.getElementById('confirmUnblacklistBtn').addEventListener('click', function() {
        overlay.remove();
        _doUnblacklist(sellerId);
    });
}

async function _doUnblacklist(id) {
    if (_blacklistInProgress) return;
    _blacklistInProgress = true;
    try {
        const result = await apiRequest('sellers/unblacklist', 'POST', { id });
        if (result.status === 'success') {
            showNotification('ยกเลิกบัญชีดำสำเร็จ', 'success');
            loadSellers();
        } else {
            showNotification(result.message || 'เกิดข้อผิดพลาด', 'error');
        }
    } finally {
        _blacklistInProgress = false;
    }
}

function closeSellerModal() {
    document.getElementById('sellerModal').classList.remove('show');
    document.getElementById('sellerForm').reset();
    currentSeller = null;
    resetSellerPhoto();
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
    // Safari-safe: replace space with T for ISO 8601
    const date = new Date(dateString.replace(' ', 'T'));
    if (isNaN(date.getTime())) return dateString;
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

window.addEventListener('click', function(event) {
    const modal = document.getElementById('sellerModal');
    if (event.target === modal) closeSellerModal();
    const viewModal = document.getElementById('viewSellerModal');
    if (event.target === viewModal) closeViewSellerModal();
});

document.getElementById('searchInput').addEventListener('keypress', function(e) {
    if (e.key === 'Enter') searchSellers();
});

// ===== Photo Upload =====
let sellerPhotoFileInput = null;

function initSellerPhotoUpload() {
    // Create hidden file input
    sellerPhotoFileInput = document.createElement('input');
    sellerPhotoFileInput.type = 'file';
    sellerPhotoFileInput.accept = 'image/*';
    sellerPhotoFileInput.style.display = 'none';
    sellerPhotoFileInput.id = 'sellerPhotoFileInput';
    document.body.appendChild(sellerPhotoFileInput);

    const area = document.getElementById('sellerPhotoArea');
    const thumb = document.getElementById('sellerPhotoThumb');
    const removeBtn = document.getElementById('sellerPhotoRemove');

    if (!area) return; // Not on sellers page

    // Click area → open file picker
    area.addEventListener('click', function(e) {
        if (e.target === removeBtn || removeBtn?.contains(e.target)) return;
        sellerPhotoFileInput.click();
    });

    // File selected → show preview
    sellerPhotoFileInput.addEventListener('change', function(e) {
        const file = e.target.files[0];
        if (!file) return;
        pendingSellerIdPhoto = file;
        const reader = new FileReader();
        reader.onload = function(ev) {
            thumb.src = ev.target.result;
            thumb.style.display = 'block';
            area.classList.add('has-photo');
            removeBtn.style.display = 'flex';
            document.getElementById('sellerPhotoIcon').style.display = 'none';
            document.querySelector('#sellerPhotoArea .seller-photo-text').style.display = 'none';
        };
        reader.readAsDataURL(file);
    });

    // Remove button → clear photo
    if (removeBtn) {
        removeBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            pendingSellerIdPhoto = null;
            thumb.style.display = 'none';
            area.classList.remove('has-photo');
            removeBtn.style.display = 'none';
            document.getElementById('sellerPhotoIcon').style.display = 'flex';
            document.querySelector('#sellerPhotoArea .seller-photo-text').style.display = 'flex';
            sellerPhotoFileInput.value = '';
        });
    }
}

function resetSellerPhoto() {
    pendingSellerIdPhoto = null;
    const thumb = document.getElementById('sellerPhotoThumb');
    const removeBtn = document.getElementById('sellerPhotoRemove');
    const area = document.getElementById('sellerPhotoArea');
    if (thumb) { thumb.style.display = 'none'; thumb.src = ''; }
    if (removeBtn) removeBtn.style.display = 'none';
    if (area) area.classList.remove('has-photo');
    const icon = document.getElementById('sellerPhotoIcon');
    const text = document.querySelector('#sellerPhotoArea .seller-photo-text');
    if (icon) icon.style.display = 'flex';
    if (text) text.style.display = 'flex';
    if (sellerPhotoFileInput) sellerPhotoFileInput.value = '';
}
