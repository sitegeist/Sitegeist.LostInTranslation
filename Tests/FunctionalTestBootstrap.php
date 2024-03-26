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

$composerAutoloader = __DIR__ . '/../../../Packages/Libraries/autoload.php';
if(!file_exists($composerAutoloader)) {
    exit(PHP_EOL . 'Neos Flow Bootstrap Error: The functional test bootstrap requires the autoloader file created at install time by Composer. Looked for "' . $composerAutoloader . '" without success.');
}
require_once($composerAutoloader);

$context = isset($_SERVER['FLOW_CONTEXT']) ? $_SERVER['FLOW_CONTEXT'] : 'Testing';

if (preg_match('/^(?:Testing|Testing\/.+)$/', $context) !== 1) {
	die(sprintf('The context "%s" is not allowed. Only "Testing" context or one of its subcontexts "Testing/*" is allowed.', $context));
}

$_SERVER['FLOW_ROOTPATH'] = __DIR__ . '/../../../';
$_SERVER['FLOW_WEBPATH'] = __DIR__ . '/../../../Web/';

if (DIRECTORY_SEPARATOR === '/') {
	// Fixes an issue with the autoloader, see FLOW-183
	shell_exec('cd ' . escapeshellarg($_SERVER['FLOW_ROOTPATH']) . ' && FLOW_CONTEXT=' . escapeshellarg($context) . ' ./flow neos.flow:cache:warmup');
}

require_once($_SERVER['FLOW_ROOTPATH'] . 'Packages/Framework/Neos.Flow/Classes/Core/Bootstrap.php');
$bootstrap = new \Neos\Flow\Core\Bootstrap($context);
$bootstrap->setPreselectedRequestHandlerClassName(\Neos\Flow\Tests\FunctionalTestRequestHandler::class);
$bootstrap->run();
