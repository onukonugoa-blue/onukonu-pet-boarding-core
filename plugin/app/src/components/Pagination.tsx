interface Props {
  page: number
  totalPages: number
  total: number
  perPage: number
  onPage: (p: number) => void
}

export default function Pagination({ page, totalPages, total, perPage, onPage }: Props) {
  if (totalPages <= 1) return null
  const from = (page - 1) * perPage + 1
  const to   = Math.min(page * perPage, total)

  return (
    <div className="flex items-center justify-between px-1 py-2 mt-2 text-sm text-gray-600">
      <span>Showing {from}–{to} of {total}</span>
      <div className="flex gap-1">
        <button
          onClick={() => onPage(page - 1)}
          disabled={page <= 1}
          className="px-2 py-1 rounded border border-gray-300 disabled:opacity-40 hover:bg-gray-50"
        >‹</button>
        {Array.from({ length: Math.min(totalPages, 7) }, (_, i) => {
          const p = totalPages <= 7 ? i + 1 : (page <= 4 ? i + 1 : page - 3 + i)
          if (p < 1 || p > totalPages) return null
          return (
            <button
              key={p}
              onClick={() => onPage(p)}
              className={`px-2.5 py-1 rounded border ${p === page ? 'bg-blue-600 text-white border-blue-600' : 'border-gray-300 hover:bg-gray-50'}`}
            >{p}</button>
          )
        })}
        <button
          onClick={() => onPage(page + 1)}
          disabled={page >= totalPages}
          className="px-2 py-1 rounded border border-gray-300 disabled:opacity-40 hover:bg-gray-50"
        >›</button>
      </div>
    </div>
  )
}
