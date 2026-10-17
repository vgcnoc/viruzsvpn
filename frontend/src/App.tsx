import { zodResolver } from '@hookform/resolvers/zod'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Activity, ArrowLeft, Cable, ChevronRight, CircleDollarSign, Copy, Gauge, LayoutDashboard, LoaderCircle, LogOut, Menu, Pencil, Plus, Radio, Search, ServerCog, Settings2, ShieldCheck, Trash2, UsersRound, X } from 'lucide-react'
import { useEffect, useState } from 'react'
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
type AuthUser = { id: number; name: string; email: string; role: string }
type RegistrationResponse = { message: string; email: string; verification_required: boolean }
const money = (amount: number, currency: string) => new Intl.NumberFormat('id-ID', { style: 'currency', currency, maximumFractionDigits: 0 }).format(amount)
const date = (value?: string) => value ? new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium' }).format(new Date(value)) : '—'
const statusClass = (status: string) => `status ${['online', 'active', 'healthy', 'success'].includes(status) ? 'good' : ['offline', 'failed', 'revoked'].includes(status) ? 'bad' : status === 'demo' ? 'demo' : 'warn'}`

function Brand({ large = false }: { large?: boolean }) {
  return <div className={`brand ${large ? 'large' : ''}`}><span className="brand-mark"><ShieldCheck size={large ? 24 : 22} /></span><span>vir<span>VPN</span></span></div>
}

function App() {
  const [token, setToken] = useState(() => localStorage.getItem('vpn-platform-token'))
  const [mobileOpen, setMobileOpen] = useState(false)
  const session = useQuery({ queryKey: ['auth-me'], enabled: Boolean(token), retry: false, queryFn: () => api.get<AuthUser>('/auth/me').then((r) => r.data) })
  useEffect(() => { if (session.isError) { localStorage.removeItem('vpn-platform-token'); setToken(null) } }, [session.isError])
  const signOut = () => { void api.post('/auth/logout').catch(() => undefined); localStorage.removeItem('vpn-platform-token'); setToken(null) }

  if (!token) return <Login onAuthenticated={(value) => { localStorage.setItem('vpn-platform-token', value); setToken(value) }} />
  if (session.isLoading || session.isError || !session.data) return <SessionLoading />
  if (session.data.role !== 'administrator') return <CustomerPortal user={session.data} onSignOut={signOut} />

  return <div className="app-shell">
    <aside className={`sidebar ${mobileOpen ? 'open' : ''}`}>
      <Brand />
      <p className="workspace-label">OPERATIONS</p>
      <nav>{nav.map(({ to, label, icon: Icon }) => <NavLink end={to === '/'} to={to} key={to} onClick={() => setMobileOpen(false)}><Icon size={18} />{label}</NavLink>)}</nav>
      <div className="sidebar-footer"><div className="security-chip"><ShieldCheck size={16} /><span>Secure control plane<br /><small>Sanctum token session</small></span></div></div>
    </aside>
    <main>
      <header className="topbar"><button className="icon-button mobile-menu" onClick={() => setMobileOpen(!mobileOpen)} aria-label="Toggle navigation"><Menu size={20} /></button><div className="crumb"><span>Control plane</span><ChevronRight size={14} /><b>Network operations</b></div><div className="topbar-actions"><span className="connection"><i /> API connected</span><button className="avatar" title="Administrator">A</button><button className="icon-button" aria-label="Sign out" onClick={signOut}><LogOut size={18} /></button></div></header>
      <section className="content"><Routes>
        <Route path="/" element={<Overview />} /><Route path="/servers" element={<Servers />} /><Route path="/accounts" element={<Accounts />} /><Route path="/plans" element={<Plans />} /><Route path="/settings" element={<Settings />} /><Route path="*" element={<Navigate to="/" replace />} />
      </Routes></section>
    </main>
  </div>
}

const loginSchema = z.object({ email: z.string().email('Enter a valid email address.'), password: z.string().min(8, 'Password must contain at least 8 characters.') })
const registrationSchema = z.object({ name: z.string().trim().min(2, 'Enter at least 2 characters.').max(120), email: z.string().trim().email('Enter a valid email address.'), password: z.string().min(12, 'Use at least 12 characters.'), password_confirmation: z.string().min(1, 'Confirm your password.') }).refine((values) => values.password === values.password_confirmation, { path: ['password_confirmation'], message: 'Passwords do not match.' })
type LoginValues = z.infer<typeof loginSchema>
type RegistrationValues = z.infer<typeof registrationSchema>
type AuthResponse = { token: string; user: AuthUser }

