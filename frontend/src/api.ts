import axios from 'axios'

const configuredApiUrl = import.meta.env.VITE_API_URL
const loopbackUrl = /^https?:\/\/(?:127\.0\.0\.1|localhost)(?::\d+)?(?:\/|$)/i
const apiBaseUrl = import.meta.env.PROD && configuredApiUrl && loopbackUrl.test(configuredApiUrl)
  ? '/api'
  : configuredApiUrl ?? (import.meta.env.DEV ? 'http://127.0.0.1:8001/api' : '/api')

export const api = axios.create({
  baseURL: apiBaseUrl,
  headers: { Accept: 'application/json' },
})

api.interceptors.request.use((config) => {
  const token = localStorage.getItem('vpn-platform-token')
  if (token) config.headers.Authorization = `Bearer ${token}`
  return config
})

export type Server = {
  id: number; name: string; adapter: string; hostname: string; port: number; location?: string
  status: string; vpn_status: string; is_demo: boolean; tls_verify: boolean; capabilities: { protocols?: string[] }; accounts_count?: number
  last_checked_at?: string; last_latency_ms?: number
}
export type ProvisioningOptions = { server_id: number; server_status: string; is_demo: boolean; protocols: string[] }
export type Plan = { id: number; name: string; description?: string; price_cents: number; currency: string; duration_days: number; allowed_protocols: string[]; allowed_server_ids?: number[]; connection_limit: number; bandwidth_mbps?: number; data_quota_bytes?: number; is_active: boolean }
export type Account = { id: number; uuid: string; identifier: string; protocol: string; status: string; expires_at?: string; allocated_ip?: string; server: Server; plan?: Plan; device_limit?: number; user_id?: number; l2tp_psk?: string; client_device_type?: string | null; remote_public_port?: number | null; remote_target_port?: number | null; remote_service?: string | null; remote_device_ip?: string | null; remote_allowed_cidr?: string | null; remote_wan_interface?: string | null; remote_gateway_ip?: string | null; remote_rule_status?: string | null }
export type CustomerServer = { id: number; name: string; location?: string; protocols: string[] }
export type Catalog = { plans: Plan[]; servers: CustomerServer[] }
export type BillingSettings = { bank_name?: string; account_name?: string; account_number?: string; instructions?: string; payment_enabled: boolean }
export type VpnOrder = { id: number; invoice_number: string; user_id: number; plan_id: number; vpn_server_id?: number; vpn_account_id?: number; type: 'new' | 'renewal'; protocol?: string; identifier?: string; client_device_type?: string | null; remote_target_port?: number | null; remote_service?: string | null; remote_device_ip?: string | null; status: string; amount_cents: number; currency: string; plan_name: string; duration_days: number; payment_reference?: string; payment_note?: string; admin_note?: string; payment_bank_name: string; payment_account_name: string; payment_account_number: string; payment_instructions?: string; payment_due_at: string; paid_at?: string; created_at: string; user?: { id: number; name: string; email: string }; server?: { id: number; name: string }; account?: { id: number; status: string; expires_at?: string; identifier?: string } }
export type Page<T> = { data: T[]; current_page: number; last_page: number; total: number }

export function apiError(error: unknown) {
  if (axios.isAxiosError(error)) {
    const payload = error.response?.data as { message?: string; errors?: Record<string, string[]> } | undefined
    return payload?.errors ? Object.values(payload.errors).flat().join(' ') : payload?.message ?? error.message
  }
  return 'An unexpected error occurred.'
}
