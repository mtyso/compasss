<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { Plus } from 'lucide-vue-next'
import CategoryIcon from '@/components/CategoryIcon.vue'
import PageHeader from '@/components/PageHeader.vue'
import CategorySheet from '@/components/money/CategorySheet.vue'
import { useMoney, type Category, type CategoryKind } from '@/stores/money'
import { useSettings } from '@/stores/settings'

const money = useMoney()
const settings = useSettings()

const kind = ref<CategoryKind>('expense')
const sheetOpen = ref(false)
const editing = ref<Category | null>(null)
const parentForNew = ref<Category | null>(null)
const showArchived = ref(false)

onMounted(() => money.loadCategories())

const tree = computed(() =>
  money.categories
    .filter((c) => c.kind === kind.value && c.parent_id === null && (showArchived.value || !c.archived))
    .map((parent) => ({
      parent,
      children: money.categories.filter((c) => c.parent_id === parent.id && (showArchived.value || !c.archived)),
    })),
)
const hasArchived = computed(() => money.categories.some((c) => c.kind === kind.value && c.archived))

function edit(category: Category): void {
  editing.value = category
  parentForNew.value = null
  sheetOpen.value = true
}

function create(parent: Category | null): void {
  editing.value = null
  parentForNew.value = parent
  sheetOpen.value = true
}
</script>

<template>
  <div class="flex flex-col gap-5">
    <PageHeader :title="$t('categories.title')" />

    <div class="grid grid-cols-2 gap-1 rounded-2xl bg-line p-1">
      <button
        v-for="item in ['expense', 'income'] as const"
        :key="item"
        type="button"
        class="rounded-xl py-2 text-sm font-medium"
        :class="kind === item ? 'bg-surface text-ink shadow-sm' : 'text-ink-2'"
        @click="kind = item"
      >
        {{ $t(`categories.kinds.${item}`) }}
      </button>
    </div>

    <p v-if="kind === 'expense'" class="px-1 text-xs text-ink-3">{{ $t('categories.intro') }}</p>

    <div v-for="{ parent, children } in tree" :key="parent.id" class="flex flex-col overflow-hidden rounded-3xl bg-surface shadow-sm" :class="{ 'opacity-50': parent.archived }">
      <button type="button" class="flex items-center gap-3 px-4 py-3 text-left active:bg-bg" @click="edit(parent)">
        <CategoryIcon :icon="parent.icon" :color="parent.color" size="sm" />
        <span class="flex-1 truncate font-medium">{{ parent.names[settings.locale] }}</span>
        <span v-if="parent.necessity" class="pill" :class="parent.necessity === 'essential' ? 'pill-essential' : 'pill-flexible'">
          {{ $t(`categories.${parent.necessity}Short`) }}
        </span>
      </button>
      <div v-if="kind === 'expense'" class="flex flex-col border-t border-line pb-1">
        <button
          v-for="child in children"
          :key="child.id"
          type="button"
          class="flex items-center gap-3 py-2 pr-4 pl-15 text-left text-sm active:bg-bg"
          :class="{ 'opacity-50': child.archived }"
          @click="edit(child)"
        >
          <span class="flex-1 truncate">{{ child.names[settings.locale] }}</span>
          <span v-if="child.necessity && child.necessity !== parent.necessity" class="pill" :class="child.necessity === 'essential' ? 'pill-essential' : 'pill-flexible'">
            {{ $t(`categories.${child.necessity}Short`) }}
          </span>
        </button>
        <button type="button" class="flex items-center gap-2 py-2 pr-4 pl-15 text-left text-sm text-ink-3 active:bg-bg" @click="create(parent)">
          <Plus class="size-4" />
          {{ $t('categories.addSub') }}
        </button>
      </div>
    </div>

    <button type="button" class="flex items-center justify-center gap-2 rounded-2xl border border-dashed border-line py-3 text-sm text-ink-2" @click="create(null)">
      <Plus class="size-4" />
      {{ $t('categories.new') }}
    </button>
    <button v-if="hasArchived" type="button" class="text-sm text-ink-3" @click="showArchived = !showArchived">
      {{ showArchived ? $t('categories.hideArchived') : $t('categories.showArchived') }}
    </button>

    <CategorySheet v-model:open="sheetOpen" :category="editing" :kind="kind" :parent="parentForNew" />
  </div>
</template>

<style scoped>
@reference "../style.css";

.pill {
  @apply rounded-lg px-2 py-0.5 text-[11px] font-semibold;
}
.pill-essential {
  @apply bg-task-soft text-task;
}
.pill-flexible {
  @apply bg-habit-soft text-habit;
}
</style>
