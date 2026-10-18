import type { Account } from './api'
import { remoteService } from './remoteServices.ts'

const quote = (value: string) => `"${value.replace(/\\/g, '\\\\').replace(/"/g, '\\"').replace(/\$/g, '\\$').replace(/[\r\n]/g, '')}"`

export function buildRouterOsRemoteScript(account: Account) {
  if (!account.allocated_ip || !account.remote_public_port || !account.remote_target_port || !account.remote_allowed_cidr || !account.remote_wan_interface || !account.remote_gateway_ip) {
    throw new Error('Remote access requires a static tunnel IP and complete port settings.')
  }
  const base = `virVPN remote account ${account.id}`
  const wan = quote(account.remote_wan_interface)
  const source = quote(account.remote_allowed_cidr)
  const clientIp = quote(account.allocated_ip)
  const gateway = quote(account.remote_gateway_ip)
  const publicPort = account.remote_public_port
  const targetPort = account.remote_target_port
  const publicHost = account.server.hostname.replace(/[^A-Za-z0-9.:-]/g, '')
  const dstComment = quote(`${base} dstnat`)
  const srcComment = quote(`${base} srcnat`)
  const filterComment = quote(`${base} forward`)
  return `# virVPN CHR: ${remoteService(account.remote_service)?.label ?? 'Remote TCP'} ${publicPort} -> ${account.allocated_ip}:${targetPort} (${account.identifier})
# Paste into the MIKROTIK SERVER (CHR), never into the client router.
# Only ${account.remote_allowed_cidr} may connect. Client tunnel IP must remain static.

:if ([:len [/ip firewall nat find where comment=${dstComment}]] = 0) do={
    /ip firewall nat add chain=dstnat action=dst-nat in-interface=${wan} protocol=tcp src-address=${source} dst-port=${publicPort} to-addresses=${clientIp} to-ports=${targetPort} comment=${dstComment}
} else={
    /ip firewall nat set [/ip firewall nat find where comment=${dstComment}] chain=dstnat action=dst-nat in-interface=${wan} protocol=tcp src-address=${source} dst-port=${publicPort} to-addresses=${clientIp} to-ports=${targetPort}
}

/ip firewall nat move [/ip firewall nat find where comment=${dstComment}] 0

:if ([:len [/ip firewall nat find where comment=${srcComment}]] = 0) do={
    /ip firewall nat add chain=srcnat action=src-nat protocol=tcp src-address=${source} dst-address=${clientIp} dst-port=${targetPort} to-addresses=${gateway} comment=${srcComment}
} else={
    /ip firewall nat set [/ip firewall nat find where comment=${srcComment}] chain=srcnat action=src-nat protocol=tcp src-address=${source} dst-address=${clientIp} dst-port=${targetPort} to-addresses=${gateway}
}
/ip firewall nat move [/ip firewall nat find where comment=${srcComment}] 0

:if ([:len [/ip firewall filter find where comment=${filterComment}]] = 0) do={
    /ip firewall filter add chain=forward action=accept in-interface=${wan} protocol=tcp src-address=${source} dst-address=${clientIp} dst-port=${targetPort} comment=${filterComment}
} else={
    /ip firewall filter set [/ip firewall filter find where comment=${filterComment}] chain=forward action=accept in-interface=${wan} protocol=tcp src-address=${source} dst-address=${clientIp} dst-port=${targetPort}
}
/ip firewall filter move [/ip firewall filter find where comment=${filterComment}] 0

# Verify all three rules and connect to ${publicHost}:${publicPort} from an allowed source.
/ip firewall nat print where comment~"virVPN remote account ${account.id}"
/ip firewall filter print where comment~"virVPN remote account ${account.id}"`
}

