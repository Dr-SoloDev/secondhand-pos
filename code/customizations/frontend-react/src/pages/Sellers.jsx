import { useQuery } from '@tanstack/react-query'
import { Users, Plus, Search, Phone, MapPin, Ban } from 'lucide-react'
import { useState } from 'react'
import { apiCall } from '@/lib/api'

export default function Sellers() {
  const [search, setSearch] = useState('')

  const { data, isLoading } = useQuery({
    queryKey: ['sellers'],
    queryFn: () => apiCall.get('/sellers').catch(() => ({ data: [] })),
  })

  const sellers = (data?.data || []).filter((s) =>
    !search || s.full_name?.toLowerCase().includes(search.toLowerCase()) ||
    s.phone?.includes(search) || s.id_card?.includes(search)
  )

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between flex-wrap gap-3">
        <div>
          <h1 className="text-2xl font-semibold text-text-primary">ผู้ขาย</h1>
          <p className="text-sm text-text-secondary mt-1">จัดการข้อมูลผู้นำสินค้ามาขาย</p>
        </div>
        <button className="btn-primary">
          <Plus className="w-4 h-4" /> เพิ่มผู้ขาย
        </button>
      </div>

      <div className="card !p-0 overflow-hidden">
        <div className="p-4 border-b border-border">
          <div className="relative max-w-sm">
            <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-text-tertiary" />
            <input
              type="text"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              className="input pl-10"
              placeholder="ค้นหาชื่อ, เบอร์โทร, เลขบัตร..."
            />
          </div>
        </div>

        {isLoading ? (
          <div className="text-text-tertiary text-sm py-12 text-center">กำลังโหลด...</div>
        ) : sellers.length === 0 ? (
          <div className="flex flex-col items-center py-16 text-center">
            <Users className="w-12 h-12 text-text-muted mb-3" />
            <div className="text-text-secondary">ยังไม่มีผู้ขาย</div>
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="text-xs text-text-tertiary uppercase tracking-wider">
                <tr className="border-b border-border">
                  <th className="text-left p-4">ชื่อ</th>
                  <th className="text-left p-4">เบอร์โทร</th>
                  <th className="text-left p-4">บัตร ปชช.</th>
                  <th className="text-left p-4">ที่อยู่</th>
                  <th className="text-left p-4">สถานะ</th>
                </tr>
              </thead>
              <tbody>
                {sellers.map((s) => (
                  <tr key={s.id} className="table-row">
                    <td className="p-4 font-medium">{s.full_name}</td>
                    <td className="p-4 text-text-secondary">
                      <span className="flex items-center gap-1.5">
                        <Phone className="w-3 h-3" />{s.phone || '-'}
                      </span>
                    </td>
                    <td className="p-4 text-text-secondary font-mono text-xs">{s.id_card || '-'}</td>
                    <td className="p-4 text-text-secondary truncate max-w-xs">
                      <span className="flex items-center gap-1.5">
                        <MapPin className="w-3 h-3" />{s.address || '-'}
                      </span>
                    </td>
                    <td className="p-4">
                      {s.is_blacklisted ? (
                        <span className="badge-danger"><Ban className="w-3 h-3 mr-1" />Blacklist</span>
                      ) : (
                        <span className="badge-success">ปกติ</span>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </div>
  )
}
