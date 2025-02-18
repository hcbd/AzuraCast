<?php

declare(strict_types=1);

namespace App\Sync\Task;

use App\Doctrine\ReloadableEntityManagerInterface;
use App\Entity\Repository\SettingsRepository;
use App\Radio\Adapters;
use App\Radio\CertificateLocator;
use Psr\Log\LoggerInterface;

class ReloadFrontendAfterSslChangeTask extends AbstractTask
{
    public function __construct(
        protected Adapters $adapters,
        protected SettingsRepository $settingsRepo,
        ReloadableEntityManagerInterface $em,
        LoggerInterface $logger
    ) {
        parent::__construct($em, $logger);
    }

    public function run(bool $force = false): void
    {
        $threshold = $this->settingsRepo->readSettings()->getSyncLongLastRun();

        $certs = CertificateLocator::findCertificate();

        $pathsToCheck = [
            $certs->getCertPath(),
            $certs->getKeyPath(),
        ];

        $certsUpdated = false;
        foreach ($pathsToCheck as $path) {
            if (file_exists($path) && filemtime($path) > $threshold) {
                $certsUpdated = true;
                break;
            }
        }

        if ($certsUpdated) {
            $this->logger->info('SSL certificates have updated; hot-reloading stations that support it.');

            foreach ($this->iterateStations() as $station) {
                $frontend = $this->adapters->getFrontendAdapter($station);
                if ($frontend->supportsReload()) {
                    $frontend->reload($station);
                }
            }
        } else {
            $this->logger->info('SSL certificates have not updated.');
        }
    }
}
