/**
 * submitGuard — Livewire isteği sürerken formun ikinci kez gönderilmesini engeller.
 *
 * Çift tıklama, art arda Enter ve barkod okuyucunun gönderdiği Enter için tekrar kullanılabilir.
 * Livewire, istek sürerken gelen ikinci gönderimi bekletip ilk yanıttan sonra gönderir; bu guard o
 * ikinci gönderimi tarayıcıda keser, hiç gönderilmez. Sunucu tarafındaki koruma (gönderim anahtarı)
 * ayrıca geçerlidir; bu yalnızca kullanıcı deneyimi içindir.
 *
 * Kullanım (formun kendisine veya formu içeren öğeye):
 *   x-data="submitGuard({ action: 'save' })"
 *
 * - action: Kilidi açacak Livewire metodu (wire:submit="save" ise 'save'). Verilmezse bileşenin herhangi
 *   bir isteğinin bitişi kilidi açar.
 * - failsafeMs: Beklenmedik durumda (ör. istek hiç başlamadıysa) kilidin kendiliğinden açılacağı süre.
 *
 * Kilitliyken öğeye data-submit-guard-busy özniteliği eklenir (görsel geri bildirim için).
 */
export function submitGuard({ action = null, failsafeMs = 30000 } = {}) {
    return {
        busy: false,

        init() {
            this.listeners = new AbortController()

            // Belge seviyesinde, yakalama (capture) aşamasında dinlenir: Livewire'ın wire:submit
            // dinleyicisinden önce çalışır ve gerekirse olayı ona ulaşmadan durdurur.
            document.addEventListener(
                'submit',
                (event) => {
                    if (! (event.target instanceof HTMLFormElement) || ! this.$el.contains(event.target)) {
                        return
                    }

                    if (this.busy) {
                        event.preventDefault()
                        event.stopImmediatePropagation()

                        return
                    }

                    this.lock()
                },
                { capture: true, signal: this.listeners.signal },
            )

            const onMessage = ({ onFinish, onCancel, onSkipped }) => {
                // onFinish başarı, doğrulama hatası, sunucu hatası ve ağ hatasında çağrılır.
                onFinish(() => this.release())
                onCancel(() => this.release())
                onSkipped(() => this.release())
            }

            this.stopIntercepting = action
                ? this.$wire.$interceptMessage(action, onMessage)
                : this.$wire.$interceptMessage(onMessage)
        },

        lock() {
            this.busy = true
            this.$el.setAttribute('data-submit-guard-busy', '')

            clearTimeout(this.failsafe)
            this.failsafe = setTimeout(() => this.release(), failsafeMs)
        },

        release() {
            this.busy = false
            this.$el.removeAttribute('data-submit-guard-busy')

            clearTimeout(this.failsafe)
        },

        destroy() {
            this.listeners?.abort()
            this.stopIntercepting?.()
            clearTimeout(this.failsafe)
        },
    }
}
