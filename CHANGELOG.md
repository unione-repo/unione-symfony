# Changelog

All notable changes of this package. Versions follow [Semantic Versioning](https://semver.org/).

## [2.3.2](https://github.com/unione-repo/unione-symfony/releases/tag/v2.3.2) - 2026-11-05

### Changed

- The package requires the exact SDK version it is released and tested with (was a range from it).

### Fixed

- The tests of the CI workflow retry the installation of the dependencies until the mirrors of the registry serve the new SDK version.

## [2.3.0](https://github.com/unione-repo/unione-symfony/releases/tag/v2.3.0) - 2026-11-05

### Added

- Tests of the package (offline: the API is faked, no request leaves the machine).
- A CI workflow (`.github/workflows/test.yml`) runs the tests on every push and pull request over the supported runtimes; a release is published only when they pass.
- The tests and the release of the package wait until the SDK version it requires is in the registry, so the SDK and the package can be released together.
- README: how send errors surface (the SDK error with its kind, retryable and hint).
- A send error that a repeat cannot fix throws `UnrecoverableTransportException`, so Messenger does not retry it (when symfony/messenger is installed); the SDK exception is `getPrevious()`.
- A Symfony Flex recipe (works once it is in symfony/recipes-contrib and the project allows contrib recipes).

### Changed

- Requires the new minor version of the SDK: its errors tell what failed, whether a repeat may succeed and how to fix an API error.

Earlier releases: the [tags](https://github.com/unione-repo/unione-symfony/tags) of the repository.
