<?php

declare(strict_types=1);

/**
 * Unit tests: no osTicket, no database, no network.
 *
 *   php tests/unit.php
 */

define('INCLUDE_DIR', __DIR__ . '/stubs/');
define('ROOT_PATH', '/');

$dir = getenv('PLUGIN_DIR') ?: dirname(__DIR__);
require $dir . '/class.AiActionsPlugin.php';

$failures = 0;
$count = 0;

function check(string $name, bool $ok, string $detail = ''): void
{
    global $failures, $count;
    $count++;
    if ($ok) {
        echo "ok   $name\n";
    } else {
        $failures++;
        echo "FAIL $name" . ($detail !== '' ? ": $detail" : '') . "\n";
    }
}

// ----------------------------------------------------------------- manifest

$manifest = require $dir . '/plugin.php';
check('manifest: version matches the plugin class', $manifest['version'] === AiActionsPlugin::VERSION,
    $manifest['version'] . ' vs ' . AiActionsPlugin::VERSION);
check('manifest: id is namespaced', $manifest['id'] === 'studio-prisma:ai-actions');
list($file, $class) = explode(':', $manifest['plugin']);
check('manifest: plugin entry points at the class', $file === 'class.AiActionsPlugin.php' && $class === 'AiActionsPlugin');

// ------------------------------------------------------------------- config

check('config: class is declared', class_exists('AiActionsConfig'));

$s = AiActionsController::normalise(array('enabled' => '1', 'roles' => '{"1":"All Access","3":"Expanded","x":"bad"}'));
check('settings: roles from stored JSON, junk keys dropped', $s['roles'] === array(1, 3) && $s['enabled'] === true);
$s = AiActionsController::normalise(array());
check('settings: off and nobody by default', $s['enabled'] === false && $s['roles'] === array());

// -------------------------------------------------------------------- tools

check('tools: due date normalised', AiActionsTools::validate('set_due_date', array('due' => '2026-11-02T17:00'))
    === array('due' => '2026-11-02 17:00'));
check('tools: impossible date rejected', AiActionsTools::validate('set_due_date', array('due' => '2026-02-30 10:00')) === null);
check('tools: bad time rejected', AiActionsTools::validate('set_due_date', array('due' => '2026-11-02 24:00')) === null);
check('tools: extra keys rejected', AiActionsTools::validate('set_due_date', array('due' => '2026-11-02 17:00', 'x' => 1)) === null);
check('tools: wrong type rejected', AiActionsTools::validate('set_due_date', array('due' => 20261102)) === null);
check('tools: unknown tool rejected', AiActionsTools::validate('close_ticket', array()) === null);
check('tools: every write tool has a definition',
    array_column(AiActionsTools::definitions(), 'name') === AiActionsTools::WRITE);
foreach (AiActionsTools::definitions() as $d) {
    check("tools: {$d['name']} schema is strict and closed",
        $d['strict'] === true && $d['input_schema']['additionalProperties'] === false);
}

$t = AiActionsI18n::strings('de_DE');
$sum = AiActionsTools::summary('set_due_date', array('due' => '2026-11-02 17:00'), $t);
check('tools: preview text comes from the input, not the model', $sum === array('label' => 'Fälligkeit', 'value' => '2026-11-02 17:00 (Mo)'));

// ------------------------------------------------------------------ planner

/** Plays back a fixed list of responses and records what it was sent. */
final class ScriptedLlm implements AiActionsLlm
{
    public $sent = array();
    private $responses;

    public function __construct(array $responses)
    {
        $this->responses = $responses;
    }

    public function send(string $system, array $messages, array $tools): array
    {
        $this->sent[] = $messages;
        return array_shift($this->responses) ?: array('stop' => 'end_turn', 'content' => array());
    }
}

function toolUse(string $name, $input, string $id = 'tu_1'): array
{
    return array('type' => 'tool_use', 'id' => $id, 'name' => $name, 'input' => $input);
}

$ctx = array('now' => '2026-11-01 11:00', 'weekday' => 'Sunday', 'timezone' => 'Europe/Berlin', 'ticket' => array('number' => '1'));

$r = (new AiActionsPlanner(new AiActionsFakeLlm('2026-11-01')))->plan($ctx, 'Setze die Fälligkeit auf morgen');
check('planner+fake: due date planned for tomorrow 17:00',
    $r['changes'] === array(array('action' => 'set_due_date', 'input' => array('due' => '2026-11-02 17:00'))));
check('planner+fake: answer from the final turn', $r['answer'] !== '');

$r = (new AiActionsPlanner(new AiActionsFakeLlm('2026-11-01')))->plan($ctx, 'What is this about?');
check('planner+fake: question plans nothing', $r['changes'] === array() && $r['answer'] !== '');

