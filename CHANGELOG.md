# Changelog

All notable changes to this project are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- AI field in the agent ticket view: a request is turned into planned changes, shown as a preview and run only after the agent confirms.
- Due date as the first action, through the same core path and permission check as osTicket's own field dialog.
- Plans are stored server-side, bound to agent and ticket, valid for 10 minutes and runnable once. The browser only sends the plan ID.
- Internal note "AI action" on every run with the request and the result of each change.
- Access by primary role; off by default.
- Offline stand-in for the model (`SPA_AI_FAKE=1`) for the local test stack and CI.

[Unreleased]: https://github.com/studio-prisma/osticket-plugin-ai-actions/commits/main
