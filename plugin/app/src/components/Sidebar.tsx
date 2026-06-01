import { NavLink } from 'react-router-dom'

interface NavItem {
  to: string
  label: string
  icon: string
  roles?: string[]
}

const ALL_LINKS: NavItem[] = [
  { to: '/',         label: 'Dashboard',    icon: '⊞' },
  { to: '/clients',  label: 'Clients',      icon: '👥', roles: ['opb_reception', 'opb_branch_manager', 'opb_super_admin'] },
  { to: '/bookings', label: 'Bookings',     icon: '📋', roles: ['opb_reception', 'opb_branch_manager', 'opb_super_admin'] },
  { to: '/kennel',   label: 'Kennel Board', icon: '🏠', roles: ['opb_reception', 'opb_branch_manager', 'opb_super_admin'] },
  { to: '/invoices', label: 'Invoices',     icon: '🧾', roles: ['opb_reception', 'opb_branch_manager', 'opb_super_admin'] },
  { to: '/tasks',    label: 'Tasks',        icon: '✓'  },
  { to: '/expenses', label: 'Expenses',     icon: '💰', roles: ['opb_branch_manager', 'opb_super_admin'] },
  { to: '/reports',  label: 'Reports',      icon: '📊', roles: ['opb_branch_manager', 'opb_super_admin'] },
  { to: '/settings', label: 'Settings',     icon: '⚙',  roles: ['opb_super_admin'] },
  { to: '/import',   label: 'Import',       icon: '📥', roles: ['opb_super_admin'] },
]

function getVisibleLinks(): NavItem[] {
  const roles: string[] = window.OPB?.user?.roles ?? []
  const isAdmin = roles.includes('administrator')
  if (isAdmin) return ALL_LINKS

  return ALL_LINKS.filter((link) => {
    if (!link.roles) return true
    return link.roles.some((r) => roles.includes(r))
  })
}

interface Props { open: boolean; onClose: () => void }

export default function Sidebar({ open, onClose }: Props) {
  const links = getVisibleLinks()

  return (
    <>
      {open && (
        <div
          className="fixed inset-0 bg-black bg-opacity-40 z-30 lg:hidden"
          onClick={onClose}
        />
      )}
      <aside
        className={`
          fixed left-0 bottom-0 w-52 bg-blue-800 flex flex-col z-40 transition-transform duration-200
          lg:static lg:translate-x-0 lg:top-auto lg:bottom-auto lg:h-full lg:flex-shrink-0
          ${open ? 'translate-x-0' : '-translate-x-full'}
        `}
        style={{ top: 'calc(3rem + env(safe-area-inset-top, 0px))' }}
      >
        <nav className="flex-1 py-3 overflow-y-auto">
          {links.map((l) => (
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

        <div className="p-3 border-t border-blue-700">
          <p className="text-xs text-blue-400">v1.3.0</p>
        </div>
      </aside>
    </>
  )
}
