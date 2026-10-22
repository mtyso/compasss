<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { BellOff, Check, ThumbsUp, X } from 'lucide-vue-next'
import UiButton from '@/components/ui/UiButton.vue'
import UiIconButton from '@/components/ui/UiIconButton.vue'
import { showToast } from '@/components/ui/toast'
import { ApiError } from '@/lib/api'
import { haptics } from '@/lib/haptics'
import { useAdvisor, type Insight } from '@/stores/advisor'
import { RULE_ICONS, SEVERITY_PANELS, SEVERITY_TONES } from './insightLook'

/**
 * One piece of advice. `compact` (on «Сегодня») offers only its action and a way to hide it;
 * the feed also asks whether it was useful and can switch the whole kind of advice off.
 */
const props = defineProps<{ insight: Insight; compact?: boolean }>()

const advisor = useAdvisor()
const router = useRouter()
const { t } = useI18n()
const busy = ref(false)

async function run(task: () => Promise<void>, done?: string): Promise<void> {
  busy.value = true
  try {
    await task()
    if (done) showToast({ message: done, tone: 'success' })
  } catch {
    showToast({ message: t('quickAdd.failed'), tone: 'danger' })
  } finally {
    busy.value = false
  }
}

function act(): void {
  haptics.impact('light')
  run(() => advisor.act(props.insight), props.insight.action === 'contribute' ? t('goals.contributed') : t('advice.markedOneOff'))
}

/** «Оплачено» on a reminder; a payment one tap cannot pay (no account, another currency) opens its form. */
async function pay(): Promise<void> {
  haptics.impact('light')
  busy.value = true
  try {
    await advisor.act(props.insight)
    haptics.notify('success')
    showToast({ message: t('payments.pay'), tone: 'success' })
  } catch (e) {
    if (e instanceof ApiError && e.status === 422) void router.push({ name: 'payments', query: { pay: props.insight.ref } })
    else showToast({ message: t('quickAdd.failed'), tone: 'danger' })
  } finally {
    busy.value = false
  }
}

/** «Вернул» on money promised back, «Перенести платёж» on a paid-off debt. If it cannot be done here, the debts screen. */
async function settle(): Promise<void> {
  haptics.impact('light')
  busy.value = true
  const returned = props.insight.action === 'returned'
  try {
    await advisor.act(props.insight)
    haptics.notify('success')
    showToast({ message: returned ? t('debts.returned') : t('debts.rolledToast'), tone: 'success' })
  } catch (e) {
    if (e instanceof ApiError && e.status === 422) void router.push({ name: 'debts', query: returned ? { settle: props.insight.ref } : {} })
    else showToast({ message: t('quickAdd.failed'), tone: 'danger' })
  } finally {
    busy.value = false
  }
}

function rate(value: 'useful' | 'dismissed'): void {
  haptics.selection()
  run(() => advisor.feedback(props.insight, value))
}

function mute(): void {
  haptics.impact('medium')
  run(() => advisor.feedback(props.insight, 'mute'), t('advice.mutedToast'))
}
</script>

<template>
  <article class="flex flex-col gap-3 rounded-card p-4" :class="[SEVERITY_PANELS[insight.severity], { 'opacity-60': insight.status === 'dismissed' }]" data-panel>
    <div class="flex items-start gap-3">
      <component :is="RULE_ICONS[insight.rule]" class="mt-0.5 size-5 shrink-0" :class="SEVERITY_TONES[insight.severity]" :stroke-width="2.1" />
      <div class="flex min-w-0 flex-1 flex-col gap-1">
        <h3 class="leading-snug font-semibold">{{ insight.title }}</h3>
        <p class="text-[0.9375rem] text-ink-2">{{ insight.body }}</p>
      </div>
      <UiIconButton v-if="compact" size="sm" :label="$t('advice.hide')" class="-mt-2 -mr-2" :disabled="busy" @click="rate('dismissed')">
        <X class="size-4" />
      </UiIconButton>
    </div>

    <div v-if="insight.action || !compact" class="flex flex-wrap items-center gap-2 pl-8">
      <UiButton v-if="insight.action === 'mark_one_off'" size="sm" variant="secondary" :loading="busy" @click="act">
        {{ $t('advice.markOneOff') }}
      </UiButton>
      <UiButton v-else-if="insight.action === 'contribute'" size="sm" :loading="busy" @click="act">
        {{ $t('goals.contribute') }}
      </UiButton>
      <UiButton v-else-if="insight.action === 'pay'" size="sm" :loading="busy" @click="pay">
        {{ $t('payments.pay') }}
      </UiButton>
      <UiButton v-else-if="insight.action === 'add_payment'" size="sm" :to="{ name: 'payments', query: { suggest: insight.ref } }">
        {{ $t('payments.addShort') }}
      </UiButton>
      <UiButton v-else-if="insight.action === 'returned' || insight.action === 'roll'" size="sm" :loading="busy" @click="settle">
        {{ insight.action === 'returned' ? $t('debts.returned') : $t('debts.roll') }}
      </UiButton>
      <template v-if="!compact && insight.status !== 'dismissed'">
        <UiButton v-if="insight.status !== 'useful'" size="sm" variant="ghost" :disabled="busy" @click="rate('useful')">
          <ThumbsUp class="size-4" />{{ $t('advice.useful') }}
        </UiButton>
        <span v-else class="flex items-center gap-1.5 px-1 text-caption font-medium text-money">
          <Check class="size-4" />{{ $t('advice.markedUseful') }}
        </span>
        <UiButton size="sm" variant="ghost" :disabled="busy" @click="rate('dismissed')">{{ $t('advice.hide') }}</UiButton>
        <UiButton size="sm" variant="ghost" class="ml-auto" :disabled="busy" @click="mute">
          <BellOff class="size-4" />{{ $t('advice.mute') }}
        </UiButton>
      </template>
    </div>
  </article>
</template>
