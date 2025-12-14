# Contributing to Business Card Generator

Thank you for your interest in contributing to Business Card Generator! This document provides guidelines and instructions for contributing.

## Getting Started

1. **Fork the repository** on GitHub
2. **Clone your fork** locally:
   ```bash
   git clone https://github.com/YOUR_USERNAME/business-card-generator.git
   cd business-card-generator
   ```
3. **Create a branch** for your changes:
   ```bash
   git checkout -b feature/your-feature-name
   ```

## Development Setup

1. **Install dependencies** (if using Composer packages):
   ```bash
   composer install
   ```

2. **Copy configuration**:
   ```bash
   cp config.example.php config.php
   ```

3. **Set up database** and configure `config.php`

4. **Run installation wizard** or manually set up database

## Making Changes

### Code Style

- Follow PSR-12 coding standards
- Use meaningful variable and function names
- Add comments for complex logic
- Keep functions focused and small

### Commit Messages

Use clear, descriptive commit messages:

```
feat: Add QR code generation feature
fix: Resolve database connection issue
docs: Update installation guide
refactor: Improve DatabaseAdapter class
```

### Testing

Before submitting a pull request:
- Test your changes thoroughly
- Ensure backward compatibility
- Test with both database and JSON fallback
- Check for any breaking changes

## Submitting Changes

1. **Push your changes** to your fork:
   ```bash
   git push origin feature/your-feature-name
   ```

2. **Create a Pull Request** on GitHub:
   - Provide a clear description of changes
   - Reference any related issues
   - Include screenshots if UI changes
   - Update documentation if needed

## Pull Request Process

1. Ensure your code follows the project's coding standards
2. Update documentation for any new features
3. Add tests if applicable
4. Ensure all tests pass
5. Get at least one review approval

## Areas for Contribution

### High Priority
- Security improvements (CSRF protection, rate limiting)
- Testing (unit tests, integration tests)
- Documentation improvements
- Bug fixes

### Medium Priority
- Performance optimizations
- UI/UX improvements
- Additional payment gateway integrations
- Feature enhancements

### Low Priority
- Code refactoring
- PostgreSQL schema support
- API documentation
- Translation improvements

## Questions?

- Open an issue for bugs or feature requests
- Check existing issues before creating new ones
- Be respectful and constructive in discussions

Thank you for contributing! 🎉
