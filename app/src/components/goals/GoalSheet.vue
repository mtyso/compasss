<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { Trash2 } from 'lucide-vue-next'
import AmountField from '@/components/AmountField.vue'
import MoneyAmount from '@/components/ui/MoneyAmount.vue'
import UiButton from '@/components/ui/UiButton.vue'
import UiSegmented from '@/components/ui/UiSegmented.vue'
import UiSheet from '@/components/ui/UiSheet.vue'
import UiSwitch from '@/components/ui/UiSwitch.vue'
import { showToast } from '@/components/ui/toast'
import { ApiError } from '@/lib/api'
import { isoDate, shiftDate } from '@/lib/dates'
import type { Currency } from '@/lib/format'
import { daysBetween, forecastAt, MONTH_DAYS, monthlyFor, roundUp } from '@/lib/goals'
import { haptics } from '@/lib/haptics'
import { useGoals, type Goal, type GoalInput, type GoalType } from '@/stores/goals'
import { GOAL_ICONS, TYPE_ICONS } from './goalLook'
import { useGoalText } from './useGoalText'

/**
 * Create or edit a goal. The type decides the rest: a sum and a date to collect it by; a purchase,
 * optionally on installment (the goal then collects the down payment); a debt with its interest and
 * payment; or a goal without money, measured by a number or by its steps.
 */
const props = defineProps<{ goal: Goal | null }>()
const open = defineModel<boolean>('open', { required: true })
const emit = defineEmits<{ saved: [goal: Goal]; deleted: [] }>()

const goals = useGoals()
const { t } = useI18n()
const text = useGoalText()

const DOWN_PERCENTS = [10, 20, 30, 40, 50]
const LOAN_MONTHS = [6, 12, 24, 36, 48, 60]

const type = ref<GoalType>('save')
const title = ref('')
const icon = ref<string | null>(null)
const currency = ref<Currency>('UZS')
const target = ref<number | null>(null)
const already = ref<number | null>(null)
const deadline = ref<string | null>(null)
const monthly = ref<number | null>(null)
const rate = ref<number | null>(null)
const cushion = ref(false)
const installment = ref(false)
const price = ref<number | null>(null)
const downPercent = ref(30)
const loanMonths = ref<number | null>(36)
const unit = ref('')
const startValue = ref<number | null>(null)
const targetValue = ref<number | null>(null)
const saving = ref(false)
const error = ref<string | null>(null)
const titleInput = ref<HTMLInputElement | null>(null)

const creating = computed(() => props.goal === null)
const hasMoney = computed(() => type.value !== 'other')
const today = computed(() => isoDate())
const typeOptions = computed(() => (['save', 'buy', 'debt', 'other'] as const).map((value) => ({ value, label: t(`goals.types.${value}`) })))
const currencyOptions = [
  { value: 'UZS' as Currency, label: 'сум' },
  { value: 'USD' as Currency, label: '$' },
]
const deadlineChoices = computed(() => [
  { value: shiftDate(today.value, 183), label: t('goals.inHalfYear') },
  { value: shiftDate(today.value, 365), label: t('goals.inYear') },
  { value: shiftDate(today.value, 730), label: t('goals.inTwoYears') },
])

watch(open, (value) => {
  if (!value) return
  const goal = props.goal
  error.value = null
  type.value = goal?.type ?? 'save'
  title.value = goal?.title ?? ''
  icon.value = goal?.icon ?? null
  currency.value = goal?.currency ?? 'UZS'
  target.value = goal?.target_amount ?? null
  already.value = null
  deadline.value = goal?.deadline ?? null
  monthly.value = goal?.monthly_amount ?? null
  rate.value = goal?.rate ?? null
  cushion.value = false
  installment.value = goal?.down_percent != null
  price.value = goal?.price ?? null
  downPercent.value = goal?.down_percent ?? 30
  loanMonths.value = goal?.loan_months ?? 36
  unit.value = goal?.unit ?? ''
  startValue.value = goal?.start_value ?? null
  targetValue.value = goal?.target_value ?? null
})

function onOpened(): void {
  if (creating.value) nextTick(() => titleInput.value?.focus({ preventScroll: true }))
}

function select(apply: () => void): void {
  haptics.selection()
  apply()
}

/** What the goal collects on installment: the down payment, as the server rounds it. */
const downPayment = computed(() => (price.value ? Math.min(price.value, roundUp(Math.ceil((price.value * downPercent.value) / 100), currency.value)) : 0))
const payment = computed(() => {
  if (!installment.value || !price.value || !loanMonths.value) return null
  return roundUp(monthlyFor(price.value - downPayment.value, loanMonths.value, rate.value), currency.value)
})
const collects = computed(() => (type.value === 'buy' && installment.value ? downPayment.value : (target.value ?? 0)))

const left = computed(() => Math.max(0, collects.value - (props.goal?.saved ?? already.value ?? 0)))

/** What the deadline asks for a month, before the server's exact split by paydays. */
const neededMonthly = computed(() => {
  if (!deadline.value || !collects.value) return null
  const months = Math.max(1, Math.ceil(daysBetween(today.value, deadline.value) / MONTH_DAYS))
  return roundUp(monthlyFor(left.value, months, type.value === 'debt' ? rate.value : null), currency.value)
})

