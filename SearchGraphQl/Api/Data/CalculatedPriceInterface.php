<?php
/**
 * Copyright © BradSearch. All rights reserved.
 */
declare(strict_types=1);

namespace BradSearch\SearchGraphQl\Api\Data;

interface CalculatedPriceInterface
{
    public function getMinimumPrice(): PriceTupleInterface;

    public function getMaximumPrice(): PriceTupleInterface;
}
