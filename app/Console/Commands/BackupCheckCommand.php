<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;
use RuntimeException;

class BackupCheckCommand extends Command
{
    protected $signature = 'kaura:backup-check';

    protected $description = 'Create a consistent SQLite backup and verify an isolated restored copy';

    private function query(PDO $connection, string $sql): \PDOStatement
    {
        $statement = $connection->query($sql);
        if ($statement === false) {
            throw new RuntimeException('Backup verification query failed.');
        }

        return $statement;
    }

    public function handle(): int
    {
        if (DB::getDriverName() !== 'sqlite' || config('database.connections.sqlite.database') === ':memory:') {
            $this->error('This command supports file-based SQLite. For PostgreSQL, use pg_dump and verify pg_restore into an isolated database.');

            return self::FAILURE;
        }
        $directory = storage_path('app/backups');
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Could not create the backup directory.');
        }
        $backup = $directory.'/kaura-'.now('Africa/Nairobi')->format('Ymd-His').'-'.Str::uuid().'.sqlite';
        $restore = $directory.'/restore-check-'.Str::uuid().'.sqlite';
        DB::statement('VACUUM INTO '.DB::getPdo()->quote($backup));
        if (! copy($backup, $restore)) {
            throw new RuntimeException('Could not create the isolated restore copy. Original backup is retained.');
        }
        try {
            $snapshot = new PDO('sqlite:'.$backup);
            $restored = new PDO('sqlite:'.$restore);
            foreach ([$snapshot, $restored] as $connection) {
                $connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                if ($this->query($connection, 'PRAGMA integrity_check')->fetchColumn() !== 'ok' || $this->query($connection, 'PRAGMA foreign_key_check')->fetchAll() !== []) {
                    throw new RuntimeException('Backup/restore integrity verification failed. Backup retained for investigation.');
                }
            }
            $tables = $this->query($snapshot, "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
            $counts = [];
            foreach ($tables as $table) {
                $name = '"'.str_replace('"', '""', (string) $table).'"';
                $count = $this->query($snapshot, 'SELECT COUNT(*) FROM '.$name)->fetchColumn();
                if ($this->query($restored, 'SELECT COUNT(*) FROM '.$name)->fetchColumn() !== $count) {
                    throw new RuntimeException('Restored row counts differ for '.$table.'.');
                }
                $counts[(string) $table] = (int) $count;
            }
            $receipt = ['created_at_utc' => now()->toISOString(), 'backup' => basename($backup), 'sha256' => hash_file('sha256', $backup), 'integrity' => 'ok', 'foreign_keys' => 'ok', 'restored_table_counts' => $counts, 'scope' => 'SQLite consistency and isolated restore; does not replace operator recovery rehearsal'];
            file_put_contents($backup.'.verification.json', json_encode($receipt, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            $snapshot = null;
            $restored = null;
        } finally {
            unset($snapshot, $restored, $connection);
            if (is_file($restore)) {
                unlink($restore);
            }
        }
        $this->info('Backup and isolated restore verification passed: '.$backup);

        return self::SUCCESS;
    }
}
