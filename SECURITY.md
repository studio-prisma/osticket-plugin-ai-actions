# Security Policy

## Supported Versions

Only the latest minor release receives security fixes. Older tags are archived, not maintained.

| Version | Supported |
|---|---|
| `0.5.x` | yes |
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
- **Another agent's conversation, earlier conversation, plan or undo**: reading, running, discarding or undoing it.
- **A request without a valid CSRF token** that changes anything, or any answer to an agent with a pending password change, 2FA setup or an unfinished upgrade.
- **Running a plan twice**, after 10 minutes, or undoing after 30 seconds.
- **Prompt injection that gets past the preview.** Ticket content that makes the plugin change the ticket without a confirmed preview, reach another ticket, send anything, or put a planned change on screen in a way that hides what it does.
- **Ticket content leaking to the wrong place**: another ticket's data in the model's context, an internal note in a reply draft without the agent's request, conversation history in another agent's chat.
- **The API key** in a response, a log line, a prompt, an error message, the release package, or in clear text in the database; a key file inside the osTicket directory being accepted; content of a file that isn't a key being sent anywhere.
- **A reply sent without the agent's click on Run**, while "Allow sending replies" is off, or to anyone but the ticket owner and its active collaborators.
- **A page fetched outside "Pages the assistant may read"**, a fetch from the help desk server itself, or a fetched page that makes the plugin change, send or leak anything past the preview and the rules that apply to ticket content.
- **Reading another ticket or a file through the read tools** that isn't the parent, a sub-ticket or an attachment of the open ticket, or that the agent can't open.
- **Model or plugin output rendered as markup** in the chat, the preview, notes or drafts.
- **Path traversal or arbitrary file reads** through the asset route or the key file setting.
- **Getting past the daily limits** or the one-message-at-a-time lock.
- **The settings chat for a non-admin**, a settings change without Run, a deletion, or a change outside its areas (agents, roles, permissions, email accounts, API keys, plugins, system settings); a department or team losing members it wasn't asked to lose.
- **Signing in as an AI agent**, or an AI agent doing more than its role and its rule's allowed actions.
- **An automatic reply that passes the send guard** with a link outside the allowed domains, an address, number or secret the customer didn't write, text from an internal note, or past the rate and loop limits.
- **A rule, AI agent or log entry changed by a non-admin**, or a proposal run by an agent who can't open the ticket.

## Out of Scope

Documented design decisions are not findings.

- **Ticket content goes to the chosen provider** (Anthropic, OpenAI or Mistral), including attachments the model reads. That's what the plugin does. Data processing agreements and privacy notices are the operator's job.
- **Actions added by other plugins** through `ai-actions.tools` run that plugin's code with the agent; their permission checks are that plugin's responsibility.
- **The reviewer model can be wrong.** It is the last of several layers; a reply it lets through that breaks none of the fixed checks is a quality issue, not a vulnerability. Automatic sending is the operator's choice per rule.
- **The model can be wrong, or be talked into a plan.** A bad suggestion that the agent sees in the preview and could reject is not a vulnerability; the preview and the confirmation are the control. A plan the agent confirms runs with the agent's rights.
- **Fetched pages go to the model**, like ticket content. The admin chooses the domains; a page on an allowed domain that misleads the model is the same case as a misleading ticket.
- **Prompt injection that only changes the model's answer text** (a misleading summary) without a change, a leak or a hidden preview.
- **Closing after an assignment in the same plan keeps the new assignee**, unlike osTicket's own close.
- **Undo of the due date writes the old value directly**, bypassing the edit form's "must be in the future" check.
- **A key stored in the settings can be decrypted by someone who has both the database and the osTicket files** (`SECRET_SALT`). That is how osTicket protects its own stored passwords; the key file avoids it and is the documented default.
- **Plans, conversations, earlier conversations and counters in the plugin's tables** for 2 days, 12 hours, the configured number of days (default 90) and 90 days.
- **The image address in the settings** is loaded by every agent's browser; an admin who sets an outside address tells that server when agents open the panel.
- **The offline test model** (`SPA_AI_FAKE=1`) when someone sets that variable on a production server.
- **Bugs in osTicket itself**, including its permission model. Report those [upstream](https://github.com/osTicket/osTicket/security).
- Anything that requires admin access to osTicket or write access to the server, including choosing the key file path.
- Costs within the configured limits.
- Automated scanner output without a working proof of concept.

## Handling of Secrets

The plugin reads the API key from the one source the admin chose: a file outside the osTicket directory, the `ANTHROPIC_API_KEY` environment variable, or the plugin settings. In the settings it goes through osTicket's `PasswordField`, which encrypts it with `SECRET_SALT` from `include/ost-config.php` before it reaches the database, and never shows it again. The plugin never logs the key and never puts it into a prompt; it only appears in the `x-api-key` header of requests to `api.anthropic.com`. The release build refuses to package anything that looks like an Anthropic key.

The test stack generates its passwords at runtime into `dev/local/` (git-ignored). A secret that reaches a commit anyway is rotated first and removed second.

## Disclosure

Coordinated. A fix ships first, the advisory follows once a patched release is available. Reporters are credited unless they ask not to be.
