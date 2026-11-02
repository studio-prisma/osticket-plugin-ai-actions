<?php

declare(strict_types=1);

/**
 * OpenAI and Mistral, next to AiActionsClaude.
 *
 * The conversation is stored in the Anthropic shape (content blocks: text,
 * tool_use, tool_result). These clients translate it on every call and
 * translate the answer back, so the planner, the chat store and the tests
 * stay the same for every provider. Blocks another provider produced
 * (Anthropic thinking, OpenAI reasoning) are left out when translating;
 * a conversation can switch provider when the admin changes the model.
 *
 * Plain HTTP, like the Claude client. The key only ever goes into the
 * Authorization header; errors are logged by type, never with the body.
 */
final class AiActionsHttp
{
    public const TIMEOUT = 60;

    /**
     * @param callable|null $transport replaces the HTTP call in the unit tests: fn(url, headers, payload)
     * @return array{0:int, 1:string, 2:int} status, body, retry-after seconds
     */
    public static function post(string $url, array $headers, string $payload, ?callable $transport = null): array
    {
        if ($transport) {
            return call_user_func($transport, $url, $headers, $payload);
        }
        $retryAfter = 0;
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADERFUNCTION => static function ($ch, $line) use (&$retryAfter) {
                if (stripos($line, 'retry-after:') === 0) {
                    $retryAfter = (int) trim(substr($line, 12));
                }
                return strlen($line);
            },
        ));
        $body = curl_exec($ch);
        $status = $body === false ? 0 : (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        return array($status, is_string($body) ? $body : '', $retryAfter);
    }

    /**
     * POST with one retry on overload, then the decoded JSON.
     *
     * @throws AiActionsLlmError
     */
    public static function json(string $url, array $headers, array $body, ?callable $transport = null): array
    {
        $payload = (string) json_encode($body, JSON_UNESCAPED_UNICODE);
        $attempt = 0;
        while (true) {
            $attempt++;
            list($status, $raw, $retryAfter) = self::post($url, $headers, $payload, $transport);
            if ($status === 200) {
                break;
            }
            $kind = AiActionsClaude::kind($status);
            if ($kind === 'unavailable' && $attempt === 1) {
                sleep(max(1, min(5, $retryAfter ?: 2)));
                continue;
            }
            throw new AiActionsLlmError($kind, 'HTTP ' . $status . ' ' . self::errorType($raw));
        }
        $res = json_decode((string) $raw, true);
        if (!is_array($res)) {
            throw new AiActionsLlmError('broken', 'unreadable response');
        }
        return $res;
    }

    /** The provider's error type or code, never its message (it may quote the request). */
    public static function errorType($raw): string
    {
        $j = json_decode((string) $raw, true);
        $type = '';
        if (is_array($j)) {
            $err = is_array($j['error'] ?? null) ? $j['error'] : $j;
            $type = (string) ($err['type'] ?? $err['code'] ?? '');
        }
        return preg_match('/^[a-z_]{1,50}$/', $type) ? $type : '';
    }

    /** Our tool definitions (Anthropic shape) as JSON Schema function parameters. */
    public static function functions(array $tools): array
    {
        $out = array();
        foreach ($tools as $t) {
            if (isset($t['type'])) {
                continue; // a Claude server tool (web_fetch), nothing to call here
            }
            $out[] = array(
                'name'        => (string) $t['name'],
                'description' => (string) ($t['description'] ?? ''),
                'parameters'  => $t['input_schema'] ?? array('type' => 'object', 'properties' => new stdClass()),
            );
        }
        return $out;
    }

    /** Text of a tool_result block; documents and images are Claude-only and become a note. */
    public static function resultText($content): string
    {
        if (is_string($content)) {
            return $content;
        }
        $parts = array();
        foreach ((array) $content as $c) {
            if (($c['type'] ?? '') === 'text') {
                $parts[] = (string) ($c['text'] ?? '');
            } else {
                $parts[] = '[A file this model cannot read was left out.]';
            }
        }
        return implode("\n", $parts);
    }

    /** Arguments as an array, whether the provider sent a JSON string or an object. */
    public static function arguments($raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        $v = json_decode((string) $raw, true);
        return is_array($v) ? $v : array();
    }
}

/**
 * OpenAI Responses API. store=false keeps the conversation off OpenAI's
 * servers; reasoning comes back encrypted and is sent back unchanged in
 * the next round, kept in an "x_openai" block of the assistant message.
 */
final class AiActionsOpenAi implements AiActionsLlm
{
    public const ENDPOINT = 'https://api.openai.com/v1/responses';
    public const MAX_TOKENS = 8000;

    /** @var string */
    private $key;
    /** @var string */
    private $model;
    /** @var string */
    private $effort;
    /** @var callable|null */
    private $transport;

    public function __construct(string $key, string $model, string $effort, ?callable $transport = null)
    {
        $this->key = $key;
        $this->model = $model;
        $this->effort = $effort;
        $this->transport = $transport;
    }

