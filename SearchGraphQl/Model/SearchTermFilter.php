<?php
/**
 * Copyright © BradSearch. All rights reserved.
 */
declare(strict_types=1);

namespace BradSearch\SearchGraphQl\Model;

/**
 * Detects search terms that are URL paths or file names rather than product searches.
 */
class SearchTermFilter
{
    private const JUNK_PATTERNS = [
        '~\.x?html?(?=[?#/]|$)~i',
        '~\.(php\d?|aspx?|jsp|cgi|xml|json|js|css|png|jpe?g|gif|svg|webp|ico|pdf|txt|zip)$~i',
        '~://~',
        '~(^|[^a-z0-9])www\.~i',
    ];

    /**
     * @param string $searchTerm
     * @return bool
     */
    public function isJunk(string $searchTerm): bool
    {
        $searchTerm = trim($searchTerm);

        if ($searchTerm === '') {
            return false;
        }

        foreach (self::JUNK_PATTERNS as $pattern) {
            if (preg_match($pattern, $searchTerm) === 1) {
                return true;
            }
        }

        return false;
    }
}
