import { Check, Copy, Download, ShieldCheck, X } from 'lucide-react'
import { useState } from 'react'
import type { Account } from './api'
import { buildSstpClientScript, buildUnverifiedSstpClientScript, usesSharedCertificateTrust, VIRVPN_CA_SHA256, VIRVPN_CA_URL } from './sstpClientScript'
import type { RouterOsTarget } from './sstpClientScript'
import './sstp-client.css'

export default function SstpClientSetup({ account, close }: { account: Account; close: () => void }) {
  const [password, setPassword] = useState('')
  const [setupMode, setSetupMode] = useState<'' | 'verified' | 'unverified'>('')
  const [routerOsTarget, setRouterOsTarget] = useState<RouterOsTarget | ''>('')
  const [legacyTrustAccepted, setLegacyTrustAccepted] = useState(false)
  const [unverifiedAccepted, setUnverifiedAccepted] = useState(false)
  const [copied, setCopied] = useState(false)
  const [error, setError] = useState('')
  const details = { id: account.id, identifier: account.identifier, host: account.server.hostname }
  const sharedTrust = setupMode === 'verified' && routerOsTarget !== '' && usesSharedCertificateTrust(routerOsTarget)
  const preview = setupMode === 'unverified'
    ? buildUnverifiedSstpClientScript({ ...details, password: '********' })
    : setupMode === 'verified' && routerOsTarget
      ? buildSstpClientScript({ ...details, password: '********' }, routerOsTarget)
      : ''
  const canCopy = password.length >= 8 && (setupMode === 'unverified'
    ? unverifiedAccepted
    : setupMode === 'verified' && !!routerOsTarget && (!sharedTrust || legacyTrustAccepted))

  async function copyScript() {
    try {
      let script: string
      if (setupMode === 'unverified') {
        if (!unverifiedAccepted) throw new Error('Konfirmasi risiko SSTP tanpa verifikasi sertifikat.')
        script = buildUnverifiedSstpClientScript({ ...details, password })
      } else {
        if (setupMode !== 'verified' || !routerOsTarget) throw new Error('Pilih metode dan versi RouterOS client terlebih dahulu.')
        if (sharedTrust && !legacyTrustAccepted) throw new Error('Konfirmasi risiko CA tepercaya untuk seluruh layanan router.')
        script = buildSstpClientScript({ ...details, password }, routerOsTarget)
      }
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
      <label className="sstp-password">Metode pemasangan<select value={setupMode} onChange={event => { setSetupMode(event.target.value as '' | 'verified' | 'unverified'); setLegacyTrustAccepted(false); setUnverifiedAccepted(false); setCopied(false); setError('') }}><option value="">Pilih metode</option><option value="verified">Dengan verifikasi sertifikat (disarankan)</option><option value="unverified">Langsung buat SSTP client tanpa CA (tidak aman)</option></select></label>
      {setupMode === 'verified' && <label className="sstp-password">Versi RouterOS pada router client<select value={routerOsTarget} onChange={event => { setRouterOsTarget(event.target.value as RouterOsTarget | ''); setLegacyTrustAccepted(false); setCopied(false); setError('') }}><option value="">Pilih versi RouterOS</option><option value="v6">RouterOS v6</option><option value="v7-legacy">RouterOS v7.0–v7.20</option><option value="v7-scoped">RouterOS v7.21 atau lebih baru</option></select><small>Periksa dengan <code>/system resource print</code> pada router client, bukan pada CHR server.</small></label>}
      {sharedTrust && <div className="sstp-trust-warning" role="note"><strong>Perhatian untuk RouterOS sebelum v7.21</strong><p>Versi ini tidak mendukung pembatasan CA hanya untuk SSTP. Jika script dijalankan, CA virVPN akan dipercaya oleh seluruh layanan router yang menggunakan sertifikat. <a href="https://mikrotik.com/supportsec/cve-2025-42611/" target="_blank" rel="noreferrer">Baca penjelasan MikroTik</a>. Periksa CA dan layanan sertifikat lain pada router sebelum melanjutkan.</p><label><input type="checkbox" checked={legacyTrustAccepted} onChange={event => setLegacyTrustAccepted(event.target.checked)} /> Saya memahami dan menyetujui CA virVPN dipercaya secara umum pada router client ini.</label></div>}
      {setupMode === 'unverified' && <div className="sstp-trust-warning sstp-unsafe-warning" role="alert"><strong>Tanpa verifikasi identitas server</strong><p>Script singkat ini tidak memasang CA, tetapi mengatur <code>verify-server-certificate=no</code>. Koneksi dapat diarahkan ke server palsu (man-in-the-middle). Gunakan hanya jika Anda menerima risiko ini; jangan gunakan untuk trafik sensitif. <a href="https://help.mikrotik.com/docs/spaces/ROS/pages/2031645/SSTP" target="_blank" rel="noreferrer">Penjelasan MikroTik</a>.</p><label><input type="checkbox" checked={unverifiedAccepted} onChange={event => setUnverifiedAccepted(event.target.checked)} /> Saya memahami bahwa SSTP client ini tidak memverifikasi sertifikat server.</label></div>}
      <label className="sstp-password">Password VPN akun {account.identifier}<input type="password" minLength={8} maxLength={255} autoComplete="off" value={password} onChange={event => { setPassword(event.target.value); setCopied(false); setError('') }} placeholder="Password saat akun VPN dibuat" /><small>Password tidak bisa diambil ulang dari aplikasi. Masukkan password yang Anda buat saat provisioning; hanya dipakai untuk script yang disalin.</small></label>
      {setupMode === 'verified' && <div className="sstp-ca-note"><span>CA sudah disertakan dalam script · SHA-256 <code>{VIRVPN_CA_SHA256}</code></span><a href={VIRVPN_CA_URL} target="_blank" rel="noreferrer"><Download size={14} /> Unduh CA manual</a></div>}
      <label className="sstp-preview-label">Pratinjau perintah · generator revisi 7 (password disamarkan)<textarea readOnly spellCheck={false} value={preview} placeholder="Pilih metode untuk melihat script." rows={setupMode === 'unverified' ? 5 : 12} /></label>
      <p className="sstp-safety-note">Klik tombol salin lalu paste sebagai <b>satu perintah</b> di Terminal MikroTik client. Router memerlukan DNS dan akses TCP 443 ke server VPN. Jangan bagikan script hasil salin karena berisi password.</p>
      {error && <p className="form-error">{error}</p>}
      {copied && <p className="sstp-copy-success"><Check size={15} /> Satu perintah tersalin. Kolom password dikosongkan; paste sekali di Terminal MikroTik client.</p>}
      <div className="modal-actions"><button type="button" className="button quiet" onClick={close}>Tutup</button><button type="button" className="button primary" disabled={!canCopy} onClick={copyScript}><Copy size={15} /> Salin perintah satu baris</button></div>
      <p className="sstp-check-note">Setelah paste, buka <code>Interfaces → SSTP Client</code> atau jalankan <code>/interface sstp-client print detail</code>. Tanda <b>R</b> berarti tunnel berjalan. Jika belum, lihat <code>/log print where topics~"sstp"</code>.</p>
    </section>
  </div>
}
