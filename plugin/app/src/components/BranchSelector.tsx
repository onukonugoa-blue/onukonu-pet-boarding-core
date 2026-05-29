import { useBranches } from '../hooks/useBranch'

export default function BranchSelector() {
  const { branches, activeBranchId, setActiveBranch } = useBranches()

  return (
    <select
      value={activeBranchId}
      onChange={(e) => setActiveBranch(Number(e.target.value))}
      className="text-sm border border-blue-400 rounded-md bg-blue-700 text-white px-2 py-1 focus:outline-none focus:ring-2 focus:ring-blue-300"
    >
      <option value={0}>All Branches</option>
      {branches.map((b) => (
        <option key={b.id} value={b.id}>{b.name}</option>
      ))}
    </select>
  )
}
