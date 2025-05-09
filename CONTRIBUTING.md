# Contributing to php-mcp/server

First off, thank you for considering contributing to `php-mcp/server`! We appreciate your time and effort. This project aims to provide a robust and easy-to-use PHP server for the Model Context Protocol.

Following these guidelines helps to communicate that you respect the time of the developers managing and developing this open-source project. In return, they should reciprocate that respect in addressing your issue, assessing changes, and helping you finalize your pull requests.

## How Can I Contribute?

There are several ways you can contribute:

*   **Reporting Bugs:** If you find a bug, please open an issue on the GitHub repository. Include steps to reproduce, expected behavior, and actual behavior. Specify your PHP version, operating system, and relevant package versions.
*   **Suggesting Enhancements:** Open an issue to suggest new features or improvements to existing functionality. Explain the use case and why the enhancement would be valuable.
*   **Improving Documentation:** If you find errors, omissions, or areas that could be clearer in the README or code comments, please submit a pull request or open an issue.
*   **Writing Code:** Submit pull requests to fix bugs or add new features.

## Development Setup

1.  **Fork the repository:** Click the "Fork" button on the [php-mcp/server GitHub page](https://github.com/php-mcp/server).
2.  **Clone your fork:** `git clone git@github.com:YOUR_USERNAME/server.git`
3.  **Navigate into the directory:** `cd server`
4.  **Install dependencies:** `composer install` (This installs runtime and development dependencies).

## Submitting Changes (Pull Requests)

1.  **Create a new branch:** `git checkout -b feature/your-feature-name` or `git checkout -b fix/issue-number`.
2.  **Make your changes:** Write your code and accompanying tests.
3.  **Ensure Code Style:** Run the code style fixer (if configured, e.g., PHP CS Fixer):
    ```bash
    composer lint # Or ./vendor/bin/php-cs-fixer fix
    ```
    Adhere to PSR-12 coding standards.
4.  **Run Tests:** Ensure all tests pass:
    ```bash
    composer test # Or ./vendor/bin/pest
    ```
    Consider adding new tests for your changes. Aim for good test coverage.
5.  **Update Documentation:** If your changes affect the public API or usage, update the `README.md` and relevant PHPDoc blocks.
6.  **Commit your changes:** Use clear and descriptive commit messages. `git commit -m "feat: Add support for resource subscriptions"` or `git commit -m "fix: Correct handling of transport errors"`
7.  **Push to your fork:** `git push origin feature/your-feature-name`
8.  **Open a Pull Request:** Go to the original `php-mcp/server` repository on GitHub and open a pull request from your branch to the `main` branch (or the appropriate development branch).
9.  **Describe your changes:** Provide a clear description of the problem and solution in the pull request. Link to any relevant issues (`Closes #123`).

## Coding Standards

*   Follow **PSR-12** coding standards.
*   Use **strict types:** `declare(strict_types=1);` at the top of PHP files.
*   Use **PHP 8.1+ features** where appropriate (readonly properties, enums, etc.).
*   Add **PHPDoc blocks** for all public classes, methods, and properties.
*   Write clear and concise code. Add comments only where necessary to explain complex logic.

## Reporting Issues

*   Use the GitHub issue tracker.
*   Check if the issue already exists.
*   Provide a clear title and description.
*   Include steps to reproduce the issue, code examples, error messages, and stack traces if applicable.
*   Specify relevant environment details (PHP version, OS, package version).

Thank you for contributing!