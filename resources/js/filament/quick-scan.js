/**
 * quickScan — Hızlı İşlem sayfasının okuma alanı (CLAUDE.md bölüm 7).
 *
 * - USB okuyucu kodu yazıp Enter gönderir; form gönderilir (submitGuard istek sürerken ikinci gönderimi keser).
 * - Aynı kod 2 saniye içinde tekrar okunursa yok sayılır (form hiç gönderilmez).
 * - Her işlemden sonra odak okuma alanına döner; sonuca göre ses ve titreşim verilir.
 * - "Kamera ile okut": @zxing/browser (yalnızca kullanılınca yüklenir; Vite ile pakete dahil, CDN yok).
 *
 * Kullanım: x-data="quickScan()" — içinde x-ref="input" olan bir form.
 */

/** Tekrar okuma süzgeci: aynı kod $windowMs içinde ikinci kez gelirse false döner. */
export function createScanFilter(windowMs = 2000, now = () => Date.now()) {
    let lastCode = null
    let lastAt = 0

    return (code) => {
        const normalized = String(code ?? '').trim().toLocaleUpperCase('tr-TR')

        if (normalized === '') {
            return false
        }

        const time = now()

        if (normalized === lastCode && time - lastAt < windowMs) {
            return false
        }

        lastCode = normalized
        lastAt = time

        return true
    }
}

/** Kısa ses (dosyasız, WebAudio). Başarı: tek yüksek bip; hata: iki düşük bip. */
export function beep(kind = 'success') {
    try {
        const AudioContext = window.AudioContext || window.webkitAudioContext
        const context = new AudioContext()
        const tones = kind === 'success' ? [[880, 0, 0.08]] : [[220, 0, 0.12], [220, 0.18, 0.12]]

        for (const [frequency, start, duration] of tones) {
            const oscillator = context.createOscillator()
            const gain = context.createGain()
            oscillator.frequency.value = frequency
            gain.gain.value = 0.15
            oscillator.connect(gain).connect(context.destination)
            oscillator.start(context.currentTime + start)
            oscillator.stop(context.currentTime + start + duration)
        }

        setTimeout(() => context.close(), 600)
    } catch {
        // Ses desteklenmiyorsa sessiz geç.
    }

    navigator.vibrate?.(kind === 'success' ? 60 : [80, 60, 80])
}

export function quickScan({ windowMs = 2000 } = {}) {
    return {
        accept: createScanFilter(windowMs),
        notice: null,
        cameraOpen: false,
        cameraError: null,
        controls: null,

        init() {
            this.focus()
        },

        /** Enter: tekrar okumaysa formu göndermeden alanı temizler. */
        onEnter(event) {
            if (! this.accept(this.$refs.input.value)) {
                event.preventDefault()
                this.$refs.input.value = ''
                this.$refs.input.dispatchEvent(new Event('input'))
            }
        },

        /** Sunucu sonucu: { status: 'success' | 'error' } */
        done(detail) {
            beep(detail?.status === 'success' ? 'success' : 'error')
            this.notice = null
            this.focus()
        },

        blocked() {
            beep('error')
            this.notice = 'İşleniyor, tekrar okutun.'
            setTimeout(() => { this.notice = null }, 2000)
        },

        focus() {
            // Açık bir pencere (işlem formu) yoksa odak okuma alanına döner.
            this.$nextTick(() => {
                if (! document.querySelector('.fi-modal-open, [aria-modal="true"]')) {
                    this.$refs.input?.focus()
                    this.$refs.input?.select()
                }
            })
        },

        async openCamera() {
            this.cameraError = null
            this.cameraOpen = true

            try {
                const { BrowserMultiFormatReader } = await import('@zxing/browser')
                const reader = new BrowserMultiFormatReader()

                this.controls = await reader.decodeFromVideoDevice(undefined, this.$refs.video, (result) => {
                    if (result) {
                        this.closeCamera()
                        this.submitCode(result.getText())
                    }
                })
            } catch (error) {
                this.cameraError = window.isSecureContext
                    ? 'Kamera açılamadı. Tarayıcıya kamera izni verin.'
                    : 'Kamera yalnızca güvenli bağlantıda (https) çalışır.'
            }
        },

        closeCamera() {
            this.controls?.stop()
            this.controls = null
            this.cameraOpen = false
            this.focus()
        },

        submitCode(code) {
            if (! this.accept(code)) {
                return
            }

            this.$refs.input.value = code
            this.$refs.input.dispatchEvent(new Event('input'))
            this.$refs.input.form.requestSubmit()
        },

        destroy() {
            this.controls?.stop()
        },
    }
}
