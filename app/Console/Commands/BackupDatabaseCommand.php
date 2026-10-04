<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/**
 * Dumps the database to storage/app/backups.
 *
 * Credentials are passed to mysqldump through its environment rather than on
 * the command line, because arguments are visible to every other user on the
 * machine via the process list. The legacy backup page built a shell string
 * with the password inline.
 */
class BackupDatabaseCommand extends Command
{
    protected $signature = 'telecom:backup
        {--keep=14 : How many previous backups to retain}
        {--path= : Directory to write into; defaults to storage/app/backups}';

    protected $description = 'Create a timestamped database backup';

    public function handle(): int
    {
        $connection = config('database.default');
        $db = config("database.connections.{$connection}");

        if (($db['driver'] ?? null) !== 'mysql') {
            $this->error('Only MySQL/MariaDB connections can be backed up with this command.');

            return self::FAILURE;
        }

        $directory = $this->option('path') ?: storage_path('app/backups');

        if (! is_dir($directory) && ! mkdir($directory, 0750, true) && ! is_dir($directory)) {
            $this->error("Could not create {$directory}.");

            return self::FAILURE;
        }

        $file = sprintf('%s/%s-%s.sql', rtrim($directory, '/\\'), $db['database'], now()->format('Ymd-His'));

        $process = new Process([
            $this->mysqldumpPath(),
            '--host='.$db['host'],
            '--port='.$db['port'],
            '--user='.$db['username'],
            '--single-transaction',
            '--quick',
            '--routines',
            '--events',
            '--add-drop-table',
            '--result-file='.$file,
            $db['database'],
        ], env: [
            // Keeps the password out of the process list.
            'MYSQL_PWD' => (string) $db['password'],
        ], timeout: 1800);

        $this->info("Backing up {$db['database']}...");

        $process->run();

        if (! $process->isSuccessful()) {
            $this->error('Backup failed: '.trim($process->getErrorOutput()));

            @unlink($file);

            return self::FAILURE;
        }

        $size = is_file($file) ? filesize($file) : 0;

        $this->info(sprintf('Wrote %s (%s)', $file, $this->humanSize((int) $size)));

        $this->prune($directory, (int) $this->option('keep'));

        return self::SUCCESS;
    }

    /** Delete all but the newest `$keep` dumps. */
    private function prune(string $directory, int $keep): void
    {
        if ($keep <= 0) {
            return;
        }

        $files = glob(rtrim($directory, '/\\').'/*.sql') ?: [];

        usort($files, fn ($a, $b) => filemtime($b) <=> filemtime($a));

        foreach (array_slice($files, $keep) as $old) {
            @unlink($old);
            $this->line('  removed old backup '.basename($old));
        }
    }

    private function mysqldumpPath(): string
    {
        $configured = env('TELECOM_MYSQLDUMP_PATH');

        return is_string($configured) && $configured !== '' ? $configured : 'mysqldump';
    }

    private function humanSize(int $bytes): string
    {
        foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
            if ($bytes < 1024) {
                return round($bytes, 1).' '.$unit;
            }

            $bytes /= 1024;
        }

        return round($bytes, 1).' TB';
    }
}
