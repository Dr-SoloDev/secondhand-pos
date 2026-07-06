const API_REQUEST_TIMEOUT_MS = 15000;
let pendingApiRequests = 0;
let slowApiRequestTimer = null;

// === Cart State Protection ===
// ป้องกันข้อมูลหายเมื่อ token expire

function saveCartState(cartState) {
  sessionStorage.setItem('cart_backup', JSON.stringify(cartState));
  sessionStorage.setItem('cart_backup_time', Date.now());
}

function restoreCartState() {
  const saved = sessionStorage.getItem('cart_backup');
  const savedTime = sessionStorage.getItem('cart_backup_time');
  if (saved && savedTime && (Date.now() - parseInt(savedTime) < 30 * 60 * 1000)) {
    try {
      return JSON.parse(saved);
    } catch (e) {
      clearCartState();
      return null;
    }
  }
  return null;
}

function clearCartState() {
  sessionStorage.removeItem('cart_backup');
  sessionStorage.removeItem('cart_backup_time');
}

document.addEventListener('DOMContentLoaded', function() {
  initOfflineBanner();

  // Check authentication — use posUser (still in localStorage); token is in httpOnly cookie
  const authUserJson = localStorage.getItem('posUser');
  if (!authUserJson) {
    window.location.href = `${basePath}/index.html`;
    return;
  }

  // Toggle user dropdown
  const userDropdown = document.querySelector('.user-dropdown-toggle');
  if (userDropdown) {
    userDropdown.addEventListener('click', function(e) {
      e.preventDefault();
      e.stopPropagation(); // หยุดการ propagate event ไปยัง document
      const dropdownMenu = document.querySelector('.user-dropdown-menu');
      dropdownMenu.classList.toggle('show');
    });
  }

  // Prevent dropdown close when clicking on dropdown menu items
  const dropdownMenu = document.querySelector('.user-dropdown-menu');
  if (dropdownMenu) {
    dropdownMenu.addEventListener('click', function(e) {
      // อนุญาตให้ click ที่ logout ทำงานได้ แต่ป้องกันการปิด dropdown เมื่อคลิกที่เมนูอื่น
      if (!e.target.matches('#logoutBtn') && !e.target.closest('#logoutBtn')) {
        e.stopPropagation();
      }
    });
  }

  // Setup profile link
  const profileLink = document.querySelector('.user-dropdown-menu a[href="#"]:first-child');
  if (profileLink) {
    profileLink.addEventListener('click', function(e) {
      e.preventDefault();
      openProfileModal();
    });
  }

  // Change password button
  const changePasswordBtn = document.getElementById('changePasswordBtn');
  if (changePasswordBtn) {
    changePasswordBtn.addEventListener('click', async function(e) {
      e.preventDefault();
      try {
        await fetch(`${apiPath}/auth/logout`, { method: 'POST' });
      } catch (err) {
        console.error('Logout API error:', err);
      }
      localStorage.removeItem('posUser');
      window.location.href = `${basePath}/index.html`;
    });
  }

  // Set user name
  const userJson = localStorage.getItem('posUser');
  if (userJson) {
    const user = JSON.parse(userJson);
    const userNameElem = document.querySelector('.user-name');
    if (userNameElem) {
      userNameElem.textContent = user.full_name || user.username;
    }
  }

  // Logout button
  const logoutBtn = document.getElementById('logoutBtn');
  if (logoutBtn) {
    logoutBtn.addEventListener('click', async function(e) {
      e.preventDefault();
      try {
        await fetch(`${apiPath}/auth/logout`, { method: 'POST' });
      } catch (err) {
        console.error('Logout API error:', err);
      }
      localStorage.removeItem('posUser');
      window.location.href = `${basePath}/index.html`;
    });
  }
});

// ตรวจสอบ auth และ return user object — redirect ถ้าไม่ได้ login
async function requireAuth() {
  const userJson = localStorage.getItem('posUser');
  if (!userJson) {
    window.location.href = `${basePath}/index.html`;
    return null;
  }
  try {
    const user = JSON.parse(userJson);
    // set username ใน topbar ถ้ามี
    const nameEl = document.getElementById('currentUser') || document.getElementById('userName');
    if (nameEl) nameEl.textContent = user.username || user.full_name || '-';
    return user;
  } catch(e) {
    window.location.href = `${basePath}/index.html`;
    return null;
  }
}

