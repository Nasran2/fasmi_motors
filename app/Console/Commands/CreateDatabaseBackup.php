<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Services\DatabaseBackupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class CreateDatabaseBackup extends Command
{
    protected $signature = 'database:backup {--scheduled : Honor saved schedule settings}';

    protected $description = 'Create a private MySQL SQL backup without changing application data';

    public function handle(DatabaseBackupService $backups): int
    {
        if ($this->option('scheduled')) {
            if (! filter_var(Setting::valueFor('backup_auto', false), FILTER_VALIDATE_BOOLEAN)) {
                return self::SUCCESS;
            }
            $frequency = Setting::valueFor('backup_frequency', 'Daily');
            if (($frequency === 'Weekly' && ! now()->isMonday()) || ($frequency === 'Monthly' && now()->day !== 1)) {
                return self::SUCCESS;
            }
        }
        $file = $backups->create();
        $this->info('Created '.$file);
        if ($this->option('scheduled')) {
            $days = max(1, (int) Setting::valueFor('backup_retention_days', 30));
            foreach ($backups->backups() as $backup) {
                if ($backup['id'] !== $file && Storage::disk('local')->lastModified('database-backups/'.$backup['id']) < now()->subDays($days)->timestamp) {
                    $backups->delete($backup['id']);
                }
            }
        }

        return self::SUCCESS;
    }
}
