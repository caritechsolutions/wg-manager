<?php
declare(strict_types=1);

/**
 * Persistence for state.json with locking, validation and automatic
 * pre-change backups.
 */
final class Store
{
    public static function exists(): bool
    {
        return is_file(WGM_STATE_FILE);
    }

    public static function load(): array
    {
        $s = Util::readJson(WGM_STATE_FILE);
        if (!is_array($s)) {
            throw new RuntimeException('wg-manager is not initialised (no ' . WGM_STATE_FILE . '). Run: wgm init');
        }
        return State::upgrade($s);
    }

    /** Write without validation or backup — only for init/import paths that validated already. */
    public static function saveRaw(array $state): void
    {
        Util::writeJson(WGM_STATE_FILE, $state, 0600);
    }

    /**
     * Apply $fn to the state under lock. $fn receives the state by reference
     * and may return a value. The result is validated; on failure nothing is
     * written and a ValidationError is thrown.
     */
    public static function update(callable $fn, string $reason)
    {
        return Util::withLock(WGM_STATE_FILE, function () use ($fn, $reason) {
            $state = self::load();
            $before = $state;
            $ret = $fn($state);
            $errors = State::validate($state);
            if ($errors) {
                throw new ValidationError($errors);
            }
            if ($state !== $before) {
                self::backup('pre-' . $reason, $before);
                self::saveRaw($state);
            }
            return $ret;
        });
    }

    // ------------------------------------------------------------------ backups

    public static function backup(string $reason, ?array $state = null): string
    {
        if (!is_dir(WGM_BACKUP_DIR)) {
            mkdir(WGM_BACKUP_DIR, 0700, true);
        }
        $state = $state ?? self::load();
        $reason = preg_replace('/[^a-z0-9-]+/', '-', strtolower($reason)) ?: 'manual';
        $name = 'state-' . gmdate('Ymd-His') . '-' . substr($reason, 0, 40) . '.json';
        $path = WGM_BACKUP_DIR . '/' . $name;
        $i = 1;
        while (is_file($path)) {
            $path = WGM_BACKUP_DIR . '/' . preg_replace('/\.json$/', "-$i.json", $name);
            $i++;
        }
        Util::writeJson($path, $state, 0600);
        self::prune((int)($state['settings']['backups']['keep'] ?? 30));
        return basename($path);
    }

    /** @return array<int,array{name:string,time:int,size:int,reason:string}> newest first */
    public static function backups(): array
    {
        $out = [];
        foreach (glob(WGM_BACKUP_DIR . '/state-*.json') ?: [] as $f) {
            $b = basename($f);
            preg_match('/^state-(\d{8}-\d{6})-(.+?)(-\d+)?\.json$/', $b, $m);
            $t = isset($m[1]) ? (int)strtotime(substr($m[1], 0, 4) . '-' . substr($m[1], 4, 2) . '-' . substr($m[1], 6, 2) . ' ' . substr($m[1], 9, 2) . ':' . substr($m[1], 11, 2) . ':' . substr($m[1], 13, 2) . ' UTC') : (int)filemtime($f);
            $out[] = ['name' => $b, 'time' => $t, 'size' => (int)filesize($f), 'reason' => $m[2] ?? ''];
        }
        usort($out, fn($a, $b) => [$b['time'], $b['name']] <=> [$a['time'], $a['name']]);
        return $out;
    }

    public static function backupPath(string $name): ?string
    {
        if (!preg_match('/^state-[A-Za-z0-9.-]+\.json$/', $name)) {
            return null;
        }
        $p = WGM_BACKUP_DIR . '/' . $name;
        return is_file($p) ? $p : null;
    }

    public static function prune(int $keep): void
    {
        $all = self::backups();
        foreach (array_slice($all, max(1, $keep)) as $b) {
            @unlink(WGM_BACKUP_DIR . '/' . $b['name']);
        }
    }

    /** Replace the whole state with $new (validated); current state is backed up first. */
    public static function replace(array $new, string $reason): void
    {
        $new = State::upgrade($new);
        $errors = State::validate($new);
        if ($errors) {
            throw new ValidationError($errors);
        }
        Util::withLock(WGM_STATE_FILE, function () use ($new, $reason) {
            if (self::exists()) {
                self::backup('pre-' . $reason);
            }
            self::saveRaw($new);
        });
    }
}

final class ValidationError extends RuntimeException
{
    /** @var string[] */
    public array $errors;

    public function __construct(array $errors)
    {
        $this->errors = array_values($errors);
        parent::__construct(implode(' ', $this->errors));
    }
}