    /** Public for the unit tests. */
    public function body(string $system, array $messages, array $tools): array
    {
        $body = array(
            'model'             => $this->model,
            'instructions'      => $system,
            'input'             => self::input($messages),
            'reasoning'         => array('effort' => $this->effort),
            'store'             => false,
            'include'           => array('reasoning.encrypted_content'),
            'max_output_tokens' => self::MAX_TOKENS,
        );
        if ($functions = AiActionsHttp::functions($tools)) {
            $body['tools'] = array_map(static function ($f) {
                return array('type' => 'function') + $f + array('strict' => false);
            }, $functions);
        }
        return $body;
    }

    /** Anthropic-shaped messages as Responses input items. Public for the unit tests. */
    public static function input(array $messages): array
    {
        $items = array();
        foreach ($messages as $m) {
            $blocks = is_array($m['content'] ?? null) ? $m['content'] : array(array('type' => 'text', 'text' => (string) ($m['content'] ?? '')));
            if (($m['role'] ?? '') === 'assistant') {
                $native = null;
                foreach ($blocks as $b) {
                    if (($b['type'] ?? '') === 'x_openai') {
                        $native = (array) ($b['items'] ?? array());
                    }
                }
                if ($native !== null) {
                    foreach ($native as $it) {
                        $items[] = $it;
                    }
                    continue;
                }
                foreach ($blocks as $b) {
                    if (($b['type'] ?? '') === 'text' && ($b['text'] ?? '') !== '') {
                        $items[] = array('role' => 'assistant', 'content' => array(array('type' => 'output_text', 'text' => (string) $b['text'])));
                    } elseif (($b['type'] ?? '') === 'tool_use') {
                        $items[] = array('type' => 'function_call', 'call_id' => (string) $b['id'], 'name' => (string) $b['name'],
                                         'arguments' => (string) json_encode($b['input'] ?? new stdClass(), JSON_UNESCAPED_UNICODE));
                    }
                }
                continue;
            }
            $texts = array();
            foreach ($blocks as $b) {
                if (($b['type'] ?? '') === 'tool_result') {
                    $items[] = array('type' => 'function_call_output', 'call_id' => (string) $b['tool_use_id'],
                                     'output' => AiActionsHttp::resultText($b['content'] ?? ''));
                } elseif (($b['type'] ?? '') === 'text') {
                    $texts[] = array('type' => 'input_text', 'text' => (string) $b['text']);
                }
            }
            if ($texts) {
                $items[] = array('role' => 'user', 'content' => $texts);
            }
        }
        return $items;
    }

    public function send(string $system, array $messages, array $tools): array
    {
        $res = AiActionsHttp::json(self::ENDPOINT, array(
            'content-type: application/json',
            'authorization: Bearer ' . $this->key,
        ), $this->body($system, $messages, $tools), $this->transport);
        return self::parse($res);
    }

    /** Public for the unit tests. */
    public static function parse(array $res): array
    {
        $output = is_array($res['output'] ?? null) ? $res['output'] : null;
        if ($output === null) {
            throw new AiActionsLlmError('broken', 'unreadable response');
        }
        $content = array();
        $calls = false;
        foreach ($output as $it) {
            $type = $it['type'] ?? '';
            if ($type === 'message') {
                foreach ((array) ($it['content'] ?? array()) as $part) {
                    if (($part['type'] ?? '') === 'refusal') {
                        throw new AiActionsLlmError('refused', '');
                    }
                    if (($part['type'] ?? '') === 'output_text') {
                        $content[] = array('type' => 'text', 'text' => (string) ($part['text'] ?? ''));
                    }
                }
            } elseif ($type === 'function_call') {
                $calls = true;
                $content[] = array('type' => 'tool_use', 'id' => (string) ($it['call_id'] ?? ''), 'name' => (string) ($it['name'] ?? ''),
                                   'input' => AiActionsHttp::arguments($it['arguments'] ?? ''));
            }
        }
        $content[] = array('type' => 'x_openai', 'items' => $output);
        $incomplete = ($res['status'] ?? '') === 'incomplete';
        return array(
            'stop'    => $calls ? 'tool_use' : ($incomplete ? 'max_tokens' : 'end_turn'),
            'content' => $content,
            'usage'   => array(
                'input'  => (int) ($res['usage']['input_tokens'] ?? 0),
                'output' => (int) ($res['usage']['output_tokens'] ?? 0),
            ),
            'model'   => (string) ($res['model'] ?? ''),
        );
    }
}

/** Mistral Chat Completions. */
final class AiActionsMistral implements AiActionsLlm
{
    public const ENDPOINT = 'https://api.mistral.ai/v1/chat/completions';
    public const MAX_TOKENS = 4000;

    /** @var string */
    private $key;
    /** @var string */
    private $model;
    /** @var callable|null */
    private $transport;

