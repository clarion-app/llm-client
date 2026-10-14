<?php

namespace Tests\Unit\Resume;

use Tests\Integration\AssembledSystemTestCase;

/**
 * The same schema assertions run against the assembled system's hand
 * declared copy (the spec 130 shadowing lesson: the two copies must be
 * updated together or tests silently run against the old shape). Red
 * now: the columns do not exist in that copy yet.
 */
class HandDeclaredResumeSchemaAssembledTest extends AssembledSystemTestCase
{
    use AssertsResumeSchema;
}
