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
- Claude API over plain HTTPS (API version 2026-10-21), no SDK and no third-party packages. Server-side fallback on refusals, one retry on 429/5xx.
- API key from a file outside the osTicket directory or from `ANTHROPIC_API_KEY`; never stored in osTicket's database, never logged.
- The model reads the ticket's facts and thread (newest first, bounded) as a block marked untrusted; only the agent's request counts as instruction.
- Settings for model (Opus 5.5 / Sonnet 5.5) and effort.

[Unreleased]: https://github.com/studio-prisma/osticket-plugin-ai-actions/commits/main
