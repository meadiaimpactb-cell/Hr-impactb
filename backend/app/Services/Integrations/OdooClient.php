<?php

namespace App\Services\Integrations;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Minimal Odoo JSON-RPC client (works with Odoo 14 → 18, API key or password).
 * Every call goes through execute_kw so any model/method is reachable.
 */
class OdooClient
{
    private ?int $uid = null;

    public function __construct(private string $url, private string $db, private string $user, private string $secret, private int $timeout = 20)
    {
        $this->url = rtrim($url, '/');
    }

    public function version(): array
    {
        return $this->rpc('common', 'version', []);
    }

    public function login(): int
    {
        if ($this->uid) {
            return $this->uid;
        }
        $uid = $this->rpc('common', 'authenticate', [$this->db, $this->user, $this->secret, []]);
        if (! $uid) {
            throw new RuntimeException('فشل تسجيل الدخول إلى أودو — تحقق من قاعدة البيانات والمستخدم ومفتاح API');
        }

        return $this->uid = (int) $uid;
    }

    public function call(string $model, string $method, array $args = [], array $kwargs = []): mixed
    {
        return $this->rpc('object', 'execute_kw', [$this->db, $this->login(), $this->secret, $model, $method, $args, $kwargs]);
    }

    public function searchRead(string $model, array $domain, array $fields, int $limit = 100, int $offset = 0, ?string $order = null): array
    {
        return $this->call($model, 'search_read', [$domain], array_filter(['fields' => $fields, 'limit' => $limit, 'offset' => $offset, 'order' => $order]));
    }

    public function search(string $model, array $domain, int $limit = 1): array
    {
        return $this->call($model, 'search', [$domain], ['limit' => $limit]);
    }

    public function create(string $model, array $values): int
    {
        return (int) $this->call($model, 'create', [$values]);
    }

    public function write(string $model, int $id, array $values): bool
    {
        return (bool) $this->call($model, 'write', [[$id], $values]);
    }

    public function fieldsGet(string $model): array
    {
        return $this->call($model, 'fields_get', [], ['attributes' => ['string', 'type', 'required', 'relation']]);
    }

    private function rpc(string $service, string $method, array $args): mixed
    {
        $res = Http::timeout($this->timeout)->acceptJson()->post($this->url.'/jsonrpc', [
            'jsonrpc' => '2.0', 'method' => 'call', 'id' => random_int(1, 999999),
            'params' => ['service' => $service, 'method' => $method, 'args' => $args],
        ]);
        if (! $res->ok()) {
            throw new RuntimeException('Odoo HTTP '.$res->status());
        }
        $body = $res->json();
        if (isset($body['error'])) {
            $msg = $body['error']['data']['message'] ?? $body['error']['message'] ?? 'Odoo error';
            throw new RuntimeException(mb_substr($msg, 0, 500));
        }

        return $body['result'] ?? null;
    }
}
