<?php

namespace EasySwoole\LinuxDash\Driver;

use EasySwoole\LinuxDash\AbstractInterface\DriverInterface;
use EasySwoole\LinuxDash\Bean\CpuInfo;
use EasySwoole\LinuxDash\Bean\DiskInfo;
use EasySwoole\LinuxDash\Bean\NetworkInfo;
use EasySwoole\LinuxDash\Bean\RamInfo;
use EasySwoole\LinuxDash\Bean\DiskIoInfo;
use EasySwoole\LinuxDash\Bean\LoadInfo;
use EasySwoole\LinuxDash\Bean\PressureInfo;
use EasySwoole\LinuxDash\Bean\SwapInfo;
use EasySwoole\LinuxDash\Bean\SystemInfo;
use EasySwoole\LinuxDash\Bean\TcpInfo;
use EasySwoole\LinuxDash\Bean\UptimeInfo;
use EasySwoole\LinuxDash\Exception\MetricReadException;
use EasySwoole\LinuxDash\Exception\UnsupportedMetricException;

final class LinuxDriver implements DriverInterface
{
    public function __construct(private readonly string $procRoot = '/proc', private readonly mixed $sleeper = null, private readonly string $sysRoot = '/sys') {}

    public function currentRam(): RamInfo
    {
        $values = [];
        foreach (preg_split('/\R/', $this->read('meminfo')) ?: [] as $line) {
            if (preg_match('/^([A-Za-z_()]+):\s+(\d+)\s+kB$/', $line, $match)) $values[$match[1]] = (int) $match[2] * 1024;
        }
        if (!isset($values['MemTotal'])) throw new MetricReadException('MemTotal is missing from /proc/meminfo');
        $available = $values['MemAvailable'] ?? (($values['MemFree'] ?? 0) + ($values['Buffers'] ?? 0) + ($values['Cached'] ?? 0) + ($values['SReclaimable'] ?? 0) - ($values['Shmem'] ?? 0));
        $available = max(0, min($values['MemTotal'], $available));
        return new RamInfo($values['MemTotal'], $values['MemTotal'] - $available, $available);
    }

    public function cpu(float $sampleSeconds = 0.1): CpuInfo
    {
        $before = $this->cpuSnapshot();
        $this->sleep($sampleSeconds);
        $after = $this->cpuSnapshot();
        $delta = [];
        foreach ($after as $key => $value) $delta[$key] = max(0, $value - $before[$key]);
        $total = array_sum($delta);
        if ($total <= 0) throw new MetricReadException('CPU counters did not advance during the sample');
        $idleTicks = $delta['idle'] + $delta['iowait'];
        return new CpuInfo(round(100 * ($total - $idleTicks) / $total, 2), round(100 * ($delta['user'] + $delta['nice']) / $total, 2), round(100 * ($delta['system'] + $delta['irq'] + $delta['softirq'] + $delta['steal']) / $total, 2), round(100 * $idleTicks / $total, 2));
    }

    public function network(float $sampleSeconds = 0.1): array
    {
        $before = $this->networkSnapshot();
        $started = hrtime(true);
        $this->sleep($sampleSeconds);
        $after = $this->networkSnapshot();
        $elapsed = max((hrtime(true) - $started) / 1_000_000_000, $sampleSeconds, 0.000001);
        $result = [];
        foreach ($after as $name => $counters) {
            $old = $before[$name] ?? $counters;
            $result[] = new NetworkInfo($name, $counters['rx'], $counters['tx'], round(max(0, $counters['rx'] - $old['rx']) / $elapsed, 2), round(max(0, $counters['tx'] - $old['tx']) / $elapsed, 2));
        }
        return $result;
    }

    public function disks(): array
    {
        $result = [];
        foreach (preg_split('/\R/', trim($this->read('self/mounts'))) ?: [] as $line) {
            $parts = preg_split('/\s+/', $line);
            if (count($parts) < 3 || in_array($parts[2], ['proc', 'sysfs', 'devtmpfs', 'devpts', 'tmpfs', 'cgroup', 'cgroup2'], true)) continue;
            $mount = str_replace(['\\040', '\\011', '\\134'], [' ', "\t", '\\'], $parts[1]);
            $size = @disk_total_space($mount); $avail = @disk_free_space($mount);
            if ($size === false || $avail === false || $size <= 0) continue;
            $used = (int) $size - (int) $avail;
            $result[] = new DiskInfo($parts[0], (int) $size, $used, (int) $avail, round($used * 100 / $size, 2), $mount);
        }
        return $result;
    }

