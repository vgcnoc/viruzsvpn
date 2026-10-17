export const VIRVPN_SSTP_HOST = 'vpn.viruzs.my.id'
export const VIRVPN_CA_URL = 'https://vpn2.viruzs.my.id/viruzs-local-ca.crt'
export const VIRVPN_CA_SHA256 = '8e8b8fbf230ada6f4ea26afbd25051dc82e9ba951c2cc3589785ad4cae3a9ee2'

type SstpClient = { id: number; identifier: string; host: string; password: string }

function quote(value: string): string {
  if (/[\x00-\x1f\x7f]/.test(value)) throw new Error('Password tidak boleh berisi karakter kontrol atau baris baru.')
  return `"${value.replace(/\\/g, '\\\\').replace(/"/g, '\\"').replace(/\$/g, '\\$')}"`
}

export function buildSstpClientScript(client: SstpClient): string {
  if (!Number.isInteger(client.id) || client.id < 1 || !/^[A-Za-z0-9._-]+$/.test(client.identifier)) {
    throw new Error('Identitas akun VPN tidak valid.')
  }
  if (client.host !== VIRVPN_SSTP_HOST) throw new Error('Sertifikat CA otomatis hanya tersedia untuk vpn.viruzs.my.id.')
  if (client.password.length < 8) throw new Error('Masukkan password VPN yang digunakan saat akun dibuat.')

  const interfaceName = quote(`virvpn-sstp-${client.id}`)
  const comment = quote(`virVPN SSTP ${client.identifier}`)

  return `# virVPN SSTP client untuk ${client.identifier}
# Paste di Terminal MikroTik CLIENT, bukan di CHR server.
# Script ini tidak mengganti default route router.

:if ([:len [/certificate find where name="virvpn-client-ca"]] = 0) do={
    /tool fetch url=${quote(VIRVPN_CA_URL)} dst-path="virvpn-client-ca.crt" check-certificate=yes-without-crl
    /certificate import file-name="virvpn-client-ca.crt" name="virvpn-client-ca" trusted=no
}
:if ([:len [/certificate find where name="virvpn-client-ca"]] = 1) do={
    :if ([/certificate get [find where name="virvpn-client-ca"] fingerprint] = "${VIRVPN_CA_SHA256}") do={
        /certificate set [find where name="virvpn-client-ca"] trusted=yes trust-store=sstp
        :if ([:len [/interface sstp-client find where name=${interfaceName}]] = 0) do={
            /interface sstp-client add name=${interfaceName} connect-to=${quote(client.host)} port=443 user=${quote(client.identifier)} password=${quote(client.password)} authentication=mschap2 verify-server-certificate=yes verify-server-address-from-certificate=yes add-default-route=no disabled=no comment=${comment}
            :put "virVPN: SSTP client dibuat. Periksa status running."
        } else={
            :put "virVPN: interface sudah ada; tidak diubah."
        }
    } else={
        :error "virVPN: sidik jari CA tidak cocok; SSTP client tidak dibuat."
    }
} else={
    :error "virVPN: sertifikat CA belum tersedia; SSTP client tidak dibuat."
}
/interface sstp-client print detail where name=${interfaceName}`
}
