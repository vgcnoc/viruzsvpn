import { Copy } from 'lucide-react'
import { useState } from 'react'
import type { Account } from './api'
import { remoteService } from './remoteServices'
import { buildRouterOsLanDeviceCleanupScript, buildRouterOsLanDeviceScript } from './routerOsRemoteScript'
import './routeros.css'

export default function RemoteLanClientSetup({ account, close }: { account: Account; close: () => void }) {
  const [message, setMessage] = useState('')
  const script = buildRouterOsLanDeviceScript(account)
  const copy = async (value: string, label: string) => {
    try { await navigator.clipboard.writeText(value); setMessage(`${label} disalin. Paste hanya di MikroTik client.`) }
    catch { setMessage('Clipboard ditolak. Salin teks secara manual.') }
  }
  return <div className="modal-backdrop remote-lan-backdrop" role="presentation"><section className="modal remote-access-modal" role="dialog" aria-modal="true" aria-label="Script remote perangkat LAN">
    <div className="modal-title"><h2>Script MikroTik client · {account.identifier}</h2><button className="icon-button" type="button" aria-label="Tutup" onClick={close}>×</button></div>
    <p className="form-help">Remote {remoteService(account.remote_service)?.label ?? 'perangkat'} di IP LAN <b>{account.remote_device_ip}:{account.remote_target_port}</b>. Script ini diterapkan pada <b>MikroTik client</b> setelah tunnel VPN tersambung, bukan pada CHR server.</p>
    {account.status !== 'active' && <p className="script-warning">Akun tidak aktif. Hapus aturan client dengan tombol di bawah; jangan pasang ulang.</p>}
    <textarea className="script-output" readOnly rows={22} aria-label="Script NAT perangkat LAN MikroTik client" value={script} />
    <p className="script-warning">Pastikan IP perangkat LAN benar, layanannya aktif, dan firewall perangkat mengizinkan koneksi dari IP LAN MikroTik client. Aturan CHR harus dipasang terpisah sebelum remote publik dapat digunakan.</p>
    {message && <p className="form-help">{message}</p>}
    <div className="modal-actions"><button className="button quiet" type="button" onClick={close}>Tutup</button><button className="button quiet" type="button" onClick={() => copy(buildRouterOsLanDeviceCleanupScript(account), 'Script hapus aturan')}>Salin script hapus</button>{account.status === 'active' && <button className="button primary" type="button" onClick={() => copy(script, 'Script LAN client')}><Copy size={15} />Salin script client</button>}</div>
  </section></div>
}
