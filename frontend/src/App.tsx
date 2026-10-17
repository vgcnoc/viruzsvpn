import { zodResolver } from '@hookform/resolvers/zod'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Activity, Cable, ChevronRight, CircleDollarSign, Copy, Gauge, LayoutDashboard, LoaderCircle, LogOut, Menu, Pencil, Plus, Radio, Search, ServerCog, Settings2, ShieldCheck, Trash2, UsersRound, X } from 'lucide-react'
import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { NavLink, Navigate, Route, Routes } from 'react-router-dom'
import { z } from 'zod'
import { api, apiError } from './api'
import './routeros.css'
import type { Account, Page, Plan, Server } from './api'

const nav = [
  { to: '/', label: 'Overview', icon: LayoutDashboard },
  { to: '/servers', label: 'Servers', icon: ServerCog },
  { to: '/accounts', label: 'VPN accounts', icon: UsersRound },
  { to: '/plans', label: 'Plans & billing', icon: CircleDollarSign },
  { to: '/settings', label: 'Settings', icon: Settings2 },
]

type DashboardData = { summary: { servers: number; servers_online: number; accounts_active: number; accounts_expiring_soon: number }; recent_operations: Array<{ id: number; operation: string; status: string; created_at: string; server?: { name: string } }>; demo_data: boolean }
const money = (amount: number, currency: string) => new Intl.NumberFormat('id-ID', { style: 'currency', currency, maximumFractionDigits: 0 }).format(amount)
const date = (value?: string) => value ? new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium' }).format(new Date(value)) : '—'
const statusClass = (status: string) => `status ${['online', 'active', 'healthy', 'success'].includes(status) ? 'good' : ['offline', 'failed', 'revoked'].includes(status) ? 'bad' : status === 'demo' ? 'demo' : 'warn'}`

function App() {
  const [token, setToken] = useState(() => localStorage.getItem('vpn-platform-token'))
  const [mobileOpen, setMobileOpen] = useState(false)
  if (!token) return <Login onAuthenticated={(value) => { localStorage.setItem('vpn-platform-token', value); setToken(value) }} />
  return <div className="app-shell">
    <aside className={`sidebar ${mobileOpen ? 'open' : ''}`}>
      <div className="brand"><span className="brand-mark"><ShieldCheck size={22} /></span><span>VPN<span>OPS</span></span></div>
      <p className="workspace-label">OPERATIONS</p>
      <nav>{nav.map(({ to, label, icon: Icon }) => <NavLink end={to === '/'} to={to} key={to} onClick={() => setMobileOpen(false)}><Icon size={18} />{label}</NavLink>)}</nav>
      <div className="sidebar-footer"><div className="security-chip"><ShieldCheck size={16} /><span>Secure control plane<br /><small>Sanctum token session</small></span></div></div>
    </aside>
    <main>
      <header className="topbar"><button className="icon-button mobile-menu" onClick={() => setMobileOpen(!mobileOpen)} aria-label="Toggle navigation"><Menu size={20} /></button><div className="crumb"><span>Control plane</span><ChevronRight size={14} /><b>Network operations</b></div><div className="topbar-actions"><span className="connection"><i /> API connected</span><button className="avatar" title="Administrator">A</button><button className="icon-button" aria-label="Sign out" onClick={() => { localStorage.removeItem('vpn-platform-token'); setToken(null) }}><LogOut size={18} /></button></div></header>
      <section className="content"><Routes>
        <Route path="/" element={<Overview />} /><Route path="/servers" element={<Servers />} /><Route path="/accounts" element={<Accounts />} /><Route path="/plans" element={<Plans />} /><Route path="/settings" element={<Settings />} /><Route path="*" element={<Navigate to="/" replace />} />
      </Routes></section>
    </main>
  </div>
}

function Login({ onAuthenticated }: { onAuthenticated: (token: string) => void }) {
  const schema = z.object({ email: z.string().email('Enter a valid email address.'), password: z.string().min(8, 'Password must contain at least 8 characters.') })
  type LoginValues = z.infer<typeof schema>
  const { register, handleSubmit, formState: { errors } } = useForm<LoginValues>({ resolver: zodResolver(schema), defaultValues: { email: 'admin@vpn.local', password: 'password' } })
  const mutation = useMutation({ mutationFn: (values: LoginValues) => api.post<{ token: string }>('/auth/login', values).then((r) => r.data), onSuccess: ({ token }) => onAuthenticated(token) })
  return <div className="login-page"><div className="login-card"><div className="brand large"><span className="brand-mark"><ShieldCheck size={24} /></span><span>VPN<span>OPS</span></span></div><p className="eyebrow">ADMINISTRATOR CONSOLE</p><h1>Secure network operations.</h1><p className="muted">Sign in to manage your VPN control plane.</p><form onSubmit={handleSubmit((values) => mutation.mutate(values))}>
    <label>Email<input {...register('email')} autoComplete="email" /></label>{errors.email && <small className="field-error">{errors.email.message}</small>}
    <label>Password<input {...register('password')} type="password" autoComplete="current-password" /></label>{errors.password && <small className="field-error">{errors.password.message}</small>}
    {mutation.isError && <p className="form-error">{apiError(mutation.error)}</p>}<button className="button primary wide" disabled={mutation.isPending}>{mutation.isPending && <LoaderCircle className="spin" size={16} />} Sign in</button>
  </form><div className="login-note"><b>Local demo</b><br />admin@vpn.local / password</div></div></div>
}

