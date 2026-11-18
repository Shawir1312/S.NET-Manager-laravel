<?php

namespace App\Services\Mikrotik;

class MikrotikConnection
{
    public function __construct(private readonly ?RouterosAPI $api) {}

    /**
     * Kirim perintah RouterOS API. Respons `!trap` dilempar sebagai exception.
     *
     * @param  array<string, string>  $args
     * @return list<array<string, string>>
     */
    public function comm(string $command, array $args = []): array
    {
        $result = $this->api->comm($command, $args);

        if (is_array($result) && isset($result['!trap'])) {
            throw new MikrotikException($result['!trap'][0]['message'] ?? "Perintah {$command} ditolak router.");
        }

        return is_array($result) ? array_values(array_filter($result, 'is_array')) : [];
    }

    public function close(): void
    {
        $this->api?->disconnect();
    }

    // ---- Perintah PPP yang dipakai banyak modul ----

    /** @return array<string, string>|null */
    public function findSecret(string $username): ?array
    {
        return $this->comm('/ppp/secret/print', ['?name' => $username])[0] ?? null;
    }

    /** Buat atau perbarui PPP secret. */
    public function upsertSecret(string $username, array $attributes): void
    {
        $existing = $this->findSecret($username);
        if ($existing) {
            $this->comm('/ppp/secret/set', ['.id' => $existing['.id']] + $attributes);
        } else {
            $this->comm('/ppp/secret/add', ['name' => $username, 'service' => 'pppoe'] + $attributes);
        }
    }

    public function removeSecret(string $username): void
    {
        if ($existing = $this->findSecret($username)) {
            $this->comm('/ppp/secret/remove', ['.id' => $existing['.id']]);
        }
    }

    /** Putus sesi aktif agar profil/password baru langsung berlaku. */
    public function kickActive(string $username): void
    {
        foreach ($this->comm('/ppp/active/print', ['?name' => $username]) as $session) {
            $this->comm('/ppp/active/remove', ['.id' => $session['.id']]);
        }
    }

    /** @return list<string> */
    public function pppProfiles(): array
    {
        return array_values(array_filter(array_column($this->comm('/ppp/profile/print', ['.proplist' => 'name']), 'name')));
    }
}
