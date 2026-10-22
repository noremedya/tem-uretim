<?php

namespace App\Imports;

use RuntimeException;

/** @internal Kontrol veya hatalı aktarmada içe aktarma transaction'ını geri almak için. */
final class RollbackImport extends RuntimeException {}