function Overview() {
  const query = useQuery({ queryKey: ['dashboard'], queryFn: () => api.get<DashboardData>('/dashboard').then((r) => r.data) })
  const data = query.data
  return <><PageTitle eyebrow="SYSTEM OVERVIEW" title="Network control plane" description="Real-time posture across registered VPN infrastructure." />
    {data?.demo_data && <div className="notice"><Activity size={17} /> Demo records are visible. They are clearly marked and do not represent live server metrics.</div>}
    <div className="metric-grid"><Metric icon={<ServerCog />} label="Registered servers" value={data?.summary.servers} detail={`${data?.summary.servers_online ?? 0} online`} loading={query.isLoading} /><Metric icon={<UsersRound />} label="Active VPN accounts" value={data?.summary.accounts_active} detail="Provisioned and available" loading={query.isLoading} /><Metric icon={<Gauge />} label="Expiring within 7 days" value={data?.summary.accounts_expiring_soon} detail="Requires renewal review" loading={query.isLoading} /><Metric icon={<ShieldCheck />} label="Control plane" value="Protected" detail="Token authentication enabled" loading={false} /></div>
    <div className="two-column"><section className="panel"><div className="panel-heading"><div><p className="eyebrow">ACTIVITY</p><h2>Latest operations</h2></div><Activity size={20} /></div>{query.isLoading ? <Skeleton rows={4} /> : data?.recent_operations.length ? <div className="activity-list">{data.recent_operations.map((item) => <div className="activity" key={item.id}><span className="activity-icon"><Radio size={15} /></span><div><b>{item.operation.replaceAll('_', ' ')}</b><p>{item.server?.name ?? 'Control plane'} · {new Date(item.created_at).toLocaleString('id-ID')}</p></div><span className={statusClass(item.status)}>{item.status}</span></div>)}</div> : <Empty icon={<Activity />} title="No recorded operations" description="Server checks and account actions will be logged here." />}</section>
      <section className="panel"><div className="panel-heading"><div><p className="eyebrow">SAFETY POSTURE</p><h2>Production readiness</h2></div><ShieldCheck size={20} /></div><div className="readiness"><Readiness label="Secret encryption at rest" value="Configured" positive /><Readiness label="Linux agent protocol" value="HTTPS + HMAC" positive /><Readiness label="RouterOS driver" value="API/API-SSL ready" positive /><Readiness label="Live server metrics" value="Awaiting test" /></div><p className="panel-footnote">No connectivity status is invented. Add a server and run a connection test to create a verified result.</p></section></div>
  </>
}

function Settings() {
  return <><PageTitle eyebrow="CONTROL PLANE" title="Settings" description="Review the local control-plane configuration and the operational safeguards used by VPNOPS." />
    <div className="two-column"><section className="panel"><div className="panel-heading"><div><p className="eyebrow">CONNECTIVITY</p><h2>Application endpoint</h2></div><Cable size={20} /></div><div className="readiness"><Readiness label="API endpoint" value={String(api.defaults.baseURL ?? 'http://127.0.0.1:8000/api')} positive /><Readiness label="Authentication" value="Sanctum bearer token" positive /><Readiness label="Session storage" value="This browser only" positive /></div><p className="panel-footnote">Change deployment endpoints and secrets through the server environment, never through a browser form.</p></section>
      <section className="panel"><div className="panel-heading"><div><p className="eyebrow">ROUTEROS</p><h2>Registration checklist</h2></div><ShieldCheck size={20} /></div><div className="readiness"><Readiness label="API service" value="API-SSL preferred" positive /><Readiness label="Allowed CIDR" value="VPNOPS source IP only" positive /><Readiness label="Certificate" value="Required for verified TLS" /></div><p className="panel-footnote">Use the server’s public address as the endpoint. The allowed CIDR must be the source address of the machine running VPNOPS—not the RouterOS address.</p></section></div>
  </>
}

