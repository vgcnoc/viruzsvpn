export const VIRVPN_SSTP_HOST = 'vpn.viruzs.my.id'
export const VIRVPN_CA_URL = 'https://vpn2.viruzs.my.id/viruzs-local-ca.crt'
export const VIRVPN_CA_SHA256 = '8e8b8fbf230ada6f4ea26afbd25051dc82e9ba951c2cc3589785ad4cae3a9ee2'
export const VIRVPN_CA_PEM = `-----BEGIN CERTIFICATE-----
MIIC+DCCAeCgAwIBAgIIelBrT0CObB4wDQYJKoZIhvcNAQELBQAwGjEYMBYGA1UE
AwwPVmlydXpzLUxvY2FsLUNBMB4XDTI2MTAxNzA4NTEyN1oXDTI3MTAxNzA4NTEy
N1owGjEYMBYGA1UEAwwPVmlydXpzLUxvY2FsLUNBMIIBIjANBgkqhkiG9w0BAQEF
AAOCAQ8AMIIBCgKCAQEAu2FbR3VUps8F0+9IPxbsT6+7PHOC+zo4lWRbtSflCyTa
/cva3JuXhyNAEydlXDw8Pt5+3VmZ2zVYqhQTY8bGUvBhWVchmZsTUpk18ugihyO5
uhNcffW0blbmBleFG6J9w7c10ZuWXv/3/9ESwIOF2zUcn+jHh2lnSibXMK7hukQn
lckeBVd5xdnXguYg+FprMaS/AL9GpBwjbaJTNVQBu/lLlNMPmMle/LrbDmlU/O3/
bAzJOazW4MzTEMffcPgbeq6QNp8ENpYT/m7TmWJsazleHhVyGu2HIJlgS90cDil3
fcgZu73cUq+Hboj9dNSq3U2TlpMKSN1pnSKsb9/94QIDAQABo0IwQDAPBgNVHRMB
Af8EBTADAQH/MA4GA1UdDwEB/wQEAwIBBjAdBgNVHQ4EFgQU6xZxgNYqQESy2Dgc
O/r2yW0D5F8wDQYJKoZIhvcNAQELBQADggEBAFzYexCqFvWJ9cm4kqaZcCuzCDbE
MDes+h+nwBtpq4R06WTs2etGPfLlwelQQ5KHeBqcZZZNuQ11AGOVs7KcCymSg5Lz
VkCkcPgX2MvjhFpPH4ZZM71SP0D5qKOEZ1j+VMXNtdTkt5XQAbhUyiQ1qKWzOYmM
bCqpsYMaY3vBjPn+eMjwKbNhWOlY7QZ3zc5LIDCeHAFj/sOVl+BeAD3Uh9J081jq
NI1fU2CfTB4/r9L/xukMN+bDh4MgFdV6yZWdkdOSRipf2v8Yj8yEVsvFz0gX1Hyp
g8cC9bW5PLZk4Av+lOeEEAxcuFVPg4UGZkqREc2a4XnkWqYhnKF8RCn02cY=
-----END CERTIFICATE-----`

type SstpClient = { id: number; identifier: string; host: string; password: string }
export type RouterOsTarget = 'v6' | 'v7-legacy' | 'v7-scoped'

export function usesSharedCertificateTrust(target: RouterOsTarget): boolean {
  return target === 'v6' || target === 'v7-legacy'
}

function quote(value: string): string {
  if (/[\x00-\x1f\x7f]/.test(value)) throw new Error('Password tidak boleh berisi karakter kontrol atau baris baru.')
  return `"${value.replace(/\\/g, '\\\\').replace(/"/g, '\\"').replace(/\$/g, '\\$')}"`
}

