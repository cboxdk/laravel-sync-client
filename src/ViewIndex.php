<?php

declare(strict_types=1);

namespace Cbox\Sync\Client\Laravel;

/**
 * Which view this device has already bootstrapped.
 *
 * The replica keys everything on a context fingerprint, which only the server
 * can compute - it owns the space and the view definition. So a device that
 * forgot which fingerprint belongs to which (type, scope) would open a fresh
 * bootstrap on every sync and be told it already entered delta. This is the
 * one small piece of local bookkeeping that cannot live anywhere else.
 */
class ViewIndex
{
    public function __construct(private readonly \PDO $pdo)
    {
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }

    public function migrate(): void
    {
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS sync_client_views_index (
            selector TEXT NOT NULL,
            fingerprint TEXT NOT NULL,
            PRIMARY KEY (selector)
        )');
    }

    public function fingerprint(string $type, ?string $scope): ?string
    {
        $statement = $this->pdo->prepare('SELECT fingerprint FROM sync_client_views_index WHERE selector = ?');
        $statement->execute([self::selector($type, $scope)]);
        $value = $statement->fetchColumn();

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function remember(string $type, ?string $scope, string $fingerprint): void
    {
        // One statement: a delete and an insert are two commits, and a crash
        // between them forgot the view outright.
        $this->pdo->prepare('INSERT INTO sync_client_views_index (selector, fingerprint) VALUES (?, ?) ON CONFLICT (selector) DO UPDATE SET fingerprint = excluded.fingerprint')
            ->execute([self::selector($type, $scope), $fingerprint]);
    }

    public function forget(string $type, ?string $scope): void
    {
        $this->pdo->prepare('DELETE FROM sync_client_views_index WHERE selector = ?')->execute([self::selector($type, $scope)]);
    }

    private static function selector(string $type, ?string $scope): string
    {
        return $type."\0".($scope ?? '');
    }
}