function Servers() {
  const client = useQueryClient(); const [showCreate, setShowCreate] = useState(false); const [editing, setEditing] = useState<Server | null>(null); const [error, setError] = useState(''); const [testMessage, setTestMessage] = useState('')
  const query = useQuery({ queryKey: ['servers'], queryFn: () => api.get<Page<Server>>('/servers').then((r) => r.data) })
  const test = useMutation({ mutationFn: (server: Server) => api.post(`/servers/${server.id}/test`).then((r) => r.data), onSuccess: (data) => { client.invalidateQueries({ queryKey: ['servers'] }); setTestMessage(data.result?.message ?? 'Connection test completed.') }, onError: (e) => setError(apiError(e)) })
  const remove = useMutation({ mutationFn: (server: Server) => api.delete(`/servers/${server.id}`), onSuccess: () => client.invalidateQueries({ queryKey: ['servers'] }), onError: (e) => setError(apiError(e)) })
  return <><PageTitle eyebrow="INFRASTRUCTURE" title="Server management" description="Register trusted control endpoints and independently track connectivity and VPN service health." action={<button className="button primary" onClick={() => setShowCreate(true)}><Plus size={17} />Add server</button>} />
    {error && <div className="notice error"><X size={16} />{error}</div>}{testMessage && <div className="notice"><Activity size={16} />{testMessage}</div>}<section className="panel table-panel"><div className="table-toolbar"><div className="search"><Search size={17} /><input placeholder="Search servers" /></div><span className="table-total">{query.data?.total ?? 0} servers</span></div>{query.isLoading ? <Skeleton rows={6} /> : <table><thead><tr><th>Server</th><th>Driver / protocols</th><th>Connectivity</th><th>VPN service</th><th>Last checked</th><th /></tr></thead><tbody>{query.data?.data.map((server) => <tr key={server.id}><td><b>{server.name}</b><small>{server.location ?? `${server.hostname}:${server.port}`}{server.is_demo ? ' · demo' : ''}</small></td><td><span className="mono">{server.adapter}</span><small>{server.capabilities.protocols?.join(', ') || 'No protocols declared'}</small></td><td><span className={statusClass(server.status)}>{server.status}</span>{server.last_latency_ms !== undefined && <small>{server.last_latency_ms} ms</small>}</td><td><span className={statusClass(server.vpn_status)}>{server.vpn_status}</span></td><td>{date(server.last_checked_at)}</td><td><div className="row-actions"><button className="button quiet" disabled={test.isPending} onClick={() => test.mutate(server)}>{test.isPending ? <LoaderCircle className="spin" size={15} /> : <Cable size={15} />}Test</button><button className="icon-button" title="Edit server" onClick={() => setEditing(server)}><Pencil size={16} /></button><button className="icon-button danger" title="Delete server" disabled={remove.isPending} onClick={() => { if (window.confirm(`Delete ${server.name}? This is unavailable while it has VPN accounts.`)) remove.mutate(server) }}><Trash2 size={16} /></button></div></td></tr>)}</tbody></table>}{!query.isLoading && !query.data?.data.length && <Empty icon={<ServerCog />} title="No servers registered" description="Add an agent, RouterOS, or clearly-labelled demo server." />}</section>{showCreate && <ServerForm close={() => setShowCreate(false)} onSuccess={() => { setShowCreate(false); client.invalidateQueries({ queryKey: ['servers'] }) }} />}{editing && <ServerForm server={editing} close={() => setEditing(null)} onSuccess={() => { setEditing(null); client.invalidateQueries({ queryKey: ['servers'] }) }} />}</>
}

const ipv4Cidr = /^(?:\d{1,3}\.){3}\d{1,3}\/(?:[0-9]|[12][0-9]|3[0-2])$/
const routerOsCertificateName = /^[A-Za-z0-9._-]{1,64}$/
const serverSchema = z.object({ name: z.string().min(3), adapter: z.enum(['linux-agent', 'mikrotik-routeros', 'demo']), hostname: z.string().min(1), port: z.coerce.number().int().min(1).max(65535), location: z.string().optional(), protocols: z.string().min(1), username: z.string().optional(), secret: z.string().optional(), routeros_api_ssl: z.boolean(), routeros_allowed_cidr: z.string().optional().refine((value) => !value || ipv4Cidr.test(value), 'Use one IPv4 CIDR, for example 203.0.113.10/32.'), routeros_certificate: z.string().optional().refine((value) => !value || routerOsCertificateName.test(value), 'Use a RouterOS certificate name (letters, numbers, dot, dash, underscore).'), tls_verify: z.boolean() })
type ServerValues = z.infer<typeof serverSchema>

