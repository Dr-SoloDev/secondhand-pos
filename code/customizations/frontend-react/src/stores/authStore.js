import { create } from 'zustand'
import { persist } from 'zustand/middleware'
import { apiCall } from '@/lib/api'

export const useAuthStore = create(
  persist(
    (set, get) => ({
      user: null,
      token: null,
      isAuthenticated: false,

      login: async (username, password) => {
        const res = await apiCall.post('/auth/login', { username, password })
        if (res.status === 'success') {
          const { token, user } = res.data
          localStorage.setItem('pos_token', token)
          localStorage.setItem('pos_user', JSON.stringify(user))
          set({ user, token, isAuthenticated: true })
          return { success: true }
        }
        return { success: false, message: res.message }
      },

      logout: () => {
        localStorage.removeItem('pos_token')
        localStorage.removeItem('pos_user')
        set({ user: null, token: null, isAuthenticated: false })
      },

      checkAuth: () => {
        const token = localStorage.getItem('pos_token')
        const userStr = localStorage.getItem('pos_user')
        if (token && userStr) {
          try {
            set({
              user: JSON.parse(userStr),
              token,
              isAuthenticated: true,
            })
          } catch {
            get().logout()
          }
        }
      },
    }),
    {
      name: 'pos-auth',
      partialize: (state) => ({ user: state.user, token: state.token, isAuthenticated: state.isAuthenticated }),
    }
  )
)
