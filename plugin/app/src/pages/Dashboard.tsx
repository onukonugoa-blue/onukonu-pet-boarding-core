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
      <div className="page-header">
        <h1 className="page-title">Dashboard</h1>
        <span className="text-sm text-gray-500">{fmt.date(date)}</span>
      </div>

      {/* KPI row */}
      <div className="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-7 gap-3">
        {[
          { label: 'Active Stays',       value: kpis.active_stays,           color: 'text-green-600',  to: undefined },
          { label: "Today's Check-ins",  value: kpis.checkins_today,         color: 'text-blue-600',   to: undefined },
          { label: "Today's Check-outs", value: kpis.checkouts_today,        color: 'text-orange-500', to: undefined },
          { label: 'Revenue (Month)',    value: fmt.inr(kpis.revenue_month), color: 'text-gray-900',   to: undefined },
          { label: 'Outstanding',        value: fmt.inr(kpis.outstanding),   color: kpis.outstanding > 0 ? 'text-red-600' : 'text-gray-900', to: undefined },
          { label: 'Tasks Due',          value: kpis.tasks_due,              color: kpis.tasks_due > 0 ? 'text-red-600' : 'text-gray-900',  to: undefined },
          { label: 'New Inquiries',      value: kpis.new_inquiries,          color: kpis.new_inquiries > 0 ? 'text-amber-600' : 'text-gray-900', to: '/inquiries' },
        ].map((k) => {
          const inner = (
            <>
              <div className={`kpi-value ${k.color}`}>{k.value}</div>
              <div className="kpi-label">{k.label}</div>
            </>
          )
          return k.to ? (
            <Link key={k.label} to={k.to} className="kpi-card hover:ring-1 hover:ring-amber-300 transition-shadow">
              {inner}
            </Link>
          ) : (
            <div key={k.label} className="kpi-card">{inner}</div>
          )
        })}
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-5">
        {/* Today's check-ins */}
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

        {/* Today's check-outs */}
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

      {/* Today's pet birthdays */}
      <div className="card">
        <div className="card-header">
          <h2 className="font-semibold text-gray-900">Today's Pet Birthdays</h2>
        </div>
        {pet_birthdays.length === 0 ? (
          <p className="text-sm text-gray-400 py-4 text-center">No pet birthdays today</p>
        ) : (
          <div className="divide-y divide-gray-100">
            {pet_birthdays.map((b, i) => (
              <div key={i} className="flex items-center justify-between py-2">
                <div>
                  <span className="text-sm font-medium text-gray-900">{b.pet_name}</span>
                  <span className="text-xs text-gray-500 ml-2">Owner: {b.client_name}</span>
                </div>
                <span className="text-xs text-gray-500">Turning {b.age} today</span>
              </div>
            ))}
          </div>
        )}
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
    </div>
  )
}
