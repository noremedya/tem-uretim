<?php

use Illuminate\Support\Facades\DB;

/*
 * Tek kolonlu her foreign key kolonu bir index'in ilk kolonudur (CLAUDE.md bölüm 4: filtrelenen alanlara index).
 * Yeni bir foreign key eklendiğinde index'i de eklenmezse bu test hata verir.
 */
it('her foreign key kolonunun index i vardır', function () {
    $missing = DB::select(<<<'SQL'
        SELECT c.conrelid::regclass::text AS table_name, a.attname AS column_name
        FROM pg_constraint c
        JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = c.conkey[1]
        WHERE c.contype = 'f'
          AND array_length(c.conkey, 1) = 1
          AND NOT EXISTS (
              SELECT 1 FROM pg_index i WHERE i.indrelid = c.conrelid AND i.indkey[0] = c.conkey[1]
          )
        ORDER BY 1, 2
        SQL);

    expect(array_map(fn ($row) => "{$row->table_name}.{$row->column_name}", $missing))->toBe([]);
});
