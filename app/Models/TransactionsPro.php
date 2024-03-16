<?php

declare(strict_types=1);

namespace BeycanPress\CryptoPay\ARM\Models;

use BeycanPress\CryptoPay\Models\AbstractTransaction;

class TransactionsPro extends AbstractTransaction
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
