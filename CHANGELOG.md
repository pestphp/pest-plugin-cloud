# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/), and this project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- Implemented `CloudRun::handle()` — packages project into `project.tar.gz` and uploads to Pest Cloud API
- Config file support via `pest.cloud.json` for `respectGitignore` and `exclude` patterns
- `.gitignore`-aware file collection using `git ls-files`
- Tarball size validation (50 MB limit)
- Upload retry with exponential backoff (3 attempts)
- Error handling for 401, 422, and 5xx API responses