const routerOsQuote = (value: string) => `"${value.replace(/\\/g, '\\\\').replace(/"/g, '\\"').replace(/\$/g, '\\$').replace(/[\r\n]/g, '')}"`
function buildRouterOsBootstrap(value: ServerValues) {
  const apiService = value.routeros_api_ssl ? 'api-ssl' : 'api'
  const certificateCheck = value.routeros_api_ssl ? `:put "VPNOPS: checking API-SSL certificate..."
/certificate
:if ([:len [find where name=$certificateName]] = 0) do={
    :put ("VPNOPS ERROR: certificate " . $certificateName . " was not found. No configuration was changed.")
    :error ("VPNOPS: create or import the certificate before enabling API-SSL.")
}
` : ''
  const certificateSetting = value.routeros_api_ssl ? ' certificate=$certificateName' : ''
  return `# VPNOPS RouterOS bootstrap — paste from a trusted terminal session.
# This creates or updates only the dedicated VPNOPS API user and API service.
# It does not alter VPN profiles or firewall rules.
:local vpnopsUser ${routerOsQuote(value.username?.trim() ?? '')}
:local vpnopsPassword ${routerOsQuote(value.secret ?? '')}
:local controlPlaneCidr ${routerOsQuote(value.routeros_allowed_cidr ?? '')}
${value.routeros_api_ssl ? `:local certificateName ${routerOsQuote(value.routeros_certificate ?? '')}
` : ''}
${certificateCheck}/user group
:if ([:len [find where name="vpnops-api"]] = 0) do={
    add name="vpnops-api" policy=read,write,api
} else={
    set [find where name="vpnops-api"] policy=read,write,api
}

/user
:if ([:len [find where name=$vpnopsUser]] = 0) do={
    add name=$vpnopsUser password=$vpnopsPassword group="vpnops-api" comment="VPNOPS control plane"
} else={
    set [find where name=$vpnopsUser] password=$vpnopsPassword group="vpnops-api" comment="VPNOPS control plane"
}

/ip service
set ${apiService} disabled=no port=${value.port} address=$controlPlaneCidr${certificateSetting}
:put "VPNOPS OK: ${apiService} is enabled on TCP ${value.port}."

# If this router has an input firewall with a final drop rule, allow TCP ${value.port}
# only from ${value.routeros_allowed_cidr} before that drop rule. Do not expose this API publicly.`
}

