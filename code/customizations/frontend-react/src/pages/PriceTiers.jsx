import { Tags, Plus } from 'lucide-react'

export default function PriceTiers() {
  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between flex-wrap gap-3">
        <div>
          <h1 className="text-2xl font-semibold text-text-primary">ราคา 3 ระดับ</h1>
          <p className="text-sm text-text-secondary mt-1">ตั้งราคารับซื้อตามคุณภาพสินค้า</p>
        </div>
        <button className="btn-primary">
          <Plus className="w-4 h-4" /> เพิ่มราคา
        </button>
      </div>

      <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
        {[
          { tier: 1, label: 'Tier 1', desc: 'ราคาปกติ', color: 'border-info/30 bg-info/5', accent: 'text-info' },
          { tier: 2, label: 'Tier 2', desc: 'ราคากลาง', color: 'border-warning/30 bg-warning/5', accent: 'text-warning' },
          { tier: 3, label: 'Tier 3', desc: 'ราคาสูงสุด', color: 'border-success/30 bg-success/5', accent: 'text-success' },
        ].map((t) => (
          <div key={t.tier} className={`card border ${t.color}`}>
            <div className="flex items-center justify-between mb-3">
              <span className={`text-xs font-medium uppercase tracking-wider ${t.accent}`}>{t.label}</span>
              <Tags className={`w-4 h-4 ${t.accent}`} />
            </div>
            <div className="text-2xl font-semibold text-text-primary">{t.desc}</div>
            <div className="text-xs text-text-tertiary mt-2">
              เลือกใช้ตามคุณภาพและประเภทสินค้า
            </div>
          </div>
        ))}
      </div>

      <div className="card">
        <div className="flex flex-col items-center justify-center py-12 text-center">
          <Tags className="w-10 h-10 text-text-muted mb-3" />
          <h3 className="text-lg font-medium text-text-primary">รายการราคา</h3>
          <p className="text-sm text-text-secondary mt-2">กำลังพัฒนาในระยะถัดไป</p>
        </div>
      </div>
    </div>
  )
}
