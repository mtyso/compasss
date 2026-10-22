<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { Lightbulb, Scale } from 'lucide-vue-next'
import InsightCard from '@/components/advisor/InsightCard.vue'
import PageHeader from '@/components/PageHeader.vue'
import UiButton from '@/components/ui/UiButton.vue'
import UiEmpty from '@/components/ui/UiEmpty.vue'
import UiRow from '@/components/ui/UiRow.vue'
import UiSegmented from '@/components/ui/UiSegmented.vue'
import { showToast } from '@/components/ui/toast'
import { useAdvisor, type Strictness } from '@/stores/advisor'

const advisor = useAdvisor()
const { t } = useI18n()
const failed = ref(false)
let seenTimer: number | undefined

onMounted(async () => {
  try {
    await advisor.load()
    // Let the «new» marks be seen before they go.
    seenTimer = window.setTimeout(() => advisor.markSeen().catch(() => {}), 1500)
  } catch {
    failed.value = true
  }
})
onBeforeUnmount(() => window.clearTimeout(seenTimer))

const fresh = computed(() => advisor.insights.filter((i) => i.status === 'new'))
const earlier = computed(() => advisor.insights.filter((i) => i.status === 'seen' || i.status === 'useful'))

const strictnessOptions = computed(() => (['soft', 'normal', 'strict'] as Strictness[]).map((value) => ({ value, label: t(`advice.strictness.${value}`) })))
const alertOptions = [70, 80, 90].map((value) => ({ value, label: `${value}%` }))
const cushionOptions = computed(() => [3, 6, 12].map((value) => ({ value, label: t('advice.months', { n: value }) })))

async function save(changes: Record<string, unknown>): Promise<void> {
  try {
    await advisor.saveSettings(changes)
  } catch {
    showToast({ message: t('quickAdd.failed'), tone: 'danger' })
  }
}

function unmute(rule: string): void {
  save({ muted_rules: advisor.muted.filter((r) => r !== rule) })
}
</script>

<template>
  <div class="page">
    <PageHeader :title="$t('advice.title')" back="/" />

    <div class="group">
      <UiRow :to="{ name: 'can-afford' }" :title="$t('advice.canAfford')" :subtitle="$t('advice.canAffordHint')">
        <template #leading>
          <span class="grid size-9 place-items-center rounded-xl bg-money-soft text-money"><Scale class="size-5" :stroke-width="2" /></span>
        </template>
      </UiRow>
    </div>

    <p v-if="failed" class="rounded-card bg-danger-soft px-4 py-3 text-sm text-danger">{{ $t('money.loadFailed') }}</p>

    <div v-else-if="!advisor.loaded" class="flex flex-col gap-3" aria-hidden="true">
      <div v-for="n in 3" :key="n" class="skeleton h-24" />
    </div>

    <template v-else>
      <UiEmpty v-if="!fresh.length && !earlier.length" :icon="Lightbulb" tone="money" :text="$t('today.adviceEmpty')" />

      <section v-if="fresh.length" class="flex flex-col gap-2.5">
        <h2 class="section-title">{{ $t('advice.new') }}</h2>
        <TransitionGroup name="list" tag="div" class="relative flex flex-col gap-3">
          <InsightCard v-for="insight in fresh" :key="insight.id" :insight="insight" />
        </TransitionGroup>
      </section>

      <section v-if="earlier.length" class="flex flex-col gap-2.5">
        <h2 class="section-title">{{ $t('advice.earlier') }}</h2>
        <TransitionGroup name="list" tag="div" class="relative flex flex-col gap-3">
          <InsightCard v-for="insight in earlier" :key="insight.id" :insight="insight" />
        </TransitionGroup>
      </section>
    </template>

    <section class="flex flex-col gap-4">
      <h2 class="section-title">{{ $t('advice.settings') }}</h2>
      <div class="flex flex-col gap-2">
        <UiSegmented :model-value="advisor.strictness" :options="strictnessOptions" :label="$t('advice.strictnessLabel')" @update:model-value="save({ advisor_strictness: $event })" />
        <p class="px-1 text-caption text-ink-3">{{ $t(`advice.strictnessHint.${advisor.strictness}`) }}</p>
      </div>
      <div class="flex flex-col gap-2">
        <span class="section-title">{{ $t('advice.alertAt') }}</span>
        <UiSegmented :model-value="advisor.alertPercent" :options="alertOptions" :label="$t('advice.alertAt')" size="sm" @update:model-value="save({ budget_alert_percent: $event })" />
      </div>
      <div class="flex flex-col gap-2">
        <span class="section-title">{{ $t('advice.cushionTarget') }}</span>
        <UiSegmented :model-value="advisor.cushionMonths" :options="cushionOptions" :label="$t('advice.cushionTarget')" size="sm" @update:model-value="save({ cushion_target_months: $event })" />
      </div>
    </section>

    <section v-if="advisor.muted.length" class="flex flex-col gap-2.5">
      <h2 class="section-title">{{ $t('advice.mutedTitle') }}</h2>
      <div class="group">
        <div v-for="rule in advisor.muted" :key="rule" class="flex min-h-14 items-center gap-3 py-2 pr-2 pl-4">
          <span class="min-w-0 flex-1 truncate font-medium">{{ $t(`advice.rules.${rule}`) }}</span>
          <UiButton size="sm" variant="ghost" @click="unmute(rule)">{{ $t('advice.unmute') }}</UiButton>
        </div>
      </div>
    </section>
  </div>
</template>

<style scoped>
.list-move,
.list-enter-active {
  transition:
    opacity var(--dur-base) var(--ease-standard),
    transform var(--dur-spring) var(--ease-spring);
}
.list-leave-active {
  position: absolute;
  inset-inline: 0;
  transition:
    opacity var(--dur-fast) var(--ease-exit),
    transform var(--dur-fast) var(--ease-exit);
}
.list-enter-from {
  opacity: 0;
  transform: translateY(8px);
}
.list-leave-to {
  opacity: 0;
  transform: scale(0.97);
}
</style>
