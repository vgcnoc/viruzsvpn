import { Download, LoaderCircle } from 'lucide-react'
import { useState } from 'react'
import { api, apiError } from './api'
import type { Account } from './api'

export default function OpenVpnClientConfig({ account, customer = false }: { account: Account; customer?: boolean }) {
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState('')
  const download = async () => {
    setLoading(true); setError('')
    try {
      const response = await api.get<Blob>(`${customer ? '/customer' : ''}/accounts/${account.id}/client-config`, { responseType: 'blob' })
      const url = URL.createObjectURL(response.data)
      const link = document.createElement('a')
      link.href = url; link.download = `${account.identifier}.ovpn`; link.click()
      URL.revokeObjectURL(url)
    } catch (failure) { setError(apiError(failure)) }
    finally { setLoading(false) }
  }
  return <div><button className={customer ? 'cp-secondary' : 'button primary'} type="button" disabled={loading} onClick={download}>{loading ? <LoaderCircle size={15} className="spin" /> : <Download size={15} />} Unduh OpenVPN (.ovpn)</button>{error && <p className={customer ? 'cp-error' : 'form-error'}>{error}</p>}</div>
}
