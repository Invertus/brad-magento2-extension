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
        '~\.(php\d?|aspx?|jsp|cgi|xml|png|jpe?g|gif|svg|webp|ico|pdf)$~i',
        '~://~',
        '~(^|[^a-z0-9])www\.~i',
        '~^/?(catalog|catalogsearch|checkout|customer|sales|wishlist|review|cms|contact|newsletter|sendfriend|productalert|paypal|emailreport|erpext|adeoweb_autorelated)/~i',
        '~(^|/)\.(env|git)\b|wp-(json|admin|content|includes|login)|^/?(wp|wordpress)/?$~i',
        '~(^|/)(proc/self|var/run/secrets|\.aws|\.svn|\.terraform|_debugbar|telescope|storage/logs)(/|$)'
        . '|\.(py|key|log|toml|ya?ml|env|prisma|tfstate|otf|ttf|woff2?)$'
        . '|^/?api/|^/?(site|blog|news|press|admin)/$|^/?eml/~i',
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