function Login({ onAuthenticated }: { onAuthenticated: (token: string) => void }) {
  const [screen, setScreen] = useState<'welcome' | 'login' | 'register' | 'check-email'>('welcome')
  const [registeredEmail, setRegisteredEmail] = useState('')
  const [emailVerified, setEmailVerified] = useState(false)
  const { register: registerLogin, handleSubmit: submitLogin, formState: { errors: loginErrors } } = useForm<LoginValues>({ resolver: zodResolver(loginSchema), defaultValues: { email: '', password: '' } })
  const { register: registerAccount, handleSubmit: submitAccount, formState: { errors: registrationErrors } } = useForm<RegistrationValues>({ resolver: zodResolver(registrationSchema), defaultValues: { name: '', email: '', password: '', password_confirmation: '' } })
  const signIn = useMutation({ mutationFn: (values: LoginValues) => api.post<AuthResponse>('/auth/login', values).then((r) => r.data), onSuccess: ({ token }) => onAuthenticated(token) })
  const createAccount = useMutation({ mutationFn: (values: RegistrationValues) => api.post<RegistrationResponse>('/auth/register', values).then((r) => r.data), onSuccess: (data) => { setRegisteredEmail(data.email); setScreen('check-email') } })
  const resendVerification = useMutation({ mutationFn: (email: string) => api.post<{ message: string }>('/auth/email/verification-notification', { email }).then((r) => r.data) })
  useEffect(() => { if (new URLSearchParams(window.location.search).get('email_verified') === '1') { setEmailVerified(true); setScreen('login'); window.history.replaceState({}, document.title, window.location.pathname) } }, [])
  const goBack = () => { signIn.reset(); createAccount.reset(); resendVerification.reset(); setScreen('welcome') }

  return <div className="login-page"><div className="auth-shell">
    <aside className="auth-showcase">
      <Brand large />
      <div className="auth-showcase-copy"><p className="eyebrow">PRIVATE VPN, MADE SIMPLE</p><h1>Secure access,<br /><span>on your terms.</span></h1><p>Create one account to manage the VPN services and connections you choose, with security built into every step.</p></div>
      <div className="auth-network-visual" aria-hidden="true"><div className="visual-label"><span />VIRVPN NETWORK</div><div className="visual-grid"><i className="visual-line line-one" /><i className="visual-line line-two" /><i className="visual-line line-three" /><b className="visual-node node-core"><ShieldCheck size={18} /></b><b className="visual-node node-one" /><b className="visual-node node-two" /><b className="visual-node node-three" /><div className="visual-card"><small>ACCOUNT PROTECTION</small><strong>Verified access before service activation.</strong></div></div></div>
      <div className="auth-capabilities"><span><b>01</b>Protected access</span><span><b>02</b>Central control</span><span><b>03</b>Clear operations</span></div>
      <p className="auth-showcase-footer">A secure starting point for your VPN journey.</p>
    </aside>
    <main className={`login-card auth-card ${screen === 'welcome' ? 'auth-choice' : ''}`}>
      <div className="auth-card-top"><span className="auth-card-dot" /><span>SECURE SESSION</span></div>
      {screen !== 'welcome' && screen !== 'check-email' && <button className="auth-back" type="button" onClick={goBack}><ArrowLeft size={16} />Back</button>}
      {screen === 'welcome' && <><p className="eyebrow">YOUR VIRVPN ACCOUNT</p><h1>VPN access starts here.</h1><p className="muted">Sign in to your account or register first. New accounts are protected with email verification.</p><div className="auth-choice-actions"><button className="button primary wide" type="button" onClick={() => setScreen('login')}>Sign in to virVPN</button><button className="button quiet wide" type="button" onClick={() => setScreen('register')}>Register</button></div><p className="auth-footnote"><ShieldCheck size={14} />Your credentials are never shown or stored in this browser.</p></>}
    {screen === 'login' && <><p className="eyebrow">WELCOME BACK</p><h1>Sign in</h1><p className="muted">Use your verified virVPN account to continue.</p>{emailVerified && <p className="auth-success">Email verified. You can now sign in.</p>}<form className="auth-form" onSubmit={submitLogin((values) => signIn.mutate(values))}>
      <label>Email address<input {...registerLogin('email')} autoComplete="email" /></label>{loginErrors.email && <small className="field-error">{loginErrors.email.message}</small>}
      <label>Password<input {...registerLogin('password')} type="password" autoComplete="current-password" /></label>{loginErrors.password && <small className="field-error">{loginErrors.password.message}</small>}
      {signIn.isError && <p className="form-error">{apiError(signIn.error)}</p>}<button className="button primary wide" disabled={signIn.isPending}>{signIn.isPending && <LoaderCircle className="spin" size={16} />}Sign in</button>
    </form><p className="auth-switch">New to virVPN? <button type="button" onClick={() => setScreen('register')}>Register</button></p></>}
    {screen === 'register' && <><p className="eyebrow">CREATE YOUR ACCOUNT</p><h1>Register for virVPN</h1><p className="muted">We will send a verification link to your email before you can sign in.</p><form className="auth-form" onSubmit={submitAccount((values) => createAccount.mutate(values))}>
      <label>Full name<input {...registerAccount('name')} autoComplete="name" /></label>{registrationErrors.name && <small className="field-error">{registrationErrors.name.message}</small>}
      <label>Email address<input {...registerAccount('email')} autoComplete="email" /></label>{registrationErrors.email && <small className="field-error">{registrationErrors.email.message}</small>}
      <label>Password<input {...registerAccount('password')} type="password" autoComplete="new-password" /></label>{registrationErrors.password && <small className="field-error">{registrationErrors.password.message}</small>}
      <label>Confirm password<input {...registerAccount('password_confirmation')} type="password" autoComplete="new-password" /></label>{registrationErrors.password_confirmation && <small className="field-error">{registrationErrors.password_confirmation.message}</small>}
      {createAccount.isError && <p className="form-error">{apiError(createAccount.error)}</p>}<button className="button primary wide" disabled={createAccount.isPending}>{createAccount.isPending && <LoaderCircle className="spin" size={16} />}Register account</button>
    </form><p className="auth-switch">Already have an account? <button type="button" onClick={() => setScreen('login')}>Sign in</button></p></>}
    {screen === 'check-email' && <><p className="eyebrow">VERIFY YOUR EMAIL</p><h1>Check your inbox.</h1><p className="muted">We sent a verification link to <b>{registeredEmail}</b>. Open the link to activate your virVPN account.</p>{resendVerification.isSuccess && <p className="auth-success">A new verification link has been sent.</p>}<div className="auth-choice-actions"><button className="button primary wide" type="button" disabled={resendVerification.isPending || !registeredEmail} onClick={() => resendVerification.mutate(registeredEmail)}>{resendVerification.isPending && <LoaderCircle className="spin" size={16} />}Resend verification email</button><button className="button quiet wide" type="button" onClick={() => setScreen('login')}>Return to sign in</button></div><p className="auth-footnote"><ShieldCheck size={14} />For your security, unverified accounts cannot sign in.</p></>}
    </main>
  </div></div>
}

