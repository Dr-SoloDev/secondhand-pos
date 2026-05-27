import { useState, useEffect, useRef } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import {
  ShoppingCart, Plus, X, Trash2, Search, Loader2,
  User, Building2, Tag, Package as PackageIcon, FileText, CheckCircle, XCircle
} from 'lucide-react'
import { apiCall } from '@/lib/api'
import { formatCurrency, formatDateTime, cn } from '@/lib/utils'

function TierButton({ tier, price, selected, onClick, disabled }) {
  const colors = {
    1: { active: 'border-info bg-info/15 text-info', label: 'Tier 1' },
    2: { active: 'border-warning bg-warning/15 text-warning', label: 'Tier 2' },
    3: { active: 'border-success bg-success/15 text-success', label: 'Tier 3' },
  }
  const c = colors[tier]
  return (
    <button
      type="button"
      disabled={disabled}
      onClick={onClick}
      className={cn(
        'flex flex-col items-center justify-center px-3 py-2 rounded-md border text-xs transition-colors',
        selected
          ? c.active
          : 'border-border text-text-secondary hover:border-border-strong hover:bg-bg-hover',
        disabled && 'opacity-40 cursor-not-allowed'
      )}
    >
      <div className="font-semibold">{c.label}</div>
      <div className="text-[10px] opacity-80 mt-0.5">{formatCurrency(price)}</div>
    </button>
  )
}

function CatalogSearchInput({ item, onUpdate }) {
  const [results, setResults] = useState([])
  const [show, setShow] = useState(false)
  const [busy, setBusy] = useState(false)
  const timer = useRef(null)

  useEffect(() => {
    return () => { if (timer.current) clearTimeout(timer.current) }
  }, [])

  const doSearch = (value) => {
    onUpdate({ item_name: value })

    if (timer.current) clearTimeout(timer.current)
    if (value.length < 1) {
      setResults([])
      setShow(false)
      return
    }

    timer.current = setTimeout(async () => {
      setBusy(true)
      try {
        const res = await apiCall.get('/purchase-catalog/search', { q: value })
        const data = res?.data || []
        const exact = data.find((r) => r.code === value || r.name === value)
        if (exact) {
          onUpdate({
            item_name: exact.name,
            category_id: String(exact.category_id || ''),
            unit: exact.default_unit || 'ชิ้น',
            unit_price: parseFloat(exact.default_price) || 0,
          })
          setResults([])
          setShow(false)
        } else {
          setResults(data)
          setShow(true)
        }
      } catch { setResults([]) }
      setBusy(false)
    }, 300)
  }

  const select = (r) => {
    onUpdate({
      item_name: r.name,
      category_id: String(r.category_id || ''),
      unit: r.default_unit || 'ชิ้น',
      unit_price: parseFloat(r.default_price) || 0,
    })
    setShow(false)
  }

  return (
    <div className="relative md:col-span-2">
      <input
        required
        type="text"
        placeholder="พิมพ์รหัสหรือชื่อสินค้า เช่น WM01, เครื่องซักผ้า"
        className="input"
        value={item.item_name || ''}
        onChange={(e) => doSearch(e.target.value)}
        onFocus={() => results.length > 0 && setShow(true)}
        onBlur={() => setTimeout(() => setShow(false), 200)}
      />
      {busy && (
        <div className="absolute right-3 top-1/2 -translate-y-1/2">
          <Loader2 className="w-4 h-4 animate-spin text-text-tertiary" />
        </div>
      )}
      {show && results.length > 0 && (
        <div className="absolute z-10 left-0 right-0 top-full mt-1 bg-bg-elevated border border-border rounded-lg shadow-lg max-h-60 overflow-y-auto">
          {results.map((r) => (
            <button
              key={r.id}
              type="button"
              className="w-full text-left px-3 py-2 hover:bg-bg-hover transition-colors flex items-center justify-between"
              onMouseDown={() => select(r)}
            >
              <div>
                <span className="text-xs font-mono text-text-tertiary mr-2">{r.code}</span>
                <span className="text-sm text-text-primary">{r.name}</span>
              </div>
              <span className="text-xs text-text-tertiary">{r.category_name}</span>
            </button>
          ))}
        </div>
      )}
    </div>
  )
}

