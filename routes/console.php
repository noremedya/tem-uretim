<?php

use Illuminate\Support\Facades\Schedule;

// Stok tutarlılık kontrolü (CLAUDE.md bölüm 5). Saat dilimi: APP_TIMEZONE.
Schedule::command('stock:reconcile')->dailyAt('03:00')->withoutOverlapping();
