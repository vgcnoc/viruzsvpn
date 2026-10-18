import assert from 'node:assert/strict'
import test from 'node:test'
import { buildRouterOsLanDeviceCleanupScript, buildRouterOsLanDeviceScript, buildRouterOsRemoteCleanupScript, buildRouterOsRemoteScript } from '../src/routerOsRemoteScript.ts'
import { remoteService } from '../src/remoteServices.ts'
import type { Account } from '../src/api'

const account = {
  id: 12,
  identifier: 'client-12',
  allocated_ip: '10.250.20.12',
  remote_public_port: 28291,
  remote_target_port: 8291,
  remote_service: 'mikrotik',
  remote_allowed_cidr: '203.0.113.10/32',
  remote_wan_interface: 'ether1',
  remote_gateway_ip: '10.250.20.1',
  server: { hostname: 'vpn.example.test' },
} as Account

test('CHR remote script contains restricted dst-NAT, return-path src-NAT and forward filter', () => {
  const script = buildRouterOsRemoteScript(account)
  assert.match(script, /chain=dstnat action=dst-nat in-interface="ether1" protocol=tcp src-address="203\.0\.113\.10\/32" dst-port=28291 to-addresses="10\.250\.20\.12" to-ports=8291/)
  assert.match(script, /chain=srcnat action=src-nat protocol=tcp src-address="203\.0\.113\.10\/32" dst-address="10\.250\.20\.12" dst-port=8291/)
  assert.match(script, /to-addresses="10\.250\.20\.1"/)
  assert.match(script, /chain=forward action=accept in-interface="ether1"/)
  assert.match(script, /virVPN remote account 12 dstnat/)
  assert.match(script, /vpn\.example\.test:28291/)
})

test('service presets choose the advertised default ports', () => {
  assert.equal(remoteService('mikrotik')?.defaultPort, 8291)
  assert.equal(remoteService('olt')?.defaultPort, 80)
  assert.equal(remoteService('olt')?.needsDeviceIp, true)
  assert.equal(remoteService('cctv')?.defaultPort, 80)
  assert.equal(remoteService('cctv-https')?.defaultPort, 443)
  assert.equal(remoteService('custom')?.defaultPort, null)
})

test('OLT LAN script forwards the client tunnel port to the OLT IPv4 address', () => {
  const olt = { ...account, remote_service: 'olt', remote_target_port: 80, remote_device_ip: '192.168.88.10' }
  const script = buildRouterOsLanDeviceScript(olt)
  assert.match(script, /dst-address="10\.250\.20\.12" dst-port=80 to-addresses="192\.168\.88\.10"/)
  assert.match(script, /chain=srcnat action=masquerade protocol=tcp src-address="10\.250\.20\.1"/)
  assert.match(script, /chain=forward action=accept protocol=tcp src-address="10\.250\.20\.1" dst-address="192\.168\.88\.10"/)
  const cleanup = buildRouterOsLanDeviceCleanupScript(olt)
  assert.match(cleanup, /virVPN remote account 12 LAN dstnat/)
})

test('cleanup script is limited to the account-tagged rules', () => {
  const script = buildRouterOsRemoteCleanupScript(account)
  assert.match(script, /comment="virVPN remote account 12 dstnat"/)
  assert.match(script, /comment="virVPN remote account 12 srcnat"/)
  assert.match(script, /comment="virVPN remote account 12 forward"/)
  assert.doesNotMatch(script, /remove \[\/ip firewall nat find\]/)
})
