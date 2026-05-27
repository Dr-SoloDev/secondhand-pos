import { useQuery } from '@tanstack/react-query'
import { TrendingUp, TrendingDown, ShoppingCart, Users, Package, DollarSign, Clock, ArrowUpRight } from 'lucide-react'
import { apiCall } from '@/lib/api'
import { formatCurrency, formatDateTime } from '@/lib/utils'
import { Link } from 'react-router-dom'

function StatCard({ icon: Icon, label, value, change, accent = false }) {
  const isPositive = change > 0
  return (
    <div className="card group">
      <div className="flex items-start justify-between">
        <div>
          <div className="text-sm text-text-secondary mb-1">{label}</div>
          <div className="text-2xl font-semibold text-text-primary">{value}</div>
          {change !== undefined && (
            <div className={`flex items-center gap-1 text-xs mt-2 ${isPositive ? 'text-success' : 'text-danger'}`}>
              {isPositive ? <TrendingUp className="w-3 h-3" /> : <TrendingDown className="w-3 h-3" />}
              {Math.abs(change)}% vs เมื่อวาน
            </div>
          )}
        </div>
        <div className={`w-10 h-10 rounded-lg flex items-center justify-center
          ${accent ? 'bg-accent/15 text-accent' : 'bg-bg-surface text-text-secondary'}
          group-hover:scale-110 transition-transform`}>
          <Icon className="w-5 h-5" />
        </div>
      </div>
    </div>
  )
}

export default function Dashboard() {
  const { data: dash, isLoading } = useQuery({
    queryKey: ['dashboard'],
    queryFn: () => apiCall.get('/reports/dashboard-stats').catch(() => ({ data: null })),
  })

  const { data: recent } = useQuery({
    queryKey: ['purchase-orders', 'recent'],
    queryFn: () =>
      apiCall.get('/purchase-orders', { limit: 5 }).catch(() => ({ data: [] })),
  })

  const stats = dash?.data || {}
  const recentOrders = recent?.data || []

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-semibold text-text-primary">แดชบอร์ด</h1>
        <p className="text-sm text-text-secondary mt-1">ภาพรวมร้านวันนี้</p>
      </div>

      {/* Stats */}
      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
        <StatCard
          icon={ShoppingCart}
          label="รับซื้อวันนี้"
          value={stats.today_purchases || 0}
          change={12}
          accent
        />
        <StatCard
          icon={DollarSign}
          label="เงินจ่ายวันนี้"
          value={formatCurrency(stats.today_amount)}
          change={-3}
        />
        <StatCard
          icon={Users}
          label="ผู้ขายทั้งหมด"
          value={stats.total_sellers || 0}
        />
        <StatCard
          icon={Package}
          label="สต็อกคงเหลือ"
          value={stats.inventory_count || 0}
          change={5}
        />
      </div>

      {/* Quick actions */}
      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div className="lg:col-span-2 card">
          <div className="flex items-center justify-between mb-4">
            <h2 className="text-lg font-semibold">รับซื้อล่าสุด</h2>
            <Link to="/purchase-orders" className="text-sm text-accent hover:text-accent-hover flex items-center gap-1">
              ดูทั้งหมด <ArrowUpRight className="w-3 h-3" />
            </Link>
          </div>

          {isLoading ? (
            <div className="text-text-tertiary text-sm py-8 text-center">กำลังโหลด...</div>
          ) : recentOrders.length === 0 ? (
            <div className="text-center py-12">
              <Clock className="w-10 h-10 mx-auto text-text-muted mb-3" />
              <div className="text-text-secondary text-sm">ยังไม่มีรายการรับซื้อวันนี้</div>
              <Link to="/purchase-orders" className="btn-primary mt-4 inline-flex">
                <ShoppingCart className="w-4 h-4" /> เริ่มรับซื้อ
              </Link>
            </div>
          ) : (
            <div className="space-y-2">
              {recentOrders.slice(0, 5).map((po) => (
                <div key={po.id} className="flex items-center justify-between p-3 bg-bg-surface rounded-lg hover:bg-bg-hover transition-colors">
                  <div>
                    <div className="text-sm font-medium">{po.po_number || `PO-${po.id}`}</div>
                    <div className="text-xs text-text-tertiary mt-0.5">
                      {po.seller_name || 'ไม่ระบุ'} · {formatDateTime(po.created_at)}
                    </div>
                  </div>
                  <div className="text-right">
                    <div className="text-sm font-medium">{formatCurrency(po.total_amount)}</div>
                    <span className="badge-info text-[10px]">{po.status || 'pending'}</span>
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>

        <div className="space-y-4">
          <div className="card">
            <h3 className="text-sm font-medium mb-3">เริ่มต้นเร็ว</h3>
            <div className="space-y-2">
              <Link to="/purchase-orders" className="btn-primary w-full justify-start">
                <ShoppingCart className="w-4 h-4" /> รับซื้อใหม่
              </Link>
              <Link to="/sellers" className="btn-secondary w-full justify-start">
                <Users className="w-4 h-4" /> เพิ่มผู้ขาย
              </Link>
              <Link to="/price-tiers" className="btn-secondary w-full justify-start">
                <Package className="w-4 h-4" /> ตั้งราคา
              </Link>
            </div>
          </div>

          <div className="card bg-gradient-to-br from-accent/10 to-transparent border-accent/20">
            <h3 className="text-sm font-medium mb-2">💡 รู้หรือไม่</h3>
            <p className="text-xs text-text-secondary leading-relaxed">
              ระบบ <strong className="text-text-primary">ราคา 3 ระดับ</strong> ช่วยให้คุณเลือกราคารับซื้อได้ตามคุณภาพของสินค้า ลดความผิดพลาดในการคิดเงิน
            </p>
          </div>
        </div>
      </div>
    </div>
  )
}
