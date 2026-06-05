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

## Behavioral (Behat) Testing

The Retranslation and Synchronization features are covered by Behat scenarios under
`Tests/Behavior/Features/` (`Synchronization.feature`, `StaleRetranslation/`,
`AIBasedAutoTranslation.feature`). They drive the Content Repository directly and assert against the
emitted **event stream**, so they need a running database.

> **Note:** Behat runs in CI as a **separate `behat` job** (the main `test` job covers style, stan,
> unit and functional). The Behat job spins up a MariaDB service, installs `neos/behat` +
> `neos/contentrepository-testsuite` + `neos/contentgraph-doctrinedbaladapter` into a fresh
> distribution, runs `doctrine:migrate`, then invokes Behat with the `php -d` flags below. It runs
> across the full PHP × Neos matrix (8.2/8.3 × 9.0/9.1), same as the main `test` job.

### Run from the distribution root

Run Behat from the **parent Neos distribution root**, not from inside
`DistributionPackages/Sitegeist.LostInTranslation/`. Running `composer test:behavior` from the package
directory fails, because that directory's `Packages/` does not register the package as a Flow package,
so reflection cannot find its own classes. The distribution symlinks the package into
`Packages/Application/` and provides `neos/behat` + `neos/contentrepository-testsuite`, so
`./bin/behat` exists there.

```shell
# from the Neos distribution root
FLOW_CONTEXT=Testing/Behat \
  php -d memory_limit=2G -d "error_reporting=E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED" \
  ./bin/behat -c DistributionPackages/Sitegeist.LostInTranslation/Tests/Behavior/behat.yml.dist
```

To run a single feature or scenario, append the feature file (and `--name "<scenario>"`):

```shell
FLOW_CONTEXT=Testing/Behat \
  php -d memory_limit=2G -d "error_reporting=E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED" \
  ./bin/behat -c DistributionPackages/Sitegeist.LostInTranslation/Tests/Behavior/behat.yml.dist \
  DistributionPackages/Sitegeist.LostInTranslation/Tests/Behavior/Features/Synchronization.feature
```

The two `php -d` flags are explained under Prerequisites below.

### Prerequisites

1. **Database.** A MySQL/MariaDB reachable by the `Testing/Behat` context with a
   `flow_functional_testing` database that has the schema applied. After creating the database, run the
   migrations once:

   ```shell
   FLOW_CONTEXT=Testing/Behat ./flow doctrine:migrate
   ```

   Without migrations, scenarios fail early with missing-table errors (e.g.
   `Table 'flow_functional_testing.neos_asset_usage' doesn't exist` from the AssetUsageCatchUpHook).

2. **PHP memory limit** (`-d memory_limit=2G`). Flow's compile-time subprocess needs a generous
   `memory_limit`; the common default `128M` OOMs.

3. **PHP 8.4+ deprecations** (`-d "error_reporting=E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED"`).
   Newer PHP deprecates non-canonical casts still used in some vendored Neos code, and Flow's
   Testing/Behat error handler rethrows deprecations as fatal configuration errors. Silencing
   `E_DEPRECATED` for the run works around it without patching vendored code.

Both are passed inline as `php -d` flags in the commands above, so no changes to your `php.ini` are
required.
