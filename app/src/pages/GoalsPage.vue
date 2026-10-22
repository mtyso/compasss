<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { CircleCheck, Plus, Target } from 'lucide-vue-next'
import ContributeSheet from '@/components/goals/ContributeSheet.vue'
import GoalRow from '@/components/goals/GoalRow.vue'
import GoalSheet from '@/components/goals/GoalSheet.vue'
import MoneyAmount from '@/components/ui/MoneyAmount.vue'
import ScreenHeader from '@/components/ui/ScreenHeader.vue'
import UiButton from '@/components/ui/UiButton.vue'
import UiEmpty from '@/components/ui/UiEmpty.vue'
import UiIconButton from '@/components/ui/UiIconButton.vue'
import UiSegmented from '@/components/ui/UiSegmented.vue'
import { showToast } from '@/components/ui/toast'
import { formatDayMonth } from '@/lib/format'
import { useGoals, type Goal, type GoalMode } from '@/stores/goals'
import { useMoney } from '@/stores/money'
import { useSettings } from '@/stores/settings'

const goals = useGoals()
const money = useMoney()
const settings = useSettings()
const router = useRouter()
const { t } = useI18n()
const failed = ref(false)

async function load(): Promise<void> {
  try {
    await Promise.all([goals.load(), money.ensureLoaded()])
    failed.value = false
  } catch {
    failed.value = true
  }
}
onMounted(load)
watch(() => money.revision, load)

const plan = computed(() => goals.plan)
const collecting = computed(() => goals.active.some((g) => g.type !== 'other' && g.remaining > 0))
const periodEnd = computed(() => (plan.value ? formatDayMonth(plan.value.period.end, settings.locale) : ''))

const sheetOpen = ref(false)
const contributeOpen = ref(false)

function created(goal: Goal): void {
  void router.push({ name: 'goal', params: { id: goal.id } })
}

const modeOptions = computed(() => (['first', 'leftover'] as const).map((value) => ({ value, label: t(`goals.modes.${value}`) })))
const mode = computed<GoalMode>({
  get: () => goals.mode,
  set: (next) => {
    goals.setMode(next).catch(() => showToast({ message: t('goals.failed'), tone: 'danger' }))
  },
})
</script>

<template>
  <div class="page">
    <ScreenHeader :title="$t('goals.title')">
      <template #trailing>
        <UiIconButton tone="filled" :label="$t('goals.new')" @click="sheetOpen = true">
          <Plus class="size-5" :stroke-width="2.2" />
        </UiIconButton>
      </template>
    </ScreenHeader>

    <div v-if="!goals.loaded && !failed" class="flex flex-col gap-4" aria-hidden="true">
      <div class="skeleton h-24 w-full rounded-card" />
      <div class="skeleton h-16 w-full" />
      <div class="skeleton h-16 w-full" />
    </div>
    <p v-else-if="failed && !goals.loaded" class="rounded-card bg-danger-soft px-4 py-3 text-sm text-danger">{{ $t('money.loadFailed') }}</p>

    <template v-else-if="plan">
      <UiEmpty v-if="!goals.goals.length" :icon="Target" tone="goal" :title="$t('goals.emptyTitle')" :text="$t('goals.empty')">
        <UiButton size="sm" @click="sheetOpen = true">{{ $t('goals.new') }}</UiButton>
      </UiEmpty>

      <template v-else>
        <!-- This pay period: what the goals still ask for, and the one button that puts it aside. -->
        <section v-if="collecting" class="card flex flex-col gap-3 p-4" data-panel>
          <template v-if="plan.due_uzs > 0">
            <div class="flex flex-col gap-1">
              <span class="text-[0.9375rem] font-medium text-ink-2">{{ $t('goals.dueUntil', { date: periodEnd }) }}</span>
              <MoneyAmount :amount="plan.due_uzs" animate class="text-[1.75rem] leading-tight font-semibold" />
              <span class="text-caption text-ink-2">{{ plan.mode === 'first' ? $t('goals.dueFirst') : $t('goals.dueLeftover') }}</span>
            </div>
            <UiButton @click="contributeOpen = true">{{ $t('goals.contribute') }}</UiButton>
          </template>
          <p v-else class="flex items-center gap-2.5 font-medium">
            <CircleCheck class="size-5 shrink-0 text-money" :stroke-width="2.2" />
            {{ $t('goals.periodDone', { date: periodEnd }) }}
          </p>
          <p class="text-caption text-ink-3">
            {{ $t('goals.monthlyTotal') }} <MoneyAmount :amount="plan.monthly_uzs" class="font-medium text-ink-2" />
          </p>
        </section>

        <section class="flex flex-col gap-3">
          <h2 class="section-title">{{ $t('goals.active') }}</h2>
          <div class="group">
            <GoalRow v-for="goal in goals.active" :key="goal.id" :goal="goal" :main="goal.id === plan.main_id" />
          </div>
          <p v-if="goals.active.length > 1" class="text-caption text-ink-3">{{ $t('goals.orderHint') }}</p>
        </section>

        <section v-if="goals.finished.length" class="flex flex-col gap-3">
          <h2 class="section-title">{{ $t('goals.finished') }}</h2>
          <div class="group">
            <GoalRow v-for="goal in goals.finished" :key="goal.id" :goal="goal" />
          </div>
        </section>

        <section class="flex flex-col gap-3">
          <h2 class="section-title">{{ $t('goals.modeTitle') }}</h2>
          <UiSegmented v-model="mode" :options="modeOptions" :label="$t('goals.modeTitle')" />
          <p class="text-caption text-ink-2">{{ $t(`goals.modeHints.${mode}`) }}</p>
        </section>
      </template>
    </template>

    <GoalSheet v-model:open="sheetOpen" :goal="null" @saved="created" />
    <ContributeSheet v-model:open="contributeOpen" />
  </div>
</template>
