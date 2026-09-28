// submitGuard mantık testi (Node'un yerleşik test çalıştırıcısı; ek paket yok).
// Tarayıcı DOM'u yerine küçük sahteler kullanılır: belge dinleyicisi, form öğesi ve $wire.
import { test, beforeEach } from 'node:test'
import assert from 'node:assert/strict'
import { submitGuard } from '../../resources/js/filament/submit-guard.js'

class FakeForm {}
globalThis.HTMLFormElement = FakeForm

let submitListener
let interceptors
let guard
let form

beforeEach(() => {
    submitListener = null
    interceptors = []

    globalThis.document = {
        addEventListener(type, listener, options) {
            assert.equal(type, 'submit')
            assert.equal(options.capture, true, 'Livewire dinleyicisinden önce çalışmak için capture olmalı')
            submitListener = listener
        },
    }

    form = new FakeForm()
    const attributes = new Set()

    guard = submitGuard({ action: 'save', failsafeMs: 50 })
    guard.$el = {
        contains: (node) => node === form,
        setAttribute: (name) => attributes.add(name),
        removeAttribute: (name) => attributes.delete(name),
        attributes,
    }
    guard.$wire = {
        $interceptMessage(action, callback) {
            assert.equal(action, 'save')
            interceptors.push(callback)

            return () => {}
        },
    }
    guard.init()
})

function submit(target = form) {
    const event = { target, prevented: false, stopped: false }
    event.preventDefault = () => { event.prevented = true }
    event.stopImmediatePropagation = () => { event.stopped = true }
    submitListener(event)

    return event
}

/** Livewire'ın bir mesajı başlatıp bitirmesini taklit eder; verilen olayla (finish, cancel, skipped) biter. */
function livewireMessage() {
    const handlers = {}
    interceptors.forEach((callback) => callback({
        onFinish: (cb) => { handlers.finish = cb },
        onCancel: (cb) => { handlers.cancel = cb },
        onSkipped: (cb) => { handlers.skipped = cb },
    }))

    return handlers
}

test('ilk gönderim geçer, istek sürerken ikinci gönderim kesilir', () => {
    const message = livewireMessage()

    const first = submit()
    assert.equal(first.prevented, false)
    assert.equal(first.stopped, false)
    assert.equal(guard.busy, true)
    assert.ok(guard.$el.attributes.has('data-submit-guard-busy'))

    const second = submit()
    assert.equal(second.prevented, true)
    assert.equal(second.stopped, true, 'Olay Livewire dinleyicisine ulaşmamalı')

    message.finish()
    guard.destroy()
})

test('istek bitince (başarı veya hata) yeni gönderim yapılabilir', () => {
    const message = livewireMessage()
    submit()

    message.finish()
    assert.equal(guard.busy, false)
    assert.equal(guard.$el.attributes.has('data-submit-guard-busy'), false)

    assert.equal(submit().prevented, false)
    guard.destroy()
})

test('iptal edilen veya atlanan istek kilidi açar', () => {
    let message = livewireMessage()
    submit()
    message.cancel()
    assert.equal(guard.busy, false)

    message = livewireMessage()
    submit()
    message.skipped()
    assert.equal(guard.busy, false)
    guard.destroy()
})

test('başka bir forma gönderim etkilenmez', () => {
    submit()
    const other = submit(new FakeForm())
    assert.equal(other.prevented, false)
    guard.destroy()
})

test('istek hiç başlamazsa kilit güvenlik süresi sonunda açılır', async () => {
    submit()
    await new Promise((resolve) => setTimeout(resolve, 80))
    assert.equal(guard.busy, false)
    guard.destroy()
})