function SessionLoading() {
  return <div className="login-page"><div className="session-loading"><LoaderCircle className="spin" size={22} /><span>Restoring your secure session…</span></div></div>
}

function CustomerPortal({ user, onSignOut }: { user: AuthUser; onSignOut: () => void }) {
  return <div className="customer-shell"><header className="customer-header"><Brand /><div><span>{user.email}</span><button className="icon-button" aria-label="Sign out" onClick={onSignOut}><LogOut size={18} /></button></div></header><main className="customer-content"><p className="eyebrow">CUSTOMER PORTAL</p><h1>Welcome, {user.name}.</h1><p className="customer-intro">Your email is verified and your virVPN account is ready. Your VPN services and account activity will appear here as they are activated.</p><section className="customer-ready"><div><span className="brand-mark"><ShieldCheck size={20} /></span><div><p>Account status</p><h2>Email verified</h2></div></div><span className="status good">ready</span></section><section className="customer-empty"><ShieldCheck size={22} /><h2>No VPN services yet</h2><p>When a VPN plan or connection is added to your account, you will be able to view and manage it from this portal.</p></section></main></div>
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

const profileSchema = z.object({ name: z.string().trim().min(2, 'Enter at least 2 characters.').max(120), email: z.string().trim().email('Enter a valid email address.') })
const passwordSchema = z.object({ current_password: z.string().min(1, 'Enter your current password.'), password: z.string().min(12, 'Use at least 12 characters.'), password_confirmation: z.string().min(1, 'Confirm the new password.') }).refine((values) => values.password === values.password_confirmation, { path: ['password_confirmation'], message: 'Passwords do not match.' })
type ProfileValues = z.infer<typeof profileSchema>
type PasswordValues = z.infer<typeof passwordSchema>

function Settings() {
  const client = useQueryClient(); const [message, setMessage] = useState('')
  const profile = useQuery({ queryKey: ['auth-me'], queryFn: () => api.get<AuthUser>('/auth/me').then((r) => r.data) })
  const { register: registerProfile, handleSubmit: submitProfile, reset: resetProfile, formState: { errors: profileErrors } } = useForm<ProfileValues>({ resolver: zodResolver(profileSchema), defaultValues: { name: '', email: '' } })
  const { register: registerPassword, handleSubmit: submitPassword, reset: resetPassword, formState: { errors: passwordErrors } } = useForm<PasswordValues>({ resolver: zodResolver(passwordSchema), defaultValues: { current_password: '', password: '', password_confirmation: '' } })
  useEffect(() => { if (profile.data) resetProfile({ name: profile.data.name, email: profile.data.email }) }, [profile.data, resetProfile])
  const saveProfile = useMutation({ mutationFn: (values: ProfileValues) => api.patch<AuthUser>('/auth/profile', values).then((r) => r.data), onSuccess: (user) => { client.setQueryData(['auth-me'], user); resetProfile({ name: user.name, email: user.email }); setMessage('Profile updated.') } })
  const changePassword = useMutation({ mutationFn: (values: PasswordValues) => api.post<{ message: string }>('/auth/password', values).then((r) => r.data), onSuccess: (data) => { resetPassword(); setMessage(data.message) } })
  return <><PageTitle eyebrow="CONTROL PLANE" title="Settings" description="Manage your administrator profile, credentials, and operational safeguards." />
    {message && <div className="notice"><ShieldCheck size={16} />{message}</div>}
    <div className="two-column settings-panels"><section className="panel"><div className="panel-heading"><div><p className="eyebrow">ACCOUNT</p><h2>Edit profile</h2></div><Pencil size={20} /></div>{profile.isLoading ? <Skeleton rows={3} /> : <form className="form-grid panel-form" onSubmit={submitProfile((values) => saveProfile.mutate(values))}><label className="span-2">Full name<input autoComplete="name" {...registerProfile('name')} />{profileErrors.name && <small className="field-error">{profileErrors.name.message}</small>}</label><label className="span-2">Email address<input autoComplete="email" {...registerProfile('email')} />{profileErrors.email && <small className="field-error">{profileErrors.email.message}</small>}</label><div className="account-role span-2"><span>Role</span><b>{profile.data?.role ?? 'administrator'}</b></div>{saveProfile.isError && <p className="form-error span-2">{apiError(saveProfile.error)}</p>}<div className="modal-actions span-2"><button className="button primary" disabled={saveProfile.isPending}>{saveProfile.isPending && <LoaderCircle className="spin" size={16} />}Save profile</button></div></form>}<p className="panel-footnote">Changes apply to the administrator account currently signed in.</p></section>
      <section className="panel"><div className="panel-heading"><div><p className="eyebrow">SECURITY</p><h2>Change password</h2></div><ShieldCheck size={20} /></div><form className="form-grid panel-form" onSubmit={submitPassword((values) => changePassword.mutate(values))}><label className="span-2">Current password<input type="password" autoComplete="current-password" {...registerPassword('current_password')} />{passwordErrors.current_password && <small className="field-error">{passwordErrors.current_password.message}</small>}</label><label>New password<input type="password" autoComplete="new-password" {...registerPassword('password')} />{passwordErrors.password && <small className="field-error">{passwordErrors.password.message}</small>}</label><label>Confirm password<input type="password" autoComplete="new-password" {...registerPassword('password_confirmation')} />{passwordErrors.password_confirmation && <small className="field-error">{passwordErrors.password_confirmation.message}</small>}</label>{changePassword.isError && <p className="form-error span-2">{apiError(changePassword.error)}</p>}<div className="modal-actions span-2"><button className="button primary" disabled={changePassword.isPending}>{changePassword.isPending && <LoaderCircle className="spin" size={16} />}Update password</button></div></form><p className="panel-footnote">Use at least 12 characters. Other active browser sessions will be signed out.</p></section></div>
    <div className="two-column settings-panels"><section className="panel"><div className="panel-heading"><div><p className="eyebrow">CONNECTIVITY</p><h2>Application endpoint</h2></div><Cable size={20} /></div><div className="readiness"><Readiness label="API endpoint" value={String(api.defaults.baseURL ?? 'http://127.0.0.1:8000/api')} positive /><Readiness label="Authentication" value="Sanctum bearer token" positive /><Readiness label="Session storage" value="This browser only" positive /></div><p className="panel-footnote">Change deployment endpoints and secrets through the server environment, never through a browser form.</p></section>
      <section className="panel"><div className="panel-heading"><div><p className="eyebrow">ROUTEROS</p><h2>Registration checklist</h2></div><ShieldCheck size={20} /></div><div className="readiness"><Readiness label="API service" value="API-SSL preferred" positive /><Readiness label="Allowed CIDR" value="virVPN source IP only" positive /><Readiness label="Certificate" value="Required for verified TLS" /></div><p className="panel-footnote">Use the server’s public address as the endpoint. The allowed CIDR must be the source address of the machine running virVPN—not the RouterOS address.</p></section></div>
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
const routerOsObjectName = /^[A-Za-z0-9._-]{1,64}$/
const serverSchema = z.object({ name: z.string().min(3), adapter: z.enum(['linux-agent', 'mikrotik-routeros', 'demo']), hostname: z.string().min(1), port: z.coerce.number().int().min(1).max(65535), location: z.string().optional(), protocols: z.string().min(1), username: z.string().optional(), secret: z.string().optional(), routeros_api_ssl: z.boolean(), routeros_allowed_cidr: z.string().optional().refine((value) => !value || ipv4Cidr.test(value), 'Use one IPv4 CIDR, for example 203.0.113.10/32.'), routeros_certificate: z.string().optional().refine((value) => !value || routerOsObjectName.test(value), 'Use a RouterOS certificate name (letters, numbers, dot, dash, underscore).'), routeros_ppp_profile: z.string().min(1).max(64).refine((value) => routerOsObjectName.test(value), 'Use a RouterOS object name.'), routeros_vpn_subnet: z.string().refine((value) => ipv4Cidr.test(value), 'Use an IPv4 subnet, for example 10.250.20.0/24.'), routeros_vpn_gateway: z.string().ip({ version: 'v4' }), routeros_vpn_pool: z.string().regex(/^(?:\d{1,3}\.){3}\d{1,3}-(?:\d{1,3}\.){3}\d{1,3}$/, 'Use an IP range, for example 10.250.20.10-10.250.20.254.'), routeros_wan_interface: z.string().min(1).max(64).refine((value) => routerOsObjectName.test(value), 'Use a RouterOS interface name.'), routeros_l2tp_secret: z.string().optional(), tls_verify: z.boolean() })
type ServerValues = z.infer<typeof serverSchema>

const routerOsQuote = (value: string) => `"${value.replace(/\\/g, '\\\\').replace(/"/g, '\\"').replace(/\$/g, '\\$').replace(/[\r\n]/g, '')}"`
function buildRouterOsBootstrap(value: ServerValues) {
  const apiService = value.routeros_api_ssl ? 'api-ssl' : 'api'
  const protocols = value.protocols.split(',').map((item) => item.trim().toLowerCase()).filter(Boolean)
  const has = (protocol: string) => protocols.includes(protocol)
  const usesCertificate = value.routeros_api_ssl || has('sstp') || has('openvpn')
  const prefix = value.routeros_vpn_subnet.split('/')[1] ?? '24'
  const apiCredentialsPresent = Boolean(value.username?.trim() && value.secret)
  const certificateCheck = usesCertificate ? `:put "virVPN: checking certificate..."
:if ([:len [/certificate find where name=$certificateName]] = 0) do={
    :put ("virVPN ERROR: certificate " . $certificateName . " was not found. No configuration was changed.")
    :error ("virVPN: create or import the certificate before running this script.")
}
` : ''
  const certificateSetting = value.routeros_api_ssl ? ' certificate=$certificateName' : ''
  const allowInput = (protocol: 'tcp' | 'udp' | 'ipsec-esp', port: number | null, label: string) => `:if ([:len [/ip firewall filter find where comment="virVPN allow ${label}"]] = 0) do={
    /ip firewall filter add chain=input action=accept protocol=${protocol}${port ? ` dst-port=${port}` : ''} comment="virVPN allow ${label}"
}`
  const apiSection = apiCredentialsPresent ? `:if ([:len [/user group find where name="vpnops-api"]] = 0) do={
    /user group add name="vpnops-api" policy=read,write,api
} else={
    /user group set [/user group find where name="vpnops-api"] policy=read,write,api
}

:if ([:len [/user find where name=$vpnopsUser]] = 0) do={
    /user add name=$vpnopsUser password=$vpnopsPassword group="vpnops-api" comment="virVPN control plane"
} else={
    /user set [/user find where name=$vpnopsUser] password=$vpnopsPassword group="vpnops-api" comment="virVPN control plane"
}

/ip service set ${apiService} disabled=no port=${value.port} address=$controlPlaneCidr${certificateSetting}
:put "virVPN OK: ${apiService} is enabled on TCP ${value.port}."` : `# API credentials were not entered, so the existing virVPN API user and API service are unchanged.
# This is expected when generating a service script from Edit server.`
  const profileSection = `:if ([:len [/ip pool find where name="virvpn-pool"]] = 0) do={
    /ip pool add name="virvpn-pool" ranges=${routerOsQuote(value.routeros_vpn_pool)} comment="virVPN client addresses"
} else={
    :put "virVPN: IP pool already exists; it was left unchanged."
}

:if ([:len [/ppp profile find where name=${routerOsQuote(value.routeros_ppp_profile)}]] = 0) do={
    /ppp profile add name=${routerOsQuote(value.routeros_ppp_profile)} local-address=${routerOsQuote(value.routeros_vpn_gateway)} remote-address="virvpn-pool" dns-server=1.1.1.1,1.0.0.1 use-encryption=required change-tcp-mss=default comment="virVPN managed PPP profile"
} else={
    :put "virVPN: PPP profile already exists; it was left unchanged."
}

:if ([:len [/ip firewall nat find where comment="virVPN NAT"]] = 0) do={
    /ip firewall nat add chain=srcnat action=masquerade src-address=${routerOsQuote(value.routeros_vpn_subnet)} out-interface=${routerOsQuote(value.routeros_wan_interface)} comment="virVPN NAT"
}

:if ([:len [/ip firewall filter find where comment="virVPN forward outbound"]] = 0) do={
    /ip firewall filter add chain=forward action=accept src-address=${routerOsQuote(value.routeros_vpn_subnet)} out-interface=${routerOsQuote(value.routeros_wan_interface)} comment="virVPN forward outbound"
}
:if ([:len [/ip firewall filter find where comment="virVPN forward return"]] = 0) do={
    /ip firewall filter add chain=forward action=accept dst-address=${routerOsQuote(value.routeros_vpn_subnet)} connection-state=established,related comment="virVPN forward return"
}`
  const wireGuardSection = has('wireguard') ? `

:if ([:len [/interface wireguard find where name="virvpn-wg"]] = 0) do={
    /interface wireguard add name="virvpn-wg" listen-port=51820 mtu=1420 comment="virVPN managed WireGuard"
}
:if ([:len [/ip address find where interface="virvpn-wg" and address=$vpnGatewayWithPrefix]] = 0) do={
    /ip address add address=$vpnGatewayWithPrefix interface="virvpn-wg" comment="virVPN WireGuard gateway"
}
${allowInput('udp', 51820, 'WireGuard')}` : ''
  const sstpSection = has('sstp') ? `

${allowInput('tcp', 443, 'SSTP')}
:if ([/interface sstp-server server get [find] disabled] = true) do={
    /interface sstp-server server set [find] enabled=yes port=443 certificate=$certificateName default-profile=$vpnProfile authentication=mschap2 pfs=yes
} else={
    :put "virVPN: SSTP is already active; its global settings were left unchanged."
}` : ''
  const l2tpSection = has('l2tp-ipsec') ? `

${allowInput('udp', 1701, 'L2TP')}
${allowInput('udp', 500, 'IPsec IKE')}
${allowInput('udp', 4500, 'IPsec NAT-T')}
${allowInput('ipsec-esp', null, 'IPsec ESP')}
:if ([/interface l2tp-server server get [find] disabled] = true) do={
    /interface l2tp-server server set [find] enabled=yes use-ipsec=required ipsec-secret=$l2tpSecret default-profile=$vpnProfile authentication=mschap2
} else={
    :put "virVPN: L2TP is already active; its global settings were left unchanged."
}` : ''
  const openVpnSection = has('openvpn') ? `

${allowInput('tcp', 1194, 'OpenVPN')}
:if ([/interface ovpn-server server get [find] disabled] = true) do={
    /interface ovpn-server server set [find] enabled=yes port=1194 mode=ip certificate=$certificateName default-profile=$vpnProfile require-client-certificate=no
} else={
    :put "virVPN: OpenVPN is already active; its global settings were left unchanged."
}` : ''
  const pptpSection = has('pptp') ? `

${allowInput('tcp', 1723, 'PPTP')}
:if ([/interface pptp-server server get [find] disabled] = true) do={
    /interface pptp-server server set [find] enabled=yes default-profile=$vpnProfile authentication=mschap2
} else={
    :put "virVPN: PPTP is already active; its global settings were left unchanged."
}` : ''
  return `# virVPN RouterOS deployment script — paste only into the intended CHR.
# Selected protocols: ${protocols.join(', ') || 'none'}
# Safe by design: it creates only virVPN-named objects and does not reset existing customers.
# Existing enabled SSTP/L2TP/OpenVPN/PPTP service settings are deliberately not overwritten.
:local vpnopsUser ${routerOsQuote(value.username?.trim() ?? '')}
:local vpnopsPassword ${routerOsQuote(value.secret ?? '')}
:local controlPlaneCidr ${routerOsQuote(value.routeros_allowed_cidr ?? '')}
:local vpnProfile ${routerOsQuote(value.routeros_ppp_profile)}
:local vpnPool "virvpn-pool"
:local vpnSubnet ${routerOsQuote(value.routeros_vpn_subnet)}
:local vpnGateway ${routerOsQuote(value.routeros_vpn_gateway)}
:local vpnGatewayWithPrefix ${routerOsQuote(`${value.routeros_vpn_gateway}/${prefix}`)}
:local vpnRange ${routerOsQuote(value.routeros_vpn_pool)}
:local wanInterface ${routerOsQuote(value.routeros_wan_interface)}
${usesCertificate ? `:local certificateName ${routerOsQuote(value.routeros_certificate ?? '')}
` : ''}${has('l2tp-ipsec') ? `:local l2tpSecret ${routerOsQuote(value.routeros_l2tp_secret ?? '')}
` : ''}
${certificateCheck}
${apiSection}

${profileSection}${wireGuardSection}${sstpSection}${l2tpSection}${openVpnSection}${pptpSection}

:put "virVPN OK: selected VPN services and the dedicated PPP profile are ready."
# WireGuard creates only the server interface here. Add client peers separately before issuing WireGuard configs.
# OpenVPN clients also require their own client configuration/certificates.`
}

function ServerForm({ close, onSuccess, server }: { close: () => void; onSuccess: () => void; server?: Server }) {
  const [bootstrapScript, setBootstrapScript] = useState<string | null>(null)
  const [copyMessage, setCopyMessage] = useState('')
  const { register, handleSubmit, watch, setError, formState: { errors } } = useForm<ServerValues>({
    resolver: zodResolver(serverSchema),
    defaultValues: {
      name: server?.name, adapter: (server?.adapter as ServerValues['adapter']) ?? 'linux-agent', hostname: server?.hostname,
      port: server?.port ?? 443, location: server?.location, protocols: server?.capabilities.protocols?.join(',') ?? 'wireguard',
      username: '', secret: '', routeros_api_ssl: true, routeros_allowed_cidr: '', routeros_certificate: '',
      routeros_ppp_profile: 'virvpn-standard', routeros_vpn_subnet: '10.250.20.0/24', routeros_vpn_gateway: '10.250.20.1',
      routeros_vpn_pool: '10.250.20.10-10.250.20.254', routeros_wan_interface: '', routeros_l2tp_secret: '',
      tls_verify: server?.tls_verify ?? true,
    },
  })
  const adapter = watch('adapter')
  const routerosApiSsl = watch('routeros_api_ssl')
  const secret = watch('secret')
  const protocols = watch('protocols').split(',').map((item) => item.trim().toLowerCase()).filter(Boolean)
  const needsServiceCertificate = protocols.includes('sstp') || protocols.includes('openvpn')
  const needsL2tpSecret = protocols.includes('l2tp-ipsec')
  const isNewRouterOsServer = !server || server.adapter !== 'mikrotik-routeros'
  const requiresRouterOsBootstrap = adapter === 'mikrotik-routeros' && (isNewRouterOsServer || Boolean(secret))
  const save = useMutation({
    mutationFn: (value: ServerValues) => {
      let credentials: Record<string, unknown> | undefined
      if (value.adapter === 'linux-agent' && value.secret) credentials = { agent_shared_secret: value.secret }
      if (value.adapter === 'mikrotik-routeros') {
        credentials = {
          ...(isNewRouterOsServer || value.secret ? {
            routeros_username: value.username, routeros_password: value.secret, routeros_api_ssl: value.routeros_api_ssl,
            routeros_allowed_cidr: value.routeros_allowed_cidr, routeros_certificate: value.routeros_certificate,
          } : {}),
          routeros_ppp_profile: value.routeros_ppp_profile,
        }
      }
      const payload = {
        name: value.name, adapter: value.adapter, hostname: value.hostname, port: value.port, location: value.location,
        tls_verify: value.tls_verify, is_demo: value.adapter === 'demo',
        capabilities: { protocols: value.protocols.split(',').map((item) => item.trim()).filter(Boolean) }, credentials,
      }
      return server ? api.put(`/servers/${server.id}`, payload) : api.post('/servers', payload)
    },
    onSuccess: (_, value) => value.adapter === 'mikrotik-routeros' ? setBootstrapScript(buildRouterOsBootstrap(value)) : onSuccess(),
  })
  const submit = (value: ServerValues) => {
    if (requiresRouterOsBootstrap && !value.routeros_allowed_cidr) { setError('routeros_allowed_cidr', { message: 'Required to restrict RouterOS API access.' }); return }
    if (requiresRouterOsBootstrap && !value.username?.trim()) { setError('username', { message: 'Enter the RouterOS API username with its password.' }); return }
    if ((requiresRouterOsBootstrap && value.routeros_api_ssl || needsServiceCertificate) && !value.routeros_certificate) { setError('routeros_certificate', { message: 'Required for API-SSL, SSTP, or OpenVPN.' }); return }
    if (needsL2tpSecret && (value.routeros_l2tp_secret?.length ?? 0) < 16) { setError('routeros_l2tp_secret', { message: 'Use a unique IPsec secret with at least 16 characters.' }); return }
    save.mutate(value)
  }
  const finishBootstrap = () => { setBootstrapScript(null); onSuccess() }
  const copyBootstrap = async () => {
    if (!bootstrapScript) return
    try { await navigator.clipboard.writeText(bootstrapScript); setCopyMessage('Script copied. Paste it into the RouterOS terminal.') }
    catch { setCopyMessage('Clipboard access was blocked. Select the script and copy it manually.') }
  }
  if (bootstrapScript) return <Modal title="RouterOS deployment script" close={finishBootstrap}>
    <p className="form-help">The script creates the selected virVPN services, profile, pool, firewall rules, NAT rule, and—when credentials were entered—the restricted API user. It can contain passwords and the L2TP/IPsec secret; keep it out of chat logs.</p>
    <p className="script-warning">Review the WAN interface and client subnet first. Existing active VPN service settings are left unchanged, so current customers are not interrupted.</p>
    <textarea className="script-output" readOnly aria-label="RouterOS deployment script" value={bootstrapScript} rows={28} />
    <p className="form-help">New PPP accounts created by virVPN will use <span className="mono">{watch('routeros_ppp_profile')}</span>. WireGuard creates the server interface only; client peers are added separately.</p>
    {copyMessage && <p className="form-help">{copyMessage}</p>}
    <div className="modal-actions"><button type="button" className="button quiet" onClick={finishBootstrap}>Done</button><button type="button" className="button primary" onClick={copyBootstrap}><Copy size={16} />Copy script</button></div>
  </Modal>
  return <Modal title={server ? 'Edit server' : 'Register server'} close={close}>
    <form className="form-grid" onSubmit={handleSubmit(submit)}>
      <label>Name<input {...register('name')} placeholder="Singapore Edge 01" /></label>
      <label>Adapter<select {...register('adapter')}><option value="linux-agent">Linux agent (HTTPS)</option><option value="mikrotik-routeros">MikroTik RouterOS</option><option value="demo">Demo (local only)</option></select></label>
      <label>Hostname / IP<input {...register('hostname')} placeholder="vpn-edge.example.net" /></label>
      <label>Port<input type="number" {...register('port')} />{adapter === 'mikrotik-routeros' && <small>API-SSL normally uses 8729; API uses 8728.</small>}</label>
      <label className="span-2">Location<input {...register('location')} placeholder="Singapore, SG" /></label>
      <label className="span-2">Supported protocols<input {...register('protocols')} placeholder="wireguard,openvpn" /><small>Comma-separated: wireguard, openvpn, l2tp-ipsec, sstp, ppp, pptp.</small></label>
      {adapter === 'linux-agent' && <label className="span-2">Linux agent shared secret<input type="password" {...register('secret')} placeholder={server ? 'Leave blank to keep the stored secret' : 'Minimum 32 characters; stored encrypted'} /></label>}
      {adapter === 'mikrotik-routeros' && <>
        <label>RouterOS API username<input autoComplete="username" {...register('username')} placeholder={server ? 'Enter again only when changing credentials' : 'virvpn-api'} />{errors.username && <small className="field-error">{errors.username.message}</small>}</label>
        <label>RouterOS API password<input type="password" autoComplete="new-password" {...register('secret')} placeholder={server ? 'Leave blank to keep stored credentials' : 'Stored encrypted; never shown again'} /></label>
        <label className="check span-2"><input type="checkbox" {...register('routeros_api_ssl')} />Use RouterOS API-SSL (recommended)</label>
        <label className="span-2">Allowed control-plane CIDR<input {...register('routeros_allowed_cidr')} placeholder="203.0.113.10/32" required={requiresRouterOsBootstrap} /><small>Public IP of the virVPN server only—not the RouterOS address.</small></label>
        {errors.routeros_allowed_cidr && <small className="field-error span-2">{errors.routeros_allowed_cidr.message}</small>}
        {(routerosApiSsl || needsServiceCertificate) && <><label className="span-2">RouterOS API-SSL / VPN certificate name<input {...register('routeros_certificate')} placeholder="sstp-template" /><small>Required for API-SSL, SSTP, and OpenVPN. The name must exactly match RouterOS.</small></label>{errors.routeros_certificate && <small className="field-error span-2">{errors.routeros_certificate.message}</small>}</>}
        <section className="routeros-setup span-2">
          <div><p className="eyebrow">PASTE-READY VPN SETUP</p><h3>Dedicated virVPN service profile</h3><p>These values build an idempotent script. It creates only named virVPN objects and leaves existing active service settings untouched.</p></div>
          <div className="routeros-setup-grid">
            <label>PPP profile<input {...register('routeros_ppp_profile')} /><small>Assigned to new virVPN PPP accounts.</small></label>
            <label>WAN interface<input {...register('routeros_wan_interface')} placeholder="ether1" /><small>Internet uplink used for NAT; do not use a LAN interface.</small></label>
            <label>VPN client subnet<input {...register('routeros_vpn_subnet')} /></label>
            <label>VPN gateway<input {...register('routeros_vpn_gateway')} /></label>
            <label className="span-2">VPN client pool<input {...register('routeros_vpn_pool')} /></label>
            {needsL2tpSecret && <label className="span-2">L2TP/IPsec pre-shared secret<input type="password" autoComplete="new-password" {...register('routeros_l2tp_secret')} /><small>Shown only in the generated script; use a unique secret with at least 16 characters.</small>{errors.routeros_l2tp_secret && <small className="field-error">{errors.routeros_l2tp_secret.message}</small>}</label>}
          </div>
          {protocols.includes('pptp') && <p className="script-warning">PPTP is legacy and weak. Select it only for an unavoidable legacy client.</p>}
        </section>
      </>}
      <label className="check span-2"><input type="checkbox" {...register('tls_verify')} />Verify the endpoint TLS certificate</label>
      {Object.keys(errors).length > 0 && <p className="form-error span-2">Check the required fields and network ranges.</p>}
      {save.isError && <p className="form-error span-2">{apiError(save.error)}</p>}
      <div className="modal-actions span-2"><button type="button" className="button quiet" onClick={close}>Cancel</button><button className="button primary" disabled={save.isPending}>{save.isPending && <LoaderCircle className="spin" size={16} />}{adapter === 'mikrotik-routeros' ? 'Save & generate script' : server ? 'Save changes' : 'Register server'}</button></div>
    </form>
  </Modal>
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
