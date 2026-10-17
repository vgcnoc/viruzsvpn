import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowRight, CheckCircle2, CircleDollarSign, CreditCard, LayoutDashboard, LifeBuoy, LoaderCircle, LogOut, Menu, Plus, Server, Settings2, ShieldCheck, X } from 'lucide-react'
import { useMemo, useState } from 'react'
import { api, apiError } from './api'
import type { Account, BillingSettings, Catalog, CustomerServer, Page, Plan, VpnOrder } from './api'
import './customer.css'
import './customer-extras.css'
import './pagination.css'

type CustomerUser = { id: number; name: string; email: string; role: string }
type View = 'overview' | 'plans' | 'services' | 'billing' | 'settings'
const rupiah = (amount: number, currency = 'IDR') => new Intl.NumberFormat('id-ID', { style: 'currency', currency, maximumFractionDigits: 0 }).format(amount)
const tanggal = (value?: string) => value ? new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium' }).format(new Date(value)) : '—'
const statusLabel: Record<string, string> = { pending_payment: 'Menunggu pembayaran', payment_submitted: 'Menunggu verifikasi', paid: 'Lunas', cancelled: 'Dibatalkan', active: 'Aktif', pending: 'Dalam proses', provisioning: 'Menyiapkan layanan', failed: 'Perlu bantuan', suspended: 'Ditangguhkan', expired: 'Berakhir' }
const nav: { key: View; label: string; icon: typeof LayoutDashboard }[] = [
  { key: 'overview', label: 'Beranda', icon: LayoutDashboard },
  { key: 'plans', label: 'Paket VPN', icon: Plus },
  { key: 'services', label: 'Layanan saya', icon: Server },
  { key: 'billing', label: 'Tagihan', icon: CreditCard },
  { key: 'settings', label: 'Akun saya', icon: Settings2 },
]