function ServerForm({ close, onSuccess, server }: { close: () => void; onSuccess: () => void; server?: Server }) {
  const [bootstrapScript, setBootstrapScript] = useState<string | null>(null); const [copyMessage, setCopyMessage] = useState('')
  const { register, handleSubmit, watch, setError, formState: { errors } } = useForm<ServerValues>({ resolver: zodResolver(serverSchema), defaultValues: { name: server?.name, adapter: (server?.adapter as ServerValues['adapter']) ?? 'linux-agent', hostname: server?.hostname, port: server?.port ?? 443, location: server?.location, protocols: server?.capabilities.protocols?.join(',') ?? 'wireguard', username: '', secret: '', routeros_api_ssl: true, routeros_allowed_cidr: '', routeros_certificate: '', tls_verify: server?.tls_verify ?? true } })
  const adapter = watch('adapter'); const routerosApiSsl = watch('routeros_api_ssl'); const secret = watch('secret')
  const requiresRouterOsBootstrap = adapter === 'mikrotik-routeros' && (!server || server.adapter !== 'mikrotik-routeros' || Boolean(secret))
  const save = useMutation({ mutationFn: (value: ServerValues) => { const credentials = value.secret ? value.adapter === 'linux-agent' ? { agent_shared_secret: value.secret } : value.adapter === 'mikrotik-routeros' ? { routeros_username: value.username, routeros_password: value.secret, routeros_api_ssl: value.routeros_api_ssl, routeros_allowed_cidr: value.routeros_allowed_cidr, routeros_certificate: value.routeros_certificate } : undefined : undefined; const payload = { name: value.name, adapter: value.adapter, hostname: value.hostname, port: value.port, location: value.location, tls_verify: value.tls_verify, is_demo: value.adapter === 'demo', capabilities: { protocols: value.protocols.split(',').map((p) => p.trim()).filter(Boolean) }, credentials }; return server ? api.put(`/servers/${server.id}`, payload) : api.post('/servers', payload) }, onSuccess: (_, value) => { if (value.adapter === 'mikrotik-routeros' && (!server || Boolean(value.secret))) setBootstrapScript(buildRouterOsBootstrap(value)); else onSuccess() } })
  const submit = (value: ServerValues) => {
    if (requiresRouterOsBootstrap && !value.routeros_allowed_cidr) { setError('routeros_allowed_cidr', { message: 'Required to restrict RouterOS API access.' }); return }
    if (requiresRouterOsBootstrap && value.routeros_api_ssl && !value.routeros_certificate) { setError('routeros_certificate', { message: 'Required when API-SSL is enabled.' }); return }
    save.mutate(value)
  }
  const finishBootstrap = () => { setBootstrapScript(null); onSuccess() }
  const copyBootstrap = async () => { if (!bootstrapScript) return; try { await navigator.clipboard.writeText(bootstrapScript); setCopyMessage('Script copied. Paste it into the RouterOS terminal.'); } catch { setCopyMessage('Clipboard access was blocked. Select the script and copy it manually.'); } }
  if (bootstrapScript) return <Modal title="RouterOS bootstrap script" close={finishBootstrap}><p className="form-help">Server registered. This script is shown only now and contains the API password; keep it out of chat logs and paste it only into the intended CHR.</p><p className="script-warning">Before pasting: make sure the certificate name exists on RouterOS and the control-plane CIDR is correct. The script does not change firewall rules.</p><textarea className="script-output" readOnly aria-label="RouterOS bootstrap script" value={bootstrapScript} rows={21} /><p className="form-help">It creates or updates a dedicated <span className="mono">vpnops-api</span> user group, restricts the API service to the specified CIDR, and leaves VPN configuration unchanged.</p>{copyMessage && <p className="form-help">{copyMessage}</p>}<div className="modal-actions"><button type="button" className="button quiet" onClick={finishBootstrap}>Done</button><button type="button" className="button primary" onClick={copyBootstrap}><Copy size={16} />Copy script</button></div></Modal>
  return <Modal title={server ? 'Edit server' : 'Register server'} close={close}><form className="form-grid" onSubmit={handleSubmit(submit)}><label>Name<input {...register('name')} placeholder="Singapore Edge 01" /></label><label>Adapter<select {...register('adapter')}><option value="linux-agent">Linux agent (HTTPS)</option><option value="mikrotik-routeros">MikroTik RouterOS</option><option value="demo">Demo (local only)</option></select></label><label>Hostname / IP<input {...register('hostname')} placeholder="vpn-edge.example.net" /></label><label>Port<input type="number" {...register('port')} />{adapter === 'mikrotik-routeros' && <small>API-SSL normally uses 8729; API uses 8728.</small>}</label><label className="span-2">Location<input {...register('location')} placeholder="Singapore, SG" /></label><label className="span-2">Supported protocols<input {...register('protocols')} placeholder="wireguard,openvpn" /><small>Comma-separated: wireguard, openvpn, l2tp-ipsec, sstp, ppp, pptp.</small></label>{adapter === 'linux-agent' && <label className="span-2">Linux agent shared secret<input type="password" {...register('secret')} placeholder={server ? 'Leave blank to keep the stored secret' : 'Minimum 32 characters; stored encrypted'} /></label>}{adapter === 'mikrotik-routeros' && <><label>RouterOS API username<input autoComplete="username" {...register('username')} placeholder={server ? 'Enter again only when changing credentials' : 'vpnops-api'} /></label><label>RouterOS API password<input type="password" autoComplete="new-password" {...register('secret')} placeholder={server ? 'Leave blank to keep stored credentials' : 'Stored encrypted; never shown again'} /></label><label className="check span-2"><input type="checkbox" {...register('routeros_api_ssl')} /> Use RouterOS API-SSL (recommended)</label><label className="span-2">Allowed control-plane CIDR<input {...register('routeros_allowed_cidr')} placeholder="203.0.113.10/32" required={requiresRouterOsBootstrap} /><small>Enter the public IP of this VPNOPS machine, not the RouterOS hostname or IP. Only this IPv4 host or subnet can reach the API.</small></label>{errors.routeros_allowed_cidr && <small className="field-error span-2">{errors.routeros_allowed_cidr.message}</small>}{routerosApiSsl && <><label className="span-2">RouterOS API-SSL certificate name<input {...register('routeros_certificate')} placeholder="vpnops-api" required={requiresRouterOsBootstrap} /><small>Create or import this certificate on the CHR first. Its name must match RouterOS exactly.</small></label>{errors.routeros_certificate && <small className="field-error span-2">{errors.routeros_certificate.message}</small>}</>}</>}<label className="check span-2"><input type="checkbox" {...register('tls_verify')} /> Verify the endpoint TLS certificate</label>{Object.keys(errors).length > 0 && <p className="form-error span-2">Check the required fields and port range.</p>}{save.isError && <p className="form-error span-2">{apiError(save.error)}</p>}<div className="modal-actions span-2"><button type="button" className="button quiet" onClick={close}>Cancel</button><button className="button primary" disabled={save.isPending}>{save.isPending && <LoaderCircle className="spin" size={16} />}{server ? 'Save changes' : 'Register server'}</button></div></form></Modal>
}

