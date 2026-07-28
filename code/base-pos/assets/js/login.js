document.addEventListener('DOMContentLoaded', function() {
  // Focus username field
  document.getElementById('username').focus();
  const loginMessage = document.getElementById('loginMessage');

  const urlParams = new URLSearchParams(window.location.search);
  if (urlParams.get('expired') === '1') {
    loginMessage.textContent = 'เซสชั่นหมดอายุ กรุณาเข้าสู่ระบบอีกครั้ง';
    loginMessage.className = 'login-message warning';
  }

  // Check if already logged in (via httpOnly cookie)
  checkAuth();

  // Password show/hide toggle
  const passwordInput = document.getElementById('password');
  const passwordToggle = document.getElementById('passwordToggle');
  passwordToggle.addEventListener('click', function() {
    const isPassword = passwordInput.type === 'password';
    passwordInput.type = isPassword ? 'text' : 'password';
    this.textContent = isPassword ? 'ซ่อน' : 'แสดง';
  });

  // Login form submission
  const loginForm = document.getElementById('loginForm');

  loginForm.addEventListener('submit', function(e) {
    e.preventDefault();

    const username = document.getElementById('username').value;
    const password = document.getElementById('password').value;
    const submitBtn = this.querySelector('button[type="submit"]');

    // Reset message
    loginMessage.innerHTML = '';
    loginMessage.className = 'login-message';

    // Loading state
    submitBtn.disabled = true;
    submitBtn.innerHTML = 'กำลังเข้าระบบ...';

    // Call API
    fetch(`${apiPath}/auth/login`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json'
      },
      body: JSON.stringify({
        username: username,
        password: password
      })
    })
      .then(response => response.json())
      .then(data => {
        if (data.status === 'success') {
          // F2: Save user info (token is in httpOnly cookie, set by PHP)
          localStorage.setItem('posUser', JSON.stringify(data.data.user));

          // Redirect based on role
          redirectByRole(data.data.user.role);
        } else {
          submitBtn.disabled = false;
          submitBtn.innerHTML = 'เข้าสู่ระบบ';
          loginMessage.innerHTML = data.message;
          loginMessage.className = 'login-message error';
        }
      })
      .catch(error => {
        submitBtn.disabled = false;
        submitBtn.innerHTML = 'เข้าสู่ระบบ';
        loginMessage.innerHTML = 'เกิดข้อผิดพลาด กรุณาลองอีกครั้ง';
        loginMessage.className = 'login-message error';
        console.error('Login error:', error);
      });
  });

  // Check auth via httpOnly cookie
  function checkAuth() {
    const userData = localStorage.getItem('posUser');
    if (!userData) return;
    fetch(`${apiPath}/auth/verify`, {
      method: 'GET',
      headers: { 'Content-Type': 'application/json' }
    })
      .then(response => response.json())
      .then(data => {
        if (data.status === 'success') {
          redirectByRole(data.data.user.role);
        } else {
          localStorage.removeItem('posUser');
        }
      })
      .catch(() => {
        localStorage.removeItem('posUser');
      });
  }

  // Redirect based on user role
  function redirectByRole(role) {
    switch (role) {
      case 'admin':
      case 'manager':
      case 'super_manager':
        window.location.href = `${basePath}/admin/index.html`;
        break;
      case 'cashier':
        window.location.href = `${basePath}/pos/index.html`;
        break;
      default:
        loginMessage.innerHTML = 'บทบาทผู้ใช้ไม่ถูกต้อง';
        loginMessage.className = 'login-message error';
        break;
    }
  }
});
