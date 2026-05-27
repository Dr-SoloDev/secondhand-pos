/**
 * Price Tiers Management
 * ตั้งค่าราคารับซื้อ 3 ระดับ ต่อหมวดหมู่สินค้า
 */

// Escape HTML to prevent XSS
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Check if user has permission (admin/manager only)
function checkPermission() {
    const userJson = localStorage.getItem('posUser');
    if (!userJson) return false;

    const user = JSON.parse(userJson);
    return user.role === 'admin' || user.role === 'manager';
}

// Load price tiers data
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

// Render the price tiers table
function renderPriceTiersTable(categories) {
    const tbody = document.getElementById('priceTiersTableBody');

    if (!categories || categories.length === 0) {
        tbody.innerHTML = '<tr><td colspan="6" class="text-center">ไม่พบหมวดหมู่สินค้า</td></tr>';
        return;
    }

    tbody.innerHTML = categories.map(cat => `
        <tr id="row-${cat.id}">
            <td>${escapeHtml(String(cat.id))}</td>
            <td>${escapeHtml(cat.name)}</td>
            <td>
                <input type="number" 
                    id="tier1-${cat.id}" 
                    class="form-control tier-input" 
                    value="${parseFloat(cat.price_tier1 || 0).toFixed(2)}" 
                    min="0" 
                    step="0.01"
                    placeholder="0.00">
            </td>
            <td>
                <input type="number" 
                    id="tier2-${cat.id}" 
                    class="form-control tier-input" 
                    value="${parseFloat(cat.price_tier2 || 0).toFixed(2)}" 
                    min="0" 
                    step="0.01"
                    placeholder="0.00">
            </td>
            <td>
                <input type="number" 
                    id="tier3-${cat.id}" 
                    class="form-control tier-input" 
                    value="${parseFloat(cat.price_tier3 || 0).toFixed(2)}" 
                    min="0" 
                    step="0.01"
                    placeholder="0.00">
            </td>
            <td>
                <button class="btn-primary btn-sm" onclick="savePriceTier(${cat.id})">💾 บันทึก</button>
            </td>
        </tr>
    `).join('');
}

// Save price tier for a specific category
async function savePriceTier(categoryId) {
    const tier1Input = document.getElementById(`tier1-${categoryId}`);
    const tier2Input = document.getElementById(`tier2-${categoryId}`);
    const tier3Input = document.getElementById(`tier3-${categoryId}`);

    const priceTier1 = parseFloat(tier1Input.value) || 0;
    const priceTier2 = parseFloat(tier2Input.value) || 0;
    const priceTier3 = parseFloat(tier3Input.value) || 0;

    // Validate non-negative
    if (priceTier1 < 0 || priceTier2 < 0 || priceTier3 < 0) {
        showNotification('ราคาต้องไม่ติดลบ', 'error');
        return;
    }

    const result = await apiRequest('price-tiers/category', 'PUT', {
        id: categoryId,
        price_tier1: priceTier1,
        price_tier2: priceTier2,
        price_tier3: priceTier3
    });

    if (result.status === 'success') {
        showNotification('บันทึกราคา Tier สำเร็จ', 'success');
        // Update input values to formatted version
        tier1Input.value = priceTier1.toFixed(2);
        tier2Input.value = priceTier2.toFixed(2);
        tier3Input.value = priceTier3.toFixed(2);
    } else {
        showNotification(result.message || 'ไม่สามารถบันทึกได้', 'error');
    }
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    loadPriceTiers();
});
