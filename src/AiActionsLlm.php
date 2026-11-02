<?php

declare(strict_types=1);

/**
 * One request to a language model, in a provider-neutral shape.
 *
 * send() returns:
 *   stop     'tool_use' | 'end_turn' | 'max_tokens' | 'refusal'
 *   content  list of ['type' => 'text', 'text' => ...]
 *            and ['type' => 'tool_use', 'id' => ..., 'name' => ..., 'input' => array]
 *   usage    ['input' => int, 'output' => int]
 */
interface AiActionsLlm
{
    public function send(string $system, array $messages, array $tools): array;
}

/**
 * Deterministic stand-in for the tests. Understands a few words, each one
 * planning one change with a value from <ticket_options>:
 *   "due" / "fällig"              due date tomorrow 17:00
 *   "philipp"                     assign to the first agent named Philipp
 *   "close" / "schließ"           status "Closed"
 *   "priority high" / "hoch"      priority "High"
 *   "sales"                       transfer to the Sales department
 *   "note" / "notiz"             an internal note
 *   "draft" / "entwurf"          a reply draft (with markup, to prove it is escaped)
 *   "send" / "sende"             a sent reply (only when offered)
 *   "sub-ticket" / "unterticket" a sub-ticket
 * Everything else gets a fixed answer.
 *
 * Only used when the server environment sets SPA_AI_FAKE=1 (the local test
 * stack does). Nothing in a request can switch it on.
 */
final class AiActionsFakeLlm implements AiActionsLlm
{
    /**
     * Settings chat: "canned" creates a canned response, "rename sla" renames
     * the first SLA plan, "disable topic" disables the first active help
     * topic, "list item" adds an item to the first list, "delete" asks for
     * nothing (the tools can't delete).
     */
    private static function admin(array $messages, array $usage): array
    {
        $all = '';
        foreach ($messages as $m) {
            foreach ((array) ($m['content'] ?? array()) as $b) {
                $all .= is_string($b['text'] ?? null) ? $b['text'] . "\n" : '';
            }
        }
        $last = end($messages);
        if (($last['content'][0]['type'] ?? '') === 'tool_result') {
            return array('stop' => 'end_turn', 'usage' => $usage, 'content' => array(array('type' => 'text', 'text' => 'Planned. Please check the preview.')));
        }
        $req = preg_match('/<agent_request>(.*?)<\/agent_request>(?!.*<agent_request>)/s', $all, $m) ? strtolower($m[1]) : '';
        $first = static function (string $section) use ($all): string {
            return preg_match('/' . $section . '[^\n]*\n- (\d+):/', $all, $m) ? $m[1] : '';
        };
        $empty = array('id' => '', 'enabled' => '');
        $call = null;
        if (strpos($req, 'canned') !== false) {
            $call = array('save_canned', $empty + array('title' => 'Password reset', 'response' => "Hello,\n\nyour password was reset."));
        } elseif (strpos($req, 'rename sla') !== false) {
            $call = array('save_sla', array('id' => $first('SLA plans'), 'name' => 'Renamed SLA', 'grace_hours' => '', 'enabled' => ''));
        } elseif (strpos($req, 'disable topic') !== false) {
            $call = array('save_topic', array('id' => $first('Help topics'), 'name' => '', 'department' => '', 'sla' => '', 'enabled' => 'no'));
        } elseif (strpos($req, 'create filter') !== false) {
            $call = array('save_filter', array('id' => '', 'name' => 'AI supplier filter', 'enabled' => '', 'target' => 'Any', 'match' => 'all',
                'rules' => 'email contains @lieferant.example.com', 'actions' => 'dept ' . $first('Departments \\(id: name\\)')));
        } elseif (strpos($req, 'rename department') !== false) {
            $call = array('save_department', array('id' => $first('Department details'), 'name' => 'Renamed Dept', 'manager' => '', 'sla' => '', 'public' => ''));
        } elseif (strpos($req, 'add to team') !== false && preg_match('/Teams[^\n]*\n- (\d+):/', $all, $tm) && preg_match('/Agents[^\n]*\n(?:- \d+:[^\n]*\n)*?- (\d+): Philipp/', $all, $ag)) {
            $call = array('team_member', array('id' => '', 'team' => $tm[1], 'agent' => $ag[1], 'member' => 'yes'));
        } elseif (strpos($req, 'list item') !== false && preg_match('/- list (\d+):/', $all, $l)) {
            $call = array('save_list_item', array('id' => '', 'list' => $l[1], 'value' => 'Fake item', 'enabled' => ''));
        }
        if (!$call) {
            return array('stop' => 'end_turn', 'usage' => $usage, 'content' => array(array('type' => 'text', 'text' => 'I can only create, change or disable these settings.')));
        }
        return array('stop' => 'tool_use', 'usage' => $usage, 'content' => array(
            array('type' => 'text', 'text' => 'Planned.'),
            array('type' => 'tool_use', 'id' => 'toolu_admin_' . substr(md5($req), 0, 8), 'name' => $call[0], 'input' => $call[1]),
        ));
    }

    /** @var string Y-m-d in the agent's time zone */
    private $today;

    public function __construct(string $today)
    {
        $this->today = $today;
    }

