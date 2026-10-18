export const remoteServices = [
  { value: 'mikrotik', label: 'MikroTik · Winbox', defaultPort: 8291, needsDeviceIp: false },
  { value: 'olt', label: 'OLT · Web HTTP', defaultPort: 80, needsDeviceIp: true },
  { value: 'olt-https', label: 'OLT · Web HTTPS', defaultPort: 443, needsDeviceIp: true },
  { value: 'cctv', label: 'CCTV / DVR / NVR · Web HTTP', defaultPort: 80, needsDeviceIp: false },
  { value: 'cctv-https', label: 'CCTV / DVR / NVR · Web HTTPS', defaultPort: 443, needsDeviceIp: false },
  { value: 'ssh', label: 'SSH perangkat', defaultPort: 22, needsDeviceIp: false },
  { value: 'https', label: 'Web HTTPS perangkat', defaultPort: 443, needsDeviceIp: false },
  { value: 'custom', label: 'Lainnya / port khusus', defaultPort: null, needsDeviceIp: false },
] as const

export type RemoteService = typeof remoteServices[number]['value']
export function remoteService(value?: string | null) {
  return remoteServices.find(service => service.value === value)
}
