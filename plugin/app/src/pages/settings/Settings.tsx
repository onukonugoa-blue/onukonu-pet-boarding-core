import { Link } from 'react-router-dom'

const sections = [
  { to: '/settings/branches',           icon: '🏠', title: 'Branches',            desc: 'Manage boarding locations and contact info' },
  { to: '/settings/kennels',            icon: '🐾', title: 'Kennels',             desc: 'Configure kennel units per branch, status and ordering' },
  { to: '/settings/boarding',           icon: '🛏', title: 'Boarding Catalogue',  desc: 'Configure pricing for overnight and day stays' },
  { to: '/settings/addons',             icon: '➕', title: 'Add-on Services',     desc: 'Grooming, transport, vet visits, extras' },
  { to: '/settings/staff',              icon: '👤', title: 'Staff & Roles',       desc: 'Assign roles and branch access to users' },
  { to: '/settings/expense-categories', icon: '🏷', title: 'Expense Categories',  desc: 'Create, rename and archive expense categories' },
  { to: '/settings/customization',      icon: '✏️', title: 'Customization',       desc: 'Edit templates, T&C, facility info, and communications' },
  { to: '/settings/customization?tab=opsmail', icon: '📡', title: 'OPSMAIL',             desc: 'Configure operational intelligence email alerts and inbox' },
]

export default function Settings() {
  return (
    <div>
      <h1 className="page-title mb-5">Settings</h1>
      <div className="grid grid-cols-1 sm:grid-cols-2 gap-4 max-w-2xl">
        {sections.map((s) => (
          <Link key={s.to} to={s.to} className="card hover:shadow-md transition-shadow flex items-start gap-3 no-underline">
            <span className="text-2xl mt-0.5">{s.icon}</span>
            <div>
              <div className="font-semibold text-gray-900">{s.title}</div>
              <div className="text-sm text-gray-500 mt-0.5">{s.desc}</div>
            </div>
          </Link>
        ))}
      </div>
    </div>
  )
}
