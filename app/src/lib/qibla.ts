// The Qibla (docs/PLAN.md, D40): the direction of the Kaaba from a city, how a phone's compass sensor reads
// as a heading, and when the sun itself stands in that direction, for a phone or a computer with no compass.

const KAABA = { lat: 21.4225, lon: 39.8262 }
const EARTH_KM = 6371

const rad = (deg: number) => (deg * Math.PI) / 180
const deg = (r: number) => (r * 180) / Math.PI
const wrap = (value: number, range = 360) => ((value % range) + range) % range

/** The Qibla's bearing from a place, in degrees from true north, clockwise: about 241° from Tashkent. */
export function qiblaBearing(lat: number, lon: number): number {
  const φ1 = rad(lat)
  const φ2 = rad(KAABA.lat)
  const Δλ = rad(KAABA.lon - lon)
  return wrap(deg(Math.atan2(Math.sin(Δλ) * Math.cos(φ2), Math.cos(φ1) * Math.sin(φ2) - Math.sin(φ1) * Math.cos(φ2) * Math.cos(Δλ))))
}

/** How far the Kaaba is along the earth, in kilometres. */
export function kaabaDistance(lat: number, lon: number): number {
  const a = Math.sin(rad(KAABA.lat - lat) / 2) ** 2 + Math.cos(rad(lat)) * Math.cos(rad(KAABA.lat)) * Math.sin(rad(KAABA.lon - lon) / 2) ** 2
  return 2 * EARTH_KM * Math.asin(Math.sqrt(a))
}

/** The bearing in words, by the eight points: 241° is «юго-запад». */
export function compassPoint(bearing: number): 'n' | 'ne' | 'e' | 'se' | 's' | 'sw' | 'w' | 'nw' {
  return (['n', 'ne', 'e', 'se', 's', 'sw', 'w', 'nw'] as const)[Math.round(wrap(bearing) / 45) % 8]
}

/**
 * Where the top of a phone lying flat points, in degrees from true north, from a `deviceorientation(absolute)`
 * reading. iOS gives the heading itself (`webkitCompassHeading`), Android an absolute `alpha` that turns the other
 * way. Both are magnetic, so the place's declination is added; a screen turned sideways is turned back.
 */
export function headingFrom(reading: { alpha: number | null; webkitCompassHeading?: number }, declination: number, screenAngle = 0): number | null {
  const magnetic = typeof reading.webkitCompassHeading === 'number' && !Number.isNaN(reading.webkitCompassHeading)
    ? reading.webkitCompassHeading
    : reading.alpha === null ? null : 360 - reading.alpha
  return magnetic === null ? null : wrap(magnetic + declination + screenAngle)
}

/** The shortest turn from one heading to another, −180…180: positive is clockwise. */
export function turnTo(from: number, to: number): number {
  return wrap(to - from + 180) - 180
}

/** Where the sun is at a moment: azimuth from true north clockwise, and its height above the horizon, in degrees. */
export function sunPosition(at: Date, lat: number, lon: number): { azimuth: number; altitude: number } {
  const d = at.getTime() / 86_400_000 + 2440587.5 - 2451545.0
  const g = rad(wrap(357.529 + 0.98560028 * d))
  const q = wrap(280.459 + 0.98564736 * d)
  const l = rad(wrap(q + 1.915 * Math.sin(g) + 0.02 * Math.sin(2 * g)))
  const e = rad(23.439 - 0.00000036 * d)
  const ra = Math.atan2(Math.cos(e) * Math.sin(l), Math.cos(l))
  const dec = Math.asin(Math.sin(e) * Math.sin(l))
  const siderealDeg = wrap(280.46061837 + 360.98564736629 * d + lon)
  const h = rad(siderealDeg) - ra
  const φ = rad(lat)
  const altitude = deg(Math.asin(Math.sin(φ) * Math.sin(dec) + Math.cos(φ) * Math.cos(dec) * Math.cos(h)))
  const azimuth = wrap(deg(Math.atan2(-Math.sin(h), Math.tan(dec) * Math.cos(φ) - Math.sin(φ) * Math.cos(h))))
  return { azimuth, altitude }
}

/**
 * When the sun stands in the Qibla's direction on the day of `day` (any moment of it in Tashkent), high enough
 * to stand facing it: null when it does not get there in daylight (from Tashkent, in winter it sets first).
 */
export function sunOverQibla(day: string, lat: number, lon: number, bearing: number): Date | null {
  const start = new Date(`${day}T00:00:00+05:00`).getTime()
  let previous: number | null = null
  for (let minute = 0; minute <= 24 * 60; minute += 1) {
    const at = new Date(start + minute * 60_000)
    const sun = sunPosition(at, lat, lon)
    const off = turnTo(sun.azimuth, bearing)
    if (sun.altitude > 3 && previous !== null && previous > 0 && off <= 0) return at
    previous = sun.altitude > 3 ? off : null
  }
  return null
}
