<script setup lang="ts">
import { ref } from 'vue'
import MapView from '@/components/MapView.vue'
import ParcelDetails from '@/components/ParcelDetails.vue'
import type { Parcel } from '@/lib/api'

const mapView = ref<InstanceType<typeof MapView> | null>(null)
const parcel = ref<Parcel | null>(null)
const loading = ref(false)
const error = ref<string | null>(null)

function updateSelection(nextParcel: Parcel | null, nextLoading: boolean, nextError: string | null): void {
  parcel.value = nextParcel
  loading.value = nextLoading
  error.value = nextError
}

function closeDetails(): void {
  mapView.value?.clearSelection()
}
</script>

<template>
  <main>
    <header class="title"><h1>Parcely Jičín</h1><p>Vyberte parcelu pro zobrazení základních údajů.</p></header>
    <MapView ref="mapView" @select="updateSelection" />
    <ParcelDetails :parcel="parcel" :loading="loading" :error="error" @close="closeDetails" />
  </main>
</template>
