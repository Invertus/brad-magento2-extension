<?php
/**
 * Copyright © BradSearch. All rights reserved.
 */
declare(strict_types=1);

namespace BradSearch\SearchGraphQl\Model\Data;

use BradSearch\SearchGraphQl\Api\Data\MoneyInterface;
use BradSearch\SearchGraphQl\Api\Data\PriceTupleInterface;

final class PriceTuple implements PriceTupleInterface
{
    private MoneyInterface $regularPrice;
    private MoneyInterface $finalPrice;
    private MoneyInterface $finalPriceExclTax;

    public function __construct(
        MoneyInterface $regularPrice,
        MoneyInterface $finalPrice,
        MoneyInterface $finalPriceExclTax
    ) {
        $this->regularPrice = $regularPrice;
        $this->finalPrice = $finalPrice;
        $this->finalPriceExclTax = $finalPriceExclTax;
    }

    public function getRegularPrice(): MoneyInterface
    {
        return $this->regularPrice;
    }

    public function getFinalPrice(): MoneyInterface
    {
        return $this->finalPrice;
    }

    public function getFinalPriceExclTax(): MoneyInterface
    {
        return $this->finalPriceExclTax;
    }
}
