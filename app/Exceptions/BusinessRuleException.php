<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * İş kuralı ihlali. Mesajı kullanıcıya doğrudan gösterilecek Türkçe metindir.
 */
class BusinessRuleException extends RuntimeException {}
