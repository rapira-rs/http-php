<?php

declare(strict_types=1);

use Testo\Application\Config\FinderConfig;
use Testo\Application\Config\SuiteConfig;

/**
 * Test suites for the `rapira/http` package, merged into the root {@see testo.php}.
 */
return [
    new SuiteConfig(
        name: 'Http: Unit',
        location: new FinderConfig(
            include: [__DIR__ . '/Unit'],
        ),
    ),
];
