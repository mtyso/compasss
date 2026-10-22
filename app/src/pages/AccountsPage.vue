<script setup lang="ts">
import { computed, onMounted } from 'vue'
import { ChevronRight, Plus } from 'lucide-vue-next'
import PageHeader from '@/components/PageHeader.vue'
import { formatMoney } from '@/lib/format'
import { useMoney } from '@/stores/money'
import { useSettings } from '@/stores/settings'

const money = useMoney()
const settings = useSettings()

onMounted(() => money.loadAccounts())

const archived = computed(() => money.accounts.filter((a) => a.archived))
</script>

<template>
  <div class="flex flex-col gap-5">
    <PageHeader :title="$t('accounts.title')" />

    <article class="flex flex-col gap-1 rounded-3xl bg-surface p-5 shadow-sm">
      <span class="text-xs font-semibold text-ink-3">{{ $t('accounts.total') }}</span>
      <span class="num font-display text-3xl font-bold">{{ formatMoney(money.totalUzs, 'UZS', settings.locale) }}</span>
      <span class="num text-xs text-ink-3">{{ $t('accounts.spendable', { amount: formatMoney(money.spendableUzs, 'UZS', settings.locale) }) }}</span>
    </article>

    <div class="flex flex-col divide-y divide-line overflow-hidden rounded-3xl bg-surface shadow-sm">
      <RouterLink
        v-for="account in money.activeAccounts"
        :key="account.id"
        :to="{ name: 'account-edit', params: { id: account.id } }"
        class="flex items-center gap-3 px-4 py-3 active:bg-bg"
      >
        <span class="flex min-w-0 flex-1 flex-col">
          <span class="truncate font-medium">{{ account.name }}</span>
          <span class="text-xs text-ink-3">
            {{ $t(`accounts.types.${account.type}`) }}
            <template v-if="account.is_cushion"> · {{ $t('accounts.cushion') }}</template>
            <template v-else-if="!account.spendable"> · {{ $t('accounts.notSpendable') }}</template>
          </span>
        </span>
        <span class="num font-semibold" :class="{ 'text-danger': account.balance < 0 }">{{ formatMoney(account.balance, account.currency, settings.locale) }}</span>
        <ChevronRight class="size-4 text-ink-3" />
      </RouterLink>
      <RouterLink :to="{ name: 'account-new' }" class="flex items-center gap-3 px-4 py-3 text-ink-2 active:bg-bg">
        <Plus class="size-5" />
        {{ $t('accounts.add') }}
      </RouterLink>
    </div>

    <section v-if="archived.length" class="flex flex-col gap-2">
      <h2 class="px-1 text-sm font-semibold text-ink-3">{{ $t('accounts.archived') }}</h2>
      <div class="flex flex-col divide-y divide-line overflow-hidden rounded-3xl bg-surface opacity-70 shadow-sm">
        <RouterLink v-for="account in archived" :key="account.id" :to="{ name: 'account-edit', params: { id: account.id } }" class="flex items-center gap-3 px-4 py-3">
          <span class="flex-1 truncate">{{ account.name }}</span>
          <span class="num text-sm">{{ formatMoney(account.balance, account.currency, settings.locale) }}</span>
        </RouterLink>
      </div>
    </section>
  </div>
</template>
