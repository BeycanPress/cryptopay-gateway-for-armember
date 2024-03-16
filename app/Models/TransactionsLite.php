<?php

declare(strict_types=1);

namespace BeycanPress\CryptoPay\ARM\Models;

use BeycanPress\CryptoPayLite\Models\AbstractTransaction;

class TransactionsLite extends AbstractTransaction
{
    public string $addon = 'arm';

    /**
     * @return void
     */
    public function __construct()
    {
        parent::__construct('arm_transaction');
    }
}
