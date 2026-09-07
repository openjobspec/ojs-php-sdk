# Contributing to the OJS PHP SDK

Thank you for your interest in contributing to the Open Job Spec PHP SDK.

## Development Setup

1. Clone the repository:

   ```bash
   git clone https://github.com/openjobspec/ojs-php-sdk.git
   cd ojs-php-sdk
   ```

2. Install the locked dependencies:

   ```bash
   composer install
   ```

3. Run the canonical checks:

   ```bash
   composer test
   composer analyse
   composer validate --strict --no-check-publish
   ```

## Coding Standards

- Support PHP 8.2 and newer.
- Preserve backward compatibility for public classes and methods.
- Add PHPUnit coverage for behavior changes.
- Keep Composer dependencies minimal.

## Pull Request Process

1. Create a focused feature branch.
2. Add or update tests and documentation.
3. Run the canonical checks above.
4. Update `CHANGELOG.md` for user-visible changes.
5. Submit a pull request with a clear description.

## License

By contributing, you agree that your contributions will be licensed under the Apache 2.0 License.
