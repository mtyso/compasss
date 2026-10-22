<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { Check } from 'lucide-vue-next'
import CategoryIcon from '@/components/CategoryIcon.vue'
import UiButton from '@/components/ui/UiButton.vue'
import UiSegmented from '@/components/ui/UiSegmented.vue'
import UiSheet from '@/components/ui/UiSheet.vue'
import { ApiError } from '@/lib/api'
import { CATEGORY_COLORS } from '@/lib/palette'
import { useMoney, type Category, type CategoryKind, type Necessity } from '@/stores/money'
import { useSettings } from '@/stores/settings'

/** Edits a category, or creates one: top-level when `parent` is null, a subcategory otherwise. */
const props = defineProps<{ category: Category | null; kind: CategoryKind; parent: Category | null }>()
const open = defineModel<boolean>('open', { required: true })

const money = useMoney()
const settings = useSettings()
const { t } = useI18n()

const ICONS = [
  'shopping-basket', 'utensils', 'coffee', 'car', 'plane', 'house', 'wrench', 'smartphone', 'heart-pulse', 'dumbbell',
  'shirt', 'sparkles', 'gift', 'baby', 'paw-print', 'tree-palm', 'graduation-cap', 'book', 'landmark', 'hand-heart',
  'banknote', 'briefcase', 'award', 'percent', 'tag', 'circle-ellipsis',
]
const COLORS = Object.keys(CATEGORY_COLORS)

const name = ref('')
const icon = ref('circle-ellipsis')
const color = ref('gray')
const necessity = ref<Necessity>('flexible')
const saving = ref(false)
const error = ref<string | null>(null)

const isTop = computed(() => (props.category ? props.category.parent_id === null : props.parent === null))
const standardName = computed(() =>
  props.category?.system_key ? props.category.names[settings.locale === 'uz' ? 'uz' : 'ru'] : '',
)

watch(open, (value) => {
  if (!value) return
  error.value = null
  const c = props.category
  name.value = c ? (c.system_key ? '' : c.names[settings.locale]) : ''
  icon.value = c?.icon ?? props.parent?.icon ?? 'circle-ellipsis'
  color.value = c?.color ?? props.parent?.color ?? 'gray'
  necessity.value = c?.necessity ?? props.parent?.necessity ?? 'flexible'
})

const necessityOptions = computed(() => (['essential', 'flexible'] as const).map((value) => ({ value, label: t(`categories.${value}`) })))
const title = computed(() => {
  const c = props.category
  if (c) return c.system_key ? standardName.value : c.names[settings.locale]
  return props.parent ? t('categories.newSub', { parent: props.parent.names[settings.locale] }) : t('categories.new')
})

const canSave = computed(() => !!props.category?.system_key || name.value.trim() !== '')

async function save(): Promise<void> {
  saving.value = true
  error.value = null
  try {
    const payload: Record<string, unknown> = {
      name: name.value.trim() || null,
      ...(isTop.value ? { icon: icon.value, color: color.value } : {}),
      ...(props.kind === 'expense' ? { necessity: necessity.value } : {}),
    }
    if (props.category) {
      await money.saveCategory(payload, props.category.id)
    } else {
      await money.saveCategory({ ...payload, kind: props.kind, parent_id: props.parent?.id ?? null })
    }
    open.value = false
  } catch (e) {
    error.value = e instanceof ApiError ? (Object.values(e.errors)[0]?.[0] ?? e.message) : t('quickAdd.failed')
  } finally {
    saving.value = false
  }
}

async function toggleArchive(): Promise<void> {
  if (!props.category) return
  await money.saveCategory({ archived: !props.category.archived }, props.category.id)
  open.value = false
}
</script>

<template>
  <UiSheet v-model:open="open" :title="title">
    <div class="flex flex-col gap-6">
      <div class="flex items-center gap-3">
        <CategoryIcon :icon="isTop ? icon : (parent?.icon ?? category?.icon)" :color="isTop ? color : (parent?.color ?? category?.color)" size="lg" />
        <label class="flex flex-1 flex-col gap-1.5">
          <span class="sr-only">{{ $t('categories.name') }}</span>
          <input v-model="name" maxlength="60" :placeholder="standardName || $t('categories.namePlaceholder')" class="field" />
        </label>
      </div>
      <p v-if="category?.system_key" class="-mt-4 px-1 text-caption text-ink-3">{{ $t('categories.standardHint') }}</p>

      <section v-if="kind === 'expense'" class="flex flex-col gap-2">
        <span class="section-title">{{ $t('categories.necessity') }}</span>
        <UiSegmented v-model="necessity" :options="necessityOptions" :label="$t('categories.necessity')" />
        <Transition name="fade" mode="out-in">
          <p :key="necessity" class="px-1 text-caption text-ink-3">{{ $t(`categories.${necessity}Hint`) }}</p>
        </Transition>
      </section>

      <template v-if="isTop">
        <section class="flex flex-col gap-2">
          <span class="section-title">{{ $t('categories.icon') }}</span>
          <div class="grid grid-cols-7 gap-1.5">
            <button
              v-for="item in ICONS"
              :key="item"
              type="button"
              class="press grid place-items-center rounded-xl p-0.5"
              :class="icon === item ? 'shadow-[0_0_0_2px_var(--c-ink)]' : ''"
              :aria-pressed="icon === item"
              @click="icon = item"
            >
              <CategoryIcon :icon="item" :color="color" size="sm" />
            </button>
          </div>
        </section>
        <section class="flex flex-col gap-2">
          <span class="section-title">{{ $t('categories.color') }}</span>
          <div class="flex flex-wrap gap-2.5">
            <button
              v-for="item in COLORS"
              :key="item"
              type="button"
              class="press grid size-9 place-items-center rounded-full text-white"
              :style="{ backgroundColor: CATEGORY_COLORS[item] }"
              :aria-label="item"
              :aria-pressed="color === item"
              @click="color = item"
            >
              <Check v-if="color === item" class="size-4" :stroke-width="3" />
            </button>
          </div>
        </section>
      </template>
    </div>

    <template #footer>
      <p v-if="error" class="pb-2 text-sm text-danger" role="alert">{{ error }}</p>
      <UiButton block :disabled="!canSave" :loading="saving" @click="save">{{ $t('accounts.save') }}</UiButton>
      <UiButton v-if="category" variant="ghost" block size="sm" class="mt-1" @click="toggleArchive">
        {{ category.archived ? $t('categories.unarchive') : $t('categories.archive') }}
      </UiButton>
    </template>
  </UiSheet>
</template>