export default function CustomerPortal({ user, onSignOut }: { user: CustomerUser; onSignOut: () => void }) {
  const [view, setView] = useState<View>('overview')
  const [menuOpen, setMenuOpen] = useState(false)
  const [selectedPlan, setSelectedPlan] = useState<Plan | null>(null)
  const [ordersPage, setOrdersPage] = useState(1)
  const [accountsPage, setAccountsPage] = useState(1)
  const client = useQueryClient()
  const catalog = useQuery({ queryKey: ['customer-catalog'], queryFn: () => api.get<Catalog>('/customer/catalog').then(r => r.data) })
  const billing = useQuery({ queryKey: ['customer-billing-settings'], queryFn: () => api.get<BillingSettings>('/customer/billing-settings').then(r => r.data) })
  const orders = useQuery({ queryKey: ['customer-orders', ordersPage], queryFn: () => api.get<Page<VpnOrder>>(`/customer/orders?page=${ordersPage}`).then(r => r.data) })
  const accounts = useQuery({ queryKey: ['customer-accounts', accountsPage], queryFn: () => api.get<Page<Account>>(`/customer/accounts?page=${accountsPage}`).then(r => r.data) })
  const refresh = () => { void client.invalidateQueries({ queryKey: ['customer-orders'] }); void client.invalidateQueries({ queryKey: ['customer-accounts'] }) }
  const go = (next: View) => { setView(next); setMenuOpen(false); window.scrollTo({ top: 0, behavior: 'smooth' }) }
  const openOrders = () => go('billing')
  const items = orders.data?.data ?? []
  const services = accounts.data?.data ?? []
  const outstanding = items.filter(item => ['pending_payment', 'payment_submitted'].includes(item.status))

  return <div className="cp-shell">
    <aside className={`cp-sidebar ${menuOpen ? 'open' : ''}`}>
      <div className="cp-logo"><span><ShieldCheck size={21} /></span>vir<span>VPN</span></div>
      <div className="cp-sidebar-label">AREA PELANGGAN</div>
      <nav aria-label="Menu pelanggan">{nav.map(({ key, label, icon: Icon }) => <button type="button" key={key} className={view === key ? 'active' : ''} onClick={() => go(key)}><Icon size={18} />{label}</button>)}</nav>
      <div className="cp-help"><LifeBuoy size={19} /><div><strong>Butuh bantuan?</strong><span>Periksa status tagihan dan layanan di sini.</span></div></div>
    </aside>
    {menuOpen && <button className="cp-menu-scrim" aria-label="Tutup menu" onClick={() => setMenuOpen(false)} />}
    <main className="cp-main">
      <header className="cp-topbar"><div><button className="cp-mobile-menu" aria-label="Buka menu" onClick={() => setMenuOpen(true)}><Menu size={21} /></button><span>Portal pelanggan</span><span className="cp-chevron">/</span><strong>{nav.find(item => item.key === view)?.label}</strong></div><div className="cp-top-user"><span className="cp-user-avatar">{user.name[0]?.toUpperCase()}</span><span className="cp-user-name">{user.name}</span><button aria-label="Keluar" title="Keluar" onClick={onSignOut}><LogOut size={18} /></button></div></header>
      <div className="cp-content">
        {view === 'overview' && <>
          <div className="cp-hero"><div><p className="cp-kicker">SELAMAT DATANG DI VIRVPN</p><h1>VPN yang siap<br /><span>untuk kebutuhan Anda.</span></h1><p>Temukan paket, atur koneksi, dan pantau tagihan dari satu tempat.</p><button className="cp-primary" onClick={() => go('plans')}>Lihat paket VPN <ArrowRight size={17} /></button></div><div className="cp-hero-art" aria-hidden="true"><span className="cp-art-ring ring-one" /><span className="cp-art-ring ring-two" /><span className="cp-art-core"><ShieldCheck size={45} /></span><span className="cp-art-dot dot-one" /><span className="cp-art-dot dot-two" /><span className="cp-art-dot dot-three" /></div></div>
          <div className="cp-metrics"><article><span>Layanan aktif</span><strong>{services.filter(item => item.status === 'active').length}</strong><small>Koneksi VPN siap digunakan</small></article><article><span>Tagihan berjalan</span><strong>{outstanding.length}</strong><small>Menunggu pembayaran atau verifikasi</small></article><article><span>Status akun</span><strong className="cp-verified"><CheckCircle2 size={23} /> Terverifikasi</strong><small>Email {user.email}</small></article></div>
          {outstanding.length > 0 && <section className="cp-panel"><div className="cp-section-heading"><div><p className="cp-kicker">PERLU DITINDAKLANJUTI</p><h2>Tagihan terbaru</h2></div><button className="cp-text-button" onClick={openOrders}>Lihat semua <ArrowRight size={15} /></button></div><OrderRows orders={outstanding.slice(0, 3)} onSelect={openOrders} /></section>}
          <section className="cp-panel"><div className="cp-section-heading"><div><p className="cp-kicker">LANGKAH BERIKUTNYA</p><h2>{services.length ? 'Layanan Anda' : 'Mulai dengan paket yang tepat'}</h2></div></div>{services.length ? <ServiceRows accounts={services.slice(0, 3)} onRenew={refresh} onOpenBilling={openOrders} /> : <div className="cp-empty-inline"><span className="cp-round-icon"><CircleDollarSign size={22} /></span><div><strong>Belum ada layanan VPN</strong><p>Pilih paket dan server yang tersedia. Layanan diaktifkan setelah pembayaran diverifikasi.</p></div><button className="cp-secondary" onClick={() => go('plans')}>Pilih paket</button></div>}</section>
        </>}
        {view === 'plans' && <><PageIntro eyebrow="KATALOG" title="Pilih paket VPN" description="Harga dan masa aktif terlihat sebelum pemesanan. Pilih server serta protokol yang sesuai kebutuhan Anda." />{catalog.isLoading ? <Loading /> : catalog.isError ? <ErrorText error={catalog.error} /> : !catalog.data?.plans.length ? <Empty title="Paket belum tersedia" body="Paket akan muncul setelah administrator menambahkannya." /> : <div className="cp-plan-grid">{catalog.data.plans.map(plan => <article className="cp-plan" key={plan.id}><div className="cp-plan-top"><span className="cp-plan-badge">PAKET VPN</span><h2>{plan.name}</h2><p>{plan.description || 'Akses VPN untuk koneksi yang stabil dan terkelola.'}</p></div><div className="cp-plan-price">{rupiah(plan.price_cents, plan.currency)}<small> / {plan.duration_days} hari</small></div><ul><li><CheckCircle2 size={16} /> {plan.connection_limit} koneksi</li><li><CheckCircle2 size={16} /> {plan.bandwidth_mbps ? `Hingga ${plan.bandwidth_mbps} Mbps` : 'Kecepatan sesuai server'}</li><li><CheckCircle2 size={16} /> {plan.allowed_protocols.join(', ')}</li></ul><button className="cp-primary" onClick={() => setSelectedPlan(plan)} disabled={!billing.data?.payment_enabled}>Pilih paket <ArrowRight size={16} /></button></article>)}</div>}{!billing.data?.payment_enabled && <div className="cp-alert">Pemesanan belum dibuka. Administrator perlu mengisi rekening pembayaran di menu Billing.</div>}{selectedPlan && <OrderDialog plan={selectedPlan} servers={catalog.data?.servers ?? []} close={() => setSelectedPlan(null)} done={() => { setSelectedPlan(null); refresh(); openOrders() }} />}</>}
        {view === 'services' && <><PageIntro eyebrow="KONEKSI SAYA" title="Layanan VPN" description="Pantau status, server, protokol, dan masa aktif koneksi Anda." action={<button className="cp-secondary" onClick={() => go('plans')}><Plus size={16} /> Pesan VPN</button>} />{accounts.isLoading ? <Loading /> : accounts.isError ? <ErrorText error={accounts.error} /> : services.length ? <section className="cp-panel"><ServiceRows accounts={services} onRenew={refresh} onOpenBilling={openOrders} /></section> : <Empty title="Belum ada layanan" body="Pesan paket terlebih dahulu. Setelah pembayaran disetujui, layanan akan muncul di sini." action={<button className="cp-primary" onClick={() => go('plans')}>Lihat paket <ArrowRight size={16} /></button>} />}</>}
        {view === 'billing' && <><PageIntro eyebrow="PEMBAYARAN" title="Tagihan & invoice" description="Setiap pesanan memiliki nomor invoice, jumlah pembayaran, dan riwayat status." />{billing.data?.payment_enabled && <div className="cp-payment-banner"><CreditCard size={23} /><div><strong>Rekening untuk pesanan baru: {billing.data.bank_name}</strong><span>{billing.data.account_number} · a.n. {billing.data.account_name}</span>{billing.data.instructions && <small>{billing.data.instructions}</small>}</div></div>}{orders.isLoading ? <Loading /> : orders.isError ? <ErrorText error={orders.error} /> : items.length ? <div className="cp-invoice-list">{items.map(order => <InvoiceCard key={order.id} order={order} onChange={refresh} />)}</div> : <Empty title="Belum ada tagihan" body="Tagihan dibuat saat Anda memesan paket VPN." action={<button className="cp-primary" onClick={() => go('plans')}>Lihat paket <ArrowRight size={16} /></button>} />}</>}
        {view === 'settings' && <><PageIntro eyebrow="PROFIL" title="Akun saya" description="Kelola identitas dan keamanan akun virVPN Anda." /><AccountSettings user={user} /></>}
        {view === 'billing' && <Pager page={ordersPage} last={orders.data?.last_page ?? 1} onChange={setOrdersPage} />}
        {view === 'services' && <Pager page={accountsPage} last={accounts.data?.last_page ?? 1} onChange={setAccountsPage} />}
      </div>
    </main>
  </div>
}

