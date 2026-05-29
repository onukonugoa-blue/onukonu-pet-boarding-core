interface Props { value: string; type?: 'payment' | 'stay' | 'task' | 'priority' | 'pet' }

const PAYMENT: Record<string, string> = {
  'Paid':            'badge-green',
  'Partially paid':  'badge-yellow',
  'Unpaid':          'badge-red',
  'Overpaid':        'badge-blue',
  'No bill':         'badge-gray',
}
const STAY: Record<string, string> = {
  'Active':    'badge-green',
  'Upcoming':  'badge-blue',
  'Completed': 'badge-gray',
  'No show':   'badge-red',
}
const TASK: Record<string, string> = {
  'Open':        'badge-blue',
  'In Progress': 'badge-yellow',
  'Done':        'badge-green',
}
const PRIORITY: Record<string, string> = {
  'High':   'badge-red',
  'Medium': 'badge-yellow',
  'Low':    'badge-gray',
}

function getBadgeClass(value: string, type?: string): string {
  if (type === 'payment') return PAYMENT[value] ?? 'badge-gray'
  if (type === 'stay')    return STAY[value]    ?? 'badge-gray'
  if (type === 'task')    return TASK[value]    ?? 'badge-gray'
  if (type === 'priority') return PRIORITY[value] ?? 'badge-gray'
  return 'badge-gray'
}

export default function StatusBadge({ value, type }: Props) {
  return <span className={getBadgeClass(value, type)}>{value}</span>
}
