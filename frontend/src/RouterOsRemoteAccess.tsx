import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Copy, LoaderCircle } from 'lucide-react'
import { useState } from 'react'
import { api, apiError } from './api'
import { buildRouterOsRemoteCleanupScript, buildRouterOsRemoteScript } from './routerOsRemoteScript'
import { remoteService, remoteServices } from './remoteServices'
import RemoteLanClientSetup from './RemoteLanClientSetup'
import type { Account } from './api'
import './routeros.css'

export default function RouterOsRemoteAccess({ account, close }: { account: Account; close: () => void }) {
  const queryClient = useQueryClient()
  const [configured, setConfigured] = useState<Account | null>(account.remote_public_port ? account : null)
  const [copyMessage, setCopyMessage] = useState('')
  const [remoteType, setRemoteType] = useState(account.remote_service ?? 'mikrotik')
  const [targetPort, setTargetPort] = useState(String(account.remote_target_port ?? 8291))
  const [deviceIp, setDeviceIp] = useState(account.remote_device_ip ?? '')
  const [showLanScript, setShowLanScript] = useState(false)
  const save = useMutation({
    mutationFn: (form: FormData) => api.put<Account>(`/accounts/${account.id}/remote-access`, {
      remote_public_port: Number(form.get('remote_public_port')),
      remote_target_port: Number(targetPort),
      remote_service: remoteType,
      remote_device_ip: remoteType !== 'mikrotik' && deviceIp.trim() ? deviceIp.trim() : null,
      remote_allowed_cidr: String(form.get('remote_allowed_cidr')).trim(),
      remote_wan_interface: String(form.get('remote_wan_interface')).trim(),
      remote_gateway_ip: String(form.get('remote_gateway_ip')).trim(),
    }).then(response => response.data),
    onSuccess: result => { setConfigured(result); void queryClient.invalidateQueries({ queryKey: ['account', account.id] }) },
  })
  const copy = async (content: string, label: string) => {
    try { await navigator.clipboard.writeText(content); setCopyMessage(`${label} disalin. Paste di Terminal CHR server.`) }
    catch { setCopyMessage('Akses clipboard ditolak. Salin teks secara manual.') }
  }
  const script = configured ? buildRouterOsRemoteScript(configured) : ''
  return <><div className="modal-backdrop" role="presentation"><section className="modal remote-access-modal" role="dialog" aria-modal="true" aria-label="Remote perangkat client">
    <div className="modal-title"><h2>Remote router client · {account.identifier}</h2><button className="icon-button" type="button" aria-label="Tutup" onClick={close}>×</button></div>
    {configured ? <>
      <p className="form-help">Script untuk MikroTik <b>server/CHR</b>. Remote {remoteService(configured.remote_service)?.label ?? 'perangkat'} melalui port publik {configured.remote_public_port} menuju {configured.allocated_ip}:{configured.remote_target_port}{configured.remote_device_ip ? `, lalu ke perangkat LAN ${configured.remote_device_ip}` : ''}. Hanya sumber {configured.remote_allowed_cidr} yang diizinkan.</p>
      {configured.status !== 'active' && <p className="script-warning">Akun ini tidak aktif. Jangan pasang ulang aturan; salin dan jalankan script hapus aturan di CHR.</p>}
      <textarea className="script-output" readOnly aria-label="Script dst-NAT MikroTik server" rows={23} value={script} />
      <p className="script-warning">Periksa interface WAN, IP gateway tunnel, dan kemungkinan bentrok dengan aturan NAT lain di CHR. {configured.remote_device_ip ? configured.client_device_type === 'mikrotik' || !configured.client_device_type ? 'Untuk perangkat LAN, paste juga script MikroTik client agar koneksi diteruskan ke IP perangkat.' : 'Jika perangkat berada di belakang gateway non-MikroTik, atur penerusan port pada gateway itu secara manual.' : `Perangkat VPN harus mengizinkan koneksi dari gateway tunnel CHR (${configured.remote_gateway_ip}).`} Jika CHR di belakang router lain, teruskan TCP {configured.remote_public_port} ke CHR juga. Saat akun ditangguhkan/dicabut, hapus aturan CHR dan client.</p>
      {copyMessage && <p className="form-help">{copyMessage}</p>}
      <div className="modal-actions">{configured.status === 'active' && <button className="button quiet" type="button" onClick={() => setConfigured(null)}>Ubah port</button>}{configured.remote_device_ip && (configured.client_device_type === 'mikrotik' || !configured.client_device_type) && <button className="button quiet" type="button" onClick={() => setShowLanScript(true)}>Script MikroTik client</button>}<button className="button quiet" type="button" onClick={() => copy(buildRouterOsRemoteCleanupScript(configured), 'Script hapus aturan')}>Salin script hapus CHR</button>{configured.status === 'active' && <button className="button primary" type="button" onClick={() => copy(script, 'Script dst-NAT')}><Copy size={15} />Salin script server</button>}</div>
    </> : <form className="form-grid" onSubmit={event => { event.preventDefault(); save.mutate(new FormData(event.currentTarget)) }}>
      <p className="form-help span-2">Tunnel client: <b>{account.allocated_ip}</b>. Gunakan port publik unik per akun. Perubahan di sini hanya menyimpan data dan membuat script; belum mengubah firewall CHR sampai script dipaste.</p>
      <label>Port publik CHR<input name="remote_public_port" type="number" min="20000" max="59999" required defaultValue={account.remote_public_port ?? ''} placeholder="28291" /><small>Contoh: {account.server.hostname}:28291. Jangan pakai 8291 milik CHR.</small></label>
      <label>Remote untuk apa?<select value={remoteType} onChange={event => { const choice = remoteService(event.target.value); setRemoteType(event.target.value); setTargetPort(choice?.defaultPort ? String(choice.defaultPort) : ''); setDeviceIp('') }}>{remoteServices.map(service => <option value={service.value} key={service.value}>{service.label}</option>)}</select></label>
      <label>Port tujuan perangkat<input name="remote_target_port" type="number" min="1" max="65535" required value={targetPort} onChange={event => setTargetPort(event.target.value)} /><small>Port default terisi otomatis; boleh diubah.</small></label>
      {remoteType !== 'mikrotik' && <label className="span-2">IP perangkat di LAN client<input name="remote_device_ip" type="text" required={remoteService(remoteType)?.needsDeviceIp} value={deviceIp} onChange={event => setDeviceIp(event.target.value)} placeholder="192.168.88.10" /><small>Wajib untuk OLT. Jika diisi, tersedia script NAT tambahan untuk MikroTik client.</small></label>}
      <label>Interface WAN CHR<input name="remote_wan_interface" required pattern="[A-Za-z0-9._-]+" defaultValue={account.remote_wan_interface ?? ''} placeholder="ether1" /><small>Interface tempat koneksi publik masuk.</small></label>
      <label>IP gateway tunnel CHR<input name="remote_gateway_ip" required defaultValue={account.remote_gateway_ip ?? ''} placeholder="10.250.20.1" /><small>Alamat local-address PPP di CHR, bukan IP client.</small></label>
      <label className="span-2">IP sumber yang boleh remote (CIDR)<input name="remote_allowed_cidr" required defaultValue={account.remote_allowed_cidr ?? ''} placeholder="203.0.113.10/32" /><small>IP publik admin; gunakan /32 untuk satu IP, /24–/32 didukung. Akses dari semua internet tidak diizinkan.</small></label>
      {save.isError && <p className="form-error span-2">{apiError(save.error)}</p>}
      <div className="modal-actions span-2"><button className="button quiet" type="button" onClick={close}>Batal</button><button className="button primary" disabled={save.isPending}>{save.isPending && <LoaderCircle className="spin" size={15} />}Simpan & buat script CHR</button></div>
    </form>}
  </section></div>{showLanScript && configured?.remote_device_ip && <RemoteLanClientSetup account={configured} close={() => setShowLanScript(false)} />}</>
}
