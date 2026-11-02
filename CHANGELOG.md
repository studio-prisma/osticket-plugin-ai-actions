# Changelog

All notable changes to this project are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **Pages the assistant may read** (new setting, empty = off): with a Claude model, the assistant may open links from the ticket that point to the listed domains, such as a vendor's security advisory, through Anthropic's server-side `web_fetch` tool. The fetch runs at Anthropic, never from the help desk server; at most 3 pages per request and 8,000 tokens per page. Fetched pages count as ticket data, never as instructions. OpenAI and Mistral models keep working without it.

### Fixed

- The model saw no link targets: thread entries were read through osTicket's search text, which drops every `href`. Alert mails often name the affected site or the fix only in a link. Entries now keep their links as "text (URL)"; Microsoft 365 Safe Links wrappers are unwrapped to the real target, and links over 500 characters, `javascript:` and `data:` links are left out.

## [0.5.5] - 2026-11-02

### Security

- Saving an AI agent in osTicket's agent form (Admin → Agents) reset its authentication backend, which the form doesn't list, and could give it a known password. Both are now put back after every save of an AI agent's record; the rest of the form's changes stay. The other two locks (unknown password, session ended at once) held throughout.

## [0.5.4] - 2026-11-02

### Fixed

- With SMTP, mail failed after an AI agent was alerted: its `@example.com` address is refused by mail servers, and osTicket 1.18 then fails every further mail of the same run ("Cannot issue HELO to existing session"), alerts to people included. Each AI agent's address can now be set on the *AI agents* tab, with the checks of osTicket's agent form plus a refusal of the help desk's own addresses, which osTicket would fetch back. The *Status* tab warns while an AI agent has an address no mail server delivers to ([RULES](docs/RULES.md)).
- The form for a new AI agent offered disabled departments, which osTicket then refused.

## [0.5.3] - 2026-11-02

### Added

- Ticket action "Help topic" (`set_topic`): the topics the ticket view offers the agent. A topic with an SLA plan brings it along and the due date is recomputed. Undo puts topic and SLA back.
- Ticket action "Parent ticket" (`link_parent`): hangs the ticket under an open ticket of the same customer, for duplicates and alert series. A note about this ticket is posted on the parent first. No chains: a sub-ticket or a ticket with sub-tickets gets no candidates. Undo removes the link; the note stays.
- A waiting proposal of an AI agent shows as a dot on the chat bubble and on board cards (`GET /ai/marks`, only tickets the agent can open).
- Automatic runs of the last 24 hours show in the ticket's chat, with undo for any agent who can open the ticket (`POST /ai/autoundo`, own permissions apply).
- A picture per AI agent, on its proposal and run cards and at its thread entries.
- Settings chat: create departments, give agents access to a department with a role or take it away, filters that reject tickets or send an email to fixed addresses. Undo for each ([ADMIN](docs/ADMIN.md)).
- Other plugins' actions: the Ticket Board (0.6.0) adds "Release assignment" and "Mark as answered/unanswered".

### Changed

- One note per automatic run: the AI agent's own note followed by the list of changes; the rule's task is no longer repeated in it.
- osTicket's events of an automatic run (priority, status, …) name the AI agent instead of SYSTEM.
- A rule's task may be 3000 characters long (was 500).
- `read_related_ticket` also reads open tickets of the same customer, so the model can compare a possible parent ticket before planning `link_parent`.

### Fixed

- A rule job on a ticket that was closed in the meantime (e.g. by a ticket filter) is skipped without a model call, unless the rule asks for statuses itself. The log shows "skipped: ticket closed".
- Setting the status a ticket already has counts as unchanged instead of failed.
- The log shows refused changes with their label and what the model asked for, instead of an empty "set_status:".
- The help topic field asked osTicket for the signed-in agent, who doesn't exist in the cron.
- On the Ticket Board the chat window could overlap the quick view by a few pixels: it measured the drawer while it was still sliding in.

### Security

- Automatic runs never close a ticket with priority High or Emergency; that is left to a person. Proposals a person confirms are not affected.

## [0.5.2] - 2026-11-01

### Fixed

- The tabs of the admin page (AI agents, Rules, Log, Status) did nothing: they used osTicket's ul.tabs class, whose script takes over clicks for in-page panes.
- The log marked the lines of a proposal with ✕ as if they had failed; they now show • (waiting in the ticket chat). Times are in the agent's time zone.

## [0.5.1] - 2026-11-01

### Fixed

- Creating an AI agent failed with an HTTP 500 where a password policy plugin is active: the account got a random password, the policy rejected it and threw. AI agents now get the bcrypt hash of a random value written directly, past the policies (the unknown backend and the session check still block any login); errors while creating show as a message.

### Added

- "AI Actions" under Applications in the agent panel, linking to the page with AI agents, rules, log and status. Non-admins see a short note instead of the forms.

## [0.5.0] - 2026-11-01

### Added

- Settings chat for admins on admin pages: canned responses, help topics, list items, SLA plans, ticket filters and teams (create, change, disable), departments (change), team members (add, remove). Preview with before → after, Run, undo in the chat and for 24 hours in the log; nothing is ever deleted. Every change goes to osTicket's system log ([ADMIN](docs/ADMIN.md)).

### Changed

- Departments and teams are changed field by field instead of through core's form save, which rebuilds memberships from `members[]`.

## [0.4.0] - 2026-11-01

### Added

- AI agents: osTicket agent accounts the AI works as, assignable like anyone, with their own role, model and persona. Nobody can sign in with them (unknown backend, unknown random password, session ended at once).
- Rules: trigger (new ticket, assignment to the AI agent, new customer message, schedule), conditions, task, allowed actions and mode (proposal or automatic), with a flow view. Triggers queue jobs; osTicket's cron runs them.
- Proposals in the ticket chat, run with the rights of the agent who confirms.
- Automatic runs with the AI agent's role, an internal note each, a log and 24-hour undo.
- Send guard for automatic replies: fixed checks (links, addresses, secrets, copied internal notes, length), rate and loop limits, a reviewer model, fail closed; held replies become an internal note.
- AI notice in automatic replies (EU AI Act Art. 50(1)), on by default, text configurable.
- Admin page `scp/ajax.php/ai/admin` with AI agents, rules, log and cron status. Emergency stop in the settings.

### Fixed

- Collaborators in the reply preview: osTicket returns a query set, which was cast to an array.

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

[Unreleased]: https://github.com/studio-prisma/osticket-plugin-ai-actions/compare/v0.5.5...HEAD
[0.5.5]: https://github.com/studio-prisma/osticket-plugin-ai-actions/compare/v0.5.4...v0.5.5
[0.5.4]: https://github.com/studio-prisma/osticket-plugin-ai-actions/compare/v0.5.3...v0.5.4
[0.5.3]: https://github.com/studio-prisma/osticket-plugin-ai-actions/compare/v0.5.2...v0.5.3
[0.5.2]: https://github.com/studio-prisma/osticket-plugin-ai-actions/compare/v0.5.1...v0.5.2
[0.5.1]: https://github.com/studio-prisma/osticket-plugin-ai-actions/compare/v0.5.0...v0.5.1
[0.5.0]: https://github.com/studio-prisma/osticket-plugin-ai-actions/compare/v0.4.0...v0.5.0
[0.4.0]: https://github.com/studio-prisma/osticket-plugin-ai-actions/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/studio-prisma/osticket-plugin-ai-actions/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/studio-prisma/osticket-plugin-ai-actions/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/studio-prisma/osticket-plugin-ai-actions/releases/tag/v0.1.0
