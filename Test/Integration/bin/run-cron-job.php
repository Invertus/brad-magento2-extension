<?php
/**
 * Runs one cron job the way Magento's cron does: the job is looked up in the merged crontab.xml config,
 * created by the object manager in the crontab area and called with a schedule. Fails when the job is not
 * registered, so a test that runs a job also proves its crontab.xml entry.
 *
 * Usage: php run-cron-job.php <group> <job name>
 */
declare(strict_types=1);

use Magento\Cron\Model\ConfigInterface;
use Magento\Cron\Model\Schedule;
use Magento\Framework\App\Area;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\State;
use Magento\Framework\ObjectManager\ConfigLoaderInterface;

if ($argc !== 3) {
    fwrite(STDERR, "Usage: php run-cron-job.php <group> <job name>\n");
    exit(2);
}
[, $group, $jobName] = $argv;

require '/var/www/html/app/bootstrap.php';

$objectManager = Bootstrap::create(BP, $_SERVER)->getObjectManager();
$objectManager->get(State::class)->setAreaCode(Area::AREA_CRONTAB);
$objectManager->configure($objectManager->get(ConfigLoaderInterface::class)->load(Area::AREA_CRONTAB));

$job = $objectManager->get(ConfigInterface::class)->getJobs()[$group][$jobName] ?? null;
if (!isset($job['instance'], $job['method'])) {
    fwrite(STDERR, "Cron job $group/$jobName is not registered\n");
    exit(1);
}

$schedule = $objectManager->create(Schedule::class)
    ->setJobCode($jobName)
    ->setStatus(Schedule::STATUS_RUNNING);
$objectManager->create($job['instance'])->{$job['method']}($schedule);
echo "Ran $group/$jobName ({$job['instance']}::{$job['method']})\n";
