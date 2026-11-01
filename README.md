# AI Actions for osTicket

[![Validate](https://github.com/studio-prisma/osticket-plugin-ai-actions/actions/workflows/validate.yml/badge.svg)](https://github.com/studio-prisma/osticket-plugin-ai-actions/actions/workflows/validate.yml)
[![License: GPL-2.0-or-later](https://img.shields.io/badge/License-GPL--2.0--or--later-blue.svg)](LICENSE)

A chat in the osTicket agent panel that turns plain-language requests into ticket changes through the Claude API. "Set the due date to tomorrow and assign it to Philipp" becomes a preview with exactly those two changes. Nothing changes until the agent clicks **Run**.

**[Deutsche Fassung →](README.de.md)**

---

## What it does

| | |
|---|---|
| Where | A bubble in the bottom-right corner of the agent panel. The conversation belongs to the ticket that is open: the ticket view, or a card in the quick view of the [Ticket Board](https://github.com/studio-prisma/osticket-plugin-board) |
| Questions | "What does the customer actually want?", "Summarise the thread". Answered from the ticket's facts and thread; nothing changes |
| Changes | Due date, priority, assignment to an agent or team, department, status, internal note, reply draft, sub-ticket, sent reply (setting, off by default), actions of other plugins ([EXTENSIONS](docs/EXTENSIONS.md)) |
| Reading | Parent ticket and sub-tickets the agent may open; attachments (text files; PDFs and images with Claude) |
| Providers | Anthropic (Claude Sonnet 5.5, Opus 5.5), OpenAI (GPT-6.1 Sol, Terra), Mistral (Large, Medium; EU) |
| Settings | For admins on admin pages: canned responses, help topics, lists, SLA, filters, departments, teams, with before → after and undo; never deletes ([ADMIN](docs/ADMIN.md)) |
| AI agents and rules | Agent accounts without login that work by rules: triage on new tickets, help when assigned, replies to customer messages, scheduled follow-ups. As proposals or automatic, with a send guard for replies ([RULES](docs/RULES.md)) |
| Preview | Every planned change as a line with osTicket's own labels, notes and drafts with their full text. **Run** or **Discard** |
| Undo | 30 seconds after a run, for every field change (not for notes, drafts and sub-tickets). Afterwards the button stays, greyed out |
| Refresh | After a run or an undo the ticket view reloads, on the board the quick view reopens with the new values |
| Follow-ups | One conversation per agent and ticket: "and now a week later" refers to the date just set. The model is told whether its last plan ran, was discarded or undone |
| Earlier conversations | "New" or 12 hours idle ends a conversation; it stays readable under the clock icon for 90 days (setting), for its own agent only |
| Context | Ticket facts, thread, parent ticket and sub-tickets (number, subject, status, assignee, due date; only those the agent may open). All times in the agent's time zone, summer and winter time included |
| Window | Large view, resizable by the top-left corner (size is remembered), full screen on phones |
| Look | Name, icon or own image, accent colour and welcome text from the settings. The model calls the system by the help desk name from Settings → System |
| Record | Every run and every undo leaves an internal note "AI action" with the request and the result of each change |
| Limits | Messages and tokens per agent and day, one message at a time per agent |

**Changes run through osTicket's own code.** Each action uses the same core method and the same permission check as its osTicket dialog: `updateField()` for due date and priority, `assign()`, `release()`, `transfer()`, `setStatus()` with the status dialog's permission matrix, `postNote()`. The values the model may use come from the lists those dialogs show the agent, and they are checked again when the plan runs. An agent can do nothing through the chat that they can't do by hand.

**The model plans, the agent decides.** Write tools are only queued. A plan is stored on the server, bound to the agent and the ticket, valid for 10 minutes and runnable once; the browser only ever sends its ID. There is no tool for other tickets, none for sending mail, none for the network or files.

**Ticket content is data, not instructions.** Facts and thread go to the model in a block marked untrusted; block tags inside ticket text are neutralised so a customer can't end the block or fake a request. In tests the model recognised and named every injected instruction (see [SECURITY.md](SECURITY.md)). The preview is the safety net either way.

**No SDK, no dependencies.** The plugin talks to the Messages API over PHP's cURL (API version `2026-10-21`). It ships no third-party library that could clash with other plugins in the same PHP process.

No core file is patched. Routes run on osTicket's `scp/ajax.php` (`ajax.scp` signal); the chat's script and stylesheet are added to agent pages through an output buffer.

---

## Requirements

- osTicket **1.18.x** (tested: 1.18.4)
- PHP **8.1 to 8.4** with the cURL extension (syntax-checked on all four; the end-to-end runs use 8.3)
- Outbound HTTPS from the server to `api.anthropic.com`
- An Anthropic API key. Use one from its own workspace with a spending limit
- Optional: [Ticket Board](https://github.com/studio-prisma/osticket-plugin-board) 0.3 or later for the chat in its quick view

**Privacy.** The ticket's facts and thread (customer messages, replies, internal notes, up to the configured length) go to Anthropic with every message. Before switching the plugin on, check your data processing agreement with Anthropic and your privacy notice. The plugin's own tables keep conversations for 12 hours, plans with the agent's request for 2 days and usage counters for 90 days.

---

## Install

Full guide: **[docs/INSTALL.md](docs/INSTALL.md)** ([deutsch](docs/INSTALL.de.md)).

Short version:

1. Download `ai-actions-<version>.zip` from the [latest release](https://github.com/studio-prisma/osticket-plugin-ai-actions/releases/latest) and unzip it into `include/plugins/`.
2. Decide where the key lives: a file **outside** the osTicket directory (recommended), the web server's environment, or pasted into the plugin settings, where osTicket stores it encrypted.
3. Admin Panel → Manage → Plugins → Add New Plugin → **AI Actions** → Install, add an instance, choose the key source, set the roles, enable it.

---

## Configuration

| Setting | Default | Purpose |
|---|---|---|
| AI assistant in the agent panel | off | Master switch |
| Agents with these primary roles | none | Who sees the bubble. Nothing selected means nobody |
| Where the API key comes from | Key file | Key file, environment variable `ANTHROPIC_API_KEY`, or stored here. Only the chosen source counts |
| API key file | empty | For "Key file": absolute path to a file that holds only the key. Files inside the osTicket directory are refused |
| API key | empty | For "Stored here": the key, encrypted by osTicket with `SECRET_SALT` from `ost-config.php`; never shown again, an empty box keeps it |
| Remove the stored key | off | Deletes the stored key on save |
| Allow sending replies | off | The AI may plan an email reply; sent only after Run, not undoable |
| Model | Claude Sonnet 5.5 | Also Claude Opus 5.5, GPT-6.1 Sol/Terra, Mistral Large/Medium. The model picks the provider; the key must be one of that provider (`ANTHROPIC_API_KEY`, `OPENAI_API_KEY`, `MISTRAL_API_KEY`) |
| Effort | Low | How long the model thinks. Low is enough for most requests |
| Messages | 100 | Per agent and day, 0 = no limit |
| Tokens | 500,000 | Input plus output per agent and day, 0 = no limit |
| Thread length | 12,000 | Characters of the thread the model reads, newest first (2,000 to 50,000) |
| Name of the assistant | empty | Window header and bubble label. Empty: "AI assistant" |
| Icon | Sparkles | Sparkles, robot, speech bubble, light bulb, lightning bolt, first letter of the name, or own image |
| Own image | empty | For "Own image": https:// address or a path on this server, shown round |
| Accent colour | empty | Hex colour for bubble, own messages and main buttons. Empty: the theme's |
| Welcome text | empty | Shown in an empty conversation |
| Keep earlier conversations (days) | 90 | How long ended conversations stay readable for their agent. 0 = not kept |

Which source to choose: a key file keeps the key out of the database and its backups entirely. Stored in the settings, it is encrypted, but someone with both the database and the osTicket files can decrypt it. The environment variable sits wherever your server or panel keeps PHP settings. The install guide compares the three.

A message usually costs 1,000 to 5,000 tokens, about one cent with Opus 5.5 at low effort. A reply takes 3 to 15 seconds.

**Theming.** All colours are CSS custom properties without specificity (`--spa-accent`, `--spa-surface` and others, listed at the top of `assets/ai-actions.css`), so a theme can override them with a plain rule, including a dark mode.

---

## Testing

```bash
php tests/unit.php                                # 127 checks, no osTicket, no network
./dev/up.sh                                       # osTicket 1.18.4 test stack on 127.0.0.1:8089
cd tests/e2e && npm ci && npx playwright test     # 35 end-to-end tests
```

The stack runs with an offline stand-in for the model (`SPA_AI_FAKE=1`), so the suite needs no key and costs nothing. It covers every acceptance criterion of the plugin's spec plus an attack suite (CSRF on every endpoint, races, tampered parameters, foreign tickets and conversations). Live runs against the real model are described in `dev/README.md`.

Not verified: PHP 8.1, 8.2 and 8.4 at runtime (lint only), osTicket with enforced 2FA, threads longer than 50,000 characters with the real model.

---

## Known limitations

- **Only the open ticket.** No requests across tickets ("close all tickets of customer X"), no search.
- **Drafts are never sent** by the plugin, and an existing draft of the agent is kept, not replaced.
- **No undo for notes, drafts and sub-tickets.** Undo works for 30 seconds, for field changes.
- **Attachments aren't read.**
- **Closing after an assignment in the same plan keeps the new assignee.** osTicket itself would make the closing agent the assignee; the plugin follows the agent's request instead.
- **On the board, the open chat can cover cards** in the right-hand column while the quick view is open.
- **Earlier conversations are read only** and visible to their own agent only; another agent's questions about the same ticket stay private.
- **German and English** interface. The model answers in the language of the request.

---

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). Security issues: see [SECURITY.md](SECURITY.md), never a public issue.

## License

[GPL-2.0-or-later](LICENSE) © studio-prisma. The plugin extends osTicket classes and therefore follows osTicket's license.
