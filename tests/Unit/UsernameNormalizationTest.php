<?php

use App\Models\User;

it('kullanıcı adını normalize eder', function (string $input, string $expected) {
    expect(User::normalizeUsername($input))->toBe($expected)
        ->and($expected)->toMatch(User::USERNAME_PATTERN);
})->with([
    ['ahmet', 'ahmet'],
    ['  Ahmet.YILMAZ ', 'ahmet.yilmaz'],
    ['şŞçÇğĞüÜöÖıİ', 'ssccgguuooii'],
    ['IŞIK', 'isik'],
    ['İsmail_Öztürk-2', 'ismail_ozturk-2'],
]);
