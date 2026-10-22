import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import UiButton from './UiButton.vue'

describe('UiButton', () => {
  it('keeps the address of a router link, so it is a real link', async () => {
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [
        { path: '/', component: { template: '<div />' } },
        { path: '/more/calc/compare', name: 'calc-compare', component: { template: '<div />' } },
      ],
    })
    await router.push('/')
    const link = mount(UiButton, { props: { to: { name: 'calc-compare', query: { goal: 7 } } }, slots: { default: 'Compare' }, global: { plugins: [router] } })
    expect(link.element.tagName).toBe('A')
    expect(link.attributes('href')).toBe('/more/calc/compare?goal=7')

    const outside = mount(UiButton, { props: { href: 'https://t.me/bot' }, global: { plugins: [router] } })
    expect(outside.attributes('href')).toBe('https://t.me/bot')

    const button = mount(UiButton, { global: { plugins: [router] } })
    expect(button.element.tagName).toBe('BUTTON')
    expect(button.attributes('href')).toBeUndefined()
  })
})