    public function load(): LoadInfo
    {
        $parts = preg_split('/\s+/', trim($this->read('loadavg'))) ?: [];
        if (count($parts) < 4 || !str_contains($parts[3], '/')) throw new MetricReadException('Invalid /proc/loadavg');
        [$running, $processes] = array_map('intval', explode('/', $parts[3], 2));
        return new LoadInfo((float) $parts[0], (float) $parts[1], (float) $parts[2], $running, $processes);
    }

    public function swap(): SwapInfo
    {
        $values = [];
        foreach (preg_split('/\R/', $this->read('meminfo')) ?: [] as $line) if (preg_match('/^(SwapTotal|SwapFree):\s+(\d+)\s+kB$/', $line, $m)) $values[$m[1]] = (int) $m[2] * 1024;
        if (!isset($values['SwapTotal'], $values['SwapFree'])) throw new MetricReadException('Swap values are missing from /proc/meminfo');
        $vm = [];
        foreach (preg_split('/\R/', $this->read('vmstat')) ?: [] as $line) if (preg_match('/^(pswpin|pswpout)\s+(\d+)$/', $line, $m)) $vm[$m[1]] = (int) $m[2];
        return new SwapInfo($values['SwapTotal'], $values['SwapTotal'] - $values['SwapFree'], $values['SwapFree'], $vm['pswpin'] ?? 0, $vm['pswpout'] ?? 0);
    }

    public function uptime(): UptimeInfo
    {
        $seconds = (float) (preg_split('/\s+/', trim($this->read('uptime')))[0] ?? 0);
        if ($seconds < 0) throw new MetricReadException('Invalid /proc/uptime');
        return new UptimeInfo($seconds, (int) floor(time() - $seconds));
    }

    public function system(): SystemInfo
    {
        $model = null;
        foreach (preg_split('/\R/', $this->read('cpuinfo')) ?: [] as $line) if (preg_match('/^(?:model name|Hardware)\s*:\s*(.+)$/i', $line, $m)) { $model = trim($m[1]); break; }
        return new SystemInfo(gethostname() ?: php_uname('n'), 'Linux', php_uname('r'), php_uname('m'), $this->logicalCpuCount(), $model);
    }

    public function diskIo(float $sampleSeconds = 0.1): array
    {
        $before = $this->diskIoSnapshot(); $started = hrtime(true); $this->sleep($sampleSeconds); $after = $this->diskIoSnapshot();
        $elapsed = max((hrtime(true) - $started) / 1_000_000_000, $sampleSeconds, 0.000001); $result = [];
        foreach ($after as $device => $now) {
            $old = $before[$device] ?? $now; $sectorSize = $this->sectorSize($device);
            $readBytes = $now['readSectors'] * $sectorSize; $writeBytes = $now['writeSectors'] * $sectorSize;
            $result[] = new DiskIoInfo($device, $now['reads'], $now['writes'], $readBytes, $writeBytes, round(max(0, $now['reads'] - $old['reads']) / $elapsed, 2), round(max(0, $now['writes'] - $old['writes']) / $elapsed, 2), round(max(0, $now['readSectors'] - $old['readSectors']) * $sectorSize / $elapsed, 2), round(max(0, $now['writeSectors'] - $old['writeSectors']) * $sectorSize / $elapsed, 2), round(min(100, max(0, $now['ioMs'] - $old['ioMs']) / ($elapsed * 10)), 2));
        }
        return $result;
    }

    public function tcp(): TcpInfo
    {
        $names = ['01'=>'ESTABLISHED','02'=>'SYN_SENT','03'=>'SYN_RECV','04'=>'FIN_WAIT1','05'=>'FIN_WAIT2','06'=>'TIME_WAIT','07'=>'CLOSE','08'=>'CLOSE_WAIT','09'=>'LAST_ACK','0A'=>'LISTEN','0B'=>'CLOSING','0C'=>'NEW_SYN_RECV'];
        $states = array_fill_keys(array_values($names), 0);
        foreach (['net/tcp', 'net/tcp6'] as $file) foreach (array_slice(preg_split('/\R/', trim($this->read($file))) ?: [], 1) as $line) {
            $columns = preg_split('/\s+/', trim($line)) ?: []; $code = $columns[3] ?? null;
            if (isset($names[$code])) $states[$names[$code]]++;
        }
        return new TcpInfo(array_filter($states), array_sum($states));
    }

