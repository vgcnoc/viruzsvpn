import assert from 'node:assert/strict'
import { X509Certificate } from 'node:crypto'
import { readFileSync } from 'node:fs'
import test from 'node:test'
import { buildSstpClientScript, buildUnverifiedSstpClientScript, defaultSstpSetupMode, usesSharedCertificateTrust, VIRVPN_CA_PEM, VIRVPN_CA_SHA256 } from '../src/sstpClientScript.ts'

const client = { id: 42, identifier: 'testsaja', host: 'vpn.viruzs.my.id', password: 'safe-password-123' }

test('published CA matches the pinned SHA-256 fingerprint', () => {
  const ca = new X509Certificate(readFileSync(new URL('../public/viruzs-local-ca.crt', import.meta.url)))
  assert.equal(ca.fingerprint256.replaceAll(':', '').toLowerCase(), VIRVPN_CA_SHA256)
  assert.equal(new X509Certificate(VIRVPN_CA_PEM).fingerprint256.replaceAll(':', '').toLowerCase(), VIRVPN_CA_SHA256)
})

test('SSTP script verifies the CA and server without changing the default route', () => {
  const script = buildSstpClientScript(client, 'v7-scoped')
  assert.equal(script.split(/\r?\n/).length, 1, 'RouterOS Terminal must receive one complete command')
  assert.match(script, /^:if \(true\) do=\{/)
  assert.match(script, /\/file add name="virvpn-ca-2026\.crt" type=file/)
  assert.match(script, /contents="-----BEGIN CERTIFICATE-----\\nMIIC/)
  assert.match(script, /\/certificate import file-name="virvpn-ca-2026\.crt" trusted=no/)
  assert.match(script, /\/certificate set \$caId trust-store=sstp trusted=yes/)
  assert.doesNotMatch(script, /^\s*\/tool fetch/m)
  assert.ok(script.includes(VIRVPN_CA_SHA256))
  assert.match(script, /verify-server-certificate=yes verify-server-address-from-certificate=yes/)
  assert.match(script, /add-default-route=no/)
  assert.match(script, /find where name="virvpn-sstp-42"/)
  assert.match(script, /connect-to="vpn\.viruzs\.my\.id" user="testsaja"/)
  assert.doesNotMatch(script, / port=443/, 'RouterOS v6 rejects an explicit SSTP client port parameter')
  assert.match(script, /\/interface sstp-client set \[find where name="virvpn-sstp-42"\]/)
})

test('SSTP script escapes RouterOS special characters in passwords', () => {
  const script = buildSstpClientScript({ ...client, password: 'safe"\\$;password' }, 'v6')
  assert.ok(script.includes('password="safe\\"\\\\\\$;password"'))
  assert.throws(() => buildSstpClientScript({ ...client, password: 'bad\npassword' }, 'v6'), /karakter kontrol/)
})

test('SSTP script refuses a host that is not covered by the published CA', () => {
  assert.throws(() => buildSstpClientScript({ ...client, host: 'another.example' }, 'v6'), /Sertifikat CA/)
})

test('RouterOS v6 and pre-7.21 scripts omit unsupported trust-store syntax', () => {
  for (const target of ['v6', 'v7-legacy']) {
    const script = buildSstpClientScript(client, target)
    assert.equal(script.split(/\r?\n/).length, 1)
    assert.match(script, /\/certificate import file-name="virvpn-ca-2026\.crt";/)
    assert.match(script, /\/certificate set \$caId trusted=yes/)
    assert.doesNotMatch(script, /trust-store=|\/certificate import[^;]*trusted=/)
    assert.match(script, /verify-server-certificate=yes verify-server-address-from-certificate=yes/)
    assert.equal(usesSharedCertificateTrust(target), true)
  }
  assert.equal(usesSharedCertificateTrust('v7-scoped'), false)
  assert.throws(() => buildSstpClientScript(client, 'other'), /Pilih versi RouterOS/)
})

test('quick SSTP script creates a client without CA and never downgrades an existing interface', () => {
  const script = buildUnverifiedSstpClientScript(client)
  assert.equal(script.split(/\r?\n/).length, 1)
  assert.ok(script.length < 800)
  assert.match(script, /\/interface sstp-client add name="virvpn-sstp-42"/)
  assert.match(script, /verify-server-certificate=no/)
  assert.doesNotMatch(script, /verify-server-address-from-certificate=| port=443/)
  assert.match(script, /add-default-route=no/)
  assert.doesNotMatch(script, /\/certificate|\/file|\/interface sstp-client set/)
  assert.match(script, /else=\{ :put "virVPN: interface sudah ada; tidak diubah\.";/)
  assert.throws(() => buildUnverifiedSstpClientScript({ ...client, password: 'bad\npassword' }), /karakter kontrol/)
})

test('RouterOS v6 defaults to direct SSTP while newer versions default to CA verification', () => {
  assert.equal(defaultSstpSetupMode(''), '')
  assert.equal(defaultSstpSetupMode('v6'), 'unverified')
  assert.equal(defaultSstpSetupMode('v7-legacy'), 'verified')
  assert.equal(defaultSstpSetupMode('v7-scoped'), 'verified')
})
