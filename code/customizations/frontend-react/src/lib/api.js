import axios from 'axios'

const API_BASE = import.meta.env.DEV ? '/api/index.php' : '/api/index.php'

export const api = axios.create({
  baseURL: API_BASE,
  headers: { 'Content-Type': 'application/json' },
  timeout: 30000,
})

// Attach JWT
api.interceptors.request.use((config) => {
  const token = localStorage.getItem('pos_token')
  if (token) {
    config.headers.Authorization = `Bearer ${token}`
  }
  return config
})

// Auto logout on 401
api.interceptors.response.use(
  (res) => res,
  (err) => {
    if (err.response?.status === 401) {
      localStorage.removeItem('pos_token')
      localStorage.removeItem('pos_user')
      if (!window.location.pathname.endsWith('/login')) {
        window.location.href = '/admin-v2/login'
      }
    }
    return Promise.reject(err)
  }
)

export const apiCall = {
  get: (url, params) => api.get(url, { params }).then((r) => r.data),
  post: (url, data) => api.post(url, data).then((r) => r.data),
  put: (url, data) => api.put(url, data).then((r) => r.data),
  delete: (url) => api.delete(url).then((r) => r.data),
}
