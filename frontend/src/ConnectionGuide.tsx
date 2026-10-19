import { CheckCircle2, Copy, KeyRound, Server } from 'lucide-react'
import { useState } from 'react'
import type { Account } from './api'
import { clientDevice } from './clientDevices'
import './connection-guide.css'

const protocolLabel: Record<string, string> = { 'l2tp-ipsec': 'L2TP/IPsec', sstp: 'SSTP', openvpn: 'OpenVPN', wireguard: 'WireGuard' }

function endpoint(account: Account) {
  const host = account.server?.hostname ?? '—'
  return ['openvpn', 'wireguard'].includes(account.protocol) ? `${host}:${account.server?.port ?? ''}` : host
}

function deviceSteps(account: Account): string[] {
  const device = account.client_device_type ?? 'other'
  if (account.protocol === 'l2tp-ipsec') {
    if (device === 'mikrotik') return ['Buka PPP → Interface → tambah L2TP Client.', 'Isi Connect To, username, dan password VPN.', 'Aktifkan Use IPsec lalu isi IPsec Secret. Jangan aktifkan Add Default Route bila hanya untuk remote perangkat.']
    if (device === 'windows') return ['Settings → Network & Internet → VPN → Add VPN.', 'Pilih Windows (built-in), lalu tipe L2TP/IPsec with pre-shared key.', 'Masukkan server, username, password VPN, serta pre-shared key di bawah.']
    if (device === 'android' || device === 'ios') return ['Buka pengaturan VPN atau aplikasi VPN yang mendukung L2TP/IPsec PSK.', 'Tambah koneksi L2TP/IPsec PSK dan masukkan server serta pre-shared key.', 'Masukkan username dan password VPN Anda, lalu sambungkan.']
    return ['Gunakan klien yang mendukung L2TP/IPsec dengan pre-shared key.', 'Masukkan server, username, password VPN, dan pre-shared key di bawah.', 'Jika perangkat tidak mendukung L2TP/IPsec, pasang VPN pada router/gateway perangkat.']
  }
  if (account.protocol === 'openvpn') return ['Unduh profil .ovpn dari tombol di bawah.', 'Impor profil ke aplikasi OpenVPN Connect atau klien OpenVPN.', 'Sambungkan menggunakan username dan password VPN yang Anda buat saat memesan.']
  if (account.protocol === 'wireguard') return ['Buat tunnel baru di aplikasi WireGuard dan simpan private key di perangkat.', 'Masukkan endpoint, alamat IP tunnel, dan public key server di bawah.', 'Private key tidak pernah disimpan oleh virVPN. Hubungi admin jika perlu mengganti public key.']
  if (account.protocol === 'sstp') return ['Tambahkan koneksi SSTP pada perangkat atau router yang mendukung SSTP.', 'Masukkan alamat server, username, dan password VPN Anda.', 'Untuk MikroTik, gunakan tombol Script MikroTik bila tersedia.']
  return ['Gunakan klien yang mendukung protokol akun ini.', 'Masukkan alamat server, username, dan password VPN Anda.']
}

export default function ConnectionGuide({ account, customer = false }: { account: Account; customer?: boolean }) {
  const [copied, setCopied] = useState<string | null>(null)
  const copy = async (label: string, value?: string | null) => {
    if (!value || !navigator.clipboard) return
    await navigator.clipboard.writeText(value)
    setCopied(label)
    window.setTimeout(() => setCopied(null), 1600)
  }
  const values = [
    ['Protokol', protocolLabel[account.protocol] ?? account.protocol],
    ['Perangkat', clientDevice(account.client_device_type)?.label ?? 'Perangkat VPN'],
    ['Server', endpoint(account)],
    ['Username', account.identifier],
    ...(account.protocol === 'wireguard' ? [['IP tunnel', account.allocated_ip ? `${account.allocated_ip}/32` : 'Belum dialokasikan'], ['Public key server', account.server?.capabilities?.wireguard_public_key ?? 'Hubungi administrator']] : []),
    ...(account.protocol === 'l2tp-ipsec' ? [['IPsec pre-shared key', account.l2tp_psk ?? 'Tersedia setelah akun aktif']] : []),
  ] as Array<[string, string]>
  return <section className={`connection-guide ${customer ? 'customer' : ''}`}>
    <header><span><Server size={16} /></span><div><p>DETAIL KONEKSI</p><h3>Siapkan {protocolLabel[account.protocol] ?? account.protocol} di perangkat Anda</h3></div></header>
    <div className="connection-values">{values.map(([label, value]) => <div key={label}><small>{label}</small><code>{value}</code>{value !== '—' && !value.startsWith('Belum') && !value.startsWith('Tersedia') && !value.startsWith('Hubungi') && <button type="button" aria-label={`Salin ${label}`} onClick={() => void copy(label, value)}>{copied === label ? <CheckCircle2 size={14} /> : <Copy size={14} />}</button>}</div>)}</div>
    <div className="connection-password"><KeyRound size={15} /><span>Password VPN tidak ditampilkan lagi demi keamanan. Gunakan password yang dibuat saat pemesanan atau pembuatan akun.</span></div>
    <ol className="connection-steps">{deviceSteps(account).map(step => <li key={step}>{step}</li>)}</ol>
    {account.protocol === 'l2tp-ipsec' && <p className="connection-note">Port yang diperlukan: UDP 500, UDP 4500, dan UDP 1701. IPsec pre-shared key berbeda dari password VPN.</p>}
  </section>
}
