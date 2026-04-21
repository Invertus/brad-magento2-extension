<?php
/**
 * Copyright © BradSearch. All rights reserved.
 */
declare(strict_types=1);

namespace BradSearch\SearchGraphQl\Api\Data;

interface PriceTupleInterface
{
    public function getRegularPrice(): MoneyInterface;

    public function getFinalPrice(): MoneyInterface;

    public function getFinalPriceExclTax(): MoneyInterface;
}
