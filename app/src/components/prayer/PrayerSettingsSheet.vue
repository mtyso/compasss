<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { Check } from 'lucide-vue-next'
import UiButton from '@/components/ui/UiButton.vue'
import UiSheet from '@/components/ui/UiSheet.vue'
import UiSwitch from '@/components/ui/UiSwitch.vue'
import { showToast } from '@/components/ui/toast'
import { haptics } from '@/lib/haptics'
import { PRAYER_NAMES, type PrayerName } from '@/lib/prayer'
import { usePrayer, type RemindMode } from '@/stores/prayer'

/**
 * Prayer settings (docs/PLAN.md, D40): the city of the taqvim, how the bot reminds, which prayers it reminds
 * of, and turning prayers off. Each change is saved as it is made.
 */
const open = defineModel<boolean>('open', { required: true })
const emit = defineEmits<{ off: [] }>()

const prayer = usePrayer()
const { t } = useI18n()
const MODES: RemindMode[] = ['start_end', 'start', 'end', 'off']

const city = ref('tashkent')
const mode = ref<RemindMode>('start_end')
const mute = ref<PrayerName[]>([])

watch(open, (value) => {
  if (!value || !prayer.overview) return
  city.value = prayer.overview.city.slug
  mode.value = prayer.overview.remind
  mute.value = [...prayer.overview.mute]
  void prayer.loadCities().catch(() => undefined)
})

const cities = computed(() => (prayer.cities.length ? prayer.cities : prayer.overview ? [prayer.overview.city] : []))

async function save(changes: Parameters<typeof prayer.saveSettings>[0]): Promise<void> {
  try {
    await prayer.saveSettings(changes)
    haptics.selection()
  } catch {
    showToast({ message: t('prayer.settings.failed'), tone: 'danger' })
  }
}

function chooseMode(next: RemindMode): void {
  if (next === mode.value) return
  mode.value = next
  void save({ prayer_remind: next })
}

function reminds(name: PrayerName) {
  return computed({
    get: () => !mute.value.includes(name),
    set: (on: boolean) => {
      mute.value = on ? mute.value.filter((n) => n !== name) : [...mute.value, name]
      void save({ prayer_mute: mute.value })
    },
  })
}
const switches = Object.fromEntries(PRAYER_NAMES.map((name) => [name, reminds(name)]))

async function turnOff(): Promise<void> {
  await save({ prayer_on: false })
  open.value = false
  emit('off')
}
</script>

<template>
  <UiSheet v-model:open="open" :title="$t('prayer.settings.title')">
    <div class="flex flex-col gap-6">
      <label class="flex flex-col gap-2">
        <span class="field-label">{{ $t('prayer.settings.city') }}</span>
        <select v-model="city" class="field" @change="save({ prayer_city: city })">
          <option v-for="c in cities" :key="c.slug" :value="c.slug">{{ c.name }}</option>
        </select>
        <span class="text-caption text-ink-3">{{ $t('prayer.settings.cityHint') }}</span>
      </label>

      <div class="flex flex-col gap-2">
        <span class="field-label">{{ $t('prayer.settings.remind') }}</span>
        <div class="group" role="radiogroup" :aria-label="$t('prayer.settings.remind')">
          <button
            v-for="m in MODES"
            :key="m"
            type="button"
            role="radio"
            :aria-checked="mode === m"
            class="press-soft flex min-h-13 w-full items-center gap-3 px-4 py-3 text-left"
            @click="chooseMode(m)"
          >
            <span class="flex min-w-0 flex-1 flex-col gap-0.5">
              <span class="font-medium">{{ $t(`prayer.settings.modes.${m}`) }}</span>
              <span class="text-caption text-ink-2">{{ $t(`prayer.settings.modesHint.${m}`) }}</span>
            </span>
            <Check v-if="mode === m" class="size-5 shrink-0 text-prayer" :stroke-width="2.4" />
          </button>
        </div>
      </div>

      <div v-if="mode !== 'off'" class="flex flex-col gap-2">
        <span class="field-label">{{ $t('prayer.settings.which') }}</span>
        <div class="group">
          <UiSwitch v-for="name in PRAYER_NAMES" :key="name" v-model="switches[name].value" :title="$t(`prayer.names.${name}`)" />
        </div>
      </div>
    </div>

    <template #footer>
      <UiButton block variant="ghost" @click="turnOff">{{ $t('prayer.settings.off') }}</UiButton>
      <p class="pt-1 text-center text-caption text-ink-3">{{ $t('prayer.settings.offHint') }}</p>
    </template>
  </UiSheet>
</template>
