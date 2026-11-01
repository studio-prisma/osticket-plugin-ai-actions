# Security Policy

## Supported Versions

Only the latest minor release receives security fixes. Older tags are archived, not maintained.

| Version | Supported |
|---|---|
| `0.1.x` | yes |
| everything older | no |

## Reporting a Vulnerability

Use **[GitHub Security Advisories](https://github.com/studio-prisma/osticket-plugin-ai-actions/security/advisories/new)**. Reports there are private by default. Don't open a public issue for a suspected vulnerability.

Include:

- plugin version, osTicket version, model and effort setting
- what an attacker gains, not only what misbehaves
- reproduction steps, ideally against a clean install (`dev/up.sh` builds one); for prompt injection, the exact ticket text and the agent's request

Expected response: an acknowledgement within 7 days, an assessment within 30. This is a spare-time project, not a vendor with an on-call rotation. That's the honest number, not a target.

## In Scope

- **A ticket change without the agent's click on Run.** Anything the plugin changes, posts or creates while the agent only asked, discarded, or did nothing.
- **A change different from the preview.** The run does something the preview didn't show, or more than it showed.
- **A change the agent's role doesn't allow** in osTicket's own dialog for that action, or a value (assignee, team, department, priority, status) the dialog wouldn't list for that agent.
- **Access to a ticket the agent can't open** in the ticket view: planning, chatting, running, undoing, resetting.
- **Another agent's conversation, plan or undo**: reading, running, discarding or undoing it.
- **A request without a valid CSRF token** that changes anything, or any answer to an agent with a pending password change, 2FA setup or an unfinished upgrade.
- **Running a plan twice**, after 10 minutes, or undoing after 30 seconds.
- **Prompt injection that gets past the preview.** Ticket content that makes the plugin change the ticket without a confirmed preview, reach another ticket, send anything, or put a planned change on screen in a way that hides what it does.
- **Ticket content leaking to the wrong place**: another ticket's data in the model's context, an internal note in a reply draft without the agent's request, conversation history in another agent's chat.
- **The API key** in a response, a log line, the database, a prompt, an error message, or the release package; a key file inside the osTicket directory being accepted; content of a file that isn't a key being sent anywhere.
- **Model or plugin output rendered as markup** in the chat, the preview, notes or drafts.
- **Path traversal or arbitrary file reads** through the asset route or the key file setting.
- **Getting past the daily limits** or the one-message-at-a-time lock.

## Out of Scope

Documented design decisions are not findings.

- **Ticket content goes to Anthropic.** That's what the plugin does. Data processing agreements and privacy notices are the operator's job.
- **The model can be wrong, or be talked into a plan.** A bad suggestion that the agent sees in the preview and could reject is not a vulnerability; the preview and the confirmation are the control. A plan the agent confirms runs with the agent's rights.
- **Prompt injection that only changes the model's answer text** (a misleading summary) without a change, a leak or a hidden preview.
- **Closing after an assignment in the same plan keeps the new assignee**, unlike osTicket's own close.
- **Undo of the due date writes the old value directly**, bypassing the edit form's "must be in the future" check.
- **Plans, conversations and counters in the plugin's tables** for 2 days, 12 hours and 90 days.
- **The offline test model** (`SPA_AI_FAKE=1`) when someone sets that variable on a production server.
- **Bugs in osTicket itself**, including its permission model. Report those [upstream](https://github.com/osTicket/osTicket/security).
- Anything that requires admin access to osTicket or write access to the server, including choosing the key file path.
- Costs within the configured limits.
- Automated scanner output without a working proof of concept.

## Handling of Secrets

The plugin reads the API key from a file outside the osTicket directory or from the `ANTHROPIC_API_KEY` environment variable. It never stores the key in osTicket's database, never logs it and never puts it into a prompt; it only appears in the `x-api-key` header of requests to `api.anthropic.com`. The release build refuses to package anything that looks like an Anthropic key.

The test stack generates its passwords at runtime into `dev/local/` (git-ignored). A secret that reaches a commit anyway is rotated first and removed second.

## Disclosure

Coordinated. A fix ships first, the advisory follows once a patched release is available. Reporters are credited unless they ask not to be.