function NewPurchaseOrderModal({ open, onClose }) {
  const queryClient = useQueryClient()
  const [items, setItems] = useState([])
  const [sellerId, setSellerId] = useState('')
  const [branchId, setBranchId] = useState('')
  const [notes, setNotes] = useState('')

  const [cur, setCur] = useState({
    item_name: '', category_id: '', condition_id: '',
    quantity: 1, unit: 'ชิ้น', unit_price: 0, weight_deduction: 0, price_tier: null,
  })

  const { data: sellers } = useQuery({
    queryKey: ['sellers'],
    queryFn: () => apiCall.get('/sellers').catch(() => ({ data: [] })),
    enabled: open,
  })
  const { data: branches } = useQuery({
    queryKey: ['branches'],
    queryFn: () => apiCall.get('/branches').catch(() => ({ data: [] })),
    enabled: open,
  })
  const { data: categories } = useQuery({
    queryKey: ['price-tiers'],
    queryFn: () => apiCall.get('/price-tiers').catch(() => ({ data: [] })),
    enabled: open,
  })
  const { data: conditions } = useQuery({
    queryKey: ['item-conditions'],
    queryFn: () => apiCall.get('/item-conditions').catch(() => ({ data: [] })),
    enabled: open,
  })

  const createMutation = useMutation({
    mutationFn: (payload) => apiCall.post('/purchase-orders', payload),
  })

  useEffect(() => {
    if (createMutation.isSuccess) {
      queryClient.invalidateQueries({ queryKey: ['purchase-orders'] })
      queryClient.invalidateQueries({ queryKey: ['dashboard'] })
      reset()
      onClose()
    }
  }, [createMutation.isSuccess])

  const reset = () => {
    setItems([])
    setSellerId('')
    setBranchId('')
    setNotes('')
    resetCur()
  }

  const resetCur = () => {
    setCur({
      item_name: '', category_id: '', condition_id: '',
      quantity: 1, unit: 'ชิ้น', unit_price: 0, weight_deduction: 0, price_tier: null,
    })
  }

  const addToList = () => {
    if (!cur.item_name) return
    setItems([...items, { key: Date.now() + Math.random(), ...cur }])
    resetCur()
  }

  const removeItem = (idx) => {
    setItems(items.filter((_, i) => i !== idx))
  }

  const netTotal = items.reduce(
    (s, it) => s + (parseFloat(it.unit_price) || 0) * Math.max(0, (parseFloat(it.quantity) || 0) - (parseFloat(it.weight_deduction) || 0)), 0
  )

  const handleSubmit = (e) => {
    e.preventDefault()
    if (!sellerId || !branchId || items.length === 0) return

    createMutation.mutate({
      seller_id: parseInt(sellerId),
      branch_id: parseInt(branchId),
      notes: notes || null,
      items: items.map((it) => ({
        item_name: it.item_name,
        category_id: it.category_id ? parseInt(it.category_id) : null,
        condition_id: parseInt(it.condition_id),
        quantity: parseFloat(it.quantity),
        weight_deduction: parseFloat(it.weight_deduction) || 0,
        unit: it.unit,
        unit_price: parseFloat(it.unit_price),
        total_price: parseFloat(it.unit_price) * Math.max(0, (parseFloat(it.quantity) || 0) - (parseFloat(it.weight_deduction) || 0)),
        price_tier: it.price_tier,
      })),
    })
  }

  const updateCur = (patch) => setCur({ ...cur, ...patch })

  const cat = (categories?.data || []).find((c) => String(c.id) === String(cur.category_id))

  if (!open) return null

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm animate-fade-in">
      <div className="bg-bg-elevated border border-border rounded-xl w-full max-w-4xl max-h-[90vh] flex flex-col overflow-hidden animate-slide-up">
        <div className="flex items-center justify-between p-5 border-b border-border">
          <div className="flex items-center gap-3">
            <div className="w-10 h-10 rounded-lg bg-accent/15 border border-accent/30 flex items-center justify-center">
              <ShoppingCart className="w-5 h-5 text-accent" />
            </div>
            <div>
              <h2 className="text-lg font-semibold">รับซื้อใหม่</h2>
              <p className="text-xs text-text-tertiary">บันทึกรายการรับซื้อสินค้า</p>
            </div>
          </div>
          <button onClick={onClose} className="text-text-tertiary hover:text-text-primary p-1">
            <X className="w-5 h-5" />
          </button>
        </div>

        <form onSubmit={handleSubmit} className="flex-1 overflow-y-auto p-5 space-y-5">
          {/* Seller + Branch */}
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label className="text-xs font-medium text-text-secondary flex items-center gap-1.5 mb-2">
                <User className="w-3 h-3" /> ผู้ขาย *
              </label>
              <select required value={sellerId} onChange={(e) => setSellerId(e.target.value)} className="input">
                <option value="">— เลือกผู้ขาย —</option>
                {(sellers?.data || []).map((s) => (
                  <option key={s.id} value={s.id}>{s.full_name} {s.phone ? `(${s.phone})` : ''}</option>
                ))}
              </select>
            </div>
            <div>
              <label className="text-xs font-medium text-text-secondary flex items-center gap-1.5 mb-2">
                <Building2 className="w-3 h-3" /> สาขา *
              </label>
              <select required value={branchId} onChange={(e) => setBranchId(e.target.value)} className="input">
                <option value="">— เลือกสาขา —</option>
                {(branches?.data || []).map((b) => (
                  <option key={b.id} value={b.id}>{b.name}</option>
                ))}
              </select>
            </div>
          </div>

          {/* ═══ Input Row ═══ */}
          <div className="card !p-4 space-y-3">
            <div className="flex items-center gap-2.5">
              <div className="w-7 h-7 rounded-md bg-accent/15 border border-accent/30 flex items-center justify-center">
                <PackageIcon className="w-3.5 h-3.5 text-accent" />
              </div>
              <span className="text-sm font-medium">เพิ่มสินค้า</span>
            </div>

            {/* Row 1: Catalog search */}
            <CatalogSearchInput
              item={cur}
              onUpdate={(patch) => updateCur(patch)}
            />

            {/* Row 2: Category + Condition */}
            <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
              <div>
                <label className="text-[10px] text-text-tertiary uppercase tracking-wider">หมวดหมู่</label>
                <select
                  className="input mt-1"
                  value={cur.category_id}
                  onChange={(e) => updateCur({ category_id: e.target.value, price_tier: null })}
                >
                  <option value="">— อัตโนมัติ —</option>
                  {(categories?.data || []).map((c) => (
                    <option key={c.id} value={c.id}>{c.name}</option>
                  ))}
                </select>
              </div>
              <div>
                <label className="text-[10px] text-text-tertiary uppercase tracking-wider">สภาพ *</label>
                <select
                  required
                  className="input mt-1"
                  value={cur.condition_id}
                  onChange={(e) => updateCur({ condition_id: e.target.value })}
                >
                  <option value="">— เลือก —</option>
                  {(conditions?.data || []).map((c) => (
                    <option key={c.id} value={c.id}>{c.name}</option>
                  ))}
                </select>
              </div>
            </div>

            {/* Row 3: Price + Weight + Deduction */}
            <div className="grid grid-cols-3 gap-2">
              <div>
                <label className="text-[10px] text-text-tertiary uppercase tracking-wider">ราคา/หน่วย (฿)</label>
                <input
                  type="number" step="0.01" min="0" className="input mt-1"
                  value={cur.unit_price}
                  onChange={(e) => updateCur({ unit_price: e.target.value, price_tier: null })}
                />
              </div>
              <div>
                <label className="text-[10px] text-text-tertiary uppercase tracking-wider">น้ำหนัก (กก.)</label>
                <input
                  type="number" step="0.01" min="0" className="input mt-1"
                  value={cur.quantity}
                  onChange={(e) => updateCur({ quantity: e.target.value })}
                />
              </div>
              <div>
                <label className="text-[10px] text-text-tertiary uppercase tracking-wider">หักน้ำหนัก (กก.)</label>
                <input
                  type="number" step="0.01" min="0" className="input mt-1"
                  value={cur.weight_deduction}
                  onChange={(e) => updateCur({ weight_deduction: e.target.value })}
                />
              </div>
            </div>

            {/* Tier buttons */}
            {cat && (parseFloat(cat.price_tier1) > 0 || parseFloat(cat.price_tier2) > 0 || parseFloat(cat.price_tier3) > 0) && (
              <div>
                <div className="text-[10px] text-text-tertiary uppercase tracking-wider mb-2">Tier ราคา</div>
                <div className="grid grid-cols-3 gap-2">
                  <TierButton tier={1} price={cat.price_tier1} selected={cur.price_tier === 1} onClick={() => updateCur({ price_tier: 1, unit_price: parseFloat(cat.price_tier1) || 0 })} />
                  <TierButton tier={2} price={cat.price_tier2} selected={cur.price_tier === 2} onClick={() => updateCur({ price_tier: 2, unit_price: parseFloat(cat.price_tier2) || 0 })} />
                  <TierButton tier={3} price={cat.price_tier3} selected={cur.price_tier === 3} onClick={() => updateCur({ price_tier: 3, unit_price: parseFloat(cat.price_tier3) || 0 })} />
                </div>
              </div>
            )}

            {/* Add button */}
            <div className="flex items-center justify-between pt-3 border-t border-border">
              <div className="text-sm">
                {cur.item_name ? (
                  <span className="text-text-tertiary">รวมรายการนี้: <span className="text-text-primary font-semibold">{formatCurrency((parseFloat(cur.unit_price) || 0) * Math.max(0, (parseFloat(cur.quantity) || 0) - (parseFloat(cur.weight_deduction) || 0)))}</span></span>
                ) : (
                  <span className="text-text-muted">เลือกสินค้าจากแคตตาล็อก</span>
                )}
              </div>
              <button
                type="button"
                onClick={addToList}
                disabled={!cur.item_name || !cur.condition_id}
                className="btn-primary"
              >
                <Plus className="w-4 h-4" /> เพิ่มรายการ
              </button>
            </div>
          </div>

          {/* ═══ Items Table ═══ */}
          <div>
            <div className="flex items-center gap-2.5 mb-3">
              <div className="w-7 h-7 rounded-md bg-accent/15 border border-accent/30 flex items-center justify-center">
                <PackageIcon className="w-3.5 h-3.5 text-accent" />
              </div>
              <span className="text-sm font-medium">รายการที่เพิ่ม ({items.length})</span>
            </div>

            {items.length === 0 ? (
              <div className="card border-dashed text-center py-6">
                <PackageIcon className="w-8 h-8 mx-auto text-text-muted mb-2" />
                <p className="text-sm text-text-secondary">กรอกข้อมูลแล้วกด "เพิ่มรายการ"</p>
              </div>
            ) : (
              <div className="card !p-0 overflow-hidden">
                <table className="w-full text-sm">
                  <thead className="text-xs text-text-tertiary uppercase tracking-wider">
                    <tr className="border-b border-border">
                      <th className="text-left p-3">#</th>
                      <th className="text-left p-3">สินค้า</th>
                      <th className="text-right p-3">น้ำหนัก</th>
                      <th className="text-right p-3">หัก</th>
                      <th className="text-right p-3">สุทธิ</th>
                      <th className="text-right p-3">ราคา</th>
                      <th className="text-right p-3">รวม</th>
                      <th className="text-center p-3"></th>
                    </tr>
                  </thead>
                  <tbody>
                    {items.map((it, idx) => {
                      const d = parseFloat(it.weight_deduction) || 0
                      const q = parseFloat(it.quantity) || 0
                      const net = Math.max(0, q - d)
                      const p = parseFloat(it.unit_price) || 0
                      return (
                        <tr key={it.key} className="table-row">
                          <td className="p-3 text-text-tertiary">{idx + 1}</td>
                          <td className="p-3 font-medium">{it.item_name}</td>
                          <td className="p-3 text-right text-text-secondary">{q} {it.unit}</td>
                          <td className="p-3 text-right text-danger">{d > 0 ? d : '-'}</td>
                          <td className="p-3 text-right text-text-secondary">{net} {it.unit}</td>
                          <td className="p-3 text-right text-text-secondary">{formatCurrency(p)}</td>
                          <td className="p-3 text-right font-medium">{formatCurrency(net * p)}</td>
                          <td className="p-3 text-center">
                            <button type="button" onClick={() => removeItem(idx)} className="text-text-tertiary hover:text-danger p-1">
                              <Trash2 className="w-3.5 h-3.5" />
                            </button>
                          </td>
                        </tr>
                      )
                    })}
                  </tbody>
                </table>
              </div>
            )}
          </div>

          <div>
            <label className="text-xs font-medium text-text-secondary flex items-center gap-1.5 mb-2">
              <FileText className="w-3 h-3" /> หมายเหตุ
            </label>
            <textarea
              className="input min-h-[60px]"
              value={notes}
              onChange={(e) => setNotes(e.target.value)}
              placeholder="ข้อมูลเพิ่มเติม (ถ้ามี)"
            />
          </div>

          {createMutation.isError && (
            <div className="text-sm text-danger bg-danger/10 border border-danger/30 rounded-md px-3 py-2">
              {createMutation.error?.response?.data?.message || 'บันทึกไม่สำเร็จ'}
            </div>
          )}
        </form>

        <div className="border-t border-border p-4 flex items-center justify-between gap-4 bg-bg-surface">
          <div>
            <div className="text-xs text-text-tertiary">ยอดรวมสุทธิ</div>
            <div className="text-2xl font-bold text-accent">{formatCurrency(netTotal)}</div>
          </div>
          <div className="flex gap-2">
            <button type="button" onClick={onClose} className="btn-secondary">ยกเลิก</button>
            <button
              onClick={handleSubmit}
              disabled={!sellerId || !branchId || items.length === 0 || createMutation.isPending}
              className="btn-primary"
            >
              {createMutation.isPending ? (
                <><Loader2 className="w-4 h-4 animate-spin" /> กำลังบันทึก...</>
              ) : (
                <><CheckCircle className="w-4 h-4" /> บันทึกรับซื้อ</>
              )}
            </button>
          </div>
        </div>
      </div>
    </div>
  )
}

