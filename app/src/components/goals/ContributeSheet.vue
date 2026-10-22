<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import AccountIcon from '@/components/money/AccountIcon.vue'
import AmountField from '@/components/AmountField.vue'
import MoneyAmount from '@/components/ui/MoneyAmount.vue'
import UiButton from '@/components/ui/UiButton.vue'
import UiSheet from '@/components/ui/UiSheet.vue'
import { showToast } from '@/components/ui/toast'
import { ApiError } from '@/lib/api'
import { haptics } from '@/lib/haptics'
import { toUzs } from '@/lib/money'
import { useGoals, type Goal } from '@/stores/goals'
import { useMoney } from '@/stores/money'
import GoalIcon from './GoalIcon.vue'

/**
 * «Отложить»: moves money from one account into goals. Opened for one goal it offers that goal; otherwise
 * every goal with a contribution still due this period, each pre-filled with what is due.
 */
const props = defineProps<{ goal?: Goal | null }>()
const open = defineModel<boolean>('open', { required: true })

const goals = useGoals()
const money = useMoney()
const { t } = useI18n()

const accountId = ref<number | null>(null)
const amounts = ref<Record<number, number | null>>({})
const saving = ref(false)
const error = ref<string | null>(null)

const sources = computed(() => money.activeAccounts.filter((a) => a.spendable && !a.is_cushion))
const items = computed<Goal[]>(() => {
  if (props.goal) return [props.goal]
  return goals.due.length ? goals.due : goals.active.filter((g) => g.type !== 'other' && g.remaining > 0)
})

watch(open, (value) => {
  if (!value) return
  error.value = null
  accountId.value = sources.value[0]?.id ?? null
  amounts.value = Object.fromEntries(
    items.value.map((g) => [g.id, g.period.due > 0 ? Math.min(g.period.due, g.remaining) : Math.min(g.monthly || g.remaining, g.remaining) || null]),
  )
})

/** What it all comes to in som, at today's rates. */
const totalUzs = computed(() =>
  items.value.reduce((sum, g) => {
    const amount = amounts.value[g.id] ?? 0
    if (!amount) return sum
    return sum + (g.currency === 'USD' ? toUzs(amount, 'USD', g.fx_rate ?? money.rates.USD ?? 0) : amount)
  }, 0),
)
const canSave = computed(() => accountId.value !== null && totalUzs.value > 0)

async function save(): Promise<void> {
  if (!canSave.value || saving.value) return
  saving.value = true
  error.value = null
  try {
    const list = items.value.map((g) => ({ goal_id: g.id, amount: amounts.value[g.id] ?? 0 })).filter((i) => i.amount > 0)
    await goals.contribute(accountId.value!, list)
    haptics.notify('success')
    showToast({ message: t('goals.contributed'), tone: 'success' })
    open.value = false
  } catch (e) {
    haptics.notify('error')
    error.value = e instanceof ApiError && e.status === 422 ? (Object.values(e.errors)[0]?.[0] ?? e.message) : t('goals.failed')
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <UiSheet v-model:open="open" :title="$t('goals.contributeTitle')">
    <div class="flex flex-col gap-5 pt-1">
      <section class="flex flex-col gap-2">
        <h3 class="field-label">{{ $t('goals.from') }}</h3>
        <div class="no-scrollbar -mx-4 flex gap-2 overflow-x-auto px-4">
          <button
            v-for="account in sources"
            :key="account.id"
            type="button"
            class="chip"
            :class="{ 'chip-on': accountId === account.id }"
            :aria-pressed="accountId === account.id"
            @click="accountId = account.id"
          >
            <AccountIcon :type="account.type" class="size-4" />
            {{ account.name }}
          </button>
        </div>
      </section>

      <section class="flex flex-col gap-3">
        <div v-for="goal in items" :key="goal.id" class="flex items-end gap-3">
          <GoalIcon :goal="goal" size="sm" class="mb-1.5" />
          <AmountField v-model="amounts[goal.id]" class="min-w-0 flex-1" :currency="goal.currency ?? 'UZS'" :label="goal.title" />
        </div>
      </section>

      <p class="text-caption text-ink-2">{{ $t('goals.contributeHint') }}</p>
    </div>

    <template #footer>
      <p v-if="error" class="pb-2 text-sm text-danger" role="alert">{{ error }}</p>
      <UiButton block :disabled="!canSave" :loading="saving" @click="save">
        {{ $t('goals.contribute') }}
        <MoneyAmount v-if="totalUzs" :amount="totalUzs" class="font-semibold" />
      </UiButton>
    </template>
  </UiSheet>
</template>