// API Request helper
async function apiRequest(endpoint, method = 'GET', data = null) {
  const controller = new AbortController();
  const timeoutId = setTimeout(() => controller.abort(), API_REQUEST_TIMEOUT_MS);
  const headers = {
    'Content-Type': 'application/json',
    // F2: JWT is in httpOnly cookie — browser sends automatically
    // Backward compat: old Bearer header system still works via Router.php
  };

  const options = {
    method,
    headers,
    signal: controller.signal
  };

  if (data && ['POST', 'PUT', 'PATCH', 'DELETE'].includes(method)) {
    options.body = JSON.stringify(data);
  }

  beginApiLoading();

  try {
    const response = await fetch(`${apiPath}/${endpoint}`, options);
    const responseText = await response.text();
    let result = {};

    if (responseText) {
      try {
        result = JSON.parse(responseText);
      } catch (parseError) {
        throw new Error('รูปแบบข้อมูลจากเซิร์ฟเวอร์ไม่ถูกต้อง');
      }
    }

    if (!response.ok) {
      // Handle unauthorized (token expired)
      if (response.status === 401) {
        // Save cart state before redirect
        const cartData = window.getCurrentCartState?.();
        if (cartData) saveCartState(cartData);

        // Clear auth
        localStorage.removeItem('posUser');
        document.cookie = 'posToken=; path=/; expires=Thu, 01 Jan 1970 00:00:00 GMT';
        document.cookie = 'posUser=; path=/; expires=Thu, 01 Jan 1970 00:00:00 GMT';

        window.location.href = `${basePath}/index.html?expired=1`;
        return {status: 'error', message: 'Session expired. Please login again.'};
      }

      throw new Error(result.message || 'API request failed');
    }

    return result;
  } catch (error) {
    if (error.name === 'AbortError') {
      showNotification('การเชื่อมต่อใช้เวลานานเกินไป กรุณาลองใหม่', 'error');
      return {status: 'error', message: 'Request timeout'};
    }

    if (!navigator.onLine) {
      showNotification('ไม่มีการเชื่อมต่ออินเทอร์เน็ต กรุณาตรวจสอบสัญญาณ', 'error');
      return {status: 'error', message: 'Offline'};
    }

    showNotification(error.message || 'ไม่สามารถเชื่อมต่อระบบได้', 'error');
    return {status: 'error', message: error.message};
  } finally {
    clearTimeout(timeoutId);
    endApiLoading();
  }
}

