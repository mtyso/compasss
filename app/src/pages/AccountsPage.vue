<script setup lang="ts">
import { computed, onMounted } from 'vue'
import { Plus } from 'lucide-vue-next'
import PageHeader from '@/components/PageHeader.vue'
import AccountIcon from '@/components/money/AccountIcon.vue'
import MoneyAmount from '@/components/ui/MoneyAmount.vue'
import UiRow from '@/components/ui/UiRow.vue'
import { formatMoney } from '@/lib/format'
import { useMoney } from '@/stores/money'
import { useSettings } from '@/stores/settings'

const money = useMoney()
const settings = useSettings()

onMounted(() => money.loadAccounts())

const archived = computed(() => money.accounts.filter((a) => a.archived))
</script>

<template>
  <div class="page">
    <PageHeader :title="$t('accounts.title')" />

    <section class="flex flex-col gap-1.5">
      <span class="text-[0.9375rem] font-medium text-ink-2">{{ $t('accounts.total') }}</span>
      <MoneyAmount :amount="money.totalUzs" animate class="text-[2.25rem] leading-none font-semibold tracking-[-0.03em]" />
      <span class="num pt-1 text-caption text-ink-3">{{ $t('accounts.spendable', { amount: formatMoney(money.spendableUzs, 'UZS', settings.locale) }) }}</span>
    </section>

    <div class="group">
      <UiRow
        v-for="account in money.activeAccounts"
        :key="account.id"
        :to="{ name: 'account-edit', params: { id: account.id } }"
        :title="account.name"
      >
        <template #leading><AccountIcon :type="account.type" :cushion="account.is_cushion" /></template>
        <template #subtitle>
          <template v-if="account.is_cushion">{{ $t('accounts.cushion') }}</template>
          <template v-else>{{ $t(`accounts.types.${account.type}`) }}<template v-if="!account.spendable">, {{ $t('accounts.notSpendable') }}</template></template>
        </template>
        <template #trailing>
          <MoneyAmount :amount="account.balance" :currency="account.currency" class="font-semibold" :class="{ 'text-danger': account.balance < 0 }" />
        </template>
      </UiRow>
      <UiRow :to="{ name: 'account-new' }" :title="$t('accounts.add')" tone="muted" :chevron="false">
        <template #leading>
          <span class="grid size-9 place-items-center text-ink-3"><Plus class="size-5" :stroke-width="2.2" /></span>
        </template>
      </UiRow>
    </div>

    <section v-if="archived.length" class="flex flex-col gap-2.5">
      <h2 class="section-title">{{ $t('accounts.archived') }}</h2>
      <div class="group opacity-70">
        <UiRow v-for="account in archived" :key="account.id" :to="{ name: 'account-edit', params: { id: account.id } }" :title="account.name">
          <template #leading><AccountIcon :type="account.type" /></template>
          <template #trailing><MoneyAmount :amount="account.balance" :currency="account.currency" class="text-sm" /></template>
        </UiRow>
      </div>
    </section>
  </div>
</template>
