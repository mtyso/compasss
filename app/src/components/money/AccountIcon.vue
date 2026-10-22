<script setup lang="ts">
import { computed } from 'vue'
import { Banknote, CreditCard, Landmark, PiggyBank, ReceiptText, ShieldCheck, Wallet } from 'lucide-vue-next'
import type { AccountType } from '@/stores/money'

// Account type as a quiet round icon; the safety cushion gets a shield in the money colour.
const props = withDefaults(defineProps<{ type: AccountType; cushion?: boolean }>(), { cushion: false })

const icon = computed(() =>
  props.cushion ? ShieldCheck : { card: CreditCard, cash: Banknote, savings: PiggyBank, deposit: Landmark, debt: ReceiptText, other: Wallet }[props.type] ?? Wallet,
)
</script>

<template>
  <span class="grid size-9 shrink-0 place-items-center rounded-full" :class="cushion ? 'bg-money-soft text-money' : 'bg-surface-2 text-ink-2'">
    <component :is="icon" class="size-[18px]" :stroke-width="2" />
  </span>
</template>