function escapeHtml(s) {
  if (s == null) return '';
  return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

// Format currency
function formatCurrency(amount) {
  return new Intl.NumberFormat('th-TH', {
    style: 'currency',
    currency: 'THB',
    minimumFractionDigits: 2
  }).format(amount);
}

function showNotification(message, type = 'info') {
  const container = getNotificationContainer();

  const notification = document.createElement('div');
  notification.className = `notification notification-${type}`;
  const messageEl = document.createElement('div');
  messageEl.className = 'notification-message';
  messageEl.textContent = message;
  const closeBtn = document.createElement('button');
  closeBtn.className = 'notification-close';
  closeBtn.type = 'button';
  closeBtn.setAttribute('aria-label', 'ปิดการแจ้งเตือน');
  closeBtn.innerHTML = '&times;';
  notification.appendChild(messageEl);
  notification.appendChild(closeBtn);

  container.appendChild(notification);

  // Auto remove after 5 seconds
  setTimeout(() => {
    if (notification.parentNode) {
      notification.parentNode.removeChild(notification);
    }
  }, 5000);

  // Close button
  closeBtn.addEventListener('click', () => {
    if (notification.parentNode) {
      notification.parentNode.removeChild(notification);
    }
  });
}

function getNotificationContainer() {
  let container = document.getElementById('notification-container');
  if (!container) {
    container = document.createElement('div');
    container.id = 'notification-container';
    document.body.appendChild(container);
  }
  return container;
}

function beginApiLoading() {
  pendingApiRequests += 1;
  if (!slowApiRequestTimer) {
    slowApiRequestTimer = setTimeout(() => {
      if (pendingApiRequests > 0) showGlobalLoading();
    }, 2000);
  }
}

function endApiLoading() {
  pendingApiRequests = Math.max(0, pendingApiRequests - 1);
  if (pendingApiRequests === 0) {
    clearTimeout(slowApiRequestTimer);
    slowApiRequestTimer = null;
    hideGlobalLoading();
  }
}

function showGlobalLoading(message = 'กำลังโหลด...') {
  let overlay = document.getElementById('globalLoadingOverlay');
  if (!overlay) {
    overlay = document.createElement('div');
    overlay.id = 'globalLoadingOverlay';
    overlay.innerHTML = `
      <div class="global-loading-box">
        <div class="spinner" aria-hidden="true"></div>
        <div class="global-loading-message"></div>
      </div>
    `;
    document.body.appendChild(overlay);
  }
  overlay.querySelector('.global-loading-message').textContent = message;
  overlay.classList.add('show');
}

function hideGlobalLoading() {
  const overlay = document.getElementById('globalLoadingOverlay');
  if (overlay) overlay.classList.remove('show');
}

function showTableLoading(target, columns = 4, rows = 5) {
  const tbody = typeof target === 'string' ? document.getElementById(target) : target;
  if (!tbody) return;
  const safeColumns = Math.max(1, Number(columns) || 1);
  const safeRows = Math.max(1, Number(rows) || 1);
  tbody.innerHTML = Array.from({ length: safeRows }, (_, index) => `
    <tr class="skeleton-table-row">
      <td colspan="${safeColumns}">
        <div class="skeleton-row" style="width:${index === safeRows - 1 ? 60 : 100}%"></div>
      </td>
    </tr>
  `).join('');
}

function setButtonLoading(button, isLoading, loadingText = 'กำลังบันทึก...') {
  const btn = typeof button === 'string' ? document.getElementById(button) : button;
  if (!btn) return;

  if (isLoading) {
    btn.dataset.originalHtml = btn.dataset.originalHtml || btn.innerHTML;
    btn.disabled = true;
    btn.textContent = loadingText;
    btn.classList.add('is-loading-text');
  } else {
    btn.disabled = false;
    btn.innerHTML = btn.dataset.originalHtml || btn.innerHTML;
    delete btn.dataset.originalHtml;
    btn.classList.remove('is-loading-text');
  }
}

function initOfflineBanner() {
  if (document.getElementById('offlineBanner')) return;

  const banner = document.createElement('div');
  banner.id = 'offlineBanner';
  banner.textContent = 'ไม่มีการเชื่อมต่ออินเทอร์เน็ต - ข้อมูลอาจไม่ถูกบันทึก';
  document.body.prepend(banner);

  const updateStatus = () => {
    banner.classList.toggle('show', !navigator.onLine);
  };

  window.addEventListener('online', () => {
    updateStatus();
    showNotification('กลับมาออนไลน์แล้ว', 'success');
  });
  window.addEventListener('offline', () => {
    updateStatus();
    showNotification('ไม่มีการเชื่อมต่ออินเทอร์เน็ต', 'warning');
  });

  updateStatus();
}

window.appShowNotification = showNotification;
window.showTableLoading = showTableLoading;
window.setButtonLoading = setButtonLoading;
// Loading overlay aliases — call these from page scripts
window.showLoading = showGlobalLoading;
window.hideLoading = hideGlobalLoading;

function openProfileModal() {
  // ตรวจสอบว่า modal มีอยู่แล้วหรือไม่
  let profileModal = document.getElementById('profileModal');

  // ถ้ายังไม่มี modal ให้สร้างใหม่
  if (!profileModal) {
    // สร้าง modal element
    profileModal = document.createElement('div');
    profileModal.id = 'profileModal';
    profileModal.className = 'modal';

    // สร้าง HTML content สำหรับ modal
    profileModal.innerHTML = `
      <div class="modal-content modal-sm">
        <div class="modal-header">
          <h2>แก้ไขโปรไฟล์</h2>
          <button class="close-modal">&times;</button>
        </div>
        <div class="modal-body">
          <form id="profileForm">
            <div class="form-group">
              <label for="profileUsername">ชื่อผู้ใช้</label>
              <input type="text" id="profileUsername" class="form-control" disabled>
            </div>
            <div class="form-group">
              <label for="profileFullName">ชื่อ-นามสกุล</label>
              <input type="text" id="profileFullName" class="form-control" required>
            </div>
            <div class="form-group">
              <label for="profileEmail">Email</label>
              <input type="email" id="profileEmail" class="form-control" required>
            </div>
            <div class="form-group">
              <label for="profileRole">บทบาท</label>
              <input type="text" id="profileRole" class="form-control" disabled>
            </div>
          </form>
        </div>
        <div class="modal-footer">
          <button class="btn btn-secondary" id="cancelProfileBtn">ยกเลิก</button>
          <button class="btn btn-primary" id="saveProfileBtn">บันทึกการเปลี่ยนแปลง</button>
        </div>
      </div>
    `;

    // เพิ่ม modal เข้าไปใน document
    document.body.appendChild(profileModal);

    // เพิ่ม event listeners สำหรับปุ่ม
    document.querySelector('#profileModal .close-modal').addEventListener('click', function() {
      profileModal.classList.remove('show');
    });

    document.getElementById('cancelProfileBtn').addEventListener('click', function() {
      profileModal.classList.remove('show');
    });

    document.getElementById('saveProfileBtn').addEventListener('click', saveProfileChanges);
  }

  // ดึงข้อมูลผู้ใช้จาก localStorage
  const userJson = localStorage.getItem('posUser');
  if (userJson) {
    const user = JSON.parse(userJson);

    // กรอกข้อมูลในฟอร์ม
    document.getElementById('profileUsername').value = user.username || '';
    document.getElementById('profileFullName').value = user.full_name || '';
    document.getElementById('profileEmail').value = user.email || '';
    document.getElementById('profileRole').value = user.role || '';
  }

  // แสดง modal
  profileModal.classList.add('show');
}

// Function to save profile changes
async function saveProfileChanges() {
  try {
    const fullName = document.getElementById('profileFullName').value;
    const email = document.getElementById('profileEmail').value;

    if (!fullName || !email) {
      showNotification('กรุณากรอกข้อมูลให้ครบ', 'error');
      return;
    }

    const response = await apiRequest('users/profile', 'PUT', {
      full_name: fullName,
      email: email
    });

    if (response.status === 'success') {
      // อัพเดตข้อมูลใน localStorage
      const userJson = localStorage.getItem('posUser');
      if (userJson) {
        const user = JSON.parse(userJson);
        user.full_name = fullName;
        user.email = email;
        localStorage.setItem('posUser', JSON.stringify(user));

        // อัพเดตชื่อที่แสดงบน UI
        const userNameElem = document.querySelector('.user-name');
        if (userNameElem) {
          userNameElem.textContent = fullName || user.username;
        }
      }

      showNotification('อัปเดตโปรไฟล์สำเร็จ', 'success');
      document.getElementById('profileModal').classList.remove('show');
    } else {
      showNotification(response.message || 'อัปเดตโปรไฟล์ไม่สำเร็จ', 'error');
    }
  } catch (error) {
    console.error('Error updating profile:', error);
    showNotification('เกิดข้อผิดพลาด: ' + error.message, 'error');
  }
}
  // === Sidebar Toggle for Mobile ===
  function initSidebarToggle() {
    // Create hamburger button
    const hamburger = document.createElement('button');
    hamburger.className = 'hamburger-btn';
    hamburger.innerHTML = '<i class="icon-menu"></i>';
    hamburger.style.display = 'none'; // Hidden by default, shown via CSS @media
    
    // Create overlay
    const overlay = document.createElement('div');
    overlay.className = 'sidebar-overlay';
    
    // Insert hamburger at start of topbar
    const topbar = document.querySelector('.topbar');
    if (topbar) {
      topbar.insertBefore(hamburger, topbar.firstChild);
    }
    
    // Insert overlay before sidebar
    const sidebar = document.querySelector('.sidebar');
    if (sidebar) {
      sidebar.parentNode.insertBefore(overlay, sidebar);
    }
    
    // Toggle function
    function toggleSidebar() {
      sidebar.classList.toggle('open');
      overlay.classList.toggle('active');
    }
    
    // Event listeners
    hamburger.addEventListener('click', toggleSidebar);
    overlay.addEventListener('click', toggleSidebar);
    
    // Show hamburger on mobile
    if (window.innerWidth <= 768) {
      hamburger.style.display = 'flex';
    }
    
    // Handle window resize
    window.addEventListener('resize', () => {
      if (window.innerWidth <= 768) {
        hamburger.style.display = 'flex';
      } else {
        hamburger.style.display = 'none';
        sidebar.classList.remove('open');
        overlay.classList.remove('active');
      }
    });
  }
  
  // Initialize sidebar toggle
  initSidebarToggle();

  // Smooth sidebar navigation — fade content out before leaving page
  document.querySelectorAll('.sidebar-menu a').forEach(link => {
    link.addEventListener('click', function(e) {
      const href = this.getAttribute('href');
      if (!href || href === '#' || this.classList.contains('active')) return;
      e.preventDefault();
      const contentArea = document.querySelector('.content-area');
      if (contentArea) {
        contentArea.style.transition = 'opacity 120ms ease, transform 120ms ease';
        contentArea.style.opacity = '0';
        contentArea.style.transform = 'translateY(4px)';
      }
      setTimeout(() => { window.location.href = href; }, 130);
    });
  });