function Accounts() {
  const client = useQueryClient(); const [showCreate, setShowCreate] = useState(false); const [viewing, setViewing] = useState<number | null>(null); const [message, setMessage] = useState('')
  const accounts = useQuery({ queryKey: ['accounts'], queryFn: () => api.get<Page<Account>>('/accounts').then((r) => r.data) })
  const action = useMutation({ mutationFn: ({ account, action }: { account: Account; action: string }) => api.post(`/accounts/${account.id}/${action}`).then((r) => r.data), onSuccess: () => { setMessage('Account operation completed.'); client.invalidateQueries({ queryKey: ['accounts'] }); client.invalidateQueries({ queryKey: ['dashboard'] }) }, onError: (e) => setMessage(apiError(e)) })
  const exportAccounts = async () => {
    try {
      const response = await api.get<Blob>('/accounts/export', { responseType: 'blob' })
      const url = URL.createObjectURL(response.data); const link = document.createElement('a'); link.href = url; link.download = 'vpn-accounts.csv'; link.click(); URL.revokeObjectURL(url)
      setMessage('Account export downloaded without client secrets.')
    } catch (error) { setMessage(apiError(error)) }
  }
  return <><PageTitle eyebrow="ACCESS MANAGEMENT" title="VPN accounts" description="Provision accounts or peers only on server-supported protocols. Client secrets are never displayed after creation." action={<button className="button primary" onClick={() => setShowCreate(true)}><Plus size={17} />Create account</button>} />
    {message && <div className="notice"><Activity size={16} />{message}</div>}<section className="panel table-panel"><div className="table-toolbar"><div className="search"><Search size={17} /><input placeholder="Search by username or peer" /></div><button className="button quiet" onClick={exportAccounts}><Copy size={15} />Export</button></div>{accounts.isLoading ? <Skeleton rows={6} /> : <table><thead><tr><th>Identity</th><th>Server</th><th>Protocol</th><th>Plan</th><th>Expires</th><th>Status</th><th /></tr></thead><tbody>{accounts.data?.data.map((account) => <tr key={account.id}><td><b>{account.identifier}</b><small>{account.allocated_ip ?? 'IP assigned by server'}</small></td><td>{account.server?.name}<small>{account.server?.is_demo ? 'Demo server' : 'Managed endpoint'}</small></td><td><span className="protocol">{account.protocol}</span></td><td>{account.plan?.name ?? 'Custom'}</td><td>{date(account.expires_at)}</td><td><span className={statusClass(account.status)}>{account.status}</span></td><td><div className="row-actions"><button className="button quiet" onClick={() => setViewing(account.id)}>View</button>{account.status === 'failed' ? <button className="button quiet" onClick={() => action.mutate({ account, action: 'retry' })}>Retry</button> : account.status === 'active' ? <button className="button quiet" onClick={() => action.mutate({ account, action: 'suspend' })}>Suspend</button> : account.status === 'suspended' ? <button className="button quiet" onClick={() => action.mutate({ account, action: 'activate' })}>Activate</button> : null}{!['revoked', 'expired'].includes(account.status) && <button className="icon-button danger" title="Revoke account" onClick={() => action.mutate({ account, action: 'revoke' })}><X size={16} /></button>}</div></td></tr>)}</tbody></table>}{!accounts.isLoading && !accounts.data?.data.length && <Empty icon={<UsersRound />} title="No VPN accounts" description="Provision the first account through a registered server." />}</section>{showCreate && <AccountForm close={() => setShowCreate(false)} onSuccess={() => { setShowCreate(false); client.invalidateQueries({ queryKey: ['accounts'] }); client.invalidateQueries({ queryKey: ['dashboard'] }) }} />}{viewing && <AccountDetails accountId={viewing} close={() => setViewing(null)} />}</>
}

function AccountDetails({ accountId, close }: { accountId: number; close: () => void }) {
  const query = useQuery({ queryKey: ['account', accountId], queryFn: () => api.get<Account & { operation_logs: Array<{ id: number; operation: string; status: string; message?: string; created_at: string }> }>(`/accounts/${accountId}`).then((r) => r.data) })
  const account = query.data
  return <Modal title={account ? `Account: ${account.identifier}` : 'Account details'} close={close}>{query.isLoading ? <Skeleton rows={4} /> : account ? <div className="activity-list"><div className="activity"><span className="activity-icon"><UsersRound size={15} /></span><div><b>{account.server?.name} · {account.protocol}</b><p>{account.allocated_ip ?? 'No static IP'} · expires {date(account.expires_at)}</p></div><span className={statusClass(account.status)}>{account.status}</span></div>{account.operation_logs.length ? account.operation_logs.map((log) => <div className="activity" key={log.id}><span className="activity-icon"><Activity size={15} /></span><div><b>{log.operation.replaceAll('_', ' ')}</b><p>{log.message ?? 'No additional message'} · {new Date(log.created_at).toLocaleString('id-ID')}</p></div><span className={statusClass(log.status)}>{log.status}</span></div>) : <Empty icon={<Activity />} title="No account events" description="Provisioning and lifecycle operations appear here." />}</div> : <p className="form-error">Account details could not be loaded.</p>}</Modal>
}

