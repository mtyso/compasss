<script setup lang="ts">
import { computed } from 'vue'
import UiRow from '@/components/ui/UiRow.vue'
import UiSheet from '@/components/ui/UiSheet.vue'
import { haptics } from '@/lib/haptics'
import type { Scope } from '@/stores/calendar'

/**
 * Which occurrences of a repeating event a change or a delete applies to: «Только это», «Это и
 * следующие» (left out on the series' first occurrence) or «Все события». `allowOne: false` leaves
 * out «Только это» (a new repeat rule is never one occurrence's own). `pick` comes before the sheet
 * closes, so a parent that undoes on close (a drag) knows the pick came first; closing without a
 * pick is a cancel.
 */
const props = withDefaults(defineProps<{ mode: 'edit' | 'delete'; allowFollowing: boolean; allowOne?: boolean }>(), { allowOne: true })
const open = defineModel<boolean>('open', { required: true })
const emit = defineEmits<{ pick: [scope: Scope] }>()

const scopes = computed(() => (['one', 'following', 'all'] as const).filter((s) => (s !== 'one' || props.allowOne) && (s !== 'following' || props.allowFollowing)))

function pick(scope: Scope): void {
  haptics.selection()
  emit('pick', scope)
  open.value = false
}
</script>

<template>
  <UiSheet v-model:open="open" :title="mode === 'edit' ? $t('events.scopeEdit') : $t('events.scopeDelete')">
    <div class="group pt-1">
      <UiRow
        v-for="scope in scopes"
        :key="scope"
        :title="$t(`events.scopes.${scope}`)"
        :tone="mode === 'delete' ? 'danger' : 'default'"
        :chevron="false"
        @click="pick(scope)"
      />
    </div>
  </UiSheet>
</template>
