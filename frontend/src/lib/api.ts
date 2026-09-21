export interface Parcel {
  id: number
  label: string
  area_value: string
  national_cadastral_reference: string | null
  land_type_code: string | null
  land_type_label: string | null
  land_use_code: string | null
  land_use_label: string | null
  hilucs_land_type: string | null
  hilucs_land_type_label: string | null
  hilucs_land_use: string | null
  cpx_id: string
  begin_lifespan_version: string | null
  end_lifespan_version: string | null
  cadastral_unit_code: number
  cadastral_unit_name: string
}

interface MapConfig {
  tile_revision: number
}

const apiUrl = import.meta.env.VITE_API_URL ?? 'http://localhost:8080'

export function parcelTilesUrl(revision: number): string {
  return `${apiUrl}/api/tiles/parcels/${revision}/{z}/{x}/{y}.pbf`
}

export async function getMapConfig(): Promise<MapConfig> {
  const response = await fetch(`${apiUrl}/api/map-config`)
  if (!response.ok) {
    throw new Error('Konfiguraci parcelní mapy se nepodařilo načíst.')
  }
  return response.json() as Promise<MapConfig>
}

export async function getParcel(id: number, signal?: AbortSignal): Promise<Parcel> {
  const response = await fetch(`${apiUrl}/api/parcels/${id}`, { signal })
  if (!response.ok) {
    throw new Error('Detail parcely se nepodařilo načíst.')
  }
  return response.json() as Promise<Parcel>
}
