import { buildWhatsAppUrl } from '../api/client'
import { useBranchStore } from '../store/branch'

interface Client { name: string; phone: string }
interface Pet { name: string; breed?: string }
interface InvoiceCtx { id: number; revenue: number; paid: number; due: number; legacy_invoice_number?: string }
interface StayCtx { check_in_date: string; check_out_date: string }

export function useWhatsApp() {
  const activeBranch = useBranchStore((s) => s.activeBranch())

  function invoiceMessage(client: Client, pets: Pet[], invoice: InvoiceCtx, stay?: StayCtx): string {
    const branchName = activeBranch?.name ?? 'Onukonu Pet Boarding'
    const petStr = pets.map((p) => `${p.name}${p.breed ? ` (${p.breed})` : ''}`).join(', ')
    const invoiceNum = invoice.legacy_invoice_number ?? String(invoice.id)
    const stayStr = stay ? `📅 Stay: ${stay.check_in_date} – ${stay.check_out_date}\n` : ''
    const settled = invoice.due <= 0

    const body = settled
      ? `Your account is fully settled. Thank you!\n\nOnukonu Pet Boarding`
      : `💰 Total: ₹${Number(invoice.revenue).toFixed(2)}\n✅ Paid:  ₹${Number(invoice.paid).toFixed(2)}\n🔴 Due:   ₹${Number(invoice.due).toFixed(2)}\n\nPlease make the balance payment at check-out.\n\nThank you!\nOnukonu Pet Boarding`

    return `Hi ${client.name},\n\nHere is your invoice from Onukonu Pet Homestyle Boarding.\n\n📋 Invoice #: ${invoiceNum}\n🐾 Pet: ${petStr}\n${stayStr}🏠 Branch: ${branchName}\n\n${body}`
  }

  function onboardingMessage(client: Client, pets: Pet[]): string {
    const branchName = activeBranch?.name ?? 'Onukonu Pet Boarding'
    const petStr = pets.length === 1 ? pets[0].name : pets.map((p) => p.name).join(', ')
    return `Hi ${client.name}, welcome to Onukonu Pet Homestyle Boarding! 🐾\n\nWe have registered ${petStr} at our ${branchName} branch.\n\nTo complete your pet's profile, please share the following with us:\n• Recent vaccination certificates\n• A clear photo of ${petStr}\n• Any dietary or medical notes we should know\n\nYou can WhatsApp these directly to this number or bring them on your first visit.\n\nIf you have any questions, feel free to reach out.\n\nSee you soon!\nOnukonu Pet Boarding`
  }

  function open(phone: string, message: string) {
    const url = buildWhatsAppUrl(phone, message)
    window.open(url, '_blank', 'noopener')
  }

  return { invoiceMessage, onboardingMessage, open }
}
