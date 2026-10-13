<?php

namespace Tests\Support;

/**
 * The exception the SnapshotFaultInjector throws at the armed point and
 * occurrence (research D21). It stands in for a SIGKILLed worker: the
 * test catches it, does the "aftermath" work, then calls the injector's
 * restore() to return the database to exactly the snapshot.
 */
class InjectedFault extends \RuntimeException
{
}
