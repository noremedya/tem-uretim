// quickScan tekrar okuma süzgeci testi (Node'un yerleşik test çalıştırıcısı; ek paket yok).
import { test } from 'node:test'
import assert from 'node:assert/strict'
import { createScanFilter } from '../../resources/js/filament/quick-scan.js'

test('aynı kod 2 saniye içinde tekrar okunursa yok sayılır', () => {
    let now = 1000
    const accept = createScanFilter(2000, () => now)

    assert.equal(accept('TM-71-2610-00001'), true)
    now += 500
    assert.equal(accept('TM-71-2610-00001'), false)
    now += 1499
    assert.equal(accept('tm-71-2610-00001 '), false, 'Büyük/küçük harf ve boşluk önemsiz')
    now += 2000
    assert.equal(accept('TM-71-2610-00001'), true, '2 saniye sonra tekrar kabul edilir')
})

test('farklı kod hemen kabul edilir; boş okuma kabul edilmez', () => {
    let now = 0
    const accept = createScanFilter(2000, () => now)

    assert.equal(accept('HM-SAC'), true)
    now += 100
    assert.equal(accept('IE-2026-00001'), true)
    assert.equal(accept('   '), false)
    assert.equal(accept(null), false)
})

test('Türkçe büyük harf dönüşümü: "ı" ve "i" farklı kalır', () => {
    let now = 0
    const accept = createScanFilter(2000, () => now)

    assert.equal(accept('bıçak'), true)
    assert.equal(accept('BIÇAK'), false, 'tr-TR büyütmede ı → I')
    assert.equal(accept('biçak'), true, 'i → İ farklı koddur')
})
