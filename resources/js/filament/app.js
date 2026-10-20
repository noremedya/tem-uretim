// Filament paneline Vite ile yüklenen ortak Alpine bileşenleri (CDN yok).
import { navSearch } from './nav-search'
import { quickScan } from './quick-scan'
import { submitGuard } from './submit-guard'

const register = (Alpine) => {
    Alpine.data('submitGuard', submitGuard)
    Alpine.data('quickScan', quickScan)
    Alpine.data('navSearch', navSearch)
}

// Livewire (ve Alpine) bu modülden önce yüklenir, DOMContentLoaded'da başlar. Her iki sıraya karşı güvenli:
if (window.Alpine) {
    register(window.Alpine)
} else {
    document.addEventListener('alpine:init', () => register(window.Alpine))
}
