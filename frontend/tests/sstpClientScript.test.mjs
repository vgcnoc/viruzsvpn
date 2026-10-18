import assert from 'node:assert/strict'
import { X509Certificate } from 'node:crypto'
import { readFileSync } from 'node:fs'
import test from 'node:test'
import { buildSstpClientScript, VIRVPN_CA_PEM, VIRVPN_CA_SHA256 } from '../src/sstpClientScript.ts'

const client = { id: 42, identifier: 'testsaja', host: 'vpn.viruzs.my.id', password: 'safe-password-123' }

test('published CA matches the pinned SHA-256 fingerprint', () => {
  const ca = new X509Certificate(readFileSync(new URL('../public/viruzs-local-ca.crt', import.meta.url)))
  assert.equal(ca.fingerprint256.replaceAll(':', '').toLowerCase(), VIRVPN_CA_SHA256)
  assert.equal(new X509Certificate(VIRVPN_CA_PEM).fingerprint256.replaceAll(':', '').toLowerCase(), VIRVPN_CA_SHA256)
})

test('SSTP script verifies the CA and server without changing the default route', () => {
  const script = buildSstpClientScript(client)
  assert.equal(script.split(/\r?\n/).length, 1, 'RouterOS Terminal must receive one complete command')
  assert.match(script, /^:if \(true\) do=\{/)
  assert.match(script, /\/file add name="virvpn-ca-2026\.crt" type=file/)
  assert.match(script, /contents="-----BEGIN CERTIFICATE-----\\nMIIC/)
  assert.match(script, /\/certificate import file-name="virvpn-ca-2026\.crt"/)
  assert.doesNotMatch(script, /\/certificate import[^;]*trusted=/, 'some RouterOS versions reject trusted= on import')
  assert.doesNotMatch(script, /^\s*\/tool fetch/m)
  assert.ok(script.includes(VIRVPN_CA_SHA256))
  assert.match(script, /verify-server-certificate=yes verify-server-address-from-certificate=yes/)
  assert.match(script, /add-default-route=no/)
  assert.match(script, /find where name="virvpn-sstp-42"/)
  assert.match(script, /connect-to="vpn\.viruzs\.my\.id" port=443 user="testsaja"/)
  assert.match(script, /\/interface sstp-client set \[find where name="virvpn-sstp-42"\]/)
})

test('SSTP script escapes RouterOS special characters in passwords', () => {
  const script = buildSstpClientScript({ ...client, password: 'safe"\\$;password' })
  assert.ok(script.includes('password="safe\\"\\\\\\$;password"'))
  assert.throws(() => buildSstpClientScript({ ...client, password: 'bad\npassword' }), /karakter kontrol/)
})

test('SSTP script refuses a host that is not covered by the published CA', () => {
  assert.throws(() => buildSstpClientScript({ ...client, host: 'another.example' }), /Sertifikat CA/)
})
