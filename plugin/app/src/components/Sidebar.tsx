import { NavLink } from 'react-router-dom'

const links = [
  { to: '/',          label: 'Dashboard',  icon: '⊞' },
  { to: '/clients',   label: 'Clients',    icon: '👥' },
  { to: '/pets',      label: 'Pets',       icon: '🐾', hide: true },
  { to: '/bookings',  label: 'Bookings',   icon: '📋' },
  { to: '/kennel',    label: 'Kennel Board',icon:'🏠' },
  { to: '/invoices',  label: 'Invoices',   icon: '🧾' },
  { to: '/tasks',     label: 'Tasks',      icon: '✓'  },
  { to: '/expenses',  label: 'Expenses',   icon: '💰' },
  { to: '/reports',   label: 'Reports',    icon: '📊' },
  { to: '/settings',  label: 'Settings',   icon: '⚙'  },
  { to: '/import',    label: 'Import',     icon: '📥' },
]

interface Props { open: boolean; onClose: () => void }

export default function Sidebar({ open, onClose }: Props) {
  return (
    <>
      {open && (
        <div
          className="fixed inset-0 bg-black bg-opacity-40 z-30 lg:hidden"
          onClick={onClose}
        />
      )}
      <aside className={`
        fixed top-20 left-0 bottom-0 w-52 bg-blue-800 flex flex-col z-40 transition-transform duration-200
        lg:static lg:translate-x-0 lg:top-auto lg:bottom-auto lg:h-full lg:flex-shrink-0
        ${open ? 'translate-x-0' : '-translate-x-full'}
      `}>
        <nav className="flex-1 py-3 overflow-y-auto">
          {links.filter(l => !l.hide).map((l) => (
            <NavLink
              key={l.to}
              to={l.to}
              end={l.to === '/'}
              onClick={onClose}
              className={({ isActive }) =>
                `sidebar-link mx-2 my-0.5 ${isActive ? 'sidebar-link-active' : 'sidebar-link-inactive'}`
              }
            >
              <span className="text-base w-5 text-center">{l.icon}</span>
              <span>{l.label}</span>
            </NavLink>
          ))}
        </nav>
        <div className="p-3 text-xs text-blue-300 border-t border-blue-700">
          v1.0.0
        </div>
      </aside>
    </>
  )
}
