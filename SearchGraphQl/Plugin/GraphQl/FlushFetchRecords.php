<?php
/**
 * Copyright (c) BradSearch. All rights reserved.
 */
declare(strict_types=1);

namespace BradSearch\SearchGraphQl\Plugin\GraphQl;

use BradSearch\SearchGraphQl\Model\Sync\FetchRecorder;
use Magento\Framework\App\ResponseInterface;
use Magento\GraphQl\Controller\GraphQl;

/**
 * Nested fields such as categories are read after the bradProducts resolver returns, so fetched products
 * are checked against the changelog only once the whole GraphQL request is done.
 */
class FlushFetchRecords
{
    /**
     * @var FetchRecorder
     */
    private FetchRecorder $fetchRecorder;

    /**
     * @param FetchRecorder $fetchRecorder
     */
    public function __construct(FetchRecorder $fetchRecorder)
    {
        $this->fetchRecorder = $fetchRecorder;
    }

    /**
     * @param GraphQl $subject
     * @param ResponseInterface $result
     * @return ResponseInterface
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterDispatch(GraphQl $subject, ResponseInterface $result): ResponseInterface
    {
        $this->fetchRecorder->flush();

        return $result;
    }
}
