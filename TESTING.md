# Testing

This package comes with an extensive automated testing suite, which is automatically run for every
pull request in GitHub. 

## CodeSniffer

This tool helps to find and fix code style issues in this package. 
To run CodeSniffer tests first ensure that you installed the required 
packages using `composer install`. Then run `composer test:style`.


To run the automated fixing of most of the styling issues, you can also execute `composer fix:style`.

## PHPStan

This tool helps to find obvious bugs in your PHP code.
To run PHPStan first ensure that you installed the required
packages using `composer install`. Then run `composer test:stan`.

## Unit Testing

To run unit tests first ensure that you installed the required
packages using `composer install`. Then run `composer test:unit`.

## Functional Testing

To run functional tests on your local machine, install this package in a fresh Neos installation. 
Instructions on how to do that can be found here: https://docs.neos.io/guide/installation-development-setup

Once you have done that, you can run the functional tests by executing the following command *in the folder of the Neos installation*:

```shell
FLOW_CONTEXT=Testing bin/phpunit --colors --stop-on-failure -c DistributionPackages/Sitegeist.LostInTranslation/Tests/FunctionalTests.xml --testsuite "LostInTranslation" --verbose
```