$llm = new ScriptedLlm(array(
    array('stop' => 'tool_use', 'content' => array(toolUse('set_due_date', array('due' => 'tomorrow')))),
    array('stop' => 'tool_use', 'content' => array(toolUse('set_due_date', array('due' => '2026-11-02 17:00'), 'tu_2'))),
    array('stop' => 'end_turn', 'content' => array(array('type' => 'text', 'text' => 'ok'))),
));
$r = (new AiActionsPlanner($llm))->plan($ctx, 'due tomorrow');
check('planner: invalid input answered with an error, model can correct',
    count($r['changes']) === 1 && $r['rejected'] === 1
    && ($llm->sent[1][2]['content'][0]['is_error'] ?? false) === true);

$llm = new ScriptedLlm(array(array('stop' => 'tool_use', 'content' => array(toolUse('close_ticket', array())))));
$r = (new AiActionsPlanner($llm))->plan($ctx, 'close it');
check('planner: unknown tool never queued', $r['changes'] === array() && $r['rejected'] === 1);

$loop = array();
for ($i = 0; $i < 20; $i++) {
    $loop[] = array('stop' => 'tool_use', 'content' => array(toolUse('set_due_date', array('due' => '2026-11-02 17:00'), "tu_$i")));
}
$llm = new ScriptedLlm($loop);
$r = (new AiActionsPlanner($llm))->plan($ctx, 'loop');
check('planner: stops after MAX_ROUNDS', count($llm->sent) === AiActionsPlanner::MAX_ROUNDS);

$many = array();
for ($i = 0; $i < 12; $i++) {
    $many[] = toolUse('set_due_date', array('due' => '2026-11-02 17:00'), "tu_$i");
}
$r = (new AiActionsPlanner(new ScriptedLlm(array(array('stop' => 'tool_use', 'content' => $many)))))->plan($ctx, 'many');
check('planner: at most MAX_CHANGES queued', count($r['changes']) === AiActionsPlanner::MAX_CHANGES && $r['rejected'] === 2);

// --------------------------------------------------------------- controller

check('prompt: trimmed', AiActionsController::promptText("  hi \r\n") === 'hi');
check('prompt: empty rejected', AiActionsController::promptText('   ') === null);
check('prompt: non-string rejected', AiActionsController::promptText(array('x')) === null);
check('prompt: invalid UTF-8 rejected', AiActionsController::promptText("\xff\xfe") === null);
check('prompt: 2000 characters ok, 2001 not',
    AiActionsController::promptText(str_repeat('ä', 2000)) !== null && AiActionsController::promptText(str_repeat('ä', 2001)) === null);

$body = AiActionsController::noteBody('due tomorrow', array(
    array('label' => 'Due date', 'value' => '2026-11-02 17:00 (Mon)', 'ok' => true),
    array('label' => 'Due date', 'value' => 'x', 'ok' => false, 'error' => 'forbidden'),
), AiActionsI18n::strings('en'));
check('note: request and every change listed',
    strpos($body, 'Request: due tomorrow') === 0 && strpos($body, '(done)') !== false
    && strpos($body, '(failed (permission denied))') !== false);

// ------------------------------------------------------------------- assets

check('assets: ticket view is a page', AiActionsAssets::isPage('/scp/tickets.php', 'GET', array('id' => '2'), false));
check('assets: subfolder install', AiActionsAssets::isPage('/help/scp/index.php', 'GET', array(), false));
check('assets: PJAX fragment skipped', !AiActionsAssets::isPage('/scp/tickets.php', 'GET', array(), true));
check('assets: POST skipped', !AiActionsAssets::isPage('/scp/tickets.php', 'POST', array(), false));
check('assets: downloads skipped', !AiActionsAssets::isPage('/scp/tickets.php', 'GET', array('a' => 'print'), false));
check('assets: other pages skipped', !AiActionsAssets::isPage('/scp/admin.php', 'GET', array(), false)
    && !AiActionsAssets::isPage('/tickets.php', 'GET', array(), false));
check('assets: html detection', AiActionsAssets::isHtml(array()) && AiActionsAssets::isHtml(array('Content-Type: text/html; charset=UTF-8'))
    && !AiActionsAssets::isHtml(array('Content-type: application/pdf')));

// ------------------------------------------------------------------- store

check('store: id format', AiActionsRunStore::isId(str_repeat('a1', 16)) && !AiActionsRunStore::isId("' OR 1=1 --")
    && !AiActionsRunStore::isId(str_repeat('A', 32)) && !AiActionsRunStore::isId(null));

echo "\n$count checks, $failures failed\n";
exit($failures ? 1 : 0);
