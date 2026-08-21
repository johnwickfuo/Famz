<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;
use ZipArchive;

/**
 * Prove the backup can actually be restored.
 *
 * An untested backup is a hope, not a backup. The failure this catches is
 * specific and common: the archive exists, it is the right size, the monitor is
 * green — and it is truncated, or it dumped an empty database because the
 * credentials rotated, or mysqldump wrote its error into the file. Every one of
 * those looks fine from the outside and is discovered on the worst possible day.
 *
 * So this takes the newest archive, restores it into a scratch database, counts
 * what came back, and throws the scratch database away. It is slow and it runs
 * weekly, which is the right trade for the only check that actually answers the
 * question.
 */
class VerifyBackupRestore extends Command
{
    protected $signature = 'backup:verify-restore
        {--keep : Leave the scratch database behind for inspection}';

    protected $description = 'Restore the newest backup into a scratch database and check it came back whole';

    /**
     * Tables that must have rows for a restore to be believable.
     *
     * Chosen because they are never empty on a running platform. A restore
     * that produced a schema and no data would pass a "did it restore" check
     * and fail this one.
     */
    private const MUST_HAVE_ROWS = ['users', 'settings', 'categories'];

    public function handle(): int
    {
        $disk = (string) config('backup.backup.destination.disks.0', 'local');
        $archive = $this->newestArchive($disk);

        if ($archive === null) {
            $this->error('No backup archive found. That is itself the finding.');

            return self::FAILURE;
        }

        $this->info("Verifying {$archive}");

        $scratch = 'restore_check_'.Str::lower(Str::random(8));
        $local = storage_path('app/backup-verify.zip');

        try {
            file_put_contents($local, Storage::disk($disk)->get($archive));

            $sql = $this->extractDump($local);

            if ($sql === null) {
                $this->error('The archive contains no database dump.');

                return self::FAILURE;
            }

            $this->restoreInto($scratch, $sql);

            return $this->report($scratch);
        } catch (Throwable $exception) {
            $this->error('The restore failed: '.$exception->getMessage());

            return self::FAILURE;
        } finally {
            @unlink($local);

            if (! $this->option('keep')) {
                // Dropped whatever happened. A scratch database left behind
                // after a failed run is how a disk fills up quietly.
                DB::statement("DROP DATABASE IF EXISTS `{$scratch}`");
            }
        }
    }

    private function newestArchive(string $disk): ?string
    {
        $files = collect(Storage::disk($disk)->allFiles(config('backup.backup.name')))
            ->filter(fn (string $path): bool => str_ends_with($path, '.zip'))
            ->sortByDesc(fn (string $path): int => Storage::disk($disk)->lastModified($path));

        return $files->first();
    }

    private function extractDump(string $archivePath): ?string
    {
        $zip = new ZipArchive;

        if ($zip->open($archivePath) !== true) {
            return null;
        }

        $target = storage_path('app/backup-verify.sql');

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);

            if (! str_ends_with($name, '.sql')) {
                continue;
            }

            $contents = $zip->getFromIndex($i);
            $zip->close();

            if ($contents === false || trim((string) $contents) === '') {
                // An empty dump inside a well-formed archive: exactly the
                // failure this command exists to find.
                return null;
            }

            file_put_contents($target, $contents);

            return $target;
        }

        $zip->close();

        return null;
    }

    private function restoreInto(string $database, string $sqlPath): void
    {
        DB::statement("CREATE DATABASE `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        $config = config('database.connections.'.config('database.default'));

        /*
         * Restored with the client rather than by splitting the file in PHP.
         * A dump is not a list of statements separated by semicolons — a
         * semicolon inside a string literal or a stored routine will break any
         * naive splitter, and it will break it on the one row that matters.
         */
        $command = sprintf(
            'mysql --host=%s --port=%s --user=%s %s %s < %s 2>&1',
            escapeshellarg((string) ($config['host'] ?? '127.0.0.1')),
            escapeshellarg((string) ($config['port'] ?? 3306)),
            escapeshellarg((string) ($config['username'] ?? 'root')),
            filled($config['password'] ?? null) ? '--password='.escapeshellarg((string) $config['password']) : '',
            escapeshellarg($database),
            escapeshellarg($sqlPath),
        );

        exec($command, $output, $exitCode);

        @unlink($sqlPath);

        if ($exitCode !== 0) {
            throw new \RuntimeException('mysql exited '.$exitCode.': '.implode(' ', array_slice($output, 0, 3)));
        }
    }

    private function report(string $database): int
    {
        $rows = [];
        $problems = 0;

        foreach (self::MUST_HAVE_ROWS as $table) {
            try {
                $count = (int) DB::selectOne("SELECT COUNT(*) as c FROM `{$database}`.`{$table}`")->c;
            } catch (Throwable) {
                $count = -1;
            }

            if ($count <= 0) {
                $problems++;
            }

            $rows[] = [$table, $count < 0 ? 'MISSING' : number_format($count)];
        }

        $this->table(['Table', 'Rows restored'], $rows);

        if ($problems > 0) {
            $this->error('The restore produced a database that is missing data. The backup is not usable.');

            return self::FAILURE;
        }

        $this->info('The backup restores cleanly.');

        return self::SUCCESS;
    }
}
