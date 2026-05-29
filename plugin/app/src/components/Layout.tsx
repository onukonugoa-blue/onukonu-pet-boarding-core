import { ReactNode, useState } from 'react'
import Sidebar from './Sidebar'
import TopBar from './TopBar'

interface Props { children: ReactNode }

export default function Layout({ children }: Props) {
  const [sidebarOpen, setSidebarOpen] = useState(false)

  return (
    <div className="flex flex-col h-screen overflow-hidden">
      <TopBar onMenuToggle={() => setSidebarOpen(!sidebarOpen)} />
      <div className="flex flex-1 overflow-hidden">
        <Sidebar open={sidebarOpen} onClose={() => setSidebarOpen(false)} />
        <main className="flex-1 overflow-y-auto bg-gray-50 p-4 lg:p-5">
          {children}
        </main>
      </div>
    </div>
  )
}
