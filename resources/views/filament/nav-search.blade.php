{{-- Sol menüde arama (resources/js/filament/nav-search.js). --}}
<div x-data="navSearch()" class="px-1 pb-2">
    <label class="sr-only" for="nav-search-input">Menüde ara</label>
    <input
        id="nav-search-input"
        type="search"
        x-model="query"
        x-on:input="filter()"
        x-on:keydown.escape.prevent="clear()"
        x-on:keydown.enter.prevent="openFirst()"
        placeholder="Menüde ara…"
        autocomplete="off"
        class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/30 dark:border-white/10 dark:bg-white/5"
    >
</div>
