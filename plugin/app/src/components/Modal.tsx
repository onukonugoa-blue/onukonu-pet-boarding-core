import { ReactNode, useEffect } from 'react'

interface Props {
  open: boolean
  onClose: () => void
  title: string
  children: ReactNode
  width?: string
  footer?: ReactNode
}

export default function Modal({ open, onClose, title, children, width = 'max-w-lg', footer }: Props) {
  useEffect(() => {
    if (!open) return
    const handler = (e: KeyboardEvent) => { if (e.key === 'Escape') onClose() }
    window.addEventListener('keydown', handler)
    return () => window.removeEventListener('keydown', handler)
  }, [open, onClose])

  if (!open) return null

  return (
    <div className="modal-overlay" onClick={(e) => e.target === e.currentTarget && onClose()}>
      <div className={`modal-box w-full ${width}`}>
        <div className="flex items-center justify-between p-4 border-b border-gray-200">
          <h3 className="text-base font-semibold text-gray-900">{title}</h3>
          <button onClick={onClose} className="text-gray-400 hover:text-gray-600 text-xl leading-none">&times;</button>
        </div>
        <div className="p-4">{children}</div>
        {footer && <div className="flex items-center justify-end gap-2 p-4 border-t border-gray-200 bg-gray-50 rounded-b-lg">{footer}</div>}
      </div>
    </div>
  )
}