    public function pressure(): PressureInfo
    {
        if (!$this->supportsPressure()) throw new UnsupportedMetricException('Linux PSI is not exposed under /proc/pressure');
        return new PressureInfo($this->parsePressure('pressure/cpu'), $this->parsePressure('pressure/memory'), $this->parsePressure('pressure/io'));
    }

    public function supportsPressure(): bool
    {
        $root = rtrim($this->procRoot, '/');
        return is_readable($root . '/pressure/cpu') && is_readable($root . '/pressure/memory') && is_readable($root . '/pressure/io');
    }

    private function parsePressure(string $file): array
    {
        $result = [];
        foreach (preg_split('/\R/', trim($this->read($file))) ?: [] as $line) {
            $parts = preg_split('/\s+/', trim($line)) ?: []; $kind = array_shift($parts); if ($kind === null) continue;
            foreach ($parts as $part) { [$key, $value] = explode('=', $part, 2); $result[$kind][$key] = $key === 'total' ? (int) $value : (float) $value; }
        }
        return $result;
    }

    private function diskIoSnapshot(): array
    {
        $result = [];
        foreach (preg_split('/\R/', trim($this->read('diskstats'))) ?: [] as $line) {
            $f = preg_split('/\s+/', trim($line)) ?: []; if (count($f) < 14 || str_starts_with($f[2], 'loop') || str_starts_with($f[2], 'ram')) continue;
            $result[$f[2]] = ['reads'=>(int)$f[3], 'readSectors'=>(int)$f[5], 'writes'=>(int)$f[7], 'writeSectors'=>(int)$f[9], 'ioMs'=>(int)$f[12]];
        }
        return $result;
    }

    private function sectorSize(string $device): int
    {
        $value = @file_get_contents(rtrim($this->sysRoot, '/') . '/class/block/' . $device . '/queue/logical_block_size');
        return $value !== false && (int) trim($value) > 0 ? (int) trim($value) : 512;
    }

    private function logicalCpuCount(): int
    {
        preg_match_all('/^processor\s*:/m', $this->read('cpuinfo'), $matches);
        return max(1, count($matches[0]));
    }

    private function cpuSnapshot(): array
    {
        $line = strtok($this->read('stat'), "\n");
        if (!is_string($line) || !preg_match('/^cpu\s+(.+)$/', $line, $match)) throw new MetricReadException('Aggregate CPU row is missing from /proc/stat');
        // guest/guest_nice are already included in user/nice and must not be counted twice.
        $raw = array_pad(array_map('intval', preg_split('/\s+/', trim($match[1])) ?: []), 8, 0);
        return array_combine(['user', 'nice', 'system', 'idle', 'iowait', 'irq', 'softirq', 'steal'], array_slice($raw, 0, 8));
    }

    private function networkSnapshot(): array
    {
        $result = [];
        foreach (array_slice(preg_split('/\R/', $this->read('net/dev')) ?: [], 2) as $line) {
            if (!str_contains($line, ':')) continue;
            [$name, $data] = array_map('trim', explode(':', $line, 2));
            $fields = preg_split('/\s+/', $data) ?: [];
            if (count($fields) >= 16) $result[$name] = ['rx' => (int) $fields[0], 'tx' => (int) $fields[8]];
        }
        return $result;
    }

    private function read(string $relative): string
    {
        $path = rtrim($this->procRoot, '/') . '/' . $relative;
        $contents = @file_get_contents($path);
        if ($contents === false) throw new MetricReadException("Unable to read {$path}");
        return $contents;
    }

    private function sleep(float $seconds): void
    {
        if ($seconds < 0) throw new \InvalidArgumentException('Sample duration must not be negative');
        if ($this->sleeper !== null) { ($this->sleeper)($seconds); return; }
        if ($seconds > 0) usleep((int) round($seconds * 1_000_000));
    }
}
