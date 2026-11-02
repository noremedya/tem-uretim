<?php

return [
    /*
    | Hareketsiz stok: aktif, bakiyesi > 0 olan ve bu kadar gündür hiç hareket görmeyen parçalar
    | haftalık özetle bildirilir (stock:idle-report, CLAUDE.md bölüm 5).
    */
    'idle_days' => 90,

    /*
    | Ürün deposu: reçetedeki her ürün kendi deposunu alır (CLAUDE.md bölüm 5 "Stok"). Firma ayarıdır.
    */
    'part_warehouses' => (bool) env('STOCK_PART_WAREHOUSES', false),
];