export function buildRouterOsLanDeviceScript(account: Account) {
  if (!account.allocated_ip || !account.remote_gateway_ip || !account.remote_device_ip || !account.remote_target_port) {
    throw new Error('A LAN device IP, static tunnel IP and CHR gateway are required.')
  }
  const base = `virVPN remote account ${account.id} LAN`
  const source = quote(account.remote_gateway_ip)
  const tunnel = quote(account.allocated_ip)
  const device = quote(account.remote_device_ip)
  const port = account.remote_target_port
  const dst = quote(`${base} dstnat`)
  const src = quote(`${base} srcnat`)
  const forward = quote(`${base} forward`)
  return `# virVPN MIKROTIK CLIENT: forward ${remoteService(account.remote_service)?.label ?? 'TCP'} from VPN tunnel to LAN device ${account.remote_device_ip}:${port}.
# Paste into the CLIENT router after its VPN tunnel is connected. Do not paste into the CHR server.
:if ([:len [/ip firewall nat find where comment=${dst}]] = 0) do={
    /ip firewall nat add chain=dstnat action=dst-nat protocol=tcp src-address=${source} dst-address=${tunnel} dst-port=${port} to-addresses=${device} to-ports=${port} comment=${dst}
} else={
    /ip firewall nat set [/ip firewall nat find where comment=${dst}] chain=dstnat action=dst-nat protocol=tcp src-address=${source} dst-address=${tunnel} dst-port=${port} to-addresses=${device} to-ports=${port}
}
/ip firewall nat move [/ip firewall nat find where comment=${dst}] 0
:if ([:len [/ip firewall nat find where comment=${src}]] = 0) do={
    /ip firewall nat add chain=srcnat action=masquerade protocol=tcp src-address=${source} dst-address=${device} dst-port=${port} comment=${src}
} else={
    /ip firewall nat set [/ip firewall nat find where comment=${src}] chain=srcnat action=masquerade protocol=tcp src-address=${source} dst-address=${device} dst-port=${port}
}
/ip firewall nat move [/ip firewall nat find where comment=${src}] 0
:if ([:len [/ip firewall filter find where comment=${forward}]] = 0) do={
    /ip firewall filter add chain=forward action=accept protocol=tcp src-address=${source} dst-address=${device} dst-port=${port} comment=${forward}
} else={
    /ip firewall filter set [/ip firewall filter find where comment=${forward}] chain=forward action=accept protocol=tcp src-address=${source} dst-address=${device} dst-port=${port}
}
/ip firewall filter move [/ip firewall filter find where comment=${forward}] 0
# Verify that the LAN device itself accepts TCP ${port} from the client router's LAN IP.`
}

export function buildRouterOsRemoteCleanupScript(account: Account) {
  const base = `virVPN remote account ${account.id}`
  return `# virVPN CHR: remove only remote-access rules for account ${account.id}.
:if ([:len [/ip firewall nat find where comment=${quote(`${base} dstnat`)}]] > 0) do={ /ip firewall nat remove [/ip firewall nat find where comment=${quote(`${base} dstnat`)}] }
:if ([:len [/ip firewall nat find where comment=${quote(`${base} srcnat`)}]] > 0) do={ /ip firewall nat remove [/ip firewall nat find where comment=${quote(`${base} srcnat`)}] }
:if ([:len [/ip firewall filter find where comment=${quote(`${base} forward`)}]] > 0) do={ /ip firewall filter remove [/ip firewall filter find where comment=${quote(`${base} forward`)}] }`
}

export function buildRouterOsLanDeviceCleanupScript(account: Account) {
  const base = `virVPN remote account ${account.id} LAN`
  return `# virVPN MikroTik client: remove only LAN forwarding rules for account ${account.id}.
:if ([:len [/ip firewall nat find where comment=${quote(`${base} dstnat`)}]] > 0) do={ /ip firewall nat remove [/ip firewall nat find where comment=${quote(`${base} dstnat`)}] }
:if ([:len [/ip firewall nat find where comment=${quote(`${base} srcnat`)}]] > 0) do={ /ip firewall nat remove [/ip firewall nat find where comment=${quote(`${base} srcnat`)}] }
:if ([:len [/ip firewall filter find where comment=${quote(`${base} forward`)}]] > 0) do={ /ip firewall filter remove [/ip firewall filter find where comment=${quote(`${base} forward`)}] }`
}
