<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { ScrollText } from 'lucide-vue-next'
import PageHeader from '@/components/PageHeader.vue'
import { recordLook } from '@/components/plan/recordLook'
import UiButton from '@/components/ui/UiButton.vue'
import UiEmpty from '@/components/ui/UiEmpty.vue'
import UiSheet from '@/components/ui/UiSheet.vue'
import { showToast } from '@/components/ui/toast'
import { formatDate, formatMonthTitle } from '@/lib/format'
import { haptics } from '@/lib/haptics'
import { useRecords, type JournalRecord } from '@/stores/records'
import { useSettings } from '@/stores/settings'

/**
 * «Рекорды» (docs/PLAN.md, D39): the journal instead of badges, a date and a fact per line, by month.
 * A line opens what it is about, or goes when it came from a mistake.
 */
const records = useRecords()
const settings = useSettings()
const router = useRouter()
const { t } = useI18n()
const failed = ref(false)

onMounted(async () => {
  try {
    await records.load()
  } catch {
    failed.value = true
  }
})

const months = computed(() => {
  const groups: { month: string; items: JournalRecord[] }[] = []
  for (const record of records.records) {
    const month = record.date.slice(0, 7)
    if (groups.at(-1)?.month !== month) groups.push({ month, items: [] })
    groups.at(-1)!.items.push(record)
  }
  return groups
})

const picked = ref<JournalRecord | null>(null)
const sheetOpen = ref(false)
const busy = ref(false)

function pick(record: JournalRecord): void {
  picked.value = record
  sheetOpen.value = true
}

function openRef(): void {
  const target = picked.value?.ref
  if (!target) return
  sheetOpen.value = false
  void router.push({ name: target.type, params: { id: target.id } })
}

async function remove(): Promise<void> {
  if (!picked.value || busy.value) return
  busy.value = true
  try {
    await records.remove(picked.value.id)
    sheetOpen.value = false
    haptics.notify('success')
    showToast({ message: t('records.removed') })
  } catch {
    haptics.notify('error')
    showToast({ message: t('records.failed'), tone: 'danger' })
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="page">
    <PageHeader :title="$t('records.title')" back="/goals" />

    <p class="-mt-2 text-caption text-ink-2">{{ $t('records.intro') }}</p>

    <p v-if="failed" class="rounded-card bg-danger-soft px-4 py-3 text-sm text-danger">{{ $t('money.loadFailed') }}</p>

    <div v-else-if="!records.loaded" class="flex flex-col gap-3" aria-hidden="true">
      <div v-for="n in 4" :key="n" class="skeleton h-14" />
    </div>

    <UiEmpty v-else-if="!records.records.length" :icon="ScrollText" tone="money" :text="$t('records.empty')" />

    <template v-else>
      <section v-for="group in months" :key="group.month" class="flex flex-col gap-2">
        <h2 class="section-title">{{ formatMonthTitle(group.month, settings.locale) }}</h2>
        <div class="group">
          <button
            v-for="record in group.items"
            :key="record.id"
            type="button"
            class="press-soft flex min-h-14 w-full items-center gap-3 px-4 py-3 text-left"
            @click="pick(record)"
          >
            <span class="grid size-9 shrink-0 place-items-center rounded-full" :class="recordLook(record).disc">
              <component :is="recordLook(record).icon" class="size-[18px]" :stroke-width="2.2" aria-hidden="true" />
            </span>
            <span class="flex min-w-0 flex-1 flex-col gap-0.5">
              <span class="font-medium">{{ record.text }}</span>
              <span class="num text-caption text-ink-3">{{ formatDate(record.date) }}</span>
            </span>
          </button>
        </div>
      </section>
    </template>

    <UiSheet v-model:open="sheetOpen" :label="$t('records.title')">
      <div v-if="picked" class="flex flex-col gap-4 pb-2">
        <div class="flex items-start gap-3">
          <span class="grid size-10 shrink-0 place-items-center rounded-full" :class="recordLook(picked).disc">
            <component :is="recordLook(picked).icon" class="size-5" :stroke-width="2.2" aria-hidden="true" />
          </span>
          <div class="flex min-w-0 flex-col gap-0.5">
            <span class="text-headline font-semibold">{{ picked.text }}</span>
            <span class="num text-caption text-ink-3">{{ formatDate(picked.date) }}</span>
          </div>
        </div>
        <UiButton v-if="picked.ref" variant="secondary" @click="openRef">
          {{ picked.ref.type === 'habit' ? $t('records.openHabit') : $t('records.openGoal') }}
        </UiButton>
        <div class="flex flex-col gap-2">
          <UiButton variant="danger-soft" :loading="busy" @click="remove">{{ $t('records.remove') }}</UiButton>
          <p class="text-caption text-ink-3">{{ $t('records.removeHint') }}</p>
        </div>
      </div>
    </UiSheet>
  </div>
</template>
