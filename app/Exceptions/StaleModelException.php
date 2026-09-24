<?php

namespace App\Exceptions;

use Illuminate\Database\Eloquent\Model;

/**
 * Optimistic locking çakışması: kayıt, okunduğundan beri başka biri tarafından değiştirildi.
 */
class StaleModelException extends BusinessRuleException
{
    public function __construct(public readonly Model $model)
    {
        parent::__construct('Bu kayıt siz düzenlerken başka bir kullanıcı tarafından değiştirildi. Değişiklikleriniz kaydedilmedi; sayfayı yenileyip güncel hâli üzerinde tekrar deneyin.');
    }
}
