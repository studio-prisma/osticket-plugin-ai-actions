<?php

declare(strict_types=1);

/** A failed model call. $kind is unavailable, auth, bad_request, refused or broken. */
final class AiActionsLlmError extends RuntimeException
{
    /** @var string */
    public $kind;

    public function __construct(string $kind, string $detail = '')
    {
        parent::__construct($detail);
        $this->kind = $kind;
    }
}

/**
 * The Messages API over plain HTTP (cURL).
 *
 * Deliberately not the PHP SDK: the plugin makes one kind of request, and
 * the SDK would add 16 packages and 23 MB that share the PHP process with
 * every other plugin's libraries. Shapes follow the API reference for
 * version 2026-10-21.
 *
 * Content blocks come back unchanged, including thinking and fallback
 * blocks: they have to be sent back as they are in the next round.
 */
final class AiActionsClaude implements AiActionsLlm
{
    public const ENDPOINT = 'https://api.anthropic.com/v1/messages';
    public const API_VERSION = '2026-10-21';
    public const FALLBACK_BETA = 'server-side-fallback-2026-07-01';

    /** Output budget per round. Plans and short answers stay far below. */
    public const MAX_TOKENS = 4000;

    public const TIMEOUT = 60;

    /** @var string */
    private $key;
    /** @var string */
    private $model;
    /** @var string */
    private $effort;
    /** @var callable|null replaces the HTTP call in the unit tests */
    private $transport;

    public function __construct(string $key, string $model, string $effort, ?callable $transport = null)
    {
        $this->key = $key;
        $this->model = $model;
        $this->effort = $effort;
        $this->transport = $transport;
    }

    public function body(string $system, array $messages, array $tools): array
    {
        $body = array(
            'model'         => $this->model,
            'max_tokens'    => self::MAX_TOKENS,
            'system'        => $system,
            'messages'      => $messages,
            'output_config' => array('effort' => $this->effort),
            'fallbacks'     => 'default',
        );
        if ($tools) {
            $body['tools'] = $tools;
        }
        return $body;
    }

    public function send(string $system, array $messages, array $tools): array
    {
        $payload = (string) json_encode($this->body($system, $messages, $tools), JSON_UNESCAPED_UNICODE);

        $attempt = 0;
        while (true) {
            $attempt++;
            list($status, $raw, $retryAfter) = $this->post($payload);
            if ($status === 200) {
                break;
            }
            $kind = self::kind($status);
            if ($kind === 'unavailable' && $attempt === 1) {
                sleep(max(1, min(5, $retryAfter ?: 2)));
                continue;
            }
            throw new AiActionsLlmError($kind, 'HTTP ' . $status . ' ' . self::errorType($raw));
        }

        $res = json_decode((string) $raw, true);
        if (!is_array($res) || !isset($res['content']) || !is_array($res['content'])) {
            throw new AiActionsLlmError('broken', 'unreadable response');
        }
        $stop = (string) ($res['stop_reason'] ?? '');
        if ($stop === 'refusal') {
            throw new AiActionsLlmError('refused', (string) ($res['stop_details']['category'] ?? ''));
        }
        return array(
            'stop'    => $stop,
            'content' => $res['content'],
            'usage'   => array(
                'input'  => (int) ($res['usage']['input_tokens'] ?? 0),
                'output' => (int) ($res['usage']['output_tokens'] ?? 0),
            ),
            'model'   => (string) ($res['model'] ?? ''),
        );
    }

    /** Public for the unit tests. */
    public static function kind(int $status): string
    {
        if ($status === 0 || $status === 408 || $status === 409 || $status === 429 || $status >= 500) {
            return 'unavailable';
        }
        if ($status === 401 || $status === 403) {
            return 'auth';
        }
        return 'bad_request';
    }

    /** The API's error type ("rate_limit_error"), never the message: it may quote the request. */
    private static function errorType($raw): string
    {
        $j = json_decode((string) $raw, true);
        $type = is_array($j) ? (string) ($j['error']['type'] ?? '') : '';
        return preg_match('/^[a-z_]{1,50}$/', $type) ? $type : '';
    }

    /** @return array{0:int, 1:string, 2:int} status, body, retry-after seconds */
    private function post(string $payload): array
    {
        $headers = array(
            'content-type: application/json',
            'x-api-key: ' . $this->key,
            'anthropic-version: ' . self::API_VERSION,
            'anthropic-beta: ' . self::FALLBACK_BETA,
        );
        if ($this->transport) {
            return call_user_func($this->transport, self::ENDPOINT, $headers, $payload);
        }

        $retryAfter = 0;
        $ch = curl_init(self::ENDPOINT);
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
}
