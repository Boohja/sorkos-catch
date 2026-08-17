<?php

declare(strict_types=1);

namespace Catch\Repositories;

use Catch\Core\Config;
use Catch\Core\Id;
use PDO;

final class EmailInboxRepository
{
    public function __construct(
        private readonly PDO $db,
        private readonly Config $config,
    ) {
    }

    public function create(string $userId): array
    {
        $id = Id::uuid();
        $token = $this->base32(random_bytes(10));
        $domain = mb_strtolower(trim((string) $this->config->get('mail.address_domain', 'catch.sorkos.net')));
        $address = 'ibx-' . $token . '@' . $domain;
        $query = $this->db->prepare(
            'INSERT INTO catch_email_inboxes (id,user_id,address,created_at) '
            . 'VALUES (:id,:user,:address,UTC_TIMESTAMP(6))',
        );
        $query->execute([
            'id' => $id,
            'user' => $userId,
            'address' => $address,
        ]);

        return $this->findOwned($id, $userId);
    }

    public function findActiveByAddress(string $address): ?array
    {
        $query = $this->db->prepare(
            'SELECT id,user_id,created_at FROM catch_email_inboxes '
            . 'WHERE address=:address AND revoked_at IS NULL LIMIT 1',
        );
        $query->execute(['address' => mb_strtolower(trim($address))]);

        return $query->fetch() ?: null;
    }

    public function all(string $userId): array
    {
        $query = $this->db->prepare(
            'SELECT id,address,created_at,revoked_at FROM catch_email_inboxes '
            . 'WHERE user_id=:user ORDER BY created_at DESC',
        );
        $query->execute(['user' => $userId]);

        return $query->fetchAll();
    }

    public function revoke(string $id, string $userId): void
    {
        $query = $this->db->prepare(
            'UPDATE catch_email_inboxes SET revoked_at=UTC_TIMESTAMP(6) '
            . 'WHERE id=:id AND user_id=:user AND revoked_at IS NULL',
        );
        $query->execute(['id' => $id, 'user' => $userId]);
    }

    private function findOwned(string $id, string $userId): array
    {
        $query = $this->db->prepare(
            'SELECT id,address,created_at,revoked_at FROM catch_email_inboxes WHERE id=:id AND user_id=:user LIMIT 1',
        );
        $query->execute(['id' => $id, 'user' => $userId]);

        return $query->fetch() ?: [];
    }

    private function base32(string $bytes): string
    {
        $alphabet = 'abcdefghijklmnopqrstuvwxyz234567';
        $buffer = 0;
        $bits = 0;
        $encoded = '';
        foreach (unpack('C*', $bytes) ?: [] as $byte) {
            $buffer = ($buffer << 8) | $byte;
            $bits += 8;
            while ($bits >= 5) {
                $bits -= 5;
                $encoded .= $alphabet[($buffer >> $bits) & 31];
                $buffer &= (1 << $bits) - 1;
            }
        }
        if ($bits > 0) {
            $encoded .= $alphabet[($buffer << (5 - $bits)) & 31];
        }

        return $encoded;
    }
}