export default function PurchaseOrders() {
  const queryClient = useQueryClient()
  const [showModal, setShowModal] = useState(false)
  const [search, setSearch] = useState('')

  const { data, isLoading } = useQuery({
    queryKey: ['purchase-orders'],
    queryFn: () => apiCall.get('/purchase-orders').catch(() => ({ data: { items: [] } })),
  })

  const cancelMutation = useMutation({
    mutationFn: (id) => apiCall.post('/purchase-orders/cancel', { id }),
  })

  useEffect(() => {
    if (cancelMutation.isSuccess) {
      queryClient.invalidateQueries({ queryKey: ['purchase-orders'] })
      queryClient.invalidateQueries({ queryKey: ['dashboard'] })
      cancelMutation.reset()
    }
  }, [cancelMutation.isSuccess])

  const handleCancel = (po) => {
    if (window.confirm(`ยกเลิก ${po.reference_no || `PO-${po.id}`}?`)) {
      cancelMutation.mutate(po.id)
    }
  }

  const orders = data?.data?.items || []
  const filtered = orders.filter((po) =>
    !search ||
    po.reference_no?.toLowerCase().includes(search.toLowerCase()) ||
    po.seller_name?.toLowerCase().includes(search.toLowerCase())
  )

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between flex-wrap gap-3">
        <div>
          <h1 className="text-2xl font-semibold text-text-primary">รับซื้อของ</h1>
          <p className="text-sm text-text-secondary mt-1">บันทึกการรับซื้อสินค้าจากผู้ขาย</p>
        </div>
        <button onClick={() => setShowModal(true)} className="btn-primary">
          <Plus className="w-4 h-4" /> รับซื้อใหม่
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
              placeholder="ค้นหาเลขที่ใบรับ, ผู้ขาย..."
            />
          </div>
        </div>

        {isLoading ? (
          <div className="text-text-tertiary text-sm py-12 text-center">กำลังโหลด...</div>
        ) : filtered.length === 0 ? (
          <div className="flex flex-col items-center py-16 text-center">
            <ShoppingCart className="w-12 h-12 text-text-muted mb-3" />
            <div className="text-text-secondary mb-4">ยังไม่มีรายการรับซื้อ</div>
            <button onClick={() => setShowModal(true)} className="btn-primary">
              <Plus className="w-4 h-4" /> เริ่มรับซื้อแรก
            </button>
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="text-xs text-text-tertiary uppercase tracking-wider">
                <tr className="border-b border-border">
                  <th className="text-left p-4">เลขที่</th>
                  <th className="text-left p-4">ผู้ขาย</th>
                  <th className="text-left p-4">สาขา</th>
                  <th className="text-right p-4">ยอดรวม</th>
                  <th className="text-left p-4">สถานะ</th>
                  <th className="text-left p-4">วันที่</th>
                  <th className="text-center p-4">จัดการ</th>
                </tr>
              </thead>
              <tbody>
                {filtered.map((po) => (
                  <tr key={po.id} className="table-row">
                    <td className="p-4 font-mono text-xs">{po.reference_no || `PO-${po.id}`}</td>
                    <td className="p-4">{po.seller_name || '-'}</td>
                    <td className="p-4 text-text-secondary">{po.branch_name || '-'}</td>
                    <td className="p-4 text-right font-medium">{formatCurrency(po.total_amount)}</td>
                    <td className="p-4">
                      <span className={cn(
                        'badge',
                        po.status === 'completed' ? 'badge-success' :
                        po.status === 'cancelled' ? 'badge-danger' : 'badge-warning'
                      )}>
                        {po.status === 'cancelled' ? 'ยกเลิก' : (po.status || 'pending')}
                      </span>
                    </td>
                    <td className="p-4 text-text-tertiary text-xs">{formatDateTime(po.created_at)}</td>
                    <td className="p-4 text-center">
                      {po.status !== 'cancelled' ? (
                        <button
                          onClick={() => handleCancel(po)}
                          disabled={cancelMutation.isPending}
                          className="text-danger hover:text-danger/80 disabled:opacity-40 p-1"
                          title="ยกเลิกใบรับซื้อ"
                        >
                          <XCircle className="w-4 h-4" />
                        </button>
                      ) : (
                        <span className="text-text-muted">—</span>
                      )}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      <NewPurchaseOrderModal open={showModal} onClose={() => setShowModal(false)} />
    </div>
  )
}
