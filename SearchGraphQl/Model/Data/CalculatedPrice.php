<?php
/**
 * Copyright © BradSearch. All rights reserved.
 */
declare(strict_types=1);

namespace BradSearch\SearchGraphQl\Model\Data;

use BradSearch\SearchGraphQl\Api\Data\CalculatedPriceInterface;
use BradSearch\SearchGraphQl\Api\Data\PriceTupleInterface;

final class CalculatedPrice implements CalculatedPriceInterface
{
    private PriceTupleInterface $minimumPrice;
    private PriceTupleInterface $maximumPrice;

    public function __construct(PriceTupleInterface $minimumPrice, PriceTupleInterface $maximumPrice)
    {
        $this->minimumPrice = $minimumPrice;
        $this->maximumPrice = $maximumPrice;
    }

    public function getMinimumPrice(): PriceTupleInterface
    {
        return $this->minimumPrice;
    }

    public function getMaximumPrice(): PriceTupleInterface
    {
        return $this->maximumPrice;
    }
}
