<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue'
import * as maplibregl from 'maplibre-gl'
import type { MapGeoJSONFeature, MapLayerMouseEvent } from 'maplibre-gl'
import { getParcel, parcelTilesUrl, type Parcel } from '@/lib/api'

const emit = defineEmits<{
  select: [parcel: Parcel | null, loading: boolean, error: string | null]
}>()

const container = ref<HTMLDivElement | null>(null)
let map: maplibregl.Map | null = null
let selectedId: number | null = null
let controller: AbortController | null = null

function selectFeature(feature: MapGeoJSONFeature): void {
  const id = Number(feature.id)
  if (!Number.isSafeInteger(id)) return

  if (selectedId !== null) {
    map?.setFeatureState({ source: 'parcels', sourceLayer: 'parcels', id: selectedId }, { selected: false })
  }
  selectedId = id
  map?.setFeatureState({ source: 'parcels', sourceLayer: 'parcels', id }, { selected: true })
  controller?.abort()
  controller = new AbortController()
  emit('select', null, true, null)
  getParcel(id, controller.signal)
    .then((parcel: Parcel) => emit('select', parcel, false, null))
    .catch((error: unknown) => {
      if (error instanceof DOMException && error.name === 'AbortError') return
      emit('select', null, false, 'Detail parcely se nepodařilo načíst.')
    })
}

function clearSelection(): void {
  if (selectedId !== null) {
    map?.setFeatureState({ source: 'parcels', sourceLayer: 'parcels', id: selectedId }, { selected: false })
  }
  selectedId = null
  controller?.abort()
  emit('select', null, false, null)
}

defineExpose({ clearSelection })

onMounted(() => {
  if (!container.value) return
  map = new maplibregl.Map({
    container: container.value,
    center: [15.351, 50.437],
    zoom: 12,
    minZoom: 9,
    style: {
      version: 8,
      sources: {
        osm: {
          type: 'raster',
          tiles: ['https://tile.openstreetmap.org/{z}/{x}/{y}.png'],
          tileSize: 256,
          attribution: '© OpenStreetMap contributors',
        },
      },
      layers: [{ id: 'osm', type: 'raster', source: 'osm' }],
    },
  })
  map.addControl(new maplibregl.NavigationControl(), 'top-right')
  map.addControl(new maplibregl.AttributionControl({ compact: true }))
  map.on('load', () => {
    if (!map) return
    map.addSource('parcels', {
      type: 'vector',
      tiles: [parcelTilesUrl],
      minzoom: 9,
      maxzoom: 22,
    })
    map.addLayer({
      id: 'parcels-fill', type: 'fill', source: 'parcels', 'source-layer': 'parcels', minzoom: 11,
      paint: {
        'fill-color': ['case', ['boolean', ['feature-state', 'selected'], false], '#f59e0b', '#2563eb'],
        'fill-opacity': ['case', ['boolean', ['feature-state', 'selected'], false], 0.55, 0.18],
      },
    })
    map.addLayer({
      id: 'parcels-line', type: 'line', source: 'parcels', 'source-layer': 'parcels', minzoom: 11,
      paint: {
        'line-color': ['case', ['boolean', ['feature-state', 'selected'], false], '#b45309', '#1d4ed8'],
        'line-width': ['case', ['boolean', ['feature-state', 'selected'], false], 2, 0.7],
      },
    })
    map.on('mouseenter', 'parcels-fill', () => { if (map) map.getCanvas().style.cursor = 'pointer' })
    map.on('mouseleave', 'parcels-fill', () => { if (map) map.getCanvas().style.cursor = '' })
    map.on('click', 'parcels-fill', (event: MapLayerMouseEvent) => {
      const feature = event.features?.[0]
      if (feature) selectFeature(feature)
    })
  })
})

onBeforeUnmount(() => {
  controller?.abort()
  map?.remove()
})
</script>

<template><div ref="container" class="map" aria-label="Mapa katastrálních parcel" /></template>
