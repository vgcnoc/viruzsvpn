import { useQuery } from '@tanstack/react-query'
import { Search, UsersRound } from 'lucide-react'
import { useState } from 'react'
import { api, apiError } from './api'
import type { Page } from './api'

type Customer = { id: number; name: string; email: string; email_verified_at?: string; vpn_orders_count: number; vpn_accounts_count: number; created_at: string }

export default function AdminCustomers() {
  const [search, setSearch] = useState('')
  const customers = useQuery({ queryKey: ['admin-customers', search], queryFn: () => api.get<Page<Customer>>(`/customers?search=${encodeURIComponent(search)}`).then(r => r.data) })
  return <><div className="page-title"><div><p className="eyebrow">PELANGGAN</p><h1>Akun pelanggan</h1><p>Pantau pendaftaran, verifikasi email, pesanan, dan jumlah layanan setiap pelanggan.</p></div></div><section className="panel table-panel"><div className="table-toolbar"><label className="search"><Search size={17} /><input aria-label="Cari pelanggan" placeholder="Cari nama atau email" value={search} onChange={event => setSearch(event.target.value)} /></label><span className="table-total">{customers.data?.total ?? 0} pelanggan</span></div>{customers.isError && <p className="form-error">{apiError(customers.error)}</p>}{customers.isLoading ? <div className="skeleton"><div className="skeleton-line" /><div className="skeleton-line" /></div> : customers.data?.data.length ? <table><thead><tr><th>PELANGGAN</th><th>VERIFIKASI</th><th>PESANAN</th><th>LAYANAN</th><th>TERDAFTAR</th></tr></thead><tbody>{customers.data.data.map(customer => <tr key={customer.id}><td><b>{customer.name}</b><small>{customer.email}</small></td><td><span className={`status ${customer.email_verified_at ? 'good' : 'warn'}`}>{customer.email_verified_at ? 'Terverifikasi' : 'Menunggu'}</span></td><td>{customer.vpn_orders_count}</td><td>{customer.vpn_accounts_count}</td><td>{new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium' }).format(new Date(customer.created_at))}</td></tr>)}</tbody></table> : <div className="empty"><span><UsersRound /></span><h3>Belum ada pelanggan</h3><p>Pelanggan yang mendaftar akan tampil di sini.</p></div>}</section></>
}
