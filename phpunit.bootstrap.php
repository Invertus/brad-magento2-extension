<?php
/**
 * Bootstrap for running the unit tests outside a Magento install.
 *
 * A real Magento writes *Factory classes to generated/code. Here Magento's
 * own test autoloader writes them to a temp directory when a test needs one.
 */
declare(strict_types=1);

use Magento\Framework\Code\Generator\Io;
use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\TestFramework\Unit\Autoloader\FactoryGenerator;
use Magento\Framework\TestFramework\Unit\Autoloader\GeneratedClassesAutoloader;

require __DIR__ . '/vendor/autoload.php';

$generatedClassesAutoloader = new GeneratedClassesAutoloader(
    [new FactoryGenerator()],
    new Io(new File(), sys_get_temp_dir() . '/bradsearch-unit-tests-generated')
);
spl_autoload_register([$generatedClassesAutoloader, 'load']);
