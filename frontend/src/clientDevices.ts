export const clientDevices = [
  { value: 'mikrotik', label: 'Router MikroTik' },
  { value: 'windows', label: 'PC / laptop Windows' },
  { value: 'android', label: 'Android' },
  { value: 'ios', label: 'iPhone / iPad' },
  { value: 'linux', label: 'Linux / router lain' },
  { value: 'cctv', label: 'CCTV / DVR / NVR' },
  { value: 'other', label: 'Perangkat lain' },
] as const

export function clientDevice(value?: string | null) {
  return clientDevices.find(device => device.value === value)
}
