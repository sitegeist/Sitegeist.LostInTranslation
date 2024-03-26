<?php
namespace Neos\Flow\Build;

/*
 * This file is part of the Neos Flow build system.
 *
 * (c) Contributors of the Neos Project - www.neos.io
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

$composerAutoloader = __DIR__ . '/../Packages/Libraries/autoload.php';
if(!file_exists($composerAutoloader)) {
    exit(PHP_EOL . 'Neos Flow Bootstrap Error: The unit test bootstrap requires the autoloader file created at install time by Composer. Looked for "' . $composerAutoloader . '" without success.');
}
require_once($composerAutoloader);

if (!class_exists('org\bovigo\vfs\vfsStream')) {
    exit(PHP_EOL . 'Neos Flow Bootstrap Error: The unit test bootstrap requires vfsStream to be installed. Try "composer update --dev".' . PHP_EOL . PHP_EOL);
}

spl_autoload_register('Neos\Flow\Build\loadClassForTesting');

$_SERVER['FLOW_ROOTPATH'] = __DIR__ . '/../';
$_SERVER['FLOW_WEBPATH'] = __DIR__ . '/../Web/';
new \Neos\Flow\Core\Bootstrap('Production');

require_once(FLOW_PATH_FLOW . 'Tests/BaseTestCase.php');
require_once(FLOW_PATH_FLOW . 'Tests/UnitTestCase.php');
require_once(FLOW_PATH_FLOW . 'Classes/Error/Debugger.php');

/**
 * A simple class loader that deals with the Framework classes and is intended
 * for use with unit tests executed by PHPUnit.
 *
 * @param string $className
 * @return void
 */
function loadClassForTesting($className) {
    $classNameParts = explode('\\', $className);
    if (!is_array($classNameParts)) {
        return;
    }

    foreach (new \DirectoryIterator(__DIR__ . '/../Packages') as $fileInfo) {
        if (!$fileInfo->isDir() || $fileInfo->isDot() || $fileInfo->getFilename() === 'Libraries') continue;

        $classFilePathAndName = $fileInfo->getPathname() . '/';
        foreach ($classNameParts as $index => $classNamePart) {
            $classFilePathAndName .= $classNamePart;
            if (file_exists($classFilePathAndName . '/Classes')) {
                $packageKeyParts = array_slice($classNameParts, 0, $index + 1);
                $classesOrTests = ($classNameParts[$index + 1] === 'Tests' && isset($classNameParts[$index + 2]) && $classNameParts[$index + 2] === 'Unit') ? '/' : '/Classes/' . implode('/', $packageKeyParts) . '/';
                $classesFilePathAndName = $classFilePathAndName . $classesOrTests . implode('/', array_slice($classNameParts, $index + 1)) . '.php';
                if (is_file($classesFilePathAndName)) {
                    require($classesFilePathAndName);
                    break;
                }
            }
            $classFilePathAndName .= '.';
        }
    }
}
