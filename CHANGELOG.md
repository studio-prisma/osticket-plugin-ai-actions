# Changelog

All notable changes to this project are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.3.0] - 2026-11-01

### Added

- OpenAI (GPT-6.1 Sol, GPT-6.1 Terra, Responses API with `store: false`) and Mistral (Large, Medium, EU) next to Claude. The model setting picks the provider; the key source works the same, with `OPENAI_API_KEY` and `MISTRAL_API_KEY` as environment variables and a key format check per provider.
- Sending a reply to the customer: preview with the full text and the recipients (owner and active collaborators, as the reply form), sent only after Run, not undoable. Setting "Allow sending replies", off by default.
- Read tools the model uses during its turn: the parent ticket or a sub-ticket the agent may open, and attachments of the ticket (text files as text; PDFs and images with Claude models). Attachments are listed in the ticket facts.
- Extension point for other plugins: the signal `ai-actions.tools` adds actions that go through the same preview, Run and note (docs/EXTENSIONS.md).

### Changed

- Default model is Claude Sonnet 5.5.

## [0.2.0] - 2026-11-01

### Added

- Earlier conversations: "New" and 12 hours idle no longer delete a conversation, they move it to an archive. The clock icon lists them per ticket, newest first, read only, for the own agent only. Kept for a configurable number of days (default 90, 0 = not kept).
- The model sees the parent ticket and the sub-tickets (number, subject, status, assignee, due date), only those the agent may open.
- Settings for the assistant's name, icon (five icons, first letter, or own image), accent colour and welcome text.
- Large view, a window resizable by its top-left corner (remembered per browser), full screen on phones.
- Three suggestions in an empty conversation.
- The SLA due date in the ticket facts while no own due date is set, so the model and the ticket view agree.
- The model and the error messages call the system by the help desk name from Settings → System instead of "osTicket".

### Changed

- Undo shows no countdown; after 30 seconds the button stays, greyed out.
- After a run or an undo on the Ticket Board, the page reloads with the quick view of the ticket open again, so the drawer shows the new values.
- Thread entries and the due date reach the model in the agent's time zone instead of database time.

### Fixed

- A due date was stored as UTC: "17:00" showed as 18:00 in Berlin in winter. The plugin now passes the time with the agent's offset for that date, so summer and winter time are right.
- The Plesk path hint in the settings lost its `<domain>` placeholder.

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

[Unreleased]: https://github.com/studio-prisma/osticket-plugin-ai-actions/compare/v0.3.0...HEAD
[0.3.0]: https://github.com/studio-prisma/osticket-plugin-ai-actions/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/studio-prisma/osticket-plugin-ai-actions/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/studio-prisma/osticket-plugin-ai-actions/releases/tag/v0.1.0
