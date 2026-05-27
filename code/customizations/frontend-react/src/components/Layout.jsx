import { useState } from 'react'
import { NavLink, Outlet, useNavigate } from 'react-router-dom'
import {
  LayoutDashboard, Users, Tags, ShoppingCart, Package,
  FileBarChart, LogOut, Menu, X, Settings, ChevronRight, TrendingUp
} from 'lucide-react'
import { useAuthStore } from '@/stores/authStore'
import { cn } from '@/lib/utils'

const navItems = [
  { path: '/dashboard', icon: LayoutDashboard, label: 'แดชบอร์ด' },
  { path: '/purchase-orders', icon: ShoppingCart, label: 'รับซื้อของ', highlight: true },
  { path: '/sale-lots', icon: TrendingUp, label: 'ขาย Lot' },
  { path: '/sellers', icon: Users, label: 'ผู้ขาย' },
  { path: '/price-tiers', icon: Tags, label: 'ราคา 3 ระดับ' },
  { path: '/inventory', icon: Package, label: 'สินค้าคงคลัง' },
  { path: '/reports', icon: FileBarChart, label: 'รายงาน' },
  { path: '/settings', icon: Settings, label: 'ตั้งค่า' },
]

export default function Layout() {
  const [sidebarOpen, setSidebarOpen] = useState(false)
  const { user, logout } = useAuthStore()
  const navigate = useNavigate()

  const handleLogout = () => {
    logout()
    navigate('/login')
  }

  return (
    <div className="min-h-screen flex bg-bg">
      {/* Mobile overlay */}
      {sidebarOpen && (
        <div
          className="fixed inset-0 bg-black/50 z-40 lg:hidden"
          onClick={() => setSidebarOpen(false)}
        />
      )}

      {/* Sidebar */}
      <aside className={cn(
        "fixed lg:sticky top-0 left-0 z-50 h-screen w-64",
        "bg-bg-elevated border-r border-border",
        "flex flex-col transition-transform duration-200",
        sidebarOpen ? "translate-x-0" : "-translate-x-full lg:translate-x-0"
      )}>
        {/* Logo */}
        <div className="flex items-center justify-between h-16 px-5 border-b border-border">
          <div className="flex items-center gap-2.5">
            <div className="w-8 h-8 rounded-md bg-accent/15 border border-accent/30 flex items-center justify-center">
              <Package className="w-4 h-4 text-accent" />
            </div>
            <div>
              <div className="text-sm font-semibold text-text-primary leading-tight">POS</div>
              <div className="text-[10px] text-text-tertiary">รับซื้อของเก่า</div>
            </div>
          </div>
          <button
            onClick={() => setSidebarOpen(false)}
            className="lg:hidden text-text-secondary hover:text-text-primary"
          >
            <X className="w-5 h-5" />
          </button>
        </div>

        {/* Nav */}
        <nav className="flex-1 px-3 py-4 space-y-0.5 overflow-y-auto">
          {navItems.map((item) => (
            <NavLink
              key={item.path}
              to={item.path}
              onClick={() => setSidebarOpen(false)}
              className={({ isActive }) =>
                cn(
                  "flex items-center gap-3 px-3 py-2 rounded-md text-sm transition-colors",
                  isActive
                    ? "bg-accent/15 text-text-primary border border-accent/30"
                    : "text-text-secondary hover:bg-bg-hover hover:text-text-primary border border-transparent",
                  item.highlight && !isActive && "text-accent"
                )
              }
            >
              <item.icon className="w-4 h-4 shrink-0" />
              <span className="flex-1">{item.label}</span>
              {item.highlight && (
                <span className="text-[9px] uppercase tracking-wider text-accent">เด่น</span>
              )}
            </NavLink>
          ))}
        </nav>

        {/* User */}
        <div className="border-t border-border p-3">
          <div className="flex items-center gap-3 px-2 py-2">
            <div className="w-8 h-8 rounded-full bg-accent/20 flex items-center justify-center text-sm font-medium text-accent">
              {user?.full_name?.charAt(0) || 'U'}
            </div>
            <div className="flex-1 min-w-0">
              <div className="text-sm font-medium text-text-primary truncate">
                {user?.full_name || 'User'}
              </div>
              <div className="text-xs text-text-tertiary capitalize">{user?.role}</div>
            </div>
            <button
              onClick={handleLogout}
              className="text-text-tertiary hover:text-danger p-1.5 rounded transition-colors"
              title="ออกจากระบบ"
            >
              <LogOut className="w-4 h-4" />
            </button>
          </div>
        </div>
      </aside>

      {/* Main */}
      <div className="flex-1 flex flex-col min-w-0">
        {/* Top bar (mobile) */}
        <header className="lg:hidden h-14 border-b border-border bg-bg-elevated flex items-center px-4 gap-3 sticky top-0 z-30">
          <button
            onClick={() => setSidebarOpen(true)}
            className="text-text-secondary hover:text-text-primary"
          >
            <Menu className="w-5 h-5" />
          </button>
          <div className="text-sm font-medium">POS System v2</div>
        </header>

        <main className="flex-1 p-4 lg:p-8 max-w-[1600px] w-full mx-auto animate-fade-in">
          <Outlet />
        </main>
      </div>
    </div>
  )
}
