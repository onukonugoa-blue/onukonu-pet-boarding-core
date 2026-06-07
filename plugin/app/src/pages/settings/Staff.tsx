import { useEffect, useState } from 'react'
import { settingsApi } from '../../api/settings'
import type { StaffUser } from '../../api/settings'
import { useBranchStore } from '../../store/branch'
import Modal from '../../components/Modal'

const OPB_ROLES = ['opb_manager','opb_receptionist','opb_caretaker','opb_accountant']

export default function Staff() {
  const [staff, setStaff] = useState<StaffUser[]>([])
  const [loading, setLoading] = useState(true)
  const [modal, setModal] = useState(false)
  const [editing, setEditing] = useState<StaffUser | null>(null)
  const [form, setForm] = useState<{ role: string; branch_id: number }>({ role: '', branch_id: 0 })
  const [saving, setSaving] = useState(false)
  const branches = useBranchStore((s) => s.branches)

  const load = () => {
    setLoading(true)
    settingsApi.getStaff().then(setStaff).catch(() => {}).finally(() => setLoading(false))
  }
  useEffect(load, [])

  const openEdit = (u: StaffUser) => {
    setEditing(u)
    setForm({ role: u.roles.find((r) => OPB_ROLES.includes(r)) ?? '', branch_id: u.branch_id })
    setModal(true)
  }

  const handleSave = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!editing) return
    setSaving(true)
    try {
      await settingsApi.updateStaff(editing.id, form)
      setModal(false)
      load()
    } catch (e: any) { alert(e.message) }
    finally { setSaving(false) }
  }

  return (
    <div>
      <div className="page-header">
        <h1 className="page-title">Staff & Roles</h1>
      </div>

      <div className="card">
        <div className="table-container">
          <table className="data-table">
            <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Branch</th><th></th></tr></thead>
            <tbody className="bg-white divide-y divide-gray-100">
              {loading ? (
                <tr><td colSpan={5} className="text-center py-8 text-gray-400">Loading…</td></tr>
              ) : staff.map((u) => {
                const opbRole = u.roles.find((r) => OPB_ROLES.includes(r) || r === 'administrator')
                const branch = branches.find((b) => b.id === u.branch_id)
                return (
                  <tr key={u.id}>
                    <td className="font-medium">{u.name}</td>
                    <td>{u.email}</td>
                    <td><span className="badge-blue">{opbRole ?? 'Unknown'}</span></td>
                    <td>{branch?.name ?? (u.branch_id ? `Branch #${u.branch_id}` : '—')}</td>
                    <td><button onClick={() => openEdit(u)} className="text-blue-600 hover:underline text-xs">Edit</button></td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
      </div>

      <Modal open={modal} onClose={() => setModal(false)} title={`Edit Staff — ${editing?.name}`}
        footer={<><button onClick={() => setModal(false)} className="btn-secondary">Cancel</button><button form="staff-form" type="submit" disabled={saving} className="btn-primary">{saving?'Saving…':'Save'}</button></>}>
        <form id="staff-form" onSubmit={handleSave} className="space-y-3">
          <div className="form-group">
            <label className="form-label">Role</label>
            <select className="form-select" value={form.role} onChange={(e) => setForm((f) => ({...f,role:e.target.value}))}>
              <option value="">Select role…</option>
              {OPB_ROLES.map((r) => <option key={r} value={r}>{r}</option>)}
            </select>
          </div>
          <div className="form-group">
            <label className="form-label">Branch</label>
            <select className="form-select" value={form.branch_id} onChange={(e) => setForm((f) => ({...f,branch_id:Number(e.target.value)}))}>
              <option value={0}>All / None</option>
              {branches.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}
            </select>
          </div>
        </form>
      </Modal>
    </div>
  )
}
