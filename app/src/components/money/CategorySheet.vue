<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { Loader2, X } from 'lucide-vue-next'
import CategoryIcon from '@/components/CategoryIcon.vue'
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
  <Transition enter-from-class="opacity-0" leave-to-class="opacity-0" enter-active-class="transition-opacity duration-200" leave-active-class="transition-opacity duration-150">
    <div v-if="open" class="fixed inset-0 z-20 flex items-end bg-black/40" @click.self="open = false">
      <div class="mx-auto flex max-h-[92dvh] w-full max-w-xl flex-col gap-5 overflow-y-auto rounded-t-3xl bg-surface px-5 pt-4 pb-[max(1.25rem,env(safe-area-inset-bottom))]" role="dialog" aria-modal="true">
        <header class="flex items-center gap-3">
          <CategoryIcon :icon="isTop ? icon : parent?.icon ?? category?.icon" :color="isTop ? color : parent?.color ?? category?.color" />
          <h2 class="flex-1 font-display text-lg font-bold">
            {{ category ? (category.system_key ? standardName : category.names[settings.locale]) : parent ? $t('categories.newSub', { parent: parent.names[settings.locale] }) : $t('categories.new') }}
          </h2>
          <button type="button" class="grid size-9 place-items-center rounded-full text-ink-2 active:bg-line" :aria-label="$t('quickAdd.close')" @click="open = false">
            <X class="size-5" />
          </button>
        </header>

        <label class="flex flex-col gap-1.5">
          <span class="px-1 text-xs font-semibold text-ink-3">{{ $t('categories.name') }}</span>
          <input v-model="name" maxlength="60" :placeholder="standardName || $t('categories.namePlaceholder')" class="field" />
          <span v-if="category?.system_key" class="px-1 text-xs text-ink-3">{{ $t('categories.standardHint') }}</span>
        </label>

        <section v-if="kind === 'expense'" class="flex flex-col gap-1.5">
          <span class="px-1 text-xs font-semibold text-ink-3">{{ $t('categories.necessity') }}</span>
          <div class="grid grid-cols-2 gap-1 rounded-2xl bg-line p-1">
            <button
              v-for="item in ['essential', 'flexible'] as const"
              :key="item"
              type="button"
              class="rounded-xl py-2 text-sm font-medium"
              :class="necessity === item ? 'bg-surface text-ink shadow-sm' : 'text-ink-2'"
              @click="necessity = item"
            >
              {{ $t(`categories.${item}`) }}
            </button>
          </div>
          <span class="px-1 text-xs text-ink-3">{{ $t(`categories.${necessity}Hint`) }}</span>
        </section>

        <template v-if="isTop">
          <section class="flex flex-col gap-1.5">
            <span class="px-1 text-xs font-semibold text-ink-3">{{ $t('categories.icon') }}</span>
            <div class="grid grid-cols-7 gap-2">
              <button
                v-for="item in ICONS"
                :key="item"
                type="button"
                class="grid place-items-center rounded-2xl p-0.5"
                :class="icon === item ? 'ring-2 ring-ink' : ''"
                @click="icon = item"
              >
                <CategoryIcon :icon="item" :color="color" size="sm" />
              </button>
            </div>
          </section>
          <section class="flex flex-col gap-1.5">
            <span class="px-1 text-xs font-semibold text-ink-3">{{ $t('categories.color') }}</span>
            <div class="flex flex-wrap gap-2">
              <button
                v-for="item in COLORS"
                :key="item"
                type="button"
                class="size-8 rounded-full ring-offset-2 ring-offset-surface"
                :class="{ 'ring-2 ring-ink': color === item }"
                :style="{ backgroundColor: CATEGORY_COLORS[item] }"
                :aria-label="item"
                @click="color = item"
              />
            </div>
          </section>
        </template>

        <p v-if="error" class="text-sm text-danger" role="alert">{{ error }}</p>

        <button
          type="button"
          class="flex items-center justify-center gap-2 rounded-2xl bg-ink py-3 font-semibold text-surface disabled:opacity-40"
          :disabled="!canSave || saving"
          @click="save"
        >
          <Loader2 v-if="saving" class="size-5 animate-spin" />
          {{ $t('accounts.save') }}
        </button>
        <button v-if="category" type="button" class="-mt-2 py-2 text-sm text-ink-2" @click="toggleArchive">
          {{ category.archived ? $t('categories.unarchive') : $t('categories.archive') }}
        </button>
      </div>
    </div>
  </Transition>
</template>
