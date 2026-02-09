<?php
/**
 * Copyright © BradSearch. All rights reserved.
 */
declare(strict_types=1);

namespace BradSearch\SearchGraphQl\Model;

/**
 * Extracts and validates mm_popularity attribute components
 *
 * Expected format: {InStock}{Sold}{HasImg}{AmazonTerms} = 8 characters total
 * - Position 1:   O or I (stock status)
 * - Position 2-4: 000-999 (sold count, reversed)
 * - Position 5:   N or I (has image)
 * - Position 6-8: 000-999 or NUL (amazon terms)
 *
 * For invalid formats, returns default values (lowest priority in sorting).
 */
class PopularityExtractor
{
    private const DEFAULT_POPULARITY = 'O999N999';
    private const EXPECTED_LENGTH = 8;

    /**
     * Extract popularity components from mm_popularity value
     *
     * @param string|null $mmPopularity
     * @return array{sort_popularity: string, sort_popularity_sales: int, is_valid: bool}
     */
    public function extract(?string $mmPopularity): array
    {
        $value = $mmPopularity ?? self::DEFAULT_POPULARITY;

        if (!$this->isValidFormat($value)) {
            return [
                'sort_popularity' => self::DEFAULT_POPULARITY,
                'sort_popularity_sales' => (int) substr(self::DEFAULT_POPULARITY, 1, 3),
                'is_valid' => false,
            ];
        }

        return [
            'sort_popularity' => $value,
            'sort_popularity_sales' => (int) substr($value, 1, 3),
            'is_valid' => true,
        ];
    }

    /**
     * Get sort_popularity value with validation
     *
     * @param string|null $mmPopularity
     * @return string
     */
    public function getSortPopularity(?string $mmPopularity): string
    {
        return $this->extract($mmPopularity)['sort_popularity'];
    }

    /**
     * Get sort_popularity_sales value with validation
     *
     * @param string|null $mmPopularity
     * @return int
     */
    public function getSortPopularitySales(?string $mmPopularity): int
    {
        return $this->extract($mmPopularity)['sort_popularity_sales'];
    }

    /**
     * Validate mm_popularity format
     *
     * @param string $value
     * @return bool
     */
    private function isValidFormat(string $value): bool
    {
        if (strlen($value) !== self::EXPECTED_LENGTH) {
            return false;
        }

        // Position 1: O or I (stock status)
        if (!in_array($value[0], ['O', 'I'], true)) {
            return false;
        }

        // Position 2-4: numeric (sold count)
        if (!ctype_digit(substr($value, 1, 3))) {
            return false;
        }

        // Position 5: N or I (has image)
        if (!in_array($value[4], ['N', 'I'], true)) {
            return false;
        }

        // Position 6-8: numeric or NUL (amazon terms)
        $amazonPart = substr($value, 5, 3);
        if ($amazonPart !== 'NUL' && !ctype_digit($amazonPart)) {
            return false;
        }

        return true;
    }
}
