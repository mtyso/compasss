<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { BellRing, Send } from 'lucide-vue-next'
import UiRow from '@/components/ui/UiRow.vue'
import UiSegmented from '@/components/ui/UiSegmented.vue'
import UiSwitch from '@/components/ui/UiSwitch.vue'
import { showToast } from '@/components/ui/toast'
import { api, ApiError } from '@/lib/api'
import { isNative } from '@/lib/native'
import { PushDenied, pushSupported } from '@/lib/push'
import { useAuth, type User } from '@/stores/auth'
import { usePush } from '@/stores/push'

/**
 * «Уведомления» in Настройки (docs/PLAN.md, D36): this phone on or off, and where notifications go once a
 * phone takes them: the bot, the phone or both. The web shows the choice too, as long as a phone is on.
 */
type Via = 'telegram' | 'push' | 'both'

const { t } = useI18n()
const auth = useAuth()
const push = usePush()
const denied = ref(false)
const testing = ref(false)

const available = computed(() => auth.user?.push?.available ?? false)
const devices = computed(() => auth.user?.push?.devices ?? 0)
const visible = computed(() => isNative || devices.value > 0)

const hint = computed(() => {
  if (!pushSupported) return t('notify.unsupported')
  if (!available.value) return t('notify.serverOff')
  if (denied.value) return t('notify.denied')
  return t('notify.phoneHint')
})

const phoneOn = computed({
  get: () => push.enabled || push.enabling,
  set: async (on: boolean) => {
    if (!on) {
      await push.disable()
      return
    }
    denied.value = false
    try {
      await push.enable()
      // Switching the phone on means wanting notifications there: the bot steps aside unless asked for both.
      if (via.value === 'telegram') await saveVia('push')
      showToast({ message: t('notify.on'), tone: 'success' })
    } catch (e) {
      denied.value = e instanceof PushDenied
      showToast({ message: t(denied.value ? 'notify.denied' : 'notify.failed'), tone: 'danger' })
    }
  },
})

const viaOptions = computed(() => (['telegram', 'push', 'both'] as const).map((value) => ({ value, label: t(`notify.via.${value}`) })))
const via = computed<Via>({
  get: () => (auth.user?.settings.notify_via as Via | undefined) ?? 'telegram',
  set: (next) => void saveVia(next).catch(() => showToast({ message: t('quickAdd.failed'), tone: 'danger' })),
})

async function saveVia(next: Via): Promise<void> {
  const user = await api<User>('/me', { method: 'PATCH', body: JSON.stringify({ settings: { notify_via: next } }) })
  auth.setUser(user)
}

async function sendTest(): Promise<void> {
  if (testing.value) return
  testing.value = true
  try {
    await push.test()
    showToast({ message: t('notify.testSent'), tone: 'success' })
  } catch (e) {
    showToast({ message: e instanceof ApiError && e.message ? e.message : t('notify.failed'), tone: 'danger' })
  } finally {
    testing.value = false
  }
}
</script>

<template>
  <section v-if="visible" class="flex flex-col gap-2">
    <h2 class="section-title">{{ $t('notify.title') }}</h2>
    <div v-if="isNative" class="group">
      <UiSwitch v-model="phoneOn" :disabled="push.enabling || (!push.enabled && (!pushSupported || !available))" :title="$t('notify.phone')" :hint="hint">
        <template #leading><BellRing class="size-[22px]" :stroke-width="1.9" /></template>
      </UiSwitch>
      <UiRow v-if="push.enabled" :title="$t('notify.test')" :tone="testing ? 'muted' : 'default'" @click="sendTest">
        <template #leading><Send class="size-[22px]" :stroke-width="1.9" /></template>
      </UiRow>
    </div>
    <div v-if="devices > 0" class="flex flex-col gap-2 pt-1">
      <span class="field-label">{{ $t('notify.where') }}</span>
      <UiSegmented v-model="via" :options="viaOptions" :label="$t('notify.where')" />
      <p class="text-caption text-ink-3">{{ $t(`notify.viaHint.${via}`) }}</p>
    </div>
  </section>
</template>
