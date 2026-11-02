# AI agents and rules

From 0.4.0 the assistant can also work on its own: as an **AI agent**, following **rules**. Everything lives on the plugin's admin page, `scp/ajax.php/ai/admin` (admins only; the link is also in the plugin settings).

## Before you start

- **osTicket's cron must run.** Rules only queue work; the cron does it. Schedule `api/cron.php` every 1–5 minutes (Plesk: Websites & Domains → Scheduled Tasks). The *Status* tab shows when it last ran. osTicket's "auto-cron" while agents browse helps, but isn't enough on its own.
- **Emergency stop:** plugin settings → "Pause all automatic work". Nothing runs or is sent automatically while it is on; queued jobs wait. The chat keeps working.

## AI agents

*AI agents* tab → name, department, role, optionally a model and a short persona.

- An AI agent is a normal osTicket agent: it appears in assignment lists and its role decides what it may do. Give it a role with only what it needs.
- Nobody can sign in with it: it gets an authentication backend that doesn't exist, a random password nobody knows, and any session of it ends at once. Editing it under Admin → Agents is fine: the plugin puts backend and password back after each save there.
- **Email address.** osTicket alerts an AI agent like any agent (new ticket in its department, assignment, …), and every agent needs an address. Without one given, it gets `…@example.com`. That is fine without SMTP. With SMTP the mail server refuses that domain, and osTicket 1.18 then fails every further mail of the same run, alerts to people included (system log: "Cannot issue HELO to existing session"). So with SMTP, set an address in the *Email (alerts)* column that your mail server accepts:
  - a team mailbox osTicket doesn't fetch, or an empty distribution list if nobody needs the alerts;
  - for several AI agents on one mailbox, plus addresses (`team+triage@…`), if your mail server supports them; osTicket wants a different address per agent.
  - Not one of the help desk's own addresses (Emails): osTicket would fetch its own alerts back and file mail from that address under the AI agent. The plugin refuses those, like osTicket's agent form does.
  
  The AI agent doesn't read mailboxes; its replies to customers go out from the department's address like anyone's. The *Status* tab warns while an AI agent has an address no mail server delivers to.
- Pause an AI agent on the same tab; its rules stop with it.
- A picture (https:// address or a path on this server) shows on its cards in the ticket chat and at its thread entries.

## Rules

*Rules* tab. A rule is a fixed chain, shown as five boxes:

**Trigger → Conditions → AI agent + task → Allowed actions → Mode**

| Part | Choices |
|---|---|
| Trigger | new ticket · assigned to the AI agent · new customer message · schedule (every N minutes over open, overdue or stale tickets, at most 20 per run) |
| Conditions | departments, help topics, priorities, statuses, sources, text contains (subject or latest customer message, any of several words), sender contains (address of whoever wrote the latest customer message, e.g. `noreply@example.com` or `@example.com`, any of several); empty = all, filled ones must all match |
| Task | what the AI agent should do, in plain words, up to 3000 characters. The model treats it as the request; ticket content stays data |
| Allowed actions | note, priority, assignment, department, due date, help topic, parent ticket, status, sub-ticket, reply draft, send reply. The AI agent gets only these tools |
| Mode | **Proposal**: the plan waits in the ticket's chat window, the bubble and the board card show a dot; whoever opens the ticket runs it with their own rights or discards it. **Automatic**: it runs right away with the AI agent's role |

Rules run in the order given; "no further rules after this one" stops the chain. "Runs per ticket and day" caps a rule on one ticket.

A ticket that is closed by the time the cron gets to it (for example by one of osTicket's ticket filters) is skipped without asking the model, unless the rule lists statuses among its conditions.

### Follow-up and series (0.5.3)

- **Help topic:** the AI agent can set the help topic. If the topic has an SLA plan, the ticket gets it and its due date follows from it, instead of a date the model picks.
- **Parent ticket:** for duplicates and alert series, the AI agent can hang a ticket under an open ticket of the same customer. It first posts a note on that parent about what this ticket reports, so closing the child later loses nothing.
- **Follow-up date:** a status such as "Follow-up" plus a due date as the date to look again; both are existing actions, the task says when to use them.
- **Never closed automatically:** tickets with priority High or Emergency, whatever the task says.

## Automatic replies and the send guard

A reply an AI agent sends on its own goes out only if every check passes. Otherwise it is held back: an internal note "AI reply held back" with the draft and the reasons, nothing sent.

1. **Fixed checks:** recipients only as the reply form (owner and collaborators); links only to the domains in "Allowed link domains" (empty = no links); no addresses, IBANs, keys, passwords or phone numbers the customer didn't write; no passage copied from an internal note; at most 5,000 characters.
2. **Limits:** per ticket at most 1 automatic reply a day and 3 in total; for the help desk at most "Automatic replies per hour"; never to bounces, mailing lists, no-reply senders or auto-replies.
3. **Reviewer:** a second model call without tools sees the customer's messages, the reply and the task, never internal notes, and answers ok or not ok with reasons (data leak, injection, commitments, tone, invented facts, off task). Anything unclear counts as not ok.
4. **AI notice:** "This reply was written by our AI assistant." is added, as the EU AI Act (Art. 50(1), in force since 2 Aug 2026) requires. Text and switch in the settings; switch it off only if the AI is obvious otherwise, e.g. from the sender name.

## Log and undo

*Log* tab: every run with rule, AI agent, mode, result, send guard verdict and tokens. Automatic runs can be undone for 24 hours (sent replies can't), here and in the ticket's chat window, where any agent who can open the ticket sees the run and can undo it with their own permissions. Every automatic run leaves one internal note in the ticket: the AI agent's own note, if it wrote one, and the list of changes. osTicket's history of the ticket names the AI agent for these changes.
