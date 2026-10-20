// Sol menüde arama (CDN yok; app.js üzerinden yüklenir). Türkçe karakter ve büyük/küçük harf önemsiz:
// "urun" → "Ürünler", "is emri" → "İş emirleri". Eşleşen öğe daraltılmış bir gruptaysa grup geçici açılır,
// arama temizlenince eski hâline döner. Enter ilk sonuca gider, Esc aramayı temizler.

export function normalizeForSearch(text) {
    return String(text ?? '')
        .toLocaleLowerCase('tr')
        .replace(/ı/g, 'i')
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .replace(/\s+/g, ' ')
        .trim()
}

// Sesli harfler atılmış iskelet: Türkçedeki ses düşmesini (emir → emri, ağız → ağzı) tolere etmek için.
const skeleton = (text) => text.replace(/[aeiou]/g, '')

/**
 * Sorgudaki her kelime etikette geçiyorsa eşleşir; boş sorgu her şeyle eşleşir. 4 harf ve üstü kelimede sesli harfler
 * yok sayılarak da karşılaştırılır ("iş emri" → "İş emirleri"); kısa kelimede bu esneklik yoktur (her şey eşleşmesin).
 */
export function matchesQuery(label, query) {
    const terms = normalizeForSearch(query).split(' ').filter(Boolean)
    const haystack = normalizeForSearch(label)

    return terms.every((term) => haystack.includes(term) || (term.length >= 4 && skeleton(haystack).includes(skeleton(term))))
}

export function navSearch() {
    return {
        query: '',
        opened: [],

        filter() {
            const sidebar = this.$root.closest('.fi-sidebar') ?? document
            const store = window.Alpine.store('sidebar')
            const searching = normalizeForSearch(this.query) !== ''

            sidebar.querySelectorAll('.fi-sidebar-group').forEach((group) => {
                let visible = 0

                group.querySelectorAll('.fi-sidebar-item').forEach((item) => {
                    const label = item.querySelector('.fi-sidebar-item-label')?.textContent ?? ''
                    const show = matchesQuery(label, this.query)
                    item.style.display = show ? '' : 'none'
                    visible += show ? 1 : 0
                })

                group.style.display = visible > 0 ? '' : 'none'

                const name = group.dataset.groupLabel
                if (searching && visible > 0 && name && store?.groupIsCollapsed?.(name)) {
                    store.toggleCollapsedGroup(name)
                    this.opened.push(name)
                }
            })

            if (! searching) {
                this.opened.forEach((name) => store?.groupIsCollapsed?.(name) || store?.toggleCollapsedGroup(name))
                this.opened = []
            }
        },

        clear() {
            this.query = ''
            this.filter()
        },

        openFirst() {
            const sidebar = this.$root.closest('.fi-sidebar') ?? document
            const first = [...sidebar.querySelectorAll('.fi-sidebar-item')].find((item) => item.style.display !== 'none')
            first?.querySelector('a')?.click()
        },
    }
}
