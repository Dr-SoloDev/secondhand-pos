import { Settings as SettingsIcon } from 'lucide-react'
import { useAuthStore } from '@/stores/authStore'

export default function Settings() {
  const user = useAuthStore((s) => s.user)

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-semibold text-text-primary">ตั้งค่า</h1>
        <p className="text-sm text-text-secondary mt-1">ข้อมูลบัญชีและระบบ</p>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div className="card">
          <h2 className="text-lg font-medium mb-4">บัญชีของฉัน</h2>
          <dl className="space-y-3 text-sm">
            <div className="flex justify-between border-b border-border-subtle pb-2">
              <dt className="text-text-secondary">ชื่อผู้ใช้</dt>
              <dd className="font-medium">{user?.username}</dd>
            </div>
            <div className="flex justify-between border-b border-border-subtle pb-2">
              <dt className="text-text-secondary">ชื่อเต็ม</dt>
              <dd className="font-medium">{user?.full_name}</dd>
            </div>
            <div className="flex justify-between border-b border-border-subtle pb-2">
              <dt className="text-text-secondary">อีเมล</dt>
              <dd className="font-medium">{user?.email}</dd>
            </div>
            <div className="flex justify-between">
              <dt className="text-text-secondary">บทบาท</dt>
              <dd><span className="badge-info">{user?.role}</span></dd>
            </div>
          </dl>
        </div>

        <div className="card">
          <h2 className="text-lg font-medium mb-4">เกี่ยวกับระบบ</h2>
          <dl className="space-y-3 text-sm">
            <div className="flex justify-between border-b border-border-subtle pb-2">
              <dt className="text-text-secondary">เวอร์ชั่น</dt>
              <dd className="font-mono">v2.0.0 (React)</dd>
            </div>
            <div className="flex justify-between border-b border-border-subtle pb-2">
              <dt className="text-text-secondary">Stack</dt>
              <dd>React + Vite + Tailwind</dd>
            </div>
            <div className="flex justify-between">
              <dt className="text-text-secondary">UI เก่า</dt>
              <dd><a href="/admin/" className="text-accent hover:underline">เปิด UI เดิม →</a></dd>
            </div>
          </dl>
        </div>
      </div>
    </div>
  )
}
