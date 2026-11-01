# Changelog

All notable changes to this project are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0] - 2026-11-01

First release.

### Added

- Chat bubble in the bottom-right corner of the agent panel. The conversation belongs to the open ticket: the ticket view or a card in the Ticket Board's quick view. The window moves aside while the board's drawer is open.
- Conversations per agent and ticket, kept on the server only (12 hours idle, 20 messages, "New" starts over). The browser sends only the next message.
- The model learns in its next turn whether a plan ran, was discarded, expired or was replaced by a new message, and sees new thread entries.
- A plan is turned into a preview card in the chat and runs only after the agent confirms.
- Due date as the first action, through the same core path and permission check as osTicket's own field dialog.
- Plans are stored server-side, bound to agent and ticket, valid for 10 minutes and runnable once. The browser only sends the plan ID.
- Internal note "AI action" on every run with the request and the result of each change.
- Access by primary role; off by default.
- Offline stand-in for the model (`SPA_AI_FAKE=1`) for the local test stack and CI.
- Claude API over plain HTTPS (API version 2026-10-21), no SDK and no third-party packages. Server-side fallback on refusals, one retry on 429/5xx.
- API key from one source the admin chooses: a file outside the osTicket directory (default), `ANTHROPIC_API_KEY`, or stored in the plugin settings, encrypted by osTicket with `SECRET_SALT` and never shown again. Never in clear text in the database, never logged.
- The model reads the ticket's facts and thread (newest first, bounded) as a block marked untrusted; only the agent's request counts as instruction.
- Settings for model (Opus 5.5 / Sonnet 5.5) and effort.
- Status, priority, assignment to an agent or team, and transfer, each through the core dialog's path and permission check. The model picks values only from the lists osTicket shows this agent; they are checked again when the plan runs.
- Changes run in a fixed order (fields, assignment, transfer, status last). Closing after an assignment in the same plan keeps the new assignee instead of core's "closing agent becomes assignee".
- Setting a value the ticket already has counts as done, not as an error.
- Internal notes, reply drafts and sub-tickets. The preview shows their full text. A draft goes into the agent's own reply field (never sent, an existing draft is kept) and needs the reply permission; a sub-ticket gets the parent's customer, department and help topic (or the default topic) and is linked by `ticket_pid`, and needs the create permission. These three are not undoable.
- Prompt hardening: block tags the prompt is built from (`ticket_data`, `agent_request`, `plan_result`, `ticket_options`) are neutralised in ticket text and option labels. JSON responses send `nosniff`. The per-agent lock is unique per osTicket install on a shared database server.
- Limits per agent and day: messages (default 100) and tokens (default 500,000), 0 for none. A message is counted before the model is called; tokens are added from the API's usage. One message at a time per agent (MySQL named lock). The thread length the AI reads is a setting (2,000 to 50,000 characters).
- Undo for 30 seconds after a run: puts every changed field back in reverse order (status first, transfer back before the rest), with the same permission checks, plus an internal note "AI action undone". The old values stay on the server; the model learns about the undo in its next turn.
- Colours are themeable custom properties (see the head of `assets/ai-actions.css`).
- The key file may start with a BOM and end with CRLF (panel file managers save it that way). A key file outside `open_basedir` is reported as such instead of "unreadable"; the install guide shows the Plesk `private` folder.

[Unreleased]: https://github.com/studio-prisma/osticket-plugin-ai-actions/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/studio-prisma/osticket-plugin-ai-actions/releases/tag/v0.1.0