function AccountForm({ close, onSuccess }: { close: () => void; onSuccess: () => void }) {
  const [serverId, setServerId] = useState(''); const [protocol, setProtocol] = useState('wireguard'); const [error, setError] = useState('')
  const servers = useQuery({ queryKey: ['servers'], queryFn: () => api.get<Page<Server>>('/servers').then((r) => r.data) }); const plans = useQuery({ queryKey: ['plans'], queryFn: () => api.get<Page<Plan>>('/plans').then((r) => r.data) })
  const create = useMutation({ mutationFn: (event: React.FormEvent<HTMLFormElement>) => { event.preventDefault(); const form = new FormData(event.currentTarget); return api.post('/accounts', { vpn_server_id: Number(serverId), plan_id: form.get('plan_id') ? Number(form.get('plan_id')) : undefined, protocol, identifier: form.get('identifier'), allocated_ip: form.get('allocated_ip') || undefined, wireguard_public_key: form.get('wireguard_public_key') || undefined, credentials: form.get('client_password') ? { password: form.get('client_password') } : undefined }, { headers: { 'Idempotency-Key': crypto.randomUUID() } }) }, onSuccess, onError: (e) => setError(apiError(e)) })
  const selected = servers.data?.data.find((server) => String(server.id) === serverId)
  return <Modal title="Create VPN account" close={close}><form className="form-grid" onSubmit={(event) => create.mutate(event)}><label>Server<select value={serverId} onChange={(e) => { const next = servers.data?.data.find((server) => String(server.id) === e.target.value); setServerId(e.target.value); setProtocol(next?.capabilities.protocols?.[0] ?? '') }} required><option value="">Select a server</option>{servers.data?.data.map((server) => <option value={server.id} key={server.id}>{server.name}{server.is_demo ? ' (demo)' : ''}</option>)}</select></label><label>Protocol<select value={protocol} onChange={(e) => setProtocol(e.target.value)} disabled={!selected}>{(selected?.capabilities.protocols ?? []).map((item) => <option key={item} value={item}>{item}</option>)}</select></label><label>Username / peer name<input name="identifier" required pattern="[A-Za-z0-9._-]+" placeholder="client-001" /></label><label>Plan<select name="plan_id"><option value="">No plan / custom</option>{plans.data?.data.filter((plan) => plan.is_active && plan.allowed_protocols.includes(protocol)).map((plan) => <option value={plan.id} key={plan.id}>{plan.name} · {money(plan.price_cents, plan.currency)}</option>)}</select></label><label className="span-2">Static IP {selected?.adapter === 'linux-agent' && protocol === 'wireguard' ? '(required)' : '(optional)'}<input name="allocated_ip" required={selected?.adapter === 'linux-agent' && protocol === 'wireguard'} placeholder="10.20.0.10" /></label>{selected?.adapter === 'linux-agent' && protocol === 'wireguard' && <label className="span-2">WireGuard client public key<input name="wireguard_public_key" required pattern="[A-Za-z0-9+/]{43}=" placeholder="Base64 32-byte public key" /><small>Generate the private key on the client; only the public key is sent to the control plane.</small></label>}{selected?.adapter === 'mikrotik-routeros' && ['ppp', 'l2tp-ipsec', 'sstp', 'pptp', 'openvpn'].includes(protocol) && <label className="span-2">Client password<input name="client_password" type="password" required minLength={8} autoComplete="new-password" /><small>Used once to create the RouterOS PPP secret, then erased from the control plane.</small></label>}<p className="form-help span-2">Provisioning runs through the server driver and records the actual result. Client secrets are never displayed after creation.</p>{error && <p className="form-error span-2">{error}</p>}<div className="modal-actions span-2"><button type="button" className="button quiet" onClick={close}>Cancel</button><button className="button primary" disabled={create.isPending || !serverId || !protocol}>{create.isPending && <LoaderCircle className="spin" size={16} />}Queue provisioning</button></div></form></Modal>
}

