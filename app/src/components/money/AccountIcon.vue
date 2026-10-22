<script setup lang="ts">
import { computed } from 'vue'
import { Banknote, CreditCard, Landmark, PiggyBank, ShieldCheck, Wallet } from 'lucide-vue-next'
import type { AccountType } from '@/stores/money'

// Account type as a small tinted tile, in the money colour; the safety cushion gets a shield.
const props = withDefaults(defineProps<{ type: AccountType; cushion?: boolean }>(), { cushion: false })

const icon = computed(() =>
  props.cushion ? ShieldCheck : { card: CreditCard, cash: Banknote, savings: PiggyBank, deposit: Landmark, other: Wallet }[props.type] ?? Wallet,
)
</script>

<template>
  <span class="grid size-9 shrink-0 place-items-center rounded-xl bg-money-soft text-money">
    <component :is="icon" class="size-[18px]" :stroke-width="2" />
  </span>
</template>
