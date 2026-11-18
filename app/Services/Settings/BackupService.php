<?php

namespace App\Services\Settings;

use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/** Backup database ke storage/app/private/backups (pengganti do_backup.php & cron_auto_backup.php). */
class BackupService
{
    public const DIR = 'backups';

    public const KEEP = 14;

    public function create(): string
    {
        $conn = config('database.connections.'.config('database.default'));
        $name = self::DIR.'/snet-'.now()->format('Ymd-His');
        $disk = Storage::disk('local');
        $disk->makeDirectory(self::DIR);

        if ($conn['driver'] === 'sqlite') {
            $disk->put($name .= '.sqlite', file_get_contents($conn['database']));
        } else {
            $path = $disk->path($name .= '.sql.gz');
            $result = Process::env(['MYSQL_PWD' => (string) $conn['password']])->timeout(600)->run(sprintf(
                'mysqldump --single-transaction --routines -h %s -P %s -u %s %s | gzip > %s',
                escapeshellarg($conn['host']), escapeshellarg((string) $conn['port']), escapeshellarg($conn['username']), escapeshellarg($conn['database']), escapeshellarg($path)
            ));
            if (! $result->successful() || filesize($path) < 100) {
                @unlink($path);
                throw new RuntimeException('mysqldump gagal: '.trim($result->errorOutput()));
            }
        }
        $this->prune();

        return $name;
    }

    /** @return list<array{name: string, size: int, time: int}> */
    public function list(): array
    {
        $disk = Storage::disk('local');

        return collect($disk->files(self::DIR))
            ->map(fn ($f) => ['name' => basename($f), 'size' => $disk->size($f), 'time' => $disk->lastModified($f)])
            ->sortByDesc('time')->values()->all();
    }

    public function path(string $name): string
    {
        abort_unless(preg_match('/^snet-[0-9\-]+\.(sql\.gz|sqlite)$/', $name) === 1, 404);

        return Storage::disk('local')->path(self::DIR.'/'.$name);
    }

    private function prune(): void
    {
        foreach (array_slice($this->list(), self::KEEP) as $old) {
            Storage::disk('local')->delete(self::DIR.'/'.$old['name']);
        }
    }
}
