<?php

namespace EasySwoole\LinuxDash\Driver;

use EasySwoole\LinuxDash\AbstractInterface\DriverInterface;
use EasySwoole\LinuxDash\Bean\CpuInfo;
use EasySwoole\LinuxDash\Bean\CpuCoreInfo;
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
        $before = $this->cpuSnapshots();
        $this->sleep($sampleSeconds);
        $after = $this->cpuSnapshots();
        $aggregate = $this->cpuPercentages($before['cpu'], $after['cpu']); $cores = [];
        foreach ($after as $name => $snapshot) {
            if ($name === 'cpu' || !isset($before[$name])) continue;
            $values = $this->cpuPercentages($before[$name], $snapshot);
            $cores[] = new CpuCoreInfo((int) substr($name, 3), ...$values);
        }
        return new CpuInfo(...array_merge($aggregate, [$cores]));
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
            $result[] = new NetworkInfo($name, $counters['rx'], $counters['tx'], round(max(0, $counters['rx'] - $old['rx']) / $elapsed, 2), round(max(0, $counters['tx'] - $old['tx']) / $elapsed, 2), $counters['rxPackets'], $counters['txPackets'], $counters['rxErrors'], $counters['txErrors'], $counters['rxDropped'], $counters['txDropped'], $counters['collisions'], $this->sysInt("class/net/{$name}/mtu"), $this->sysString("class/net/{$name}/address"), $this->sysString("class/net/{$name}/operstate"), $this->interfaceAddresses($name));
        }
        return $result;
    }

    public function disks(): array
    {
        $result = [];
        foreach (preg_split('/\R/', trim($this->read('self/mounts'))) ?: [] as $line) {
            $parts = preg_split('/\s+/', $line);
            if (count($parts) < 3 || in_array($parts[2], ['proc','sysfs','devtmpfs','devpts','tmpfs','cgroup','cgroup2','securityfs','pstore','autofs','debugfs','tracefs','configfs','fusectl','mqueue','hugetlbfs','bpf'], true)) continue;
            $mount = str_replace(['\\040', '\\011', '\\134'], [' ', "\t", '\\'], $parts[1]);
            $size = @disk_total_space($mount); $avail = @disk_free_space($mount);
            if ($size === false || $avail === false || $size <= 0) continue;
            $used = (int) $size - (int) $avail;
            $inode = $this->inodeStats($mount);
            $result[] = new DiskInfo($parts[0], (int) $size, $used, (int) $avail, round($used * 100 / $size, 2), $mount, $inode['total'], $inode['used'], $inode['available'], $inode['percentage']);
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
        return new SystemInfo(gethostname() ?: php_uname('n'), 'Linux', php_uname('r'), php_uname('m'), $this->logicalCpuCount(), $model, $this->physicalCpuCount(), $this->cpuFrequency());
    }

    public function diskIo(float $sampleSeconds = 0.1): array
    {
        $before = $this->diskIoSnapshot(); $started = hrtime(true); $this->sleep($sampleSeconds); $after = $this->diskIoSnapshot();
        $elapsed = max((hrtime(true) - $started) / 1_000_000_000, $sampleSeconds, 0.000001); $result = [];
        foreach ($after as $device => $now) {
            $old = $before[$device] ?? $now; $sectorSize = $this->sectorSize($device);
            $readBytes = $now['readSectors'] * $sectorSize; $writeBytes = $now['writeSectors'] * $sectorSize;
            $readOps = max(0, $now['reads'] - $old['reads']); $writeOps = max(0, $now['writes'] - $old['writes']);
            $result[] = new DiskIoInfo($device, $now['reads'], $now['writes'], $readBytes, $writeBytes, round($readOps / $elapsed, 2), round($writeOps / $elapsed, 2), round(max(0, $now['readSectors'] - $old['readSectors']) * $sectorSize / $elapsed, 2), round(max(0, $now['writeSectors'] - $old['writeSectors']) * $sectorSize / $elapsed, 2), round(min(100, max(0, $now['ioMs'] - $old['ioMs']) / ($elapsed * 10)), 2), $readOps > 0 ? round(max(0, $now['readMs'] - $old['readMs']) / $readOps, 2) : 0.0, $writeOps > 0 ? round(max(0, $now['writeMs'] - $old['writeMs']) / $writeOps, 2) : 0.0, round(max(0, $now['weightedIoMs'] - $old['weightedIoMs']) / ($elapsed * 1000), 2), $now['inProgress'], is_file(rtrim($this->sysRoot, '/') . '/class/block/' . $device . '/partition'));
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
            $result[$f[2]] = ['reads'=>(int)$f[3], 'readSectors'=>(int)$f[5], 'readMs'=>(int)$f[6], 'writes'=>(int)$f[7], 'writeSectors'=>(int)$f[9], 'writeMs'=>(int)$f[10], 'inProgress'=>(int)$f[11], 'ioMs'=>(int)$f[12], 'weightedIoMs'=>(int)$f[13]];
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

    private function cpuSnapshots(): array
    {
        $result = [];
        foreach (preg_split('/\R/', $this->read('stat')) ?: [] as $line) {
            if (!preg_match('/^(cpu\d*)\s+(.+)$/', $line, $match)) continue;
            $raw = array_pad(array_map('intval', preg_split('/\s+/', trim($match[2])) ?: []), 8, 0);
            $result[$match[1]] = array_combine(['user', 'nice', 'system', 'idle', 'iowait', 'irq', 'softirq', 'steal'], array_slice($raw, 0, 8));
        }
        if (!isset($result['cpu'])) throw new MetricReadException('Aggregate CPU row is missing from /proc/stat');
        return $result;
    }

    /** @return array{0:float,1:float,2:float,3:float,4:float,5:float,6:float} */
    private function cpuPercentages(array $before, array $after): array
    {
        $delta = []; foreach ($after as $key => $value) $delta[$key] = max(0, $value - $before[$key]);
        $total = array_sum($delta); if ($total <= 0) throw new MetricReadException('CPU counters did not advance during the sample');
        $percent = static fn (int $ticks): float => round(100 * $ticks / $total, 2);
        return [$percent($total - $delta['idle'] - $delta['iowait']), $percent($delta['user'] + $delta['nice']), $percent($delta['system']), $percent($delta['idle']), $percent($delta['iowait']), $percent($delta['steal']), $percent($delta['irq'] + $delta['softirq'])];
    }

    private function networkSnapshot(): array
    {
        $result = [];
        foreach (array_slice(preg_split('/\R/', $this->read('net/dev')) ?: [], 2) as $line) {
            if (!str_contains($line, ':')) continue;
            [$name, $data] = array_map('trim', explode(':', $line, 2));
            $fields = preg_split('/\s+/', $data) ?: [];
            if (count($fields) >= 16) $result[$name] = ['rx'=>(int)$fields[0], 'rxPackets'=>(int)$fields[1], 'rxErrors'=>(int)$fields[2], 'rxDropped'=>(int)$fields[3], 'tx'=>(int)$fields[8], 'txPackets'=>(int)$fields[9], 'txErrors'=>(int)$fields[10], 'txDropped'=>(int)$fields[11], 'collisions'=>(int)$fields[13]];
        }
        return $result;
    }

    private function sysString(string $relative): ?string
    {
        $value = @file_get_contents(rtrim($this->sysRoot, '/') . '/' . $relative);
        return $value === false ? null : trim($value);
    }

    private function sysInt(string $relative): ?int
    {
        $value = $this->sysString($relative); return $value !== null && is_numeric($value) ? (int) $value : null;
    }

    private function interfaceAddresses(string $name): array
    {
        $result = [];
        if (function_exists('net_get_interfaces')) {
            $interfaces = net_get_interfaces();
            foreach (($interfaces[$name]['unicast'] ?? []) as $address) if (isset($address['address'])) $result[] = $address['address'];
            if ($result !== []) return array_values(array_unique($result));
        }
        $path = rtrim($this->procRoot, '/') . '/net/if_inet6'; $contents = @file_get_contents($path);
        if ($contents === false) return $result;
        foreach (preg_split('/\R/', trim($contents)) ?: [] as $line) {
            $f = preg_split('/\s+/', trim($line)) ?: []; if (($f[5] ?? null) !== $name || strlen($f[0] ?? '') !== 32) continue;
            $binary = hex2bin($f[0]); if ($binary !== false && function_exists('inet_ntop')) $result[] = inet_ntop($binary);
        }
        return array_values(array_unique($result));
    }

    private function physicalCpuCount(): ?int
    {
        $physical = null; $core = null; $pairs = [];
        foreach (preg_split('/\R/', $this->read('cpuinfo')) ?: [] as $line) {
            if (preg_match('/^physical id\s*:\s*(\d+)/', $line, $m)) $physical = $m[1];
            elseif (preg_match('/^core id\s*:\s*(\d+)/', $line, $m)) $core = $m[1];
            elseif ($line === '' && $physical !== null && $core !== null) { $pairs[$physical . ':' . $core] = true; $physical = $core = null; }
        }
        if ($physical !== null && $core !== null) $pairs[$physical . ':' . $core] = true;
        return $pairs === [] ? null : count($pairs);
    }

    private function cpuFrequency(): ?int
    {
        $khz = $this->sysInt('devices/system/cpu/cpu0/cpufreq/scaling_cur_freq');
        if ($khz !== null) return $khz * 1000;
        if (preg_match('/^cpu MHz\s*:\s*([0-9.]+)/mi', $this->read('cpuinfo'), $m)) return (int) round((float) $m[1] * 1_000_000);
        return null;
    }

    /** @return array{total:?int,used:?int,available:?int,percentage:?float} */
    private function inodeStats(string $mount): array
    {
        $empty = ['total'=>null,'used'=>null,'available'=>null,'percentage'=>null];
        if (!extension_loaded('FFI')) return $empty;
        try {
            $ffi = \FFI::cdef('typedef unsigned long ulong; struct statvfs { ulong f_bsize,f_frsize,f_blocks,f_bfree,f_bavail,f_files,f_ffree,f_favail,f_fsid,f_flag,f_namemax; int f_spare[6]; }; int statvfs(const char *, struct statvfs *);');
            $stats = $ffi->new('struct statvfs[1]'); if ($ffi->statvfs($mount, $stats) !== 0) return $empty;
            $total = (int)$stats[0]->f_files; $available = (int)$stats[0]->f_favail; $used = max(0, $total - (int)$stats[0]->f_ffree);
            return ['total'=>$total,'used'=>$used,'available'=>$available,'percentage'=>$total > 0 ? round($used * 100 / $total, 2) : 0.0];
        } catch (\Throwable) { return $empty; }
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
