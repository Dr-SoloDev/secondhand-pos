import { useState, useEffect } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import {
  TrendingUp, Plus, X, Trash2, Search, Loader2,
  User, Building2, Tag, Package as PackageIcon, FileText, CheckCircle, AlertTriangle
} from 'lucide-react'
import { apiCall } from '@/lib/api'
import { formatCurrency, formatDateTime, cn } from '@/lib/utils'

function StatusBadge({ status }) {
  const map = {
    draft:     'badge-warning',
    confirmed: 'badge-success',
    cancelled: 'badge-danger',
  }
  const label = {
    draft:     'แบบร่าง',
    confirmed: 'ยืนยันแล้ว',
    cancelled: 'ยกเลิก',
  }
  return (
    <span className={cn('badge', map[status] || 'badge-warning')}>
      {label[status] || status || 'แบบร่าง'}
    </span>
  )
}

function ConfirmDialog({ open, title, message, onConfirm, onCancel, loading }) {
  if (!open) return null
  return (
    <div className="fixed inset-0 z-[60] flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm animate-fade-in">
      <div className="bg-bg-elevated border border-border rounded-xl w-full max-w-sm p-6 animate-slide-up">
        <div className="flex items-center gap-3 mb-3">
          <div className="w-10 h-10 rounded-lg bg-warning/15 border border-warning/30 flex items-center justify-center shrink-0">
            <AlertTriangle className="w-5 h-5 text-warning" />
          </div>
          <h3 className="text-base font-semibold">{title}</h3>
        </div>
        <p className="text-sm text-text-secondary mb-5">{message}</p>
        <div className="flex gap-2 justify-end">
          <button type="button" onClick={onCancel} className="btn-secondary" disabled={loading}>ยกเลิก</button>
          <button type="button" onClick={onConfirm} className="btn-primary" disabled={loading}>
            {loading ? <><Loader2 className="w-4 h-4 animate-spin" /> กำลังดำเนินการ...</> : <><CheckCircle className="w-4 h-4" /> ยืนยัน</>}
          </button>
        </div>
      </div>
    </div>
  )
}

function SaleLotModal({ open, onClose, editId }) {
  const queryClient = useQueryClient()
  const [items, setItems] = useState([])
  const [buyerName, setBuyerName] = useState('')
  const [saleDate, setSaleDate] = useState(() => new Date().toISOString().slice(0, 10))
  const [branchId, setBranchId] = useState('')
  const [notes, setNotes] = useState('')
  const [showConfirmSave, setShowConfirmSave] = useState(false)

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

  const { data: editData, isLoading: loadingEdit } = useQuery({
    queryKey: ['sale-lot', editId],
    queryFn: () => apiCall.get(`/sale-lots/${editId}`),
    enabled: open && !!editId,
  })

  useEffect(() => {
    const d = editData?.data
    if (!d) return
    setBuyerName(d.buyer_name || '')
    setSaleDate(d.sale_date ? d.sale_date.slice(0, 10) : new Date().toISOString().slice(0, 10))
    setBranchId(String(d.branch_id || ''))
    setNotes(d.notes || '')
    setItems((d.items || []).map((it) => ({
      key: it.id || Date.now() + Math.random(),
      id: it.id,
      category_id: String(it.category_id || ''),
      quantity_kg: it.quantity_kg ?? it.quantity ?? 0,
      unit_price: it.unit_price ?? 0,
    })))
  }, [editData])

  const createMutation = useMutation({
    mutationFn: (payload) => apiCall.post('/sale-lots', payload),
  })

  useEffect(() => {
    if (createMutation.isSuccess) {
      queryClient.invalidateQueries({ queryKey: ['sale-lots'] })
      queryClient.invalidateQueries({ queryKey: ['dashboard'] })
      reset()
      onClose()
    }
  }, [createMutation.isSuccess])

  const updateMutation = useMutation({
    mutationFn: (payload) => apiCall.put(`/sale-lots/${editId}`, payload),
  })

  useEffect(() => {
    if (updateMutation.isSuccess) {
      queryClient.invalidateQueries({ queryKey: ['sale-lots'] })
      queryClient.invalidateQueries({ queryKey: ['sale-lot', editId] })
      reset()
      onClose()
    }
  }, [updateMutation.isSuccess])

  const activeMutation = editId ? updateMutation : createMutation

  const reset = () => {
    setItems([])
    setBuyerName('')
    setSaleDate(new Date().toISOString().slice(0, 10))
    setBranchId('')
    setNotes('')
  }

  const addItem = () => {
    setItems([...items, {
      key: Date.now() + Math.random(),
      category_id: '',
      quantity_kg: 0,
      unit_price: 0,
    }])
  }

  const updateItem = (idx, patch) => {
    setItems(items.map((it, i) => (i === idx ? { ...it, ...patch } : it)))
  }

  const removeItem = (idx) => {
    setItems(items.filter((_, i) => i !== idx))
  }

  const totalAmount = items.reduce(
    (sum, it) => sum + (parseFloat(it.unit_price) || 0) * (parseFloat(it.quantity_kg) || 0),
    0
  )

  const buildPayload = () => ({
    buyer_name: buyerName,
    sale_date: saleDate,
    branch_id: parseInt(branchId),
    notes: notes || null,
    items: items.map((it) => ({
      ...(it.id ? { id: it.id } : {}),
      category_id: it.category_id ? parseInt(it.category_id) : null,
      quantity_kg: parseFloat(it.quantity_kg),
      unit_price: parseFloat(it.unit_price),
      subtotal: (parseFloat(it.unit_price) || 0) * (parseFloat(it.quantity_kg) || 0),
    })),
  })

  const handleSubmit = (e) => {
    e.preventDefault()
    if (!buyerName || !branchId || items.length === 0) return
    setShowConfirmSave(true)
  }

  const handleConfirmSave = () => {
    activeMutation.mutate(buildPayload())
    setShowConfirmSave(false)
  }

  if (!open) return null

  return (
    <>
      <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm animate-fade-in">
        <div className="bg-bg-elevated border border-border rounded-xl w-full max-w-4xl max-h-[90vh] flex flex-col overflow-hidden animate-slide-up">
          <div className="flex items-center justify-between p-5 border-b border-border">
            <div className="flex items-center gap-3">
              <div className="w-10 h-10 rounded-lg bg-success/15 border border-success/30 flex items-center justify-center">
                <TrendingUp className="w-5 h-5 text-success" />
              </div>
              <div>
                <h2 className="text-lg font-semibold">{editId ? 'แก้ไข Lot ขาย' : 'ขาย Lot ใหม่'}</h2>
                <p className="text-xs text-text-tertiary">บันทึกรายการขาย Lot สินค้า</p>
              </div>
            </div>
            <button onClick={onClose} className="text-text-tertiary hover:text-text-primary p-1">
              <X className="w-5 h-5" />
            </button>
          </div>

          {loadingEdit ? (
            <div className="flex-1 flex items-center justify-center py-16 text-text-tertiary text-sm">
              <Loader2 className="w-5 h-5 animate-spin mr-2" /> กำลังโหลด...
            </div>
          ) : (
            <form onSubmit={handleSubmit} className="flex-1 overflow-y-auto p-5 space-y-5">
              {/* Buyer + Date + Branch */}
              <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div className="md:col-span-1">
                  <label className="text-xs font-medium text-text-secondary flex items-center gap-1.5 mb-2">
                    <User className="w-3 h-3" /> ชื่อผู้ซื้อ *
                  </label>
                  <input
                    required
                    type="text"
                    className="input"
                    placeholder="ชื่อผู้ซื้อ / บริษัท"
                    value={buyerName}
                    onChange={(e) => setBuyerName(e.target.value)}
                  />
                </div>
                <div>
                  <label className="text-xs font-medium text-text-secondary flex items-center gap-1.5 mb-2">
                    <Tag className="w-3 h-3" /> วันที่ขาย *
                  </label>
                  <input
                    required
                    type="date"
                    className="input"
                    value={saleDate}
                    onChange={(e) => setSaleDate(e.target.value)}
                  />
                </div>
                <div>
                  <label className="text-xs font-medium text-text-secondary flex items-center gap-1.5 mb-2">
                    <Building2 className="w-3 h-3" /> สาขา *
                  </label>
                  <select
                    required
                    value={branchId}
                    onChange={(e) => setBranchId(e.target.value)}
                    className="input"
                  >
                    <option value="">— เลือกสาขา —</option>
                    {(branches?.data || []).map((b) => (
                      <option key={b.id} value={b.id}>{b.name}</option>
                    ))}
                  </select>
                </div>
              </div>

              {/* Line Items */}
              <div>
                <div className="flex items-center justify-between mb-3">
                  <label className="text-sm font-medium flex items-center gap-1.5">
                    <PackageIcon className="w-4 h-4" /> รายการสินค้า ({items.length})
                  </label>
                  <button type="button" onClick={addItem} className="btn-secondary !py-1 !px-3 text-xs">
                    <Plus className="w-3 h-3" /> เพิ่มรายการ
                  </button>
                </div>

                {items.length === 0 ? (
                  <div className="card border-dashed text-center py-8">
                    <PackageIcon className="w-8 h-8 mx-auto text-text-muted mb-2" />
                    <p className="text-sm text-text-secondary">ยังไม่มีรายการ — กดปุ่ม &quot;เพิ่มรายการ&quot;</p>
                  </div>
                ) : (
                  <div className="space-y-3">
                    {items.map((item, idx) => {
                      const subtotal = (parseFloat(item.unit_price) || 0) * (parseFloat(item.quantity_kg) || 0)
                      return (
                        <div key={item.key} className="card !p-4 space-y-3">
                          <div className="flex items-center justify-between">
                            <span className="text-xs text-text-tertiary">รายการที่ {idx + 1}</span>
                            <button
                              type="button"
                              onClick={() => removeItem(idx)}
                              className="text-text-tertiary hover:text-danger p-1"
                            >
                              <Trash2 className="w-3.5 h-3.5" />
                            </button>
                          </div>

                          <div className="grid grid-cols-1 md:grid-cols-3 gap-3 items-end">
                            <div>
                              <label className="text-[10px] text-text-tertiary uppercase tracking-wider">หมวดสินค้า</label>
                              <select
                                className="input"
                                value={item.category_id}
                                onChange={(e) => updateItem(idx, { category_id: e.target.value })}
                              >
                                <option value="">— เลือกหมวด —</option>
                                {(categories?.data || []).map((c) => (
                                  <option key={c.id} value={c.id}>{c.name}</option>
                                ))}
                              </select>
                            </div>
                            <div>
                              <label className="text-[10px] text-text-tertiary uppercase tracking-wider">น้ำหนัก (กก.)</label>
                              <input
                                type="number"
                                step="0.01"
                                min="0"
                                className="input"
                                value={item.quantity_kg}
                                onChange={(e) => updateItem(idx, { quantity_kg: e.target.value })}
                              />
                            </div>
                            <div>
                              <label className="text-[10px] text-text-tertiary uppercase tracking-wider">ราคา/กก. (฿)</label>
                              <input
                                type="number"
                                step="0.01"
                                min="0"
                                className="input"
                                value={item.unit_price}
                                onChange={(e) => updateItem(idx, { unit_price: e.target.value })}
                              />
                            </div>
                          </div>

                          <div className="text-right text-sm">
                            <span className="text-text-tertiary">รวม: </span>
                            <span className="font-semibold text-text-primary">{formatCurrency(subtotal)}</span>
                          </div>
                        </div>
                      )
                    })}
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

              {activeMutation.isError && (
                <div className="text-sm text-danger bg-danger/10 border border-danger/30 rounded-md px-3 py-2">
                  {activeMutation.error?.response?.data?.message || 'บันทึกไม่สำเร็จ'}
                </div>
              )}
            </form>
          )}

          <div className="border-t border-border p-4 flex items-center justify-between gap-4 bg-bg-surface">
            <div>
              <div className="text-xs text-text-tertiary">ยอดขายรวม</div>
              <div className="text-2xl font-bold text-success">{formatCurrency(totalAmount)}</div>
            </div>
            <div className="flex gap-2">
              <button type="button" onClick={onClose} className="btn-secondary">ยกเลิก</button>
              <button
                onClick={handleSubmit}
                disabled={!buyerName || !branchId || items.length === 0 || activeMutation.isPending}
                className="btn-primary"
              >
                {activeMutation.isPending ? (
                  <><Loader2 className="w-4 h-4 animate-spin" /> กำลังบันทึก...</>
                ) : (
                  <><CheckCircle className="w-4 h-4" /> บันทึก Lot ขาย</>
                )}
              </button>
            </div>
          </div>
        </div>
      </div>

      <ConfirmDialog
        open={showConfirmSave}
        title="ยืนยันการบันทึก"
        message={`ต้องการบันทึก Lot ขาย มูลค่ารวม ${formatCurrency(totalAmount)} ใช่หรือไม่?`}
        onConfirm={handleConfirmSave}
        onCancel={() => setShowConfirmSave(false)}
        loading={activeMutation.isPending}
      />
    </>
  )
}

export default function SaleLots() {
  const queryClient = useQueryClient()
  const [showModal, setShowModal] = useState(false)
  const [editId, setEditId] = useState(null)
  const [search, setSearch] = useState('')
  const [confirmAction, setConfirmAction] = useState(null)

  const { data, isLoading } = useQuery({
    queryKey: ['sale-lots'],
    queryFn: () => apiCall.get('/sale-lots').catch(() => ({ data: { items: [] } })),
  })

  const confirmMutation = useMutation({
    mutationFn: (id) => apiCall.post(`/sale-lots/${id}/confirm`),
  })

  useEffect(() => {
    if (confirmMutation.isSuccess) {
      queryClient.invalidateQueries({ queryKey: ['sale-lots'] })
      setConfirmAction(null)
    }
  }, [confirmMutation.isSuccess])

  const deleteMutation = useMutation({
    mutationFn: (id) => apiCall.delete(`/sale-lots/${id}`),
  })

  useEffect(() => {
    if (deleteMutation.isSuccess) {
      queryClient.invalidateQueries({ queryKey: ['sale-lots'] })
      setConfirmAction(null)
    }
  }, [deleteMutation.isSuccess])

  const lots = data?.data?.items || []
  const filtered = lots.filter((lot) =>
    !search ||
    lot.reference_no?.toLowerCase().includes(search.toLowerCase()) ||
    lot.buyer_name?.toLowerCase().includes(search.toLowerCase())
  )

  const openEdit = (id) => {
    setEditId(id)
    setShowModal(true)
  }

  const closeModal = () => {
    setShowModal(false)
    setEditId(null)
  }

  const handleConfirmLot = (lot) => {
    setConfirmAction({
      type: 'confirm',
      id: lot.id,
      title: 'ยืนยัน Lot ขาย',
      message: `ต้องการยืนยัน Lot "${lot.reference_no || `SL-${lot.id}`}" ใช่หรือไม่? ไม่สามารถแก้ไขได้หลังยืนยัน`,
    })
  }

  const handleDeleteLot = (lot) => {
    setConfirmAction({
      type: 'delete',
      id: lot.id,
      title: 'ลบ Lot ขาย',
      message: `ต้องการลบ Lot "${lot.reference_no || `SL-${lot.id}`}" ใช่หรือไม่? การดำเนินการนี้ไม่สามารถย้อนกลับได้`,
    })
  }

  const handleActionConfirm = () => {
    if (!confirmAction) return
    if (confirmAction.type === 'confirm') {
      confirmMutation.mutate(confirmAction.id)
    } else if (confirmAction.type === 'delete') {
      deleteMutation.mutate(confirmAction.id)
    }
  }

  const actionLoading = confirmMutation.isPending || deleteMutation.isPending

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between flex-wrap gap-3">
        <div>
          <h1 className="text-2xl font-semibold text-text-primary">ขาย Lot</h1>
          <p className="text-sm text-text-secondary mt-1">บันทึกการขาย Lot สินค้ามือสอง</p>
        </div>
        <button onClick={() => setShowModal(true)} className="btn-primary">
          <Plus className="w-4 h-4" /> ขาย Lot ใหม่
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
              placeholder="ค้นหาเลขที่ Lot, ผู้ซื้อ..."
            />
          </div>
        </div>

        {isLoading ? (
          <div className="text-text-tertiary text-sm py-12 text-center">กำลังโหลด...</div>
        ) : filtered.length === 0 ? (
          <div className="flex flex-col items-center py-16 text-center">
            <TrendingUp className="w-12 h-12 text-text-muted mb-3" />
            <div className="text-text-secondary mb-4">ยังไม่มีรายการขาย Lot</div>
            <button onClick={() => setShowModal(true)} className="btn-primary">
              <Plus className="w-4 h-4" /> เริ่มขาย Lot แรก
            </button>
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="text-xs text-text-tertiary uppercase tracking-wider">
                <tr className="border-b border-border">
                  <th className="text-left p-4">เลขที่</th>
                  <th className="text-left p-4">วันที่ขาย</th>
                  <th className="text-left p-4">ผู้ซื้อ</th>
                  <th className="text-right p-4">ยอดขาย</th>
                  <th className="text-right p-4">ต้นทุน</th>
                  <th className="text-right p-4">กำไร</th>
                  <th className="text-left p-4">สถานะ</th>
                  <th className="text-left p-4">จัดการ</th>
                </tr>
              </thead>
              <tbody>
                {filtered.map((lot) => {
                  const profit = (parseFloat(lot.total_amount) || 0) - (parseFloat(lot.total_cost) || 0)
                  return (
                    <tr key={lot.id} className="table-row">
                      <td className="p-4 font-mono text-xs">{lot.reference_no || `SL-${lot.id}`}</td>
                      <td className="p-4 text-text-secondary text-xs">{lot.sale_date ? lot.sale_date.slice(0, 10) : formatDateTime(lot.created_at)}</td>
                      <td className="p-4">{lot.buyer_name || '-'}</td>
                      <td className="p-4 text-right font-medium">{formatCurrency(lot.total_amount)}</td>
                      <td className="p-4 text-right text-text-secondary">{formatCurrency(lot.total_cost)}</td>
                      <td className={cn('p-4 text-right font-semibold', profit >= 0 ? 'text-success' : 'text-danger')}>
                        {profit >= 0 ? '+' : ''}{formatCurrency(profit)}
                      </td>
                      <td className="p-4"><StatusBadge status={lot.status} /></td>
                      <td className="p-4">
                        <div className="flex items-center gap-1">
                          {lot.status !== 'confirmed' && lot.status !== 'cancelled' && (
                            <>
                              <button
                                onClick={() => openEdit(lot.id)}
                                className="text-xs btn-secondary !py-1 !px-2"
                              >
                                แก้ไข
                              </button>
                              <button
                                onClick={() => handleConfirmLot(lot)}
                                className="text-xs btn-primary !py-1 !px-2"
                              >
                                ยืนยัน
                              </button>
                            </>
                          )}
                          {lot.status !== 'confirmed' && (
                            <button
                              onClick={() => handleDeleteLot(lot)}
                              className="text-text-tertiary hover:text-danger p-1"
                            >
                              <Trash2 className="w-3.5 h-3.5" />
                            </button>
                          )}
                        </div>
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        )}
      </div>

      <SaleLotModal open={showModal} onClose={closeModal} editId={editId} />

      <ConfirmDialog
        open={!!confirmAction}
        title={confirmAction?.title || ''}
        message={confirmAction?.message || ''}
        onConfirm={handleActionConfirm}
        onCancel={() => setConfirmAction(null)}
        loading={actionLoading}
      />
    </div>
  )
}
