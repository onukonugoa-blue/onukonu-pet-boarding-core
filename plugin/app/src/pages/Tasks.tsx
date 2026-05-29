import { useEffect, useState } from 'react'
import { tasksApi } from '../api/tasks'
import type { Task } from '../api/tasks'
import { useBranchStore } from '../store/branch'
import { fmt } from '../api/client'
import StatusBadge from '../components/StatusBadge'
import Modal from '../components/Modal'

const STATUSES: Task['status'][] = ['Open', 'In Progress', 'Done']
const PRIORITIES: Task['priority'][] = ['High', 'Medium', 'Low']
const EMPTY_FORM: Partial<Task> = { title: '', priority: 'Medium', status: 'Open', description: '', due_date: '', assignee: '' }

export default function Tasks() {
  const [tasks, setTasks] = useState<Task[]>([])
  const [loading, setLoading] = useState(true)
  const [filter, setFilter] = useState<string>('')
  const [modal, setModal] = useState(false)
  const [editing, setEditing] = useState<Task | null>(null)
  const [form, setForm] = useState<Partial<Task>>(EMPTY_FORM)
  const [saving, setSaving] = useState(false)
  const activeBranchId = useBranchStore((s) => s.activeBranchId)
  const branches = useBranchStore((s) => s.branches)

  const load = () => {
    setLoading(true)
    const p: Record<string, unknown> = { per_page: 100 }
    if (filter) p.status = filter
    if (activeBranchId) p.branch_id = activeBranchId
    tasksApi.list(p).then((r) => setTasks(r.data)).catch(console.error).finally(() => setLoading(false))
  }
  useEffect(load, [activeBranchId, filter])

  const openNew = () => {
    setEditing(null)
    setForm({ ...EMPTY_FORM, branch_id: activeBranchId || undefined })
    setModal(true)
  }
  const openEdit = (t: Task) => { setEditing(t); setForm(t); setModal(true) }

  const set = (k: keyof Task, v: unknown) => setForm((f) => ({ ...f, [k]: v }))

  const handleSave = async (e: React.FormEvent) => {
    e.preventDefault()
    setSaving(true)
    try {
      if (editing) await tasksApi.update(editing.id, form)
      else await tasksApi.create(form)
      setModal(false)
      await load()
    } catch (e: any) { alert(e.message) }
    finally { setSaving(false) }
  }

  const handleDelete = async (t: Task) => {
    if (!confirm('Delete task?')) return
    await tasksApi.delete(t.id)
    setTasks((prev) => prev.filter((x) => x.id !== t.id))
  }

  return (
    <div>
      <div className="page-header">
        <h1 className="page-title">Tasks</h1>
        <button onClick={openNew} className="btn-primary">+ New Task</button>
      </div>

      <div className="flex gap-2 mb-4">
        {['', ...STATUSES].map((s) => (
          <button key={s} onClick={() => setFilter(s)} className={`btn btn-sm ${filter===s?'btn-primary':'btn-secondary'}`}>{s||'All'}</button>
        ))}
      </div>

      <div className="card">
        <div className="table-container">
          <table className="data-table">
            <thead><tr><th>Title</th><th>Priority</th><th>Status</th><th>Due</th><th>Assignee</th><th>Branch</th><th></th></tr></thead>
            <tbody className="bg-white divide-y divide-gray-100">
              {loading ? (
                <tr><td colSpan={7} className="text-center py-8 text-gray-400">Loading…</td></tr>
              ) : tasks.length === 0 ? (
                <tr><td colSpan={7} className="text-center py-8 text-gray-400">No tasks</td></tr>
              ) : tasks.map((t) => (
                <tr key={t.id} className="hover:bg-gray-50">
                  <td className="font-medium cursor-pointer" onClick={() => openEdit(t)}>{t.title}</td>
                  <td><StatusBadge value={t.priority} type="priority" /></td>
                  <td><StatusBadge value={t.status} type="task" /></td>
                  <td>{fmt.date(t.due_date ?? null)}</td>
                  <td>{t.assignee ?? '—'}</td>
                  <td>{t.branch_name ?? '—'}</td>
                  <td className="flex gap-1">
                    <button onClick={() => openEdit(t)} className="text-blue-600 hover:underline text-xs">Edit</button>
                    <button onClick={() => handleDelete(t)} className="text-red-500 hover:underline text-xs ml-1">Del</button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>

      <Modal open={modal} onClose={() => setModal(false)} title={editing ? 'Edit Task' : 'New Task'}
        footer={<><button onClick={() => setModal(false)} className="btn-secondary">Cancel</button><button form="task-form" type="submit" disabled={saving} className="btn-primary">{saving?'Saving…':'Save'}</button></>}>
        <form id="task-form" onSubmit={handleSave} className="space-y-3">
          <div className="form-group"><label className="form-label">Title *</label><input className="form-input" required value={form.title ?? ''} onChange={(e) => set('title',e.target.value)} /></div>
          <div className="grid grid-cols-2 gap-3">
            <div className="form-group"><label className="form-label">Priority</label><select className="form-select" value={form.priority??'Medium'} onChange={(e) => set('priority',e.target.value)}>{PRIORITIES.map((p) => <option key={p}>{p}</option>)}</select></div>
            <div className="form-group"><label className="form-label">Status</label><select className="form-select" value={form.status??'Open'} onChange={(e) => set('status',e.target.value)}>{STATUSES.map((s) => <option key={s}>{s}</option>)}</select></div>
          </div>
          <div className="grid grid-cols-2 gap-3">
            <div className="form-group"><label className="form-label">Due Date</label><input className="form-input" type="date" value={form.due_date??''} onChange={(e) => set('due_date',e.target.value)} /></div>
            <div className="form-group"><label className="form-label">Assignee</label><input className="form-input" value={form.assignee??''} onChange={(e) => set('assignee',e.target.value)} /></div>
          </div>
          <div className="form-group"><label className="form-label">Branch</label><select className="form-select" value={form.branch_id??0} onChange={(e) => set('branch_id',Number(e.target.value))}><option value={0}>Select…</option>{branches.map((b) => <option key={b.id} value={b.id}>{b.name}</option>)}</select></div>
          <div className="form-group"><label className="form-label">Description</label><textarea className="form-input" rows={3} value={form.description??''} onChange={(e) => set('description',e.target.value)} /></div>
        </form>
      </Modal>
    </div>
  )
}