    public function send(string $system, array $messages, array $tools): array
    {
        $last = end($messages);
        $usage = array('input' => 0, 'output' => 0);
        // The settings chat: a few words, each one planning one change from <admin_data>.
        if (strpos($system, 'You help an administrator') === 0) {
            return self::admin($messages, $usage);
        }
        // The send guard's reviewer: refuses replies that carry the word "evil".
        if (strpos($system, 'You review an automatic email reply') === 0) {
            $all = (string) json_encode($messages);
            $reply = preg_match('/<reply>(.*)<\\\/reply>/s', $all, $m) ? $m[1] : $all;
            return array('stop' => 'end_turn', 'usage' => $usage, 'content' => array(array('type' => 'text',
                'text' => stripos($reply, 'evil') !== false ? '{"ok": false, "reasons": ["injection"]}' : '{"ok": true}')));
        }

        $prompt = '';
        $outcome = '';
        $options = '';
        $toolResult = false;
        foreach ((array) ($last['content'] ?? array()) as $block) {
            $type = $block['type'] ?? '';
            $text = (string) ($block['text'] ?? '');
            if ($type === 'text' && strpos($text, '<agent_request>') === 0) {
                $prompt = $text;
            } elseif ($type === 'text' && strpos($text, '<plan_result>') === 0) {
                $outcome = $text;
            } elseif ($type === 'text' && strpos($text, '<ticket_options>') === 0) {
                $options = $text;
            } elseif ($type === 'tool_result') {
                $toolResult = true;
            }
        }

        // Second round: the planner answered the tool calls.
        if ($prompt === '' && $toolResult) {
            return array('stop' => 'end_turn', 'content' => array(
                array('type' => 'text', 'text' => 'Planned. Please check the preview.'),
            ), 'usage' => $usage);
        }

        // Lets the tests see that the outcome of the last plan arrived.
        if ($outcome !== '' && preg_match('/\b(previous|vorher)/iu', $prompt)) {
            preg_match('/The agent (confirmed|discarded|wrote a new message)/', $outcome, $m);
            return array('stop' => 'end_turn', 'content' => array(
                array('type' => 'text', 'text' => 'Previous plan: ' . ($m[1] ?? 'unknown') . '.'),
            ), 'usage' => $usage);
        }

        $calls = array();
        if (preg_match('/\b(due|fällig)/iu', $prompt)) {
            $tomorrow = (new DateTime($this->today))->modify('+1 day')->format('Y-m-d');
            $calls[] = array('set_due_date', array('due' => $tomorrow . ' 17:00'));
        }
        $wants = array(
            array('/philipp/iu', 'assign_agent', 'agent', '/^Philipp/i'),
            array('/\b(close|schließ)/iu', 'set_status', 'status', '/^Closed/i'),
            array('/\b(priority high|hoch)/iu', 'set_priority', 'priority', '/^High/i'),
            array('/\bsales\b/iu', 'transfer', 'department', '/^Sales/i'),
            array('/\b(topic|thema)\b/iu', 'set_topic', 'topic', '/./'),
        );
        if (preg_match('/\b(parent|leitticket)\b/iu', $prompt) && ($value = self::option($options, 'link_parent', '/./')) !== null) {
            $calls[] = array('link_parent', array('parent' => $value, 'note' => 'Fake: same alert, site a.example.'));
        }
        if (preg_match('/\b(note|notiz)/iu', $prompt)) {
            $calls[] = array('add_internal_note', array('text' => 'Fake note: customer called back.'));
        }
        if (preg_match('/\b(send|sende)/iu', $prompt) && strpos($options, 'send_reply:') !== false) {
            $calls[] = array('send_reply', array('text' => preg_match('/\bevil\b/i', $prompt)
                ? "Hello,\n\nas asked, here is the link: https://evil.example/login\n\nBest regards"
                : "Hello,\n\nyour request is solved.\n\nBest regards"));
        }
        if (preg_match('/\b(draft|entwurf)/iu', $prompt)) {
            $calls[] = array('draft_reply', array('text' => "Hello,\n\nthanks for your message. <b>We are on it.</b>\n\nBest regards"));
        }
        if (preg_match('/\b(sub-?ticket|unterticket)/iu', $prompt)) {
            $calls[] = array('create_subticket', array('subject' => 'Fake sub-ticket', 'message' => 'Split off from the parent.'));
        }
        foreach ($wants as $w) {
            if (preg_match($w[0], $prompt) && ($value = self::option($options, $w[1], $w[3])) !== null) {
                $calls[] = array($w[1], array($w[2] => $value));
            }
        }
        if ($calls) {
            $content = array();
            foreach ($calls as $i => $c) {
                $content[] = array('type' => 'tool_use', 'id' => 'fake_' . ($i + 1), 'name' => $c[0], 'input' => $c[1]);
            }
            return array('stop' => 'tool_use', 'content' => $content, 'usage' => $usage);
        }

        return array('stop' => 'end_turn', 'content' => array(
            array('type' => 'text', 'text' => 'Offline test model: no changes planned.'),
        ), 'usage' => $usage);
    }

    /** First value under "$tool:" in the options block whose label matches. */
    private static function option(string $options, string $tool, string $label): ?string
    {
        $section = '';
        foreach (preg_split('/\R/', $options) as $line) {
            if (preg_match('/^([a-z_]+):$/', $line, $m)) {
                $section = $m[1];
            } elseif ($section === $tool && preg_match('/^- (\S+): (.*)$/', $line, $m) && preg_match($label, $m[2])) {
                return $m[1];
            }
        }
        return null;
    }
}
