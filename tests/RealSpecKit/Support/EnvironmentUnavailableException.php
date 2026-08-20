<?php

namespace Tests\RealSpecKit\Support;

/**
 * Thrown by SpecKitCliFixtureBuilder::assertAvailable() when the real
 * `specify` CLI cannot be resolved/run at all in this environment (network
 * egress, missing uv/uvx, CLI build failure — research.md D7).
 *
 * Deliberately its own type, not a bare \RuntimeException, so a caller
 * outside SpecKitCliFixtureBuilder can `catch (EnvironmentUnavailableException)`
 * and distinguish "environment can't run this suite" from any other failure.
 */
final class EnvironmentUnavailableException extends \RuntimeException
{
}
