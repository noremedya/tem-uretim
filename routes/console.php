<?php

use Illuminate\Support\Facades\Schedule;

// Stok tutarlılık kontrolü (CLAUDE.md bölüm 5). Saat dilimi: APP_TIMEZONE.
Schedule::command('stock:reconcile')->dailyAt('03:00')->withoutOverlapping();

// Hareketsiz stok haftalık özeti (CLAUDE.md bölüm 5): pazartesi sabahı.
Schedule::command('stock:idle-report')->weeklyOn(1, '08:00')->withoutOverlapping();
