import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { dashboardApi } from '../api/dashboard'
import type { DashboardData } from '../api/dashboard'
import { useBranchStore } from '../store/branch'
import { fmt } from '../api/client'
import StatusBadge from '../components/StatusBadge'

export default function Dashboard() {
  const [data, setData] = useState<DashboardData | null>(null)
  const [loading, setLoading] = useState(true)
  const activeBranchId = useBranchStore((s) => s.activeBranchId)

  const load = () => {
    setLoading(true)
    dashboardApi.get(activeBranchId || undefined)
      .then(setData)
      .catch(() => {})
      .finally(() => setLoading(false))
  }

  useEffect(() => { load() }, [activeBranchId])

  if (loading) return <div className="flex items-center justify-center py-20 text-gray-400">Loading dashboard…</div>
  if (!data) return null

  const { kpis, todays_checkins, todays_checkouts, open_tasks, pet_birthdays, date } = data

  return (
    <div className="space-y-5">

      {/* Page header */}
      <div className="page-header">
        <h1 className="page-title">Dashboard</h1>
        <span className="text-sm text-gray-500">{fmt.date(date)}</span>
      </div>

      {/* New Inquiries Banner — only shown when actionable */}
      {kpis.new_inquiries > 0 && (
        <Link
          to="/inquiries"
          state={{ statusFilter: 'NEW,READY_FOR_REVIEW' }}
          className="flex items-center gap-3 px-4 py-2.5 rounded-lg bg-amber-50 border border-amber-200 hover:bg-amber-100 transition-colors group"
        >
          <svg className="w-4 h-4 text-amber-500 shrink-0" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
          </svg>
          <span className="text-sm font-semibold text-amber-800">
            {kpis.new_inquiries}
          </span>
          <span className="text-sm text-amber-700">
            {kpis.new_inquiries === 1 ? 'New Inquiry' : 'New Inquiries'} awaiting review
          </span>
          <span className="ml-auto text-xs font-medium text-amber-500 group-hover:text-amber-700 transition-colors">
            Review →
          </span>
        </Link>
      )}

      {/* KPI row — 6 operational metrics */}
      <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3">

        {/* Standard KPI cards */}
        {[
          { label: 'Active Stays',       value: kpis.active_stays,           color: 'text-green-600'  },
          { label: "Today's Check-ins",  value: kpis.checkins_today,         color: 'text-blue-600'   },
          { label: "Today's Check-outs", value: kpis.checkouts_today,        color: 'text-orange-500' },
          { label: 'Revenue (Month)',    value: fmt.inr(kpis.revenue_month), color: 'text-gray-900'   },
          { label: 'Outstanding',        value: fmt.inr(kpis.outstanding),   color: kpis.outstanding > 0 ? 'text-red-600' : 'text-gray-900' },
        ].map((k) => (
          <div key={k.label} className="kpi-card">
            <div className={`kpi-value ${k.color}`}>{k.value}</div>
            <div className="kpi-label">{k.label}</div>
          </div>
        ))}

        {/* Tasks Due — accented, actionable */}
        <Link
          to="/tasks"
          className="rounded-lg shadow-sm p-4 flex flex-col bg-blue-50 border border-blue-100 border-l-4 border-l-blue-400 hover:bg-blue-100 transition-colors"
        >
          <div className={`kpi-value ${kpis.tasks_due > 0 ? 'text-red-600' : 'text-gray-900'}`}>
            {kpis.tasks_due}
          </div>
          <div className="kpi-label">Tasks Due</div>
          <span className="mt-2 text-xs font-medium text-blue-500">View tasks →</span>
        </Link>

      </div>

      {/* Check-ins / Check-outs */}
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-5">

        <div className="card">
          <div className="card-header flex items-center justify-between">
            <h2 className="font-semibold text-gray-900">Today's Check-ins ({todays_checkins.length})</h2>
            <Link to="/bookings" className="text-sm text-blue-600 hover:underline">All bookings →</Link>
          </div>
          {todays_checkins.length === 0 ? (
            <p className="text-sm text-gray-400 py-4 text-center">No check-ins today</p>
          ) : (
            <div className="space-y-2">
              {todays_checkins.map((ci) => (
                <div key={ci.stay_id} className="flex items-center justify-between py-2 border-b border-gray-100 last:border-0">
                  <div>
                    <Link to={`/bookings/${ci.booking_id}`} className="font-medium text-blue-600 hover:underline text-sm">{ci.pet_name}</Link>
                    <span className="text-xs text-gray-500 ml-1">({ci.breed ?? ''})</span>
                    <div className="text-xs text-gray-500">{ci.client_name} · {ci.check_in_slot ?? 'Any slot'}</div>
                  </div>
                  <div className="text-right">
                    <StatusBadge value={ci.status} type="stay" />
                    {ci.kennel && <div className="text-xs text-gray-500 mt-0.5">Kennel: {ci.kennel}</div>}
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>

        <div className="card">
          <div className="card-header flex items-center justify-between">
            <h2 className="font-semibold text-gray-900">Today's Check-outs ({todays_checkouts.length})</h2>
            <Link to="/invoices" className="text-sm text-blue-600 hover:underline">All invoices →</Link>
          </div>
          {todays_checkouts.length === 0 ? (
            <p className="text-sm text-gray-400 py-4 text-center">No check-outs today</p>
          ) : (
            <div className="space-y-2">
              {todays_checkouts.map((co) => (
                <div key={co.stay_id} className="flex items-center justify-between py-2 border-b border-gray-100 last:border-0">
                  <div>
                    <Link to={`/bookings/${co.booking_id}`} className="font-medium text-blue-600 hover:underline text-sm">{co.pet_name}</Link>
                    <div className="text-xs text-gray-500">{co.client_name} · {co.check_out_slot ?? 'Any slot'}</div>
                  </div>
                  <div className="text-right">
                    {co.payment_status && <StatusBadge value={co.payment_status} type="payment" />}
                    {co.due != null && Number(co.due) > 0 && (
                      <div className="text-xs text-red-600 mt-0.5">Due: {fmt.inr(co.due)}</div>
                    )}
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>

      </div>

      {/* Open tasks */}
      {open_tasks.length > 0 && (
        <div className="card">
          <div className="card-header flex items-center justify-between">
            <h2 className="font-semibold text-gray-900">Open Tasks</h2>
            <Link to="/tasks" className="text-sm text-blue-600 hover:underline">All tasks →</Link>
          </div>
          <div className="space-y-2">
            {open_tasks.map((t) => (
              <div key={t.id} className="flex items-center gap-3 py-1.5 border-b border-gray-100 last:border-0">
                <StatusBadge value={t.priority} type="priority" />
                <span className="text-sm flex-1">{t.title}</span>
                <StatusBadge value={t.status} type="task" />
                {t.due_date && <span className="text-xs text-gray-400">{fmt.date(t.due_date)}</span>}
              </div>
            ))}
          </div>
        </div>
      )}

      {/* Pet Birthdays — delight section, bottom, low visual weight */}
      <div className="border-t border-gray-100 pt-4">
        <p className="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-2">
          Pet Birthdays Today
        </p>
        {pet_birthdays.length === 0 ? (
          <p className="text-xs text-gray-400">No pet birthdays today</p>
        ) : (
          <div className="flex flex-wrap gap-2">
            {pet_birthdays.map((b, i) => (
              <span
                key={i}
                className="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-pink-50 border border-pink-100 text-pink-700 text-xs"
              >
                <span className="font-medium">{b.pet_name}</span>
                <span className="text-pink-400">·</span>
                <span>{b.age} yrs</span>
                <span className="text-pink-400">·</span>
                <span className="text-pink-600">{b.client_name}</span>
              </span>
            ))}
          </div>
        )}
      </div>

    </div>
  )
}
