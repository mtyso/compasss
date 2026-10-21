<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { Plus } from 'lucide-vue-next'
import UiButton from '@/components/ui/UiButton.vue'
import UiSegmented from '@/components/ui/UiSegmented.vue'
import CategoryIcon from '@/components/CategoryIcon.vue'
import PageHeader from '@/components/PageHeader.vue'
import CategorySheet from '@/components/money/CategorySheet.vue'
import { useMoney, type Category, type CategoryKind } from '@/stores/money'
import { useSettings } from '@/stores/settings'

const money = useMoney()
const settings = useSettings()

const { t } = useI18n()
const kind = ref<CategoryKind>('expense')
const kindOptions = computed(() => (['expense', 'income'] as const).map((value) => ({ value, label: t(`categories.kinds.${value}`) })))
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
  <div class="page">
    <PageHeader :title="$t('categories.title')" />

    <UiSegmented v-model="kind" :options="kindOptions" :label="$t('categories.title')" />

    <p v-if="kind === 'expense'" class="-mt-1 px-1 text-caption text-ink-3">{{ $t('categories.intro') }}</p>

    <Transition name="fade" mode="out-in">
      <div :key="kind" class="flex flex-col gap-3">
        <div v-for="{ parent, children } in tree" :key="parent.id" class="group" :class="{ 'opacity-50': parent.archived }">
          <button type="button" class="press-soft flex min-h-15 items-center gap-3 px-4 py-3 text-left" @click="edit(parent)">
            <CategoryIcon :icon="parent.icon" :color="parent.color" size="md" />
            <span class="flex-1 truncate font-semibold">{{ parent.names[settings.locale] }}</span>
            <span v-if="parent.necessity" class="pill" :class="parent.necessity === 'essential' ? 'pill-essential' : 'pill-flexible'">
              {{ $t(`categories.${parent.necessity}Short`) }}
            </span>
          </button>
          <div v-if="kind === 'expense'" class="flex flex-col py-1">
            <button
              v-for="child in children"
              :key="child.id"
              type="button"
              class="press-soft flex min-h-11 items-center gap-3 py-2 pr-4 pl-[4.25rem] text-left text-sm"
              :class="{ 'opacity-50': child.archived }"
              @click="edit(child)"
            >
              <span class="flex-1 truncate">{{ child.names[settings.locale] }}</span>
              <span v-if="child.necessity && child.necessity !== parent.necessity" class="pill" :class="child.necessity === 'essential' ? 'pill-essential' : 'pill-flexible'">
                {{ $t(`categories.${child.necessity}Short`) }}
              </span>
            </button>
            <button type="button" class="press-soft flex min-h-11 items-center gap-2 py-2 pr-4 pl-[4.25rem] text-left text-sm text-ink-3" @click="create(parent)">
              <Plus class="size-4" />
              {{ $t('categories.addSub') }}
            </button>
          </div>
        </div>
      </div>
    </Transition>

    <button type="button" class="press flex items-center justify-center gap-2 rounded-card border border-dashed border-ink/20 py-3.5 text-sm font-medium text-ink-2" @click="create(null)">
      <Plus class="size-[18px]" />
      {{ $t('categories.new') }}
    </button>
    <UiButton v-if="hasArchived" variant="ghost" size="sm" class="self-center" @click="showArchived = !showArchived">
      {{ showArchived ? $t('categories.hideArchived') : $t('categories.showArchived') }}
    </UiButton>

    <CategorySheet v-model:open="sheetOpen" :category="editing" :kind="kind" :parent="parentForNew" />
  </div>
</template>

<style scoped>
@reference "../style.css";

.pill {
  @apply shrink-0 rounded-full px-2.5 py-1 text-[0.6875rem] font-semibold;
}
.pill-essential {
  @apply bg-task-soft text-task;
}
.pill-flexible {
  @apply bg-habit-soft text-habit;
}
</style>
