# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Type
- Omeka S module for generating and selecting video thumbnails
- PHP 7.4+ with Laminas/Zend Framework

## Code Style
- Follow PSR-4 autoloading namespace structure
- Use consistent type hints in method signatures
- Organize classes by responsibility (Controllers, Forms, Jobs, etc.)
- Use camelCase for variables/methods, PascalCase for classes
- Proper error handling using try/catch blocks
- Follow existing patterns in the codebase for dependency injection
- Check type with instanceof when handling mixed types
- Escaping: Always use escapeshellcmd() for shell commands

## Testing
- Manual testing through Omeka S admin interface
- Test FFmpeg integration by verifying executable path before operations

## Commands
- No formal build system, tests, or linting tools
- For manual testing, install in an Omeka S instance

## Security
- Validate all user inputs before use
- Escape shell commands with escapeshellcmd()
- Validate file paths and types before processing
- Use Omeka S permissions system for access control