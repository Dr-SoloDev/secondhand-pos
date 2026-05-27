import { FileBarChart } from 'lucide-react'

export default function Reports() {
  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-semibold text-text-primary">รายงาน</h1>
        <p className="text-sm text-text-secondary mt-1">สรุปข้อมูลและสถิติ</p>
      </div>
      <div className="card">
        <div className="flex flex-col items-center justify-center py-16 text-center">
          <FileBarChart className="w-12 h-12 text-text-muted mb-3" />
          <h3 className="text-lg font-medium">หน้านี้กำลังพัฒนา</h3>
          <p className="text-sm text-text-secondary mt-2">รายงานรายวัน รายเดือน รายปี — Phase ถัดไป</p>
        </div>
      </div>
    </div>
  )
}
