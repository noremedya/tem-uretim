<?php

return [
    /*
    | Hareketsiz stok: aktif, bakiyesi > 0 olan ve bu kadar gündür hiç hareket görmeyen parçalar
    | haftalık özetle bildirilir (stock:idle-report, CLAUDE.md bölüm 5).
    */
    'idle_days' => 90,
];
