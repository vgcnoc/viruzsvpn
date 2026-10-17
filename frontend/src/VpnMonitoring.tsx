import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Activity, ArrowDownRight, Ban, Cable, CalendarClock, CheckCircle2, CircleAlert, LoaderCircle, MonitorDot, PauseCircle, PlayCircle, RefreshCw, Search, ServerCog, ShieldAlert, Unplug, X } from 'lucide-react'
import { useEffect, useState } from 'react'
import { api, apiError } from './api'
import './monitoring.css'

type Session = { id: string | null; identifier: string; service: string; address?: string | null; caller_id?: string | null; uptime?: string | null }
type MonitoredAccount = { id: number; identifier: string; protocol: string; status: string; connection: 'online' | 'offline' | 'unknown'; sessions: Session[]; expires_at?: string | null; allocated_ip?: string | null; server: { id: number; name: string; adapter: string }; plan?: { id: number; name: string } | null; user?: { id: number; name: string; email: string } | null }
type MonitoringData = { summary: { total: number; online: number; sessions: number; offline: number; unknown: number; suspended: number; expiring_soon: number }; servers: Array<{ id: number; name: string; available: boolean; status: string; message?: string | null }>; server_options: Array<{ id: number; name: string }>; accounts: { data: MonitoredAccount[]; current_page: number; last_page: number; total: number }; checked_at: string }
type Detail = MonitoredAccount & { operation_logs?: Array<{ id: number; operation: string; status: string; message?: string; created_at: string }> }

const dates = (value?: string | null) => value ? new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium' }).format(new Date(value)) : 'Tanpa batas'
const stamps = (value?: string | null) => value ? new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value)) : '—'
const labels: Record<string, string> = { all: 'Semua status', online: 'Terhubung', offline: 'Tidak terhubung', unknown: 'Belum diketahui', active: 'Aktif', suspended: 'Ditangguhkan', expiring: 'Akan berakhir', expired: 'Kedaluwarsa', pending: 'Menunggu', provisioning: 'Provisioning', failed: 'Gagal', revoked: 'Dicabut' }

export default function VpnMonitoring() {
  const client = useQueryClient()
  const [serverId, setServerId] = useState('')
  const [state, setState] = useState('all')
  const [searchDraft, setSearchDraft] = useState('')
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const [autoRefresh, setAutoRefresh] = useState(true)
  const [selected, setSelected] = useState<number | null>(null)
  const [notice, setNotice] = useState('')
  const [expiry, setExpiry] = useState('')
  useEffect(() => { const timer = window.setTimeout(() => { setSearch(searchDraft); setPage(1) }, 300); return () => window.clearTimeout(timer) }, [searchDraft])
  const query = useQuery({
    queryKey: ['monitoring', serverId, state, search, page],
    queryFn: () => api.get<MonitoringData>('/monitoring', { params: { server_id: serverId || undefined, state, search: search || undefined, page } }).then(r => r.data),
    refetchInterval: autoRefresh ? 30000 : false,
    retry: false,
  })
  const detail = useQuery({ queryKey: ['monitoring-detail', selected], enabled: selected !== null, queryFn: () => api.get<Detail>(`/accounts/${selected}`).then(r => r.data) })
  const action = useMutation({
    mutationFn: async ({ account, type, sessionId, expiresAt }: { account: MonitoredAccount; type: 'disconnect' | 'suspend' | 'activate' | 'extend'; sessionId?: string; expiresAt?: string }) => {
      if (type === 'disconnect') return api.post(`/monitoring/accounts/${account.id}/disconnect`, { session_id: sessionId })
      if (type === 'extend') return api.post(`/accounts/${account.id}/extend`, { expires_at: expiresAt })
      return api.post(`/accounts/${account.id}/${type}`)
    },
    onSuccess: (_response, variables) => {
      setNotice(variables.type === 'disconnect' ? 'Sesi berhasil diputus. Akun tetap aktif dan dapat tersambung kembali.' : variables.type === 'extend' ? 'Masa aktif berhasil diperbarui.' : variables.type === 'suspend' ? 'Akun ditangguhkan. Putuskan sesi aktif secara terpisah jika masih terhubung.' : 'Akun diaktifkan kembali.')
      void client.invalidateQueries({ queryKey: ['monitoring'] })
      void client.invalidateQueries({ queryKey: ['monitoring-detail'] })
      void client.invalidateQueries({ queryKey: ['accounts'] })
      void client.invalidateQueries({ queryKey: ['dashboard'] })
      setExpiry('')
    },
    onError: error => setNotice(apiError(error)),
  })

  function changeAccount(account: MonitoredAccount, type: 'suspend' | 'activate') {
    const prompt = type === 'suspend' ? `Tangguhkan akun ${account.identifier}? Sesi yang sedang aktif mungkin perlu diputus terpisah.` : `Aktifkan kembali akun ${account.identifier}?`
    if (window.confirm(prompt)) action.mutate({ account, type })
  }
  function disconnect(account: MonitoredAccount, session: Session) {
    if (!session.id) return
    if (window.confirm(`Putuskan sesi ${account.identifier} dari ${session.caller_id || session.address || 'client'}? Akun tetap aktif dan dapat terhubung kembali.`)) action.mutate({ account, type: 'disconnect', sessionId: session.id })
  }
  function extend(account: MonitoredAccount) {
    if (!expiry) { setNotice('Pilih tanggal masa aktif baru.'); return }
    const end = new Date(`${expiry}T23:59:59`)
    if (Number.isNaN(end.getTime()) || end <= new Date()) { setNotice('Tanggal baru harus setelah hari ini.'); return }
    if (window.confirm(`Perpanjang akun ${account.identifier} sampai ${dates(end.toISOString())}?`)) action.mutate({ account, type: 'extend', expiresAt: end.toISOString() })
  }

  const data = query.data
  const rows = data?.accounts.data ?? []
  return <div className="monitor-page">
    <header className="monitor-hero"><div><p className="eyebrow">OPERASIONAL / LIVE SESSIONS</p><h1>Monitoring VPN<span className="monitor-hero-dot" /></h1><p>Amati sesi yang benar-benar terhubung, status akun, dan kesehatan pembacaan server dalam satu tempat.</p></div><div className="monitor-hero-actions"><span className="monitor-sync"><span className={query.isFetching ? 'monitor-pulse' : ''} />{data ? `Diperiksa ${stamps(data.checked_at)}` : 'Belum diperiksa'}</span><button className="button quiet" onClick={() => { setAutoRefresh(!autoRefresh); setNotice('') }}>{autoRefresh ? <PauseCircle size={16} /> : <PlayCircle size={16} />}{autoRefresh ? 'Jeda otomatis' : 'Lanjut otomatis'}</button><button className="button primary" disabled={query.isFetching} onClick={() => void query.refetch()}>{query.isFetching ? <LoaderCircle className="spin" size={16} /> : <RefreshCw size={16} />} Perbarui</button></div></header>
    {notice && <div className={`notice ${action.isError ? 'error' : ''}`} role="status"><Activity size={16} />{notice}<button className="icon-button" aria-label="Tutup pesan" onClick={() => setNotice('')}><X size={14} /></button></div>}
    {query.isError && <div className="notice error" role="alert"><CircleAlert size={16} />{apiError(query.error)}</div>}
    <div className="monitor-stats"><Stat icon={<Cable size={20} />} label="Sedang terhubung" value={data?.summary.online} note={`${data?.summary.sessions ?? 0} sesi aktif`} tone="aqua" /><Stat icon={<ArrowDownRight size={20} />} label="Belum terhubung" value={data?.summary.offline} note="Akun aktif tanpa sesi" tone="blue" /><Stat icon={<Ban size={20} />} label="Ditangguhkan" value={data?.summary.suspended} note="Akses dinonaktifkan" tone="amber" /><Stat icon={<CalendarClock size={20} />} label="Segera berakhir" value={data?.summary.expiring_soon} note="Dalam 7 hari" tone="rose" /></div>
    {data && data.summary.unknown > 0 && <div className="monitor-warning"><ShieldAlert size={18} /><span><b>{data.summary.unknown} akun belum dapat dipastikan koneksinya.</b> Server tidak merespons atau adapter belum menyediakan data sesi. Jangan anggap akun ini offline.</span></div>}
    <section className="monitor-main panel"><div className="monitor-section-top"><div><p className="eyebrow">ACCOUNT WATCHLIST</p><h2>Semua akun VPN</h2><p>Status akun dan koneksi adalah dua hal berbeda. Akun aktif bisa saja tidak sedang dipakai.</p></div><span className="monitor-total">{data?.summary.total ?? '—'} akun terdaftar</span></div>
      <div className="monitor-filters"><label className="monitor-search"><Search size={17} /><input aria-label="Cari akun, pelanggan, atau email" placeholder="Cari username, pelanggan, atau email..." value={searchDraft} onChange={event => setSearchDraft(event.target.value)} /></label><label><span>Server</span><select value={serverId} onChange={event => { setServerId(event.target.value); setPage(1) }}><option value="">Semua server</option>{data?.server_options.map(server => <option key={server.id} value={server.id}>{server.name}</option>)}</select></label><label><span>Status</span><select value={state} onChange={event => { setState(event.target.value); setPage(1) }}>{Object.entries(labels).map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select></label></div>
      <div className="monitor-table-wrap"><table className="monitor-table"><thead><tr><th>Akun / pelanggan</th><th>Server & protokol</th><th>Status akun</th><th>Koneksi nyata</th><th>Berakhir</th><th>Aksi</th></tr></thead><tbody>{rows.map(account => <tr key={account.id}><td><b>{account.identifier}</b><small>{account.user?.name ?? 'Akun manual'}{account.user?.email ? ` · ${account.user.email}` : ''}</small></td><td><b>{account.server.name}</b><small>{account.protocol}</small></td><td><AccountBadge status={account.status} /></td><td><ConnectionBadge state={account.connection} count={account.sessions.length} /><small>{account.connection === 'online' ? account.sessions.map(session => session.address).filter(Boolean).join(', ') || 'Sesi aktif' : account.connection === 'offline' ? 'Tidak ada sesi saat diperiksa' : 'Telemetri tidak tersedia'}</small></td><td>{dates(account.expires_at)}</td><td><div className="monitor-row-actions"><button className="button quiet" onClick={() => { setSelected(account.id); setExpiry('') }}>Detail</button>{account.status === 'active' && <button className="icon-button monitor-action" title="Tangguhkan akun" aria-label={`Tangguhkan ${account.identifier}`} disabled={action.isPending} onClick={() => changeAccount(account, 'suspend')}><PauseCircle size={17} /></button>}{account.status === 'suspended' && <button className="icon-button monitor-action" title="Aktifkan akun" aria-label={`Aktifkan ${account.identifier}`} disabled={action.isPending} onClick={() => changeAccount(account, 'activate')}><PlayCircle size={17} /></button>}{account.connection === 'online' && account.sessions[0]?.id && <button className="icon-button monitor-action" title="Putuskan sesi pertama" aria-label={`Putuskan sesi ${account.identifier}`} disabled={action.isPending} onClick={() => disconnect(account, account.sessions[0])}><Unplug size={17} /></button>}</div></td></tr>)}</tbody></table>{!query.isLoading && !rows.length && <div className="monitor-empty"><MonitorDot size={30} /><h3>Tidak ada akun yang cocok</h3><p>Ubah pencarian atau filter status untuk melihat akun lain.</p></div>}{query.isLoading && <div className="monitor-loading"><LoaderCircle className="spin" size={22} /> Membaca kondisi server...</div>}</div>
      <footer className="monitor-pagination"><span>Menampilkan {rows.length} dari {data?.accounts.total ?? 0} hasil</span><div><button className="button quiet" disabled={page <= 1} onClick={() => setPage(page - 1)}>Sebelumnya</button><span>Halaman {data?.accounts.current_page ?? page} / {data?.accounts.last_page ?? 1}</span><button className="button quiet" disabled={page >= (data?.accounts.last_page ?? 1)} onClick={() => setPage(page + 1)}>Berikutnya</button></div></footer></section>
    <section className="monitor-servers"><div className="monitor-strip-heading"><ServerCog size={17} /><span>Pembacaan server</span></div>{data?.servers.length ? data.servers.map(server => <div className="monitor-server" key={server.id}><span className={server.available ? 'monitor-server-light live' : 'monitor-server-light'} /><b>{server.name}</b><span>{server.available ? 'Sesi langsung tersedia' : server.status === 'unsupported' ? 'Belum mendukung sesi' : 'Tidak dapat dibaca'}</span>{!server.available && server.message && <small title={server.message}>{server.message}</small>}</div>) : <p>Belum ada server terdaftar.</p>}</section>
    {selected !== null && <div className="modal-backdrop" role="presentation" onMouseDown={() => setSelected(null)}><section className="modal monitor-detail" role="dialog" aria-modal="true" aria-label="Detail monitoring VPN" onMouseDown={event => event.stopPropagation()}><div className="modal-title"><div><p className="eyebrow">VPN ACCOUNT / LIVE ACTIVITY</p><h2>{detail.data?.identifier ?? 'Memuat akun...'}</h2></div><button className="icon-button" aria-label="Tutup detail" onClick={() => setSelected(null)}><X size={18} /></button></div>{detail.isLoading ? <div className="monitor-loading"><LoaderCircle className="spin" size={20} /> Memuat detail...</div> : detail.isError ? <div className="notice error">{apiError(detail.error)}</div> : detail.data && <><div className="monitor-detail-badges"><AccountBadge status={detail.data.status} /><ConnectionBadge state={rows.find(row => row.id === selected)?.connection ?? 'unknown'} count={rows.find(row => row.id === selected)?.sessions.length ?? 0} /></div><dl className="monitor-detail-grid"><div><dt>Pelanggan</dt><dd>{detail.data.user?.name ?? 'Akun manual'}</dd></div><div><dt>Server</dt><dd>{detail.data.server.name}</dd></div><div><dt>Protokol</dt><dd>{detail.data.protocol}</dd></div><div><dt>Paket</dt><dd>{detail.data.plan?.name ?? 'Manual'}</dd></div><div><dt>IP dialokasikan</dt><dd>{detail.data.allocated_ip ?? 'Dari server'}</dd></div><div><dt>Berakhir</dt><dd>{dates(detail.data.expires_at)}</dd></div></dl><div className="monitor-detail-section"><h3>Sesi langsung</h3>{rows.find(row => row.id === selected)?.sessions.length ? rows.find(row => row.id === selected)?.sessions.map(session => <div className="monitor-session" key={session.id}><div><b>{session.address ?? 'Alamat belum tersedia'}</b><small>{session.caller_id ?? 'Client tidak diketahui'} · uptime {session.uptime ?? '—'}</small></div>{session.id && <button className="button quiet" disabled={action.isPending} onClick={() => disconnect(detail.data!, session)}><Unplug size={14} /> Putuskan</button>}</div>) : <p className="monitor-muted">{rows.find(row => row.id === selected)?.connection === 'unknown' ? 'Sesi belum dapat dibaca dari server.' : 'Tidak ada sesi aktif saat pemeriksaan terakhir.'}</p>}</div>{['active', 'suspended'].includes(detail.data.status) && <div className="monitor-detail-section"><h3>Perpanjang masa aktif</h3><div className="monitor-extend"><input aria-label="Tanggal akhir baru" type="date" min={new Date(Date.now() + 86400000).toISOString().slice(0, 10)} value={expiry} onChange={event => setExpiry(event.target.value)} /><button className="button quiet" disabled={action.isPending || !expiry} onClick={() => extend(detail.data!)}><CalendarClock size={15} /> Perpanjang</button></div><p className="monitor-muted">Perpanjangan tidak otomatis mengubah status akun yang ditangguhkan.</p></div>}<div className="monitor-detail-section"><h3>Aktivitas terakhir</h3>{detail.data.operation_logs?.slice(0, 5).map(log => <div className="monitor-log" key={log.id}><span className={log.status === 'success' ? 'monitor-log-icon success' : 'monitor-log-icon'}>{log.status === 'success' ? <CheckCircle2 size={15} /> : <CircleAlert size={15} />}</span><div><b>{log.operation.replaceAll('_', ' ')}</b><small>{log.message ?? stamps(log.created_at)}</small></div><time>{stamps(log.created_at)}</time></div>)}{!detail.data.operation_logs?.length && <p className="monitor-muted">Belum ada aktivitas tercatat.</p>}</div></>}</section></div>}
  </div>
}

function Stat({ icon, label, value, note, tone }: { icon: React.ReactNode; label: string; value?: number; note: string; tone: string }) { return <div className={`monitor-stat ${tone}`}><div className="monitor-stat-top"><span>{label}</span>{icon}</div><strong>{value ?? '—'}</strong><small>{note}</small></div> }
function AccountBadge({ status }: { status: string }) { return <span className={`monitor-badge account-${status}`}>{labels[status] ?? status}</span> }
function ConnectionBadge({ state, count }: { state: string; count: number }) { return <span className={`monitor-badge connection-${state}`}><i />{state === 'online' ? `${count} sesi online` : state === 'offline' ? 'Tidak terhubung' : 'Tidak diketahui'}</span> }
