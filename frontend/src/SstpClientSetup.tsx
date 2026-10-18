import { Check, Copy, Download, ShieldCheck, X } from 'lucide-react'
import { useState } from 'react'
import type { Account } from './api'
import { buildSstpClientScript, VIRVPN_CA_SHA256, VIRVPN_CA_URL } from './sstpClientScript'
import './sstp-client.css'

export default function SstpClientSetup({ account, close }: { account: Account; close: () => void }) {
  const [password, setPassword] = useState('')
  const [copied, setCopied] = useState(false)
  const [error, setError] = useState('')
  const details = { id: account.id, identifier: account.identifier, host: account.server.hostname }
  const preview = buildSstpClientScript({ ...details, password: '********' })

  async function copyScript() {
    try {
      const script = buildSstpClientScript({ ...details, password })
      if (navigator.clipboard?.writeText) {
        await navigator.clipboard.writeText(script)
      } else {
        const temporary = document.createElement('textarea')
        temporary.value = script
        temporary.style.position = 'fixed'
        temporary.style.opacity = '0'
        document.body.appendChild(temporary)
        try {
          temporary.select()
          if (!document.execCommand('copy')) throw new Error('Clipboard tidak tersedia. Coba gunakan browser yang mendukung HTTPS clipboard.')
        } finally {
          temporary.remove()
        }
      }
      setPassword('')
      setError('')
      setCopied(true)
    } catch (cause) {
      setError(cause instanceof Error ? cause.message : 'Script gagal disalin.')
    }
  }

  return <div className="modal-backdrop sstp-setup-backdrop" role="presentation" onMouseDown={close}>
    <section className="modal sstp-setup-modal" role="dialog" aria-modal="true" aria-label={`Script MikroTik client ${account.identifier}`} onMouseDown={event => event.stopPropagation()}>
      <div className="modal-title"><h2>Script MikroTik client</h2><button className="icon-button" aria-label="Tutup" onClick={close}><X size={18} /></button></div>
      <div className="sstp-setup-intro"><ShieldCheck size={20} /><p>Paste script di <b>router MikroTik client</b>, bukan di CHR Sukabumi BC1. Script membuat koneksi SSTP untuk <b>{account.identifier}</b> ke <b>{account.server.hostname}:443</b> tanpa mengubah default route.</p></div>
      <label className="sstp-password">Password VPN akun {account.identifier}<input type="password" minLength={8} maxLength={255} autoComplete="off" value={password} onChange={event => { setPassword(event.target.value); setCopied(false); setError('') }} placeholder="Password saat akun VPN dibuat" /><small>Password tidak bisa diambil ulang dari aplikasi. Masukkan password yang Anda buat saat provisioning; hanya dipakai untuk script yang disalin.</small></label>
      <div className="sstp-ca-note"><span>CA sudah disertakan dalam script · SHA-256 <code>{VIRVPN_CA_SHA256}</code></span><a href={VIRVPN_CA_URL} target="_blank" rel="noreferrer"><Download size={14} /> Unduh CA manual</a></div>
      <label className="sstp-preview-label">Pratinjau script (password disamarkan)<textarea readOnly spellCheck={false} value={preview} rows={16} /></label>
      <p className="sstp-safety-note">Script lengkap berisi password VPN; jangan bagikan. CA publik sudah tertanam, jadi router tidak perlu mengunduhnya. Router client tetap memerlukan DNS, waktu yang benar, dan akses TCP 443 ke server VPN. Perhatikan pesan virVPN [1/3]–[3/3] di Terminal; bila gagal, kirim teks error-nya tanpa password.</p>
      {error && <p className="form-error">{error}</p>}
      {copied && <p className="sstp-copy-success"><Check size={15} /> Script tersalin. Kolom password sudah dikosongkan; paste sekarang di Terminal MikroTik client.</p>}
      <div className="modal-actions"><button type="button" className="button quiet" onClick={close}>Tutup</button><button type="button" className="button primary" disabled={password.length < 8} onClick={copyScript}><Copy size={15} /> Salin script lengkap</button></div>
      <p className="sstp-check-note">Setelah paste, buka <code>Interfaces → SSTP Client</code> atau jalankan <code>/interface sstp-client print detail</code>. Tanda <b>R</b> berarti tunnel berjalan. Jika belum, lihat <code>/log print where topics~"sstp"</code>.</p>
    </section>
  </div>
}