export function buildUnverifiedSstpClientScript(client: SstpClient): string {
  if (!Number.isInteger(client.id) || client.id < 1 || !/^[A-Za-z0-9._-]+$/.test(client.identifier)) {
    throw new Error('Identitas akun VPN tidak valid.')
  }
  if (client.host !== VIRVPN_SSTP_HOST) throw new Error('Alamat server SSTP tidak sesuai.')
  if (client.password.length < 8) throw new Error('Masukkan password VPN yang digunakan saat akun dibuat.')

  const interfaceName = quote(`virvpn-sstp-${client.id}`)
  const options = `name=${interfaceName} connect-to=${quote(client.host)} user=${quote(client.identifier)} password=${quote(client.password)} authentication=mschap2 verify-server-certificate=no add-default-route=no disabled=no comment=${quote(`virVPN SSTP ${client.identifier}`)}`
  // Only create. Never silently downgrade an existing verified interface.
  return `:if ([:len [/interface sstp-client find where name=${interfaceName}]] = 0) do={ /interface sstp-client add ${options}; :put "virVPN: SSTP client dibuat tanpa verifikasi sertifikat."; } else={ :put "virVPN: interface sudah ada; tidak diubah."; }; /interface sstp-client print detail where name=${interfaceName}`
}

export function buildSstpClientScript(client: SstpClient, target: RouterOsTarget): string {
  if (!['v6', 'v7-legacy', 'v7-scoped'].includes(target)) throw new Error('Pilih versi RouterOS client.')
  if (!Number.isInteger(client.id) || client.id < 1 || !/^[A-Za-z0-9._-]+$/.test(client.identifier)) {
    throw new Error('Identitas akun VPN tidak valid.')
  }
  if (client.host !== VIRVPN_SSTP_HOST) throw new Error('Sertifikat CA otomatis hanya tersedia untuk vpn.viruzs.my.id.')
  if (client.password.length < 8) throw new Error('Masukkan password VPN yang digunakan saat akun dibuat.')

  const interfaceName = quote(`virvpn-sstp-${client.id}`)
  const comment = quote(`virVPN SSTP ${client.identifier}`)
  const caContents = `"${VIRVPN_CA_PEM.replace(/\n/g, '\\n')}"`
  const clientOptions = `connect-to=${quote(client.host)} user=${quote(client.identifier)} password=${quote(client.password)} authentication=mschap2 verify-server-certificate=yes verify-server-address-from-certificate=yes add-default-route=no disabled=no comment=${comment}`
  // WinBox Terminal may submit every physical newline as a separate command.
  // Keep the complete operation in one command and separate nested statements explicitly.
  const block = (statements: string[]) => `{ ${statements.join('; ')}; }`
  const prepareCa = `:if ([:len $caId] = 0) do=${block([
    `:if ([:len [/file find where name="virvpn-ca-2026.crt"]] = 0) do=${block(['/file add name="virvpn-ca-2026.crt" type=file'])}`,
    `/file set [find where name="virvpn-ca-2026.crt"] contents=${caContents}`,
    `/certificate import file-name="virvpn-ca-2026.crt"${target === 'v7-scoped' ? ' trusted=no' : ''}`,
    `:set caId [/certificate find where fingerprint="${VIRVPN_CA_SHA256}"]`,
  ])}`
  const configureClient = `:if ([:len [/interface sstp-client find where name=${interfaceName}]] = 0) do=${block([
    `/interface sstp-client add name=${interfaceName} ${clientOptions}`,
  ])} else=${block([
    `/interface sstp-client set [find where name=${interfaceName}] ${clientOptions}`,
  ])}`

  return `:if (true) do=${block([
    ':put "virVPN [1/3] Menyiapkan CA publik..."',
    `:local caId [/certificate find where fingerprint="${VIRVPN_CA_SHA256}"]`,
    prepareCa,
    `:if ([:len $caId] != 1) do=${block([':error "virVPN: fingerprint CA tidak cocok. SSTP tidak dibuat."'])}`,
    target === 'v7-scoped'
      ? '/certificate set $caId trust-store=sstp trusted=yes'
      : '/certificate set $caId trusted=yes',
    ':put "virVPN [2/3] Membuat atau memperbarui SSTP client..."',
    configureClient,
    ':put "virVPN [3/3] Status interface (R = running):"',
    `/interface sstp-client print detail where name=${interfaceName}`,
    ':put "Jika belum R, periksa log SSTP pada router client."',
  ])}`
}