function Plans() {
  const client = useQueryClient(); const [showCreate, setShowCreate] = useState(false)
  const plans = useQuery({ queryKey: ['plans'], queryFn: () => api.get<Page<Plan>>('/plans').then((r) => r.data) })
  return <><PageTitle eyebrow="COMMERCIAL CONTROLS" title="Plans & billing" description="Define eligibility, duration, protocol allowance, connection limits, and renewal records." action={<button className="button primary" onClick={() => setShowCreate(true)}><Plus size={17} />Create plan</button>} /><div className="plan-grid">{plans.isLoading ? <Skeleton rows={3} /> : plans.data?.data.map((plan) => <article className="plan-card" key={plan.id}><div><span className={statusClass(plan.is_active ? 'active' : 'disabled')}>{plan.is_active ? 'Active' : 'Disabled'}</span><h2>{plan.name}</h2><p className="price">{money(plan.price_cents, plan.currency)}<small> / {plan.duration_days} days</small></p></div><dl><div><dt>Protocols</dt><dd>{plan.allowed_protocols.join(', ')}</dd></div><div><dt>Connections</dt><dd>{plan.connection_limit} devices</dd></div><div><dt>Bandwidth</dt><dd>{plan.bandwidth_mbps ? `${plan.bandwidth_mbps} Mbps` : 'Not limited'}</dd></div></dl></article>)}</div>{!plans.isLoading && !plans.data?.data.length && <section className="panel"><Empty icon={<CircleDollarSign />} title="No commercial plans" description="Create a plan before assigning subscriptions." /></section>}{showCreate && <PlanForm close={() => setShowCreate(false)} onSuccess={() => { setShowCreate(false); client.invalidateQueries({ queryKey: ['plans'] }) }} />}</>
}

function PlanForm({ close, onSuccess }: { close: () => void; onSuccess: () => void }) {
  const [error, setError] = useState(''); const create = useMutation({ mutationFn: (event: React.FormEvent<HTMLFormElement>) => { event.preventDefault(); const form = new FormData(event.currentTarget); return api.post('/plans', { name: form.get('name'), price_cents: Number(form.get('price_cents')), currency: 'IDR', duration_days: Number(form.get('duration_days')), allowed_protocols: String(form.get('protocols')).split(',').map((p) => p.trim()), connection_limit: Number(form.get('connection_limit')), bandwidth_mbps: form.get('bandwidth_mbps') ? Number(form.get('bandwidth_mbps')) : undefined, is_active: true }) }, onSuccess, onError: (e) => setError(apiError(e)) })
  return <Modal title="Create commercial plan" close={close}><form className="form-grid" onSubmit={(event) => create.mutate(event)}><label>Plan name<input name="name" required placeholder="Business 30" /></label><label>Price (IDR)<input name="price_cents" type="number" min="0" required /></label><label>Duration (days)<input name="duration_days" type="number" min="1" required defaultValue="30" /></label><label>Connection limit<input name="connection_limit" type="number" min="1" defaultValue="1" required /></label><label className="span-2">Allowed protocols<input name="protocols" defaultValue="wireguard,openvpn" required /></label><label className="span-2">Bandwidth (Mbps)<input name="bandwidth_mbps" type="number" min="1" placeholder="Optional" /></label>{error && <p className="form-error span-2">{error}</p>}<div className="modal-actions span-2"><button type="button" className="button quiet" onClick={close}>Cancel</button><button className="button primary" disabled={create.isPending}>Create plan</button></div></form></Modal>
}

function PageTitle({ eyebrow, title, description, action }: { eyebrow: string; title: string; description: string; action?: React.ReactNode }) { return <div className="page-title"><div><p className="eyebrow">{eyebrow}</p><h1>{title}</h1><p>{description}</p></div>{action}</div> }
function Metric({ icon, label, value, detail, loading }: { icon: React.ReactNode; label: string; value: string | number | undefined; detail: string; loading: boolean }) { return <article className="metric"><span className="metric-icon">{icon}</span><p>{label}</p>{loading ? <div className="skeleton-line short" /> : <h2>{value ?? '—'}</h2>}<small>{detail}</small></article> }
function Readiness({ label, value, positive = false }: { label: string; value: string; positive?: boolean }) { return <div><span>{label}</span><b className={positive ? 'positive' : ''}>{value}</b></div> }
function Empty({ icon, title, description }: { icon: React.ReactNode; title: string; description: string }) { return <div className="empty"><span>{icon}</span><h3>{title}</h3><p>{description}</p></div> }
function Skeleton({ rows }: { rows: number }) { return <div className="skeleton">{Array.from({ length: rows }, (_, i) => <div className="skeleton-line" key={i} />)}</div> }
function Modal({ title, close, children }: { title: string; close: () => void; children: React.ReactNode }) { return <div className="modal-backdrop" role="presentation" onMouseDown={close}><section className="modal" role="dialog" aria-modal="true" aria-label={title} onMouseDown={(e) => e.stopPropagation()}><div className="modal-title"><h2>{title}</h2><button className="icon-button" onClick={close} aria-label="Close"><X size={18} /></button></div>{children}</section></div> }

export default App
