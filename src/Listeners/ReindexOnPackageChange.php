<?php

namespace ClarionApp\LlmClient\Listeners;

use ClarionApp\Backend\Events\InstallComposerPackageEvent;
use ClarionApp\Backend\Events\UninstallComposerPackageEvent;
use ClarionApp\LlmClient\Jobs\ReindexOperationsJob;

class ReindexOnPackageChange
{
    public function __construct()
    {
        //
    }

    public function handle(InstallComposerPackageEvent|UninstallComposerPackageEvent $event): void
    {
        // Dispatch async reindex job
        ReindexOperationsJob::dispatch();
    }
}
