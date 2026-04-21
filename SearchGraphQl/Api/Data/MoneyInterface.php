<?php
/**
 * Copyright © BradSearch. All rights reserved.
 */
declare(strict_types=1);

namespace BradSearch\SearchGraphQl\Api\Data;

interface MoneyInterface
{
    public function getValue(): float;

    public function getCurrency(): string;
}
