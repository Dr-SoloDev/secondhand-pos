document.addEventListener('DOMContentLoaded', function() {
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
      localStorage.removeItem('posToken');
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
      localStorage.removeItem('posToken');
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
  const headers = {
    'Content-Type': 'application/json',
    // F2: JWT is in httpOnly cookie — browser sends automatically
    // Backward compat: old Bearer header system still works via Router.php
  };

  const options = {
    method,
    headers
  };

  if (data && (method === 'POST' || method === 'PUT')) {
    options.body = JSON.stringify(data);
  }

  try {
    const response = await fetch(`${apiPath}/${endpoint}`, options);
    const result = await response.json();

    if (!response.ok) {
      // Handle unauthorized (token expired)
      if (response.status === 401) {
        localStorage.removeItem('posToken');
        localStorage.removeItem('posUser');
        window.location.href = `${basePath}/index.html`;
        return {status: 'error', message: 'Session expired. Please login again.'};
      }

      throw new Error(result.message || 'API request failed');
    }

    return result;
  } catch (error) {
    console.error('API Request Error:', error);
    return {status: 'error', message: error.message};
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
  const container = document.getElementById('notification-container');

  if (!container) return;

  const notification = document.createElement('div');
  notification.className = `notification notification-${type}`;
  notification.innerHTML = `
      <div class="notification-message">${message}</div>
      <button class="notification-close">&times;</button>
  `;

  container.appendChild(notification);

  // Auto remove after 5 seconds
  setTimeout(() => {
    if (notification.parentNode) {
      notification.parentNode.removeChild(notification);
    }
  }, 5000);

  // Close button
  const closeBtn = notification.querySelector('.notification-close');
  if (closeBtn) {
    closeBtn.addEventListener('click', () => {
      if (notification.parentNode) {
        notification.parentNode.removeChild(notification);
      }
    });
  }
}

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