function PageIntro({ eyebrow, title, description, action }: { eyebrow: string; title: string; description: string; action?: React.ReactNode }) { return <div className="cp-page-intro"><div><p className="cp-kicker">{eyebrow}</p><h1>{title}</h1><p>{description}</p></div>{action}</div> }
function Loading() { return <div className="cp-loading"><LoaderCircle className="spin" size={23} /> Memuat data...</div> }
function ErrorText({ error }: { error: unknown }) { return <div className="cp-alert error">{apiError(error)}</div> }
function Pager({ page, last, onChange }: { page: number; last: number; onChange: (value: number) => void }) { return last > 1 ? <div className="cp-pagination"><button className="cp-secondary" disabled={page <= 1} onClick={() => onChange(page - 1)}>Sebelumnya</button><span>Halaman {page} dari {last}</span><button className="cp-secondary" disabled={page >= last} onClick={() => onChange(page + 1)}>Berikutnya</button></div> : null }
function Empty({ title, body, action }: { title: string; body: string; action?: React.ReactNode }) { return <div className="cp-empty"><span className="cp-round-icon"><ShieldCheck size={25} /></span><h2>{title}</h2><p>{body}</p>{action}</div> }
function OrderRows({ orders, onSelect }: { orders: VpnOrder[]; onSelect: () => void }) { return <div className="cp-rows">{orders.map(order => <button className="cp-row" onClick={onSelect} key={order.id}><span className="cp-row-icon"><CreditCard size={19} /></span><span className="cp-row-body"><strong>{order.invoice_number}</strong><small>{order.plan_name} · {tanggal(order.created_at)}</small></span><span className={`cp-state ${order.status}`}>{statusLabel[order.status] ?? order.status}</span><ArrowRight size={17} /></button>)}</div> }

