<?php
/**
 * Copyright (c) BradSearch. All rights reserved.
 */
declare(strict_types=1);

namespace BradSearch\SearchGraphQl\Test\Unit\Plugin\GraphQl;

use BradSearch\SearchGraphQl\Model\Sync\FetchRecorder;
use BradSearch\SearchGraphQl\Plugin\GraphQl\FlushFetchRecords;
use Magento\Framework\App\ResponseInterface;
use Magento\GraphQl\Controller\GraphQl;
use PHPUnit\Framework\TestCase;

class FlushFetchRecordsTest extends TestCase
{
    public function testFlushesAfterTheWholeRequestAndKeepsTheResponse(): void
    {
        $recorder = $this->createMock(FetchRecorder::class);
        $recorder->expects($this->once())->method('flush');
        $response = $this->createMock(ResponseInterface::class);

        $result = (new FlushFetchRecords($recorder))->afterDispatch($this->createMock(GraphQl::class), $response);

        $this->assertSame($response, $result);
    }
}
