<?php
declare(strict_types=1);

namespace BWFC\DailyBrief;

/**
 * Database backup: runs mysqldump to a dated .sql file and prunes old ones.
 * Used by bin/backup_db.php (scheduled) and api/backup_run.php (on-demand).
 *
 * Configurable via env:
 *   BACKUP_DIR      where to write backups (default <project>/backups)
 *   BACKUP_KEEP     how many recent backups to keep (default 14)
 *   MYSQLDUMP_PATH  path to mysqldump binary (auto-detected if unset)
 */
final class Backup
{
    /**
     * @return array{success:bool, file:?string, bytes:int, kept:int, pruned:int, message:string}
     */
    public static function run(): array
    {
        $config = require BASE_PATH . '/config/database.php';
        $dir = self::backupDir();
        $keep = max(1, (int)env('BACKUP_KEEP', 14));

        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return self::fail("Could not create backup directory: {$dir}");
        }
        if (!is_writable($dir)) {
            return self::fail("Backup directory is not writable: {$dir}");
        }

        $mysqldump = self::findMysqldump();
        $file = rtrim($dir, "/\\") . DIRECTORY_SEPARATOR . 'bwfc_' . date('Y-m-d_His') . '.sql';

        // Credentials via a temp defaults file so the password never appears in
        // the process list / command line.
        $defaults = tempnam(sys_get_temp_dir(), 'bwfcdump');
        $ini = "[client]\n"
            . 'host=' . $config['host'] . "\n"
            . 'port=' . $config['port'] . "\n"
            . 'user=' . $config['user'] . "\n"
            . 'password=' . $config['pass'] . "\n";
        file_put_contents($defaults, $ini);

        $errFile = $file . '.err';
        $cmd = escapeshellarg($mysqldump)
            . ' --defaults-extra-file=' . escapeshellarg($defaults)
            . ' --single-transaction --quick --routines --events --databases '
            . escapeshellarg((string)$config['dbname'])
            . ' > ' . escapeshellarg($file)
            . ' 2> ' . escapeshellarg($errFile);

        $output = [];
        $exit = 0;
        exec($cmd, $output, $exit);

        @unlink($defaults);
        $bytes = is_file($file) ? (int)filesize($file) : 0;
        $err = is_file($errFile) ? trim((string)file_get_contents($errFile)) : '';
        @unlink($errFile);

        if ($exit !== 0 || $bytes < 200) {
            @unlink($file);
            $hint = $err !== '' ? ' — ' . $err : '';
            return self::fail("mysqldump failed (exit {$exit}){$hint}. Check MYSQLDUMP_PATH and DB credentials.");
        }

        $pruned = self::prune($dir, $keep);
        $kept = count(self::backupFiles($dir));

        return [
            'success' => true,
            'file'    => $file,
            'bytes'   => $bytes,
            'kept'    => $kept,
            'pruned'  => $pruned,
            'message' => 'Backup written (' . self::humanBytes($bytes) . '). Keeping ' . $kept . ', pruned ' . $pruned . '.',
        ];
    }

    public static function backupDir(): string
    {
        $dir = (string)env('BACKUP_DIR', '');
        return $dir !== '' ? $dir : BASE_PATH . '/backups';
    }

    /** @return array<int,string> newest first */
    public static function backupFiles(string $dir): array
    {
        $files = glob(rtrim($dir, "/\\") . DIRECTORY_SEPARATOR . 'bwfc_*.sql') ?: [];
        rsort($files); // filenames embed the timestamp, so this is newest-first
        return $files;
    }

    private static function prune(string $dir, int $keep): int
    {
        $files = self::backupFiles($dir);
        $old = array_slice($files, $keep);
        $n = 0;
        foreach ($old as $f) {
            if (@unlink($f)) {
                $n++;
            }
        }
        return $n;
    }

    private static function findMysqldump(): string
    {
        $env = (string)env('MYSQLDUMP_PATH', '');
        if ($env !== '' && is_file($env)) {
            return $env;
        }
        $candidates = [
            'C:\\xampp_new\\mysql\\bin\\mysqldump.exe',
            'C:\\xampp\\mysql\\bin\\mysqldump.exe',
            '/opt/lampp/bin/mysqldump',
            '/usr/bin/mysqldump',
            '/usr/local/bin/mysqldump',
        ];
        foreach ($candidates as $c) {
            if (is_file($c)) {
                return $c;
            }
        }
        return 'mysqldump'; // fall back to PATH
    }

    private static function humanBytes(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . ' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024, 1) . ' KB';
        }
        return $bytes . ' B';
    }

    /** @return array{success:bool, file:?string, bytes:int, kept:int, pruned:int, message:string} */
    private static function fail(string $message): array
    {
        return ['success' => false, 'file' => null, 'bytes' => 0, 'kept' => 0, 'pruned' => 0, 'message' => $message];
    }
}
