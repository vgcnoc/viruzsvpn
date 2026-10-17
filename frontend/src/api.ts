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
export type Plan = { id: number; name: string; price_cents: number; currency: string; duration_days: number; allowed_protocols: string[]; connection_limit: number; bandwidth_mbps?: number; is_active: boolean }
export type Account = { id: number; uuid: string; identifier: string; protocol: string; status: string; expires_at?: string; allocated_ip?: string; server: Server; plan?: Plan; device_limit?: number }
export type Page<T> = { data: T[]; current_page: number; last_page: number; total: number }

export function apiError(error: unknown) {
  if (axios.isAxiosError(error)) {
    const payload = error.response?.data as { message?: string; errors?: Record<string, string[]> } | undefined
    return payload?.message ?? (payload?.errors ? Object.values(payload.errors).flat().join(' ') : error.message)
  }
  return 'An unexpected error occurred.'
}