/** When the chosen contribution collects the goal: below what the deadline asks for, this date is the forecast. */
const monthlyReach = computed(() => (type.value === 'debt' || !monthly.value || !left.value ? null : forecastAt(left.value, monthly.value, null, today.value)))

const canSave = computed(() => {
  if (!title.value.trim()) return false
  if (type.value === 'buy' && installment.value) return !!price.value && !!loanMonths.value
  if (type.value === 'save' || type.value === 'buy' || (type.value === 'debt' && creating.value)) return !!target.value
  return true
})

function input(): GoalInput {
  const data: GoalInput = { title: title.value.trim(), icon: icon.value, deadline: deadline.value }
  if (creating.value) data.type = type.value
  if (hasMoney.value) {
    if (creating.value) data.currency = currency.value
    data.monthly_amount = monthly.value
    if (type.value === 'buy' && installment.value) {
      Object.assign(data, { price: price.value, down_percent: downPercent.value, loan_months: loanMonths.value, rate: rate.value })
    } else {
      data.target_amount = target.value
      if (type.value === 'buy') Object.assign(data, { price: null, down_percent: null, loan_months: null })
      if (type.value === 'debt') data.rate = rate.value
    }
    if (creating.value && type.value === 'save' && cushion.value) data.cushion = true
    if (creating.value && type.value !== 'debt' && already.value) data.saved = already.value
  } else {
    Object.assign(data, { unit: unit.value.trim() || null, start_value: startValue.value, target_value: targetValue.value })
  }
  return data
}

async function save(): Promise<void> {
  if (!canSave.value || saving.value) return
  saving.value = true
  error.value = null
  try {
    const goal = await goals.save(input(), props.goal?.id)
    haptics.notify('success')
    open.value = false
    emit('saved', goal)
  } catch (e) {
    haptics.notify('error')
    error.value = e instanceof ApiError && e.status === 422 ? (Object.values(e.errors)[0]?.[0] ?? e.message) : t('goals.failed')
  } finally {
    saving.value = false
  }
}

async function remove(): Promise<void> {
  const goal = props.goal
  if (!goal) return
  open.value = false
  await goals.remove(goal.id)
  showToast({ message: t('goals.deleted'), detail: goal.title })
  emit('deleted')
}
</script>

