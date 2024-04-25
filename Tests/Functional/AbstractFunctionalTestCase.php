<?php

namespace Sitegeist\LostInTranslation\Tests\Functional;

use Neos\Flow\Tests\FunctionalTestCase;

abstract class AbstractFunctionalTestCase extends FunctionalTestCase
{
    protected static $testablePersistenceEnabled = true;
}
