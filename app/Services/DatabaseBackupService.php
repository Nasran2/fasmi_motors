<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

class DatabaseBackupService
{
    public function backups(): array
    {
        return collect(Storage::disk('local')->files('database-backups'))
            ->filter(fn ($path) => preg_match('/^database-backups\/database-[A-Za-z0-9-]+\.sql$/D', $path))
            ->sortDesc()->map(fn ($path) => [
                'id' => basename($path),
                'date' => date('d M Y h:i A', Storage::disk('local')->lastModified($path)),
                'type' => 'Database SQL',
                'size' => number_format(Storage::disk('local')->size($path) / 1024, 1).' KB',
                'status' => 'Available',
            ])->values()->all();
    }

    public function create(): string
    {
        return Cache::lock('database-backup-create', 180)->block(5, function () {
            $config = config('database.connections.'.config('database.default'));
            if (($config['driver'] ?? '') !== 'mysql') {
                throw ValidationException::withMessages(['backup' => 'Database SQL backups require MySQL.']);
            }
            $binary = (new ExecutableFinder)->find('mysqldump');
            $binary ??= is_executable('/Applications/XAMPP/xamppfiles/bin/mysqldump') ? '/Applications/XAMPP/xamppfiles/bin/mysqldump' : null;
            if (! $binary) {
                throw ValidationException::withMessages(['backup' => 'mysqldump is not available on this server.']);
            }
            $file = 'database-'.now()->format('Ymd-His').'-'.Str::lower(Str::random(8)).'.sql';
            Storage::disk('local')->makeDirectory('database-backups');
            $path = Storage::disk('local')->path('database-backups/'.$file);
            $arguments = [$binary, '--single-transaction', '--quick', '--hex-blob', '--user='.$config['username'], '--result-file='.$path];
            if (! empty($config['unix_socket'])) {
                $arguments[] = '--socket='.$config['unix_socket'];
            } else {
                $arguments[] = '--host='.$config['host'];
                $arguments[] = '--port='.($config['port'] ?? 3306);
            }
            $arguments[] = $config['database'];
            touch($path);
            chmod($path, 0600);
            $process = new Process($arguments, null, ['MYSQL_PWD' => $config['password'] ?? '']);
            $process->setTimeout(120);
            try {
                $process->mustRun();
                if (filesize($path) === 0) {
                    throw new \RuntimeException('Empty database dump.');
                }
            } catch (\Throwable $exception) {
                Storage::disk('local')->delete('database-backups/'.$file);
                report($exception);
                throw ValidationException::withMessages(['backup' => 'Database backup failed. Check the server log; no successful backup was recorded.']);
            }

            return $file;
        });
    }

    public function delete(string $id): void
    {
        if (! preg_match('/^database-[A-Za-z0-9-]+\.sql$/D', $id) || ! Storage::disk('local')->exists('database-backups/'.$id)) {
            throw ValidationException::withMessages(['backup_id' => 'Select an existing managed backup.']);
        }
        if (! Storage::disk('local')->delete('database-backups/'.$id)) {
            throw ValidationException::withMessages(['backup_id' => 'The backup could not be deleted.']);
        }
    }
}