<template>
  <UiSheet v-model:open="open" :title="goal ? $t('goals.edit') : $t('goals.new')" @opened="onOpened">
    <div class="flex flex-col gap-5 pt-1">
      <UiSegmented v-if="creating" v-model="type" size="sm" :options="typeOptions" :label="$t('goals.type')" />

      <section class="flex flex-col gap-2">
        <input
          ref="titleInput"
          v-model="title"
          maxlength="80"
          enterkeyhint="done"
          :placeholder="$t(`goals.titlePlaceholder.${type}`)"
          class="field text-lg font-semibold"
        />
        <div class="no-scrollbar -mx-4 flex gap-1.5 overflow-x-auto px-4" role="radiogroup" :aria-label="$t('goals.icon')">
          <button
            v-for="(component, name) in GOAL_ICONS"
            :key="name"
            type="button"
            role="radio"
            class="press grid size-10 shrink-0 place-items-center rounded-full"
            :class="(icon ?? TYPE_ICONS[type]) === name ? 'bg-goal text-bg' : 'bg-surface-2 text-ink-2'"
            :aria-checked="(icon ?? TYPE_ICONS[type]) === name"
            :aria-label="String(name)"
            @click="select(() => (icon = String(name)))"
          >
            <component :is="component" class="size-5" :stroke-width="2" />
          </button>
        </div>
      </section>

      <template v-if="hasMoney">
        <div v-if="creating" class="flex items-center justify-between gap-3">
          <h3 class="field-label">{{ $t('goals.currency') }}</h3>
          <UiSegmented v-model="currency" size="sm" :options="currencyOptions" :label="$t('goals.currency')" class="w-32" />
        </div>

        <div v-if="type === 'buy'" class="group">
          <UiSwitch v-model="installment" :title="$t('goals.installment')" :hint="$t('goals.installmentHint')" />
        </div>

        <template v-if="type === 'buy' && installment">
          <AmountField v-model="price" :currency="currency" :label="$t('goals.price')" />
          <section class="flex flex-col gap-2">
            <h3 class="field-label">{{ $t('goals.downPercent') }}</h3>
            <div class="flex flex-wrap gap-2">
              <button v-for="p in DOWN_PERCENTS" :key="p" type="button" class="chip num" :class="{ 'chip-on': downPercent === p }" @click="select(() => (downPercent = p))">{{ p }}%</button>
            </div>
          </section>
          <section class="flex flex-col gap-2">
            <h3 class="field-label">{{ $t('goals.loanMonths') }}</h3>
            <div class="flex flex-wrap gap-2">
              <button v-for="m in LOAN_MONTHS" :key="m" type="button" class="chip num" :class="{ 'chip-on': loanMonths === m }" @click="select(() => (loanMonths = m))">
                {{ $t('goals.months', m) }}
              </button>
            </div>
          </section>
          <label class="flex flex-col gap-2">
            <span class="field-label">{{ $t('goals.loanRate') }}</span>
            <input v-model.number="rate" type="number" min="0" max="200" step="0.1" inputmode="decimal" placeholder="0" class="field num" />
          </label>
          <div v-if="price" class="card flex flex-col gap-1.5 px-4 py-3 text-sm" data-panel>
            <p class="flex justify-between gap-3"><span class="text-ink-2">{{ $t('goals.collectDown') }}</span><MoneyAmount :amount="downPayment" :currency="currency" class="font-semibold" /></p>
            <p v-if="payment" class="flex justify-between gap-3"><span class="text-ink-2">{{ $t('goals.thenPay') }}</span><MoneyAmount :amount="payment" :currency="currency" class="font-semibold" /></p>
          </div>
        </template>
        <AmountField v-else v-model="target" :currency="currency" :label="$t(`goals.target.${type}`)" />

        <section v-if="creating && type !== 'debt'" class="flex flex-col gap-2">
          <AmountField v-model="already" :currency="currency" :label="$t('goals.already')" />
          <p class="text-caption text-ink-3">{{ $t('goals.alreadyHint') }}</p>
        </section>

        <label v-if="type === 'debt'" class="flex flex-col gap-2">
          <span class="field-label">{{ $t('goals.debtRate') }}</span>
          <input v-model.number="rate" type="number" min="0" max="200" step="0.1" inputmode="decimal" placeholder="0" class="field num" />
        </label>

        <div v-if="creating && type === 'save'" class="group">
          <UiSwitch v-model="cushion" :title="$t('goals.cushion')" :hint="$t('goals.cushionHint')" />
        </div>
      </template>

      <section class="flex flex-col gap-2">
        <h3 class="field-label">{{ type === 'debt' ? $t('goals.payOffBy') : $t('goals.deadline') }}</h3>
        <div class="flex flex-wrap gap-2">
          <button type="button" class="chip" :class="{ 'chip-on': !deadline }" @click="select(() => (deadline = null))">{{ $t('goals.noDeadline') }}</button>
          <button v-for="choice in deadlineChoices" :key="choice.value" type="button" class="chip" :class="{ 'chip-on': deadline === choice.value }" @click="select(() => (deadline = choice.value))">
            {{ choice.label }}
          </button>
          <input
            type="date"
            :value="deadline ?? ''"
            :min="shiftDate(today, 1)"
            class="chip"
            :class="{ 'chip-on': deadline && !deadlineChoices.some((c) => c.value === deadline) }"
            @change="deadline = ($event.target as HTMLInputElement).value || null"
          />
        </div>
        <p v-if="neededMonthly" class="text-caption text-ink-2">
          {{ $t('goals.neededMonthly') }} <MoneyAmount :amount="neededMonthly" :currency="currency" class="font-semibold text-ink" />
        </p>
      </section>

      <section v-if="hasMoney" class="flex flex-col gap-2">
        <AmountField v-model="monthly" :currency="currency" :label="type === 'debt' ? $t('goals.payment') : $t('goals.monthly')" />
        <template v-if="type !== 'debt'">
          <p v-if="monthlyReach" class="text-caption text-ink-2">{{ $t('goals.monthlyReach', { date: text.dayYear(monthlyReach) }) }}</p>
          <p class="text-caption text-ink-3">{{ $t('goals.monthlyHint') }}</p>
        </template>
      </section>

      <template v-if="!hasMoney">
        <p class="text-caption text-ink-2">{{ $t('goals.otherHint') }}</p>
        <div class="grid grid-cols-3 gap-2">
          <label class="flex flex-col gap-2">
            <span class="field-label">{{ $t('goals.startValue') }}</span>
            <input v-model.number="startValue" type="number" inputmode="decimal" class="field num !px-3" />
          </label>
          <label class="flex flex-col gap-2">
            <span class="field-label">{{ $t('goals.targetValue') }}</span>
            <input v-model.number="targetValue" type="number" inputmode="decimal" class="field num !px-3" />
          </label>
          <label class="flex flex-col gap-2">
            <span class="field-label">{{ $t('goals.unit') }}</span>
            <input v-model="unit" maxlength="20" :placeholder="$t('goals.unitPlaceholder')" class="field !px-3" />
          </label>
        </div>
      </template>
    </div>

    <template #footer>
      <p v-if="error" class="pb-2 text-sm text-danger" role="alert">{{ error }}</p>
      <div class="flex gap-2">
        <UiButton v-if="goal" variant="danger-soft" class="!w-12 !px-0" :aria-label="$t('goals.delete')" @click="remove">
          <Trash2 class="size-5" />
        </UiButton>
        <UiButton class="flex-1" :disabled="!canSave" :loading="saving" @click="save">{{ goal ? $t('goals.save') : $t('goals.create') }}</UiButton>
      </div>
    </template>
  </UiSheet>
</template>
