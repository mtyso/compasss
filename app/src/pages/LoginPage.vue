<template>
  <div class="mx-auto flex h-full max-w-sm flex-col justify-center gap-8 px-6">
    <div class="flex flex-col gap-2">
      <h1 class="font-display text-4xl font-bold">{{ $t('appName') }}</h1>
      <p class="text-ink-2">{{ $t('login.subtitle') }}</p>
    </div>
    <!-- Telegram sign-in arrives in step 1.2 (docs/PLAN.md, section 6). -->
    <button type="button" disabled class="rounded-2xl bg-[#229ED9] py-3.5 font-semibold text-white opacity-60">
      {{ $t('login.telegram') }}
    </button>
  </div>
</template>
