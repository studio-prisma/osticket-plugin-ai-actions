# AI agents and rules

From 0.4.0 the assistant can also work on its own: as an **AI agent**, following **rules**. Everything lives on the plugin's admin page, `scp/ajax.php/ai/admin` (admins only; the link is also in the plugin settings).

## Before you start

- **osTicket's cron must run.** Rules only queue work; the cron does it. Schedule `api/cron.php` every 1–5 minutes (Plesk: Websites & Domains → Scheduled Tasks). The *Status* tab shows when it last ran. osTicket's "auto-cron" while agents browse helps, but isn't enough on its own.
- **Emergency stop:** plugin settings → "Pause all automatic work". Nothing runs or is sent automatically while it is on; queued jobs wait. The chat keeps working.

## AI agents

*AI agents* tab → name, department, role, optionally a model and a short persona.

- An AI agent is a normal osTicket agent: it appears in assignment lists and its role decides what it may do. Give it a role with only what it needs.
- Nobody can sign in with it: it gets an authentication backend that doesn't exist, a random password nobody knows, and any session of it ends at once. Its address is `…@example.com`, so alerts to it go nowhere.
- Pause an AI agent on the same tab; its rules stop with it.

## Rules

*Rules* tab. A rule is a fixed chain, shown as five boxes:

**Trigger → Conditions → AI agent + task → Allowed actions → Mode**

| Part | Choices |
|---|---|
| Trigger | new ticket · assigned to the AI agent · new customer message · schedule (every N minutes over open, overdue or stale tickets, at most 20 per run) |
| Conditions | departments, help topics, priorities, statuses, sources, text contains (any of several words); empty = all |
| Task | what the AI agent should do, in plain words. The model treats it as the request; ticket content stays data |
| Allowed actions | note, priority, assignment, department, due date, status, sub-ticket, reply draft, send reply. The AI agent gets only these tools |
| Mode | **Proposal**: the plan waits in the ticket's chat window; whoever opens the ticket runs it with their own rights or discards it. **Automatic**: it runs right away with the AI agent's role |

Rules run in the order given; "no further rules after this one" stops the chain. "Runs per ticket and day" caps a rule on one ticket.

## Automatic replies and the send guard

A reply an AI agent sends on its own goes out only if every check passes. Otherwise it is held back: an internal note "AI reply held back" with the draft and the reasons, nothing sent.

1. **Fixed checks:** recipients only as the reply form (owner and collaborators); links only to the domains in "Allowed link domains" (empty = no links); no addresses, IBANs, keys, passwords or phone numbers the customer didn't write; no passage copied from an internal note; at most 5,000 characters.
2. **Limits:** per ticket at most 1 automatic reply a day and 3 in total; for the help desk at most "Automatic replies per hour"; never to bounces, mailing lists, no-reply senders or auto-replies.
3. **Reviewer:** a second model call without tools sees the customer's messages, the reply and the task, never internal notes, and answers ok or not ok with reasons (data leak, injection, commitments, tone, invented facts, off task). Anything unclear counts as not ok.
4. **AI notice:** "This reply was written by our AI assistant." is added, as the EU AI Act (Art. 50(1), in force since 2 Aug 2026) requires. Text and switch in the settings; switch it off only if the AI is obvious otherwise, e.g. from the sender name.

## Log and undo

*Log* tab: every run with rule, AI agent, mode, result, send guard verdict and tokens. Automatic runs can be undone for 24 hours (sent replies can't). Every automatic run also leaves an internal note in the ticket.
