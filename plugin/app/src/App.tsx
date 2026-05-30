import { Routes, Route, Navigate } from 'react-router-dom'
import Layout from './components/Layout'
import Dashboard from './pages/Dashboard'
import ClientList from './pages/clients/ClientList'
import ClientProfile from './pages/clients/ClientProfile'
import ClientForm from './pages/clients/ClientForm'
import PetProfile from './pages/pets/PetProfile'
import PetForm from './pages/pets/PetForm'
import BookingList from './pages/bookings/BookingList'
import BookingDetail from './pages/bookings/BookingDetail'
import BookingCreate from './pages/bookings/BookingCreate'
import CheckIn from './pages/bookings/CheckIn'
import CheckOut from './pages/bookings/CheckOut'
import OccupancyBoard from './pages/OccupancyBoard'
import InvoiceList from './pages/invoices/InvoiceList'
import InvoiceDetail from './pages/invoices/InvoiceDetail'
import Tasks from './pages/Tasks'
import Expenses from './pages/Expenses'
import Import from './pages/Import'
import Settings from './pages/settings/Settings'
import Branches from './pages/settings/Branches'
import Staff from './pages/settings/Staff'
import BoardingCatalogue from './pages/settings/BoardingCatalogue'
import AddonCatalogue from './pages/settings/AddonCatalogue'
import Reports from './pages/Reports'

export default function App() {
  return (
    <Layout>
      <Routes>
        <Route path="/"                        element={<Dashboard />} />
        <Route path="/clients"                 element={<ClientList />} />
        <Route path="/clients/new"             element={<ClientForm />} />
        <Route path="/clients/:id"             element={<ClientProfile />} />
        <Route path="/clients/:id/edit"        element={<ClientForm />} />
        <Route path="/clients/:id/pets/new"    element={<PetForm />} />
        <Route path="/pets/:id"                element={<PetProfile />} />
        <Route path="/pets/:id/edit"           element={<PetForm />} />
        <Route path="/bookings"                element={<BookingList />} />
        <Route path="/bookings/new"            element={<BookingCreate />} />
        <Route path="/bookings/:id"            element={<BookingDetail />} />
        <Route path="/bookings/:id/checkin"    element={<CheckIn />} />
        <Route path="/bookings/:id/checkout"   element={<CheckOut />} />
        <Route path="/kennel"                  element={<OccupancyBoard />} />
        <Route path="/invoices"                element={<InvoiceList />} />
        <Route path="/invoices/:id"            element={<InvoiceDetail />} />
        <Route path="/tasks"                   element={<Tasks />} />
        <Route path="/expenses"                element={<Expenses />} />
        <Route path="/import"                  element={<Import />} />
        <Route path="/settings"                element={<Settings />} />
        <Route path="/settings/branches"       element={<Branches />} />
        <Route path="/settings/boarding"       element={<BoardingCatalogue />} />
        <Route path="/settings/addons"         element={<AddonCatalogue />} />
        <Route path="/settings/staff"          element={<Staff />} />
        <Route path="/reports"                 element={<Reports />} />
        <Route path="*"                        element={<Navigate to="/" replace />} />
      </Routes>
    </Layout>
  )
}
