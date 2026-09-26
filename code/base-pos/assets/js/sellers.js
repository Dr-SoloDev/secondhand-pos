let sellers = [];
let currentSeller = null;
let currentViewSellerId = null; // ผู้ขายที่เปิดใน view modal (ใช้กับชุดหลักฐาน/disclosure)
let pendingSellerIdPhoto = null; // File | null
let sellerPermissions = null;

document.addEventListener('DOMContentLoaded', async function() {
    sellerPermissions = await getAppPermissions();
    if (!hasAppPermission('actions.sellers.blacklist', sellerPermissions)) {
        const blacklistToggle = document.getElementById('isBlacklisted');
        if (blacklistToggle) {
            blacklistToggle.disabled = true;
            blacklistToggle.closest('.form-group').style.display = 'none';
        }
        document.getElementById('blacklistReasonGroup').style.display = 'none';
    }
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
    showTableLoading('sellersTableBody', 10, 5);
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
        tbody.innerHTML = `<tr><td colspan="10" class="text-center">${msg}</td></tr>`;
        return;
    }

    tbody.innerHTML = sellers.map(seller => {
        const statusBadge = seller.is_blacklisted
            ? `<span class="badge badge-danger" title="${escapeHtml(seller.blacklist_reason || 'ไม่ได้ระบุเหตุผล')}">⚠️ บัญชีดำ</span>`
            : '<span class="badge badge-success">ปกติ</span>';

        const tierLevel = seller.tier_level || 1;
        const tierBadge = tierLevel > 1
            ? `<span class="badge badge-tier-${tierLevel}">บิล ${tierLevel}</span>`
            : '<span class="badge badge-tier-1">บิล 1</span>';

        const lastTransaction = seller.last_transaction_at
            ? formatDate(seller.last_transaction_at)
            : '-';

        return `
            <tr>
                <td>${seller.id}</td>
                <td>${escapeHtml(seller.full_name)}</td>
                <td>${escapeHtml(maskPhone(seller.phone))}</td>
                <td>${escapeHtml(maskIdCard(seller.id_card))}</td>
                <td class="text-center">${seller.total_transactions ?? '-'}</td>
                <td class="text-right">${formatNumber(seller.total_amount)}</td>
                <td>${lastTransaction}</td>
                <td class="text-center">${tierBadge}</td>
                <td>${statusBadge}</td>
                <td class="text-center">
                    <div class="action-btn-group">
                    <button class="btn-sm btn-info" onclick="viewSeller(${seller.id})" title="ดูรายละเอียด"><i class="icon-search"></i></button>
                    <a class="btn-sm btn-secondary" href="seller-history.html?id=${seller.id}" title="ประวัติการขาย" style="display:inline-flex;align-items:center;text-decoration:none"><i class="icon-report"></i></a>
                    <button class="btn-sm btn-warning" onclick="editSeller(${seller.id})" title="แก้ไข"><i class="icon-edit"></i></button>
                    ${hasAppPermission('actions.sellers.blacklist', sellerPermissions)
                        ? (seller.is_blacklisted
                            ? `<button class="btn-sm btn-success" onclick="unblacklistSeller(${seller.id})" title="ยกเลิกบัญชีดำ">✓</button>`
                            : `<button class="btn-sm btn-danger" onclick="confirmBlacklist(${seller.id})" title="ขึ้นบัญชีดำ">×</button>`)
                        : ''}
                    </div>
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
    const tierGroup = document.getElementById('tierLevelGroup');
    if (tierGroup) {
        tierGroup.style.display = hasAppPermission('actions.sellers.set_tier', sellerPermissions) ? '' : 'none';
        document.getElementById('tierLevel').value = '1';
    }
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

    const tierSelect = document.getElementById('tierLevel');
    const tierGroup = document.getElementById('tierLevelGroup');
    if (tierSelect && tierGroup) {
        tierSelect.value = currentSeller.tier_level || 1;
        tierGroup.style.display = hasAppPermission('actions.sellers.set_tier', sellerPermissions) ? '' : 'none';
    }
    const bl = currentSeller.is_blacklisted == 1;
    document.getElementById('isBlacklisted').checked = bl;
    document.getElementById('blacklistReason').value = currentSeller.blacklist_reason || '';
    document.getElementById('blacklistReasonGroup').classList.toggle('show', bl);

    const pdpaCheck = document.getElementById('pdpaConsent');
    if (currentSeller.pdpa_consented_at) {
        pdpaCheck.checked = true;
        pdpaCheck.disabled = true;
        document.getElementById('pdpaConsentText').textContent = 'ให้ความยินยอมแล้ว';
    } else {
        // ผู้ขายเก่าที่ยังไม่มียินยอม — เปิดให้ติ๊กเพื่อบันทึกย้อนหลังตอนกดบันทึก
        pdpaCheck.checked = false;
        pdpaCheck.disabled = false;
        document.getElementById('pdpaConsentText').textContent = 'ผู้ขายยินยอมให้ร้านเก็บข้อมูลส่วนบุคคลและรูปบัตรประชาชน เพื่อปฏิบัติตามกฎหมายรับซื้อของเก่า (ม.357) เท่านั้น — ติ๊กเพื่อบันทึกความยินยอม';
    }

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
    currentViewSellerId = id;
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
    document.getElementById('viewSellerIdCard').textContent = maskIdCard(seller.id_card);
    document.getElementById('viewSellerPhone').textContent = maskPhone(seller.phone);
    document.getElementById('viewSellerVehicle').textContent = seller.vehicle_plate || '-';
    document.getElementById('viewSellerVehicleType').textContent = seller.vehicle_type || '-';
    document.getElementById('viewSellerAddress').textContent = seller.address || '-';
    document.getElementById('viewSellerNotes').textContent = seller.notes || '-';

    // Disclosure log — เห็นเฉพาะ manager+ (ตรงกับ backend gating)
    const dcBtn = document.getElementById('disclosureFormBtn');
    if (dcBtn) dcBtn.style.display = canManageDisclosure() ? '' : 'none';
    const epBtn = document.getElementById('evidencePackBtn');
    if (epBtn) epBtn.style.display = canManageDisclosure() ? '' : 'none';
    loadDisclosures(id);

    // tier_level display
    const tierLabels = { 1: 'บิล 1 (ทั่วไป)', 2: 'บิล 2', 3: 'บิล 3' };
    const tierEl = document.getElementById('viewSellerTier');
    if (tierEl) {
        const tl = seller.tier_level || 1;
        tierEl.textContent = tierLabels[tl] || 'บิล 1 (ทั่วไป)';
    }

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
    // ปุ่มบันทึกยินยอมย้อนหลัง — โชว์เฉพาะคนที่ยังไม่มี
    const pdpaBtn = document.getElementById('recordPdpaBtn');
    if (pdpaBtn) pdpaBtn.style.display = seller.pdpa_consented_at ? 'none' : '';

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

    // Items table — ปรับ header ให้ตรง Lot (slate เข้ม สลับสีแถว)
    let itemsHtml = '';
    if (po.items && po.items.length) {
        itemsHtml = `
            <table class="po-items-table" style="width:100%;border-collapse:collapse;font-size:13px">
                <thead>
                    <tr style="background:#f1f5f9;color:#475569;font-size:11px;letter-spacing:0.3px;text-transform:uppercase">
                        <th style="text-align:left;padding:8px 10px;font-weight:600">รายการ <span style="font-weight:400;color:#94a3b8">(📸 ถ้ามีรูป)</span></th>
                        <th class="col-qty" style="text-align:center;padding:8px 10px;font-weight:600">จำนวน</th>
                        <th class="col-price" style="text-align:right;padding:8px 10px;font-weight:600">ราคา/หน่วย</th>
                        <th class="col-total" style="text-align:right;padding:8px 10px;font-weight:600">รวม</th>
                    </tr>
                </thead>
                <tbody>
                    ${po.items.map((item, i) => {
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

                        return `<tr style="background:${i%2===0?'#fff':'#f8fafc'};border-top:1px solid #f1f5f9">
                            <td class="item-name" style="padding:8px 10px">
                                ${escapeHtml(item.item_name)}
                                ${item.category_name ? `<span style="color:#64748b;font-size:12px"> · ${escapeHtml(item.category_name)}</span>` : ''}
                                ${item.notes ? `<div style="font-size:11px;color:#64748b;margin-top:2px">${escapeHtml(item.notes)}</div>` : ''}
                                ${itemPhotosHtml}
                            </td>
                            <td class="text-center" style="padding:8px 10px;font-variant-numeric:tabular-nums">${qtyDisplay} ${escapeHtml(item.unit)}</td>
                            <td class="text-right" style="padding:8px 10px;font-variant-numeric:tabular-nums">${formatNumber(item.unit_price)}</td>
                            <td class="text-right" style="padding:8px 10px;font-variant-numeric:tabular-nums"><strong>${formatNumber(item.total_price)}</strong></td>
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
    currentViewSellerId = null;
    // ปิดฟอร์ม disclosure ด้วยกันค้างรอบหน้า
    const dcBox = document.getElementById('disclosureFormBox');
    if (dcBox) dcBox.style.display = 'none';
}

// ===== Evidence Pack (ชุดหลักฐาน) =====
function openEvidencePack() {
    if (!currentViewSellerId) return;
    if (!canManageDisclosure()) {
        showNotification('ส่วนนี้สำหรับผู้จัดการขึ้นไป', 'error');
        return;
    }
    window.open(`evidence-pack.html?id=${encodeURIComponent(currentViewSellerId)}`, '_blank');
}

// ===== PDPA Disclosure Log =====
function toggleDisclosureForm(forceClose = false) {
    const box = document.getElementById('disclosureFormBox');
    if (!box) return;
    const willShow = !forceClose && box.style.display === 'none';
    box.style.display = willShow ? 'block' : 'none';
    if (willShow) {
        document.getElementById('dcRecipient').value = '';
        document.getElementById('dcPurpose').value = '';
        document.getElementById('dcLegalBasis').value = '';
        document.getElementById('dcNotes').value = '';
        document.querySelectorAll('.dcItem').forEach(c => c.checked = false);
        document.getElementById('dcRecipient').focus();
    }
}

async function saveDisclosure() {
    if (!currentViewSellerId) return;
    const recipient = document.getElementById('dcRecipient').value.trim();
    const purpose = document.getElementById('dcPurpose').value.trim();
    if (!recipient || !purpose) {
        showNotification('กรุณากรอก "ผู้รับข้อมูล" และ "วัตถุประสงค์"', 'error');
        return;
    }
    const items = Array.from(document.querySelectorAll('.dcItem:checked')).map(c => c.value);
    const payload = {
        seller_id: currentViewSellerId,
        recipient,
        purpose,
        method: document.getElementById('dcMethod').value,
        items,
        legal_basis: document.getElementById('dcLegalBasis').value.trim() || null,
        notes: document.getElementById('dcNotes').value.trim() || null,
    };
    const res = await apiRequest('sellers/disclosure-log', 'POST', payload);
    if (res.status === 'success') {
        showNotification('บันทึกการเปิดเผยข้อมูลสำเร็จ', 'success');
        toggleDisclosureForm(true);
        loadDisclosures(currentViewSellerId);
    } else {
        showNotification(res.message || 'บันทึกไม่สำเร็จ', 'error');
    }
}

// บันทึกความยินยอม PDPA ย้อนหลัง (stamp ครั้งเดียวฝั่ง backend + ต่อ hash chain)
async function recordPdpaConsent() {
    if (!currentViewSellerId) return;
    if (!confirm('ยืนยันว่าผู้ขายยินยอมให้ร้านเก็บข้อมูลส่วนบุคคลและรูปบัตรประชาชนแล้ว?')) return;
    const res = await apiRequest('sellers/seller', 'PUT', { id: currentViewSellerId, pdpa_consent: true });
    if (res.status === 'success') {
        showNotification('บันทึกความยินยอมสำเร็จ', 'success');
        viewSeller(currentViewSellerId);
    } else {
        showNotification(res.message || 'บันทึกไม่สำเร็จ', 'error');
    }
}

// สิทธิ์ disclosure/evidence-pack — ตรงกับ backend requireAuth roles
function canManageDisclosure() {
    try {
        const u = JSON.parse(localStorage.getItem('posUser') || '{}');
        return ['admin', 'manager', 'super_manager'].includes(u.role);
    } catch (e) { return false; }
}

async function loadDisclosures(sellerId) {
    const listEl = document.getElementById('viewSellerDisclosureList');
    if (!listEl) return;
    if (!canManageDisclosure()) {
        listEl.innerHTML = '<div class="seller-view-empty" style="font-size:13px;color:#64748b;padding:4px 0">ส่วนนี้สำหรับผู้จัดการขึ้นไป</div>';
        return;
    }
    listEl.innerHTML = '<div class="seller-view-loading">กำลังโหลด...</div>';
    const res = await apiRequest(`sellers/disclosure-log?id=${encodeURIComponent(sellerId)}`);
    if (res.status !== 'success') {
        listEl.innerHTML = '<div class="seller-view-error">โหลดรายการเปิดเผยข้อมูลไม่สำเร็จ</div>';
        return;
    }
    const items = (res.data && res.data.items) || [];
    if (!items.length) {
        listEl.innerHTML = '<div class="seller-view-empty" style="font-size:13px;color:#64748b;padding:4px 0">ยังไม่มีการเปิดเผยข้อมูลให้บุคคลภายนอก</div>';
        return;
    }
    const methodLabels = {
        in_person: 'มาที่ร้าน',
        electronic: 'อิเล็กทรอนิกส์',
        api: 'ระบบ/API',
        other: 'อื่นๆ',
    };
    const itemLabels = {
        id_card: 'เลขบัตร', id_card_photo: 'รูปบัตร', phone: 'เบอร์โทร',
        address: 'ที่อยู่', transactions: 'ธุรกรรม', other: 'อื่นๆ',
    };
    listEl.innerHTML = `
        <table style="width:100%;border-collapse:collapse;font-size:12.5px">
            <thead>
                <tr style="background:#f1f5f9;color:#475569;font-size:11px">
                    <th style="text-align:left;padding:6px 8px">วันเวลา</th>
                    <th style="text-align:left;padding:6px 8px">ผู้รับข้อมูล</th>
                    <th style="text-align:left;padding:6px 8px">วัตถุประสงค์</th>
                    <th style="text-align:left;padding:6px 8px">ข้อมูลที่เปิดเผย</th>
                    <th style="text-align:left;padding:6px 8px">ผู้ดำเนินการ</th>
                </tr>
            </thead>
            <tbody>
                ${items.map((x, i) => `
                    <tr style="background:${i % 2 === 0 ? '#fff' : '#f8fafc'};border-top:1px solid #e2e8f0">
                        <td style="padding:6px 8px;white-space:nowrap">${escapeHtml(x.disclosed_at)}</td>
                        <td style="padding:6px 8px">${escapeHtml(x.recipient)}</td>
                        <td style="padding:6px 8px">${escapeHtml(x.purpose)}${x.legal_basis ? ` <span style="color:#64748b">(${escapeHtml(x.legal_basis)})</span>` : ''}</td>
                        <td style="padding:6px 8px">${(x.items || []).map(it => itemLabels[it] || escapeHtml(it)).join(', ') || '-'}
                            <span style="color:#94a3b8">· ${methodLabels[x.method] || escapeHtml(x.method)}</span></td>
                        <td style="padding:6px 8px">${escapeHtml(x.disclosed_by_name || '-')}</td>
                    </tr>`).join('')}
            </tbody>
        </table>`;
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
    const tierLevelEl = document.getElementById('tierLevel');
    const tierGroup = document.getElementById('tierLevelGroup');
    // Only send tier_level if the dropdown is visible (admin/manager users);
    // otherwise omit so the controller doesn't block non-admin users.
    const data = {
        full_name: fullName,
        phone: phone || null,
        id_card: idCard || null,
        address: address || null,
        notes: notes || null,
        vehicle_plate: vehiclePlate || null,
        vehicle_type: vehicleType || null,
        tier_level: (tierLevelEl && tierGroup && tierGroup.style.display !== 'none')
            ? parseInt(tierLevelEl.value, 10) : undefined,
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

// ── Seller Autocomplete Search ──
let _searchDebounceTimer = null;
const SEARCH_DELAY = 300; // ms

document.getElementById('searchInput').addEventListener('input', function(e) {
    clearTimeout(_searchDebounceTimer);
    const q = e.target.value.trim();
    if (q.length < 2) {
        closeSellerSearchDropdown();
        return;
    }
    _searchDebounceTimer = setTimeout(() => searchSellerAutocomplete(q), SEARCH_DELAY);
});

document.getElementById('searchInput').addEventListener('keypress', function(e) {
    if (e.key === 'Enter') {
        closeSellerSearchDropdown();
        searchSellers();
    }
});

// Close dropdown on outside click
document.addEventListener('click', function(e) {
    const container = document.querySelector('.card-tools[style*="position:relative"]');
    if (container && !container.contains(e.target)) {
        closeSellerSearchDropdown();
    }
});

async function searchSellerAutocomplete(q) {
    const resultsBox = document.getElementById('sellerSearchResults');
    if (!resultsBox) return;

    const includeBlacklisted = document.getElementById('showBlacklisted').checked;
    const res = await apiRequest(`sellers/search?q=${encodeURIComponent(q)}${includeBlacklisted ? '&include_blacklisted=true' : ''}`);
    if (res.status !== 'success') { closeSellerSearchDropdown(); return; }

    const items = res.data || [];
    if (items.length === 0) {
        resultsBox.innerHTML = '<div class="seller-item" style="color:#999;cursor:default">ไม่พบผู้ขายที่ค้นหา</div>';
        resultsBox.style.display = 'block';
        return;
    }

    // Single result — auto-select (open Data Center)
    if (items.length === 1) {
        closeSellerSearchDropdown();
        viewSeller(items[0].id);
        return;
    }

    // Multiple results — show dropdown
    resultsBox.innerHTML = '';
    items.forEach(s => {
        const div = document.createElement('div');
        div.className = 'seller-item' + (s.is_blacklisted ? ' seller-item--blacklisted' : '');
        const tierLabel = 'บิล ' + (s.tier_level || 1);
        const phoneFmt = s.phone ? ' · ' + escapeHtml(maskPhone(s.phone)) : '';
        const idCardFmt = s.national_id ? ' · ' + escapeHtml(maskIdCard(s.national_id)) : '';
        const blacklistIcon = s.is_blacklisted ? ' ⛔' : '';
        div.innerHTML = `
            <div class="seller-item__name">
                <strong>${escapeHtml(s.name)}</strong>${blacklistIcon}
                <span class="badge badge-tier-${s.tier_level || 1}" style="font-size:10px;padding:1px 6px">${tierLabel}</span>
            </div>
            <div class="seller-item__id">${phoneFmt}${idCardFmt}</div>`;
        div.addEventListener('click', () => {
            closeSellerSearchDropdown();
            viewSeller(s.id);
        });
        resultsBox.appendChild(div);
    });
    resultsBox.style.display = 'block';
}

function closeSellerSearchDropdown() {
    const box = document.getElementById('sellerSearchResults');
    if (box) { box.style.display = 'none'; box.innerHTML = ''; }
}

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