    public function __construct(string $key, string $model, ?callable $transport = null)
    {
        $this->key = $key;
        $this->model = $model;
        $this->transport = $transport;
    }

    /** Public for the unit tests. */
    public function body(string $system, array $messages, array $tools): array
    {
        $body = array(
            'model'      => $this->model,
            'messages'   => self::messages($system, $messages),
            'max_tokens' => self::MAX_TOKENS,
        );
        if ($functions = AiActionsHttp::functions($tools)) {
            $body['tools'] = array_map(static function ($f) {
                return array('type' => 'function', 'function' => $f);
            }, $functions);
            $body['tool_choice'] = 'auto';
        }
        return $body;
    }

    /**
     * Mistral wants tool call IDs of nine letters and digits; IDs from
     * another provider are mapped, the same way for the call and its result.
     * Public for the unit tests.
     */
    public static function callId(string $id): string
    {
        return preg_match('/^[A-Za-z0-9]{9}$/', $id) ? $id : substr(md5($id), 0, 9);
    }

    /** Public for the unit tests. */
    public static function messages(string $system, array $messages): array
    {
        $out = array(array('role' => 'system', 'content' => $system));
        $names = array();
        foreach ($messages as $m) {
            $blocks = is_array($m['content'] ?? null) ? $m['content'] : array(array('type' => 'text', 'text' => (string) ($m['content'] ?? '')));
            if (($m['role'] ?? '') === 'assistant') {
                $text = array();
                $calls = array();
                foreach ($blocks as $b) {
                    if (($b['type'] ?? '') === 'text') {
                        $text[] = (string) $b['text'];
                    } elseif (($b['type'] ?? '') === 'tool_use') {
                        $id = self::callId((string) $b['id']);
                        $names[$id] = (string) $b['name'];
                        $calls[] = array('id' => $id, 'type' => 'function', 'function' => array(
                            'name' => (string) $b['name'],
                            'arguments' => (string) json_encode($b['input'] ?? new stdClass(), JSON_UNESCAPED_UNICODE)));
                    }
                }
                if (!$text && !$calls) {
                    continue;
                }
                $msg = array('role' => 'assistant', 'content' => implode("\n\n", $text));
                if ($calls) {
                    $msg['tool_calls'] = $calls;
                }
                $out[] = $msg;
                continue;
            }
            $text = array();
            foreach ($blocks as $b) {
                if (($b['type'] ?? '') === 'tool_result') {
                    $id = self::callId((string) $b['tool_use_id']);
                    $out[] = array('role' => 'tool', 'tool_call_id' => $id, 'name' => $names[$id] ?? '',
                                   'content' => AiActionsHttp::resultText($b['content'] ?? ''));
                } elseif (($b['type'] ?? '') === 'text') {
                    $text[] = (string) $b['text'];
                }
            }
            if ($text) {
                $out[] = array('role' => 'user', 'content' => implode("\n\n", $text));
            }
        }
        return $out;
    }

    public function send(string $system, array $messages, array $tools): array
    {
        $res = AiActionsHttp::json(self::ENDPOINT, array(
            'content-type: application/json',
            'accept: application/json',
            'authorization: Bearer ' . $this->key,
        ), $this->body($system, $messages, $tools), $this->transport);
        return self::parse($res);
    }

    /** Public for the unit tests. */
    public static function parse(array $res): array
    {
        $choice = $res['choices'][0] ?? null;
        if (!is_array($choice) || !is_array($choice['message'] ?? null)) {
            throw new AiActionsLlmError('broken', 'unreadable response');
        }
        $msg = $choice['message'];
        $content = array();
        $text = $msg['content'] ?? '';
        if (is_array($text)) {
            // Reasoning models answer in chunks; only the text chunks count.
            $text = implode('', array_map(static function ($c) {
                return ($c['type'] ?? '') === 'text' ? (string) ($c['text'] ?? '') : '';
            }, $text));
        }
        if (trim((string) $text) !== '') {
            $content[] = array('type' => 'text', 'text' => (string) $text);
        }
        foreach ((array) ($msg['tool_calls'] ?? array()) as $c) {
            $content[] = array('type' => 'tool_use', 'id' => (string) ($c['id'] ?? ''), 'name' => (string) ($c['function']['name'] ?? ''),
                               'input' => AiActionsHttp::arguments($c['function']['arguments'] ?? ''));
        }
        $finish = (string) ($choice['finish_reason'] ?? '');
        $stop = !empty($msg['tool_calls']) ? 'tool_use' : ($finish === 'length' ? 'max_tokens' : 'end_turn');
        return array(
            'stop'    => $stop,
            'content' => $content,
            'usage'   => array(
                'input'  => (int) ($res['usage']['prompt_tokens'] ?? 0),
                'output' => (int) ($res['usage']['completion_tokens'] ?? 0),
            ),
            'model'   => (string) ($res['model'] ?? ''),
        );
    }
}
