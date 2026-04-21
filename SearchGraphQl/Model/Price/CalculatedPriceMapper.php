<?php
/**
 * Copyright © BradSearch. All rights reserved.
 */
declare(strict_types=1);

namespace BradSearch\SearchGraphQl\Model\Price;

use BradSearch\SearchGraphQl\Api\Data\CalculatedPriceInterface;
use BradSearch\SearchGraphQl\Api\Data\MoneyInterface;
use BradSearch\SearchGraphQl\Api\Data\PriceTupleInterface;

/**
 * Maps CalculatedPriceInterface DTOs to the nested array shape GraphQL
 * field resolvers expect for Money scalars (value + currency).
 */
class CalculatedPriceMapper
{
    /**
     * @return array{
     *     minimum_price: array{
     *         regular_price: array{value: float, currency: string},
     *         final_price: array{value: float, currency: string},
     *         final_price_excl_tax: array{value: float, currency: string}
     *     },
     *     maximum_price: array{
     *         regular_price: array{value: float, currency: string},
     *         final_price: array{value: float, currency: string},
     *         final_price_excl_tax: array{value: float, currency: string}
     *     }
     * }
     */
    public function toGraphQlArray(CalculatedPriceInterface $price): array
    {
        return [
            'minimum_price' => $this->tupleToArray($price->getMinimumPrice()),
            'maximum_price' => $this->tupleToArray($price->getMaximumPrice()),
        ];
    }

    /**
     * @return array{
     *     regular_price: array{value: float, currency: string},
     *     final_price: array{value: float, currency: string},
     *     final_price_excl_tax: array{value: float, currency: string}
     * }
     */
    private function tupleToArray(PriceTupleInterface $tuple): array
    {
        return [
            'regular_price' => $this->moneyToArray($tuple->getRegularPrice()),
            'final_price' => $this->moneyToArray($tuple->getFinalPrice()),
            'final_price_excl_tax' => $this->moneyToArray($tuple->getFinalPriceExclTax()),
        ];
    }

    /**
     * @return array{value: float, currency: string}
     */
    private function moneyToArray(MoneyInterface $money): array
    {
        return [
            'value' => $money->getValue(),
            'currency' => $money->getCurrency(),
        ];
    }
}
