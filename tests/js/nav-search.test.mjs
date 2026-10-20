import test from 'node:test'
import assert from 'node:assert/strict'
import { matchesQuery, normalizeForSearch } from '../../resources/js/filament/nav-search.js'

test('Türkçe karakter ve büyük/küçük harf önemsiz', () => {
    assert.equal(normalizeForSearch('  İŞ Emirleri  '), 'is emirleri')
    assert.equal(normalizeForSearch('Ürünler'), 'urunler')
    assert.equal(normalizeForSearch('ÇEK ve Senetler'), 'cek ve senetler')
    assert.equal(normalizeForSearch('Döviz kurları'), 'doviz kurlari')
})

test('her kelime etikette geçmeli; boş sorgu her şeyle eşleşir', () => {
    assert.ok(matchesQuery('Ürünler', 'urun'))
    assert.ok(matchesQuery('İş emirleri', 'is em'))
    assert.ok(matchesQuery('Vadesi geçmiş alacaklar', 'alacak vade'))
    assert.ok(! matchesQuery('Ürünler', 'motor'))
    // Ses düşmesi: "emri" → "emirleri".
    assert.ok(matchesQuery('İş emirleri', 'iş emri'))
    assert.ok(matchesQuery('Stok Hareketleri', 'hareket'))
    // Kısa kelimede iskelet eşleşmesi yok: "is" her "s" içeren etiketle eşleşmemeli.
    assert.ok(! matchesQuery('Siparişler', 'is emri'))
    assert.ok(! matchesQuery('Kasa ve bankalar', 'ksa'))
    assert.ok(matchesQuery('Siparişler', ''))
    assert.ok(matchesQuery('Siparişler', '   '))
})
