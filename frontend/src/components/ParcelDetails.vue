<script setup lang="ts">
import type { Parcel } from '@/lib/api'

defineProps<{
  parcel: Parcel | null
  loading: boolean
  error: string | null
}>()

const emit = defineEmits<{ close: [] }>()

function value(value: string | null): string {
  return value || 'Neuvedeno'
}
</script>

<template>
  <aside class="details" aria-live="polite">
    <button class="close" type="button" aria-label="Zavřít detail" @click="emit('close')">×</button>
    <p class="eyebrow">Detail parcely</p>
    <p v-if="loading" class="state">Načítám detail…</p>
    <p v-else-if="error" class="state error">{{ error }}</p>
    <template v-else-if="parcel">
      <h2>{{ parcel.label }}</h2>
      <dl>
        <div><dt>Výměra</dt><dd>{{ Number(parcel.area_value).toLocaleString('cs-CZ') }} m²</dd></div>
        <div><dt>Katastrální území</dt><dd>{{ parcel.cadastral_unit_name }}</dd></div>
        <div><dt>Druh pozemku</dt><dd>{{ value(parcel.land_type_label) }}</dd></div>
        <div><dt>Způsob využití</dt><dd>{{ value(parcel.land_use_code) }}</dd></div>
        <div><dt>HILUCS</dt><dd>{{ value(parcel.hilucs_land_type) }}</dd></div>
      </dl>
      <details>
        <summary>Technické informace</summary>
        <dl>
          <div><dt>CPX ID</dt><dd class="technical">{{ parcel.cpx_id }}</dd></div>
          <div><dt>Katastrální reference</dt><dd>{{ value(parcel.national_cadastral_reference) }}</dd></div>
        </dl>
      </details>
    </template>
    <p v-else class="state">Vyber parcelu kliknutím do mapy.</p>
  </aside>
</template>
