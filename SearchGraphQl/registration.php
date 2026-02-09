<?php
/**
 * Copyright © BradSearch. All rights reserved.
 */
declare(strict_types=1);

use Magento\Framework\Component\ComponentRegistrar;

ComponentRegistrar::register(
    ComponentRegistrar::MODULE,
    'BradSearch_SearchGraphQl',
    __DIR__
);