function OrderDialog({ plan, servers, close, done }: { plan: Plan; servers: CustomerServer[]; close: () => void; done: () => void }) {
  const eligible = useMemo(() => servers.filter(server => (!plan.allowed_server_ids?.length || plan.allowed_server_ids.includes(server.id)) && server.protocols.some(protocol => plan.allowed_protocols.includes(protocol))), [plan, servers])
  const [serverId, setServerId] = useState(String(eligible[0]?.id ?? ''))
  const [protocol, setProtocol] = useState(eligible[0]?.protocols.find(item => plan.allowed_protocols.includes(item)) ?? '')
  const [identifier, setIdentifier] = useState('')
  const [password, setPassword] = useState('')
  const [confirm, setConfirm] = useState('')
  const [error, setError] = useState('')
  const selected = eligible.find(server => String(server.id) === serverId)
  const protocols = (selected?.protocols ?? []).filter(item => plan.allowed_protocols.includes(item))
  const create = useMutation({ mutationFn: () => api.post('/customer/orders', { plan_id: plan.id, vpn_server_id: Number(serverId), protocol, identifier, client_password: password }), onSuccess: done, onError: error => setError(apiError(error)) })
  const submit = (event: React.FormEvent) => { event.preventDefault(); if (password !== confirm) { setError('Konfirmasi password VPN tidak cocok.'); return } setError(''); create.mutate() }
  return <div className="cp-dialog-backdrop" onMouseDown={close}><section className="cp-dialog" role="dialog" aria-modal="true" aria-label={`Pesan ${plan.name}`} onMouseDown={event => event.stopPropagation()}><div className="cp-dialog-head"><div><p className="cp-kicker">PESANAN BARU</p><h2>{plan.name}</h2></div><button aria-label="Tutup" onClick={close}><X size={20} /></button></div><p className="cp-dialog-description">{rupiah(plan.price_cents, plan.currency)} untuk {plan.duration_days} hari. Tagihan dibuat lebih dulu; VPN aktif setelah pembayaran disetujui admin.</p>{!eligible.length ? <div className="cp-alert">Belum ada server online yang mendukung paket ini. Silakan pilih paket lain atau hubungi admin.</div> : <form className="cp-form" onSubmit={submit}><label>Server VPN<select required value={serverId} onChange={event => { const next = eligible.find(server => String(server.id) === event.target.value); setServerId(event.target.value); setProtocol(next?.protocols.find(item => plan.allowed_protocols.includes(item)) ?? '') }}>{eligible.map(server => <option key={server.id} value={server.id}>{server.name}{server.location ? ` · ${server.location}` : ''}</option>)}</select></label><label>Protokol<select value={protocol} onChange={event => setProtocol(event.target.value)}>{protocols.map(item => <option key={item} value={item}>{item}</option>)}</select></label><label>Username VPN<input required pattern="[A-Za-z0-9._-]+" maxLength={128} value={identifier} onChange={event => setIdentifier(event.target.value)} placeholder="nama-vpn-anda" /></label><label>Password VPN<input required type="password" minLength={12} maxLength={255} autoComplete="new-password" value={password} onChange={event => setPassword(event.target.value)} placeholder="Minimal 12 karakter" /></label><label>Ulangi password VPN<input required type="password" minLength={12} autoComplete="new-password" value={confirm} onChange={event => setConfirm(event.target.value)} /></label><p className="cp-form-note">Simpan password VPN Anda. Password ini dipakai saat menghubungkan perangkat dan tidak ditampilkan kembali.</p>{error && <p className="cp-error">{error}</p>}<button className="cp-primary" disabled={create.isPending}>{create.isPending && <LoaderCircle className="spin" size={16} />} Buat tagihan <ArrowRight size={16} /></button></form>}</section></div>
}

