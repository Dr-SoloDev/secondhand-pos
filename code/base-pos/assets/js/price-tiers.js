function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function checkPermission() {
    const userJson = localStorage.getItem('posUser');
    if (!userJson) return false;
    const user = JSON.parse(userJson);
    return user.role === 'admin' || user.role === 'manager';
}

async function loadPriceTiers() {
    const hasPermission = checkPermission();
    if (!hasPermission) {
        document.getElementById('accessDenied').style.display = 'block';
        document.getElementById('priceTiersTable').style.display = 'none';
        return;
    }
    const result = await apiRequest('price-tiers', 'GET');
    if (result.status === 'success') {
        renderPriceTiersTable(result.data);
    } else {
        showNotification(result.message || 'ไม่สามารถโหลดข้อมูลได้', 'error');
    }
}

function renderPriceTiersTable(items) {
    const tbody = document.getElementById('priceTiersTableBody');
    if (!items || items.length === 0) {
        tbody.innerHTML = '<tr><td colspan="5" class="text-center">ไม่พบรายการสินค้าในแคตตาล็อก</td></tr>';
        return;
    }

    tbody.innerHTML = items.map(item => {
        const tiers = (item.tier_prices || []).slice().sort((a, b) => (a.price || 0) - (b.price || 0));
        const tiersHtml = tiers.map((t, i) => `
            <div class="tier-row" style="display:flex;gap:6px;align-items:center;margin-bottom:4px">
                <input type="text" class="form-control tier-label-input"
                    data-item="${item.id}" data-idx="${i}"
                    value="${escapeHtml(t.label)}"
                    style="width:150px" placeholder="ชื่อระดับ">
                <input type="number" class="form-control tier-price-input"
                    data-item="${item.id}" data-idx="${i}"
                    value="${parseFloat(t.price || 0).toFixed(2)}"
                    min="0" step="0.01" style="width:110px" placeholder="0.00">
                ${tiers.length > 1 ? `<button class="btn btn-sm btn-danger" onclick="removeTier(${item.id}, ${i})" style="padding:2px 8px;font-size:12px">ลบ</button>` : ''}
            </div>
        `).join('');

        return `
            <tr id="row-${item.id}">
                <td>${escapeHtml(item.code)}</td>
                <td>${escapeHtml(item.name)}</td>
                <td>${escapeHtml(item.category_name || '—')}</td>
                <td>
                    <div id="tiersContainer-${item.id}">
                        ${tiersHtml}
                    </div>
                    <button class="btn btn-sm btn-secondary" onclick="addTier(${item.id})" style="margin-top:4px;font-size:12px">
                        + เพิ่มระดับ
                    </button>
                </td>
                <td>
                    <button class="btn-primary btn-sm" onclick="savePriceTier(${item.id})">บันทึก</button>
                </td>
            </tr>
        `;
    }).join('');
}

function getTiersForItem(itemId) {
    const container = document.getElementById(`tiersContainer-${itemId}`);
    const labelInputs = container.querySelectorAll('.tier-label-input');
    const priceInputs = container.querySelectorAll('.tier-price-input');
    const tiers = [];
    labelInputs.forEach((labelInput, i) => {
        const priceInput = priceInputs[i];
        if (!priceInput) return;
        const label = labelInput.value.trim();
        const price = parseFloat(priceInput.value) || 0;
        if (!label) return;
        tiers.push({ label, price });
    });
    return tiers;
}

function addTier(itemId) {
    const container = document.getElementById(`tiersContainer-${itemId}`);
    const idx = container.querySelectorAll('.tier-row').length;
    const row = document.createElement('div');
    row.className = 'tier-row';
    row.style.cssText = 'display:flex;gap:6px;align-items:center;margin-bottom:4px';
    row.innerHTML = `
        <input type="text" class="form-control tier-label-input"
            data-item="${itemId}" data-idx="${idx}"
            value="บิล${idx + 1}"
            style="width:150px" placeholder="ชื่อระดับ">
        <input type="number" class="form-control tier-price-input"
            data-item="${itemId}" data-idx="${idx}"
            value="0.00" min="0" step="0.01"
            style="width:110px" placeholder="0.00">
        <button class="btn btn-sm btn-danger" onclick="removeTier(${itemId}, ${idx})" style="padding:2px 8px;font-size:12px">ลบ</button>
    `;
    container.appendChild(row);

    const saveBtn = document.querySelector(`#row-${itemId} .btn-primary`);
    if (saveBtn) saveBtn.style.display = 'inline-block';
}

function removeTier(itemId, idx) {
    const container = document.getElementById(`tiersContainer-${itemId}`);
    const rows = container.querySelectorAll('.tier-row');
    if (rows.length <= 1) return;
    rows[idx].remove();

    const remaining = container.querySelectorAll('.tier-row');
    if (remaining.length <= 1) {
        remaining.forEach(r => {
            const delBtn = r.querySelector('.btn-danger');
            if (delBtn) delBtn.style.display = 'none';
        });
    }
}

async function savePriceTier(itemId) {
    const tiers = getTiersForItem(itemId);
    if (tiers.length === 0) {
        showNotification('กรุณาเพิ่มอย่างน้อย 1 ระดับ', 'error');
        return;
    }
    for (const t of tiers) {
        if (!t.label) {
            showNotification('กรุณากรอกชื่อทุกระดับ', 'error');
            return;
        }
        if (t.price < 0) {
            showNotification(`ราคา "${t.label}" ต้องไม่ติดลบ`, 'error');
            return;
        }
    }
    const result = await apiRequest('price-tiers/category', 'PUT', {
        id: itemId,
        tiers: tiers
    });
    if (result.status === 'success') {
        showNotification('บันทึกราคา ระดับ สำเร็จ', 'success');
        loadPriceTiers();
    } else {
        showNotification(result.message || 'ไม่สามารถบันทึกได้', 'error');
    }
}

async function loadCategories() {
    const result = await apiRequest('inventory/categories', 'GET');
    if (result.status !== 'success') return;
    const sel = document.getElementById('newCategory');
    result.data.forEach(c => {
        const opt = document.createElement('option');
        opt.value = c.id;
        opt.textContent = c.name;
        sel.appendChild(opt);
    });
}

function openAddModal() {
    document.getElementById('newCode').value = '';
    document.getElementById('newName').value = '';
    document.getElementById('newUnit').value = 'กก.';
    document.getElementById('newCategory').value = '';
    document.getElementById('addModal').classList.add('show');
}

function closeAddModal() {
    document.getElementById('addModal').classList.remove('show');
}

async function submitAddItem() {
    const code = document.getElementById('newCode').value.trim();
    const name = document.getElementById('newName').value.trim();
    if (!code || !name) {
        showNotification('กรุณากรอกรหัสและชื่อสินค้า', 'error');
        return;
    }
    const result = await apiRequest('price-tiers', 'POST', {
        code,
        name,
        category_id: document.getElementById('newCategory').value || null,
        default_unit: document.getElementById('newUnit').value.trim() || 'กก.',
        tiers: [{ label: 'บิล1', price: 0 }]
    });
    if (result.status === 'success') {
        showNotification('เพิ่มรายการสำเร็จ', 'success');
        closeAddModal();
        loadPriceTiers();
    } else {
        showNotification(result.message || 'ไม่สามารถเพิ่มได้', 'error');
    }
}

document.addEventListener('DOMContentLoaded', function() {
    loadPriceTiers();
    loadCategories();
});
