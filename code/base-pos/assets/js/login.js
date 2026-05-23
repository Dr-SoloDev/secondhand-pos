document.addEventListener('DOMContentLoaded', function() {
  // Check if already logged in
  const token = localStorage.getItem('posToken');
  if (token) {
    verifyToken(token);
  }

  // Login form submission
  const loginForm = document.getElementById('loginForm');
  const loginMessage = document.getElementById('loginMessage');

  loginForm.addEventListener('submit', function(e) {
    e.preventDefault();

    const username = document.getElementById('username').value;
    const password = document.getElementById('password').value;

    // Reset message
    loginMessage.innerHTML = '';
    loginMessage.className = 'login-message';

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
          // Save token and user info
          localStorage.setItem('posToken', data.data.token);
          localStorage.setItem('posUser', JSON.stringify(data.data.user));

          // Redirect based on role
          redirectByRole(data.data.user.role);
        } else {
          loginMessage.innerHTML = data.message;
          loginMessage.className = 'login-message error';
        }
      })
      .catch(error => {
        loginMessage.innerHTML = 'An error occurred. Please try again.';
        loginMessage.className = 'login-message error';
        console.error('Login error:', error);
      });
  });

  // Verify token function
  function verifyToken(token) {
    fetch(`${apiPath}/auth/verify`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json'
      },
      body: JSON.stringify({
        token: token
      })
    })
      .then(response => response.json())
      .then(data => {
        if (data.status === 'success') {
          // Token is valid, redirect based on role
          redirectByRole(data.data.user.role);
        } else {
          // Token is invalid, clear storage
          localStorage.removeItem('posToken');
          localStorage.removeItem('posUser');
        }
      })
      .catch(error => {
        console.error('Token verification error:', error);
        // On error, clear storage
        localStorage.removeItem('posToken');
        localStorage.removeItem('posUser');
      });
  }

  // Redirect based on user role
  function redirectByRole(role) {
    switch (role) {
      case 'admin':
      case 'manager':
        window.location.href = `${basePath}/admin/index.html`;
        break;
      case 'cashier':
        window.location.href = `${basePath}/pos/index.html`;
        break;
      default:
        loginMessage.innerHTML = 'Unknown user role';
        loginMessage.className = 'login-message error';
        break;
    }
  }
});