function InvoiceCard({ order, onChange }: { order: VpnOrder; onChange: () => void }) {
  const [reference, setReference] = useState('')
  const [note, setNote] = useState('')
  const [error, setError] = useState('')
  const submit = useMutation({ mutationFn: () => api.post(`/customer/orders/${order.id}/payment`, { payment_reference: reference, payment_note: note }), onSuccess: () => { setError(''); onChange() }, onError: error => setError(apiError(error)) })
  const cancel = useMutation({ mutationFn: () => api.post(`/customer/orders/${order.id}/cancel`), onSuccess: onChange, onError: error => setError(apiError(error)) })
  const pending = order.status === 'pending_payment' && new Date(order.payment_due_at).getTime() > Date.now()
  return <article className="cp-invoice"><div className="cp-invoice-top"><div><span className="cp-kicker">{order.type === 'renewal' ? 'PERPANJANGAN' : 'PESANAN VPN'}</span><h2>{order.invoice_number}</h2><p>{order.plan_name} · {order.server?.name ?? 'Server VPN'} · {order.identifier}</p></div><span className={`cp-state ${order.status}`}>{statusLabel[order.status] ?? order.status}</span></div><div className="cp-invoice-details"><div><span>Total tagihan</span><strong>{rupiah(order.amount_cents, order.currency)}</strong></div><div><span>Terbit</span><strong>{tanggal(order.created_at)}</strong></div><div><span>Batas pembayaran</span><strong>{tanggal(order.payment_due_at)}</strong></div></div>{pending && <div className="cp-invoice-action"><p>Transfer sesuai total tagihan ke <b>{order.payment_bank_name} {order.payment_account_number} a.n. {order.payment_account_name}</b>. {order.payment_instructions} Setelah transfer, masukkan nomor referensi transaksi. Pembayaran diverifikasi manual oleh admin.</p><form onSubmit={event => { event.preventDefault(); submit.mutate() }}><label>Nomor referensi transfer<input required minLength={3} maxLength={150} value={reference} onChange={event => setReference(event.target.value)} placeholder="Nomor transaksi dari bank" /></label><label>Catatan (opsional)<input maxLength={1000} value={note} onChange={event => setNote(event.target.value)} placeholder="Nama pengirim atau keterangan" /></label><div className="cp-invoice-buttons"><button className="cp-primary" disabled={submit.isPending}>Saya sudah transfer <ArrowRight size={15} /></button><button type="button" className="cp-text-button" disabled={cancel.isPending} onClick={() => cancel.mutate()}>Batalkan pesanan</button></div></form></div>}{order.status === 'payment_submitted' && <p className="cp-invoice-note">Referensi transfer telah dikirim. Admin akan memeriksa pembayaran sebelum mengaktifkan layanan.</p>}{order.status === 'pending_payment' && !pending && <p className="cp-invoice-note">Batas pembayaran telah lewat. Buat pesanan baru untuk melanjutkan.</p>}{order.admin_note && <p className="cp-invoice-note">Catatan admin: {order.admin_note}</p>}{order.account && <p className="cp-invoice-note">Layanan VPN: {statusLabel[order.account.status] ?? order.account.status}{order.account.expires_at ? ` · Aktif hingga ${tanggal(order.account.expires_at)}` : ''}</p>}{error && <p className="cp-error">{error}</p>}</article>
}

function ServiceRows({ accounts, onRenew, onOpenBilling }: { accounts: Account[]; onRenew: () => void; onOpenBilling: () => void }) {
  const [error, setError] = useState('')
  const renew = useMutation({ mutationFn: (accountId: number) => api.post(`/customer/accounts/${accountId}/renew`), onSuccess: () => { onRenew(); onOpenBilling() }, onError: error => setError(apiError(error)) })
  return <div className="cp-service-list">{accounts.map(account => <div className="cp-service" key={account.id}><span className="cp-row-icon"><Server size={20} /></span><div className="cp-service-main"><h3>{account.identifier}<span className={`cp-state ${account.status}`}>{statusLabel[account.status] ?? account.status}</span></h3><p>{account.plan?.name ?? 'Paket VPN'} · {account.protocol} · {account.server?.name}</p><small>Alamat server: {account.server?.hostname ?? '—'} · Berlaku hingga {tanggal(account.expires_at)}</small>{account.l2tp_psk && <small className="cp-secret-note">IPsec pre-shared key: <code>{account.l2tp_psk}</code> · Berbeda dari password VPN Anda.</small>}</div>{account.status === 'active' && account.plan && <button className="cp-secondary" disabled={renew.isPending} onClick={() => renew.mutate(account.id)}>Perpanjang</button>}</div>)}{error && <p className="cp-error">{error}</p>}</div>
}

function AccountSettings({ user }: { user: CustomerUser }) {
  const [name, setName] = useState(user.name)
  const [currentPassword, setCurrentPassword] = useState('')
  const [password, setPassword] = useState('')
  const [confirm, setConfirm] = useState('')
  const [message, setMessage] = useState('')
  const profile = useMutation({ mutationFn: () => api.patch('/auth/profile', { name, email: user.email }), onSuccess: () => setMessage('Nama berhasil disimpan. Muat ulang halaman untuk melihat perubahan di menu.'), onError: error => setMessage(apiError(error)) })
  const changePassword = useMutation({ mutationFn: () => api.post('/auth/password', { current_password: currentPassword, password, password_confirmation: confirm }), onSuccess: () => { setMessage('Password akun berhasil diganti.'); setCurrentPassword(''); setPassword(''); setConfirm('') }, onError: error => setMessage(apiError(error)) })
  return <div className="cp-settings-grid"><section className="cp-panel cp-settings-card"><h2>Profil</h2><p>Email terverifikasi: {user.email}</p><form className="cp-form" onSubmit={event => { event.preventDefault(); profile.mutate() }}><label>Nama lengkap<input required minLength={2} maxLength={120} value={name} onChange={event => setName(event.target.value)} /></label><button className="cp-secondary" disabled={profile.isPending}>Simpan nama</button></form></section><section className="cp-panel cp-settings-card"><h2>Ganti password akun</h2><p>Ini adalah password untuk masuk ke virVPN, terpisah dari password VPN Anda.</p><form className="cp-form" onSubmit={event => { event.preventDefault(); changePassword.mutate() }}><label>Password saat ini<input required type="password" autoComplete="current-password" value={currentPassword} onChange={event => setCurrentPassword(event.target.value)} /></label><label>Password baru<input required minLength={12} type="password" autoComplete="new-password" value={password} onChange={event => setPassword(event.target.value)} /></label><label>Konfirmasi password baru<input required type="password" autoComplete="new-password" value={confirm} onChange={event => setConfirm(event.target.value)} /></label><button className="cp-secondary" disabled={changePassword.isPending}>Ganti password</button></form></section>{message && <p className="cp-alert">{message}</p>}</div>
}
