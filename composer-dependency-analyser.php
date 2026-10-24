<?php

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;

$config = new Configuration;

return $config
    ->disableComposerAutoloadPathScan()
    ->addPathToScan(__DIR__.'/src', isDev: false)
    ->addPathToScan(__DIR__.'/tests', isDev: true)
    ->addPathToScan(__DIR__.'/workbench', isDev: true)
    ->addPathRegexToExclude('~Test(Cases)?\.php$~')
    ->ignoreErrorsOnPackage('aryeo/tooling-laravel', [ErrorType::UNUSED_DEPENDENCY])
    ->ignoreErrorsOnPackage('aryeo/eloquent-concurrency', [ErrorType::UNUSED_DEPENDENCY]);
