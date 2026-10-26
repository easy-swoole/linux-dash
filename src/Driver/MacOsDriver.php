<?php

namespace EasySwoole\LinuxDash\Driver;

use EasySwoole\LinuxDash\AbstractInterface\DriverInterface;
use EasySwoole\LinuxDash\Bean\CpuInfo;
use EasySwoole\LinuxDash\Bean\DiskInfo;
use EasySwoole\LinuxDash\Bean\NetworkInfo;
use EasySwoole\LinuxDash\Bean\RamInfo;
use EasySwoole\LinuxDash\Bean\LoadInfo;
use EasySwoole\LinuxDash\Bean\PressureInfo;
use EasySwoole\LinuxDash\Bean\SwapInfo;
use EasySwoole\LinuxDash\Bean\SystemInfo;
use EasySwoole\LinuxDash\Bean\TcpInfo;
use EasySwoole\LinuxDash\Bean\UptimeInfo;
use EasySwoole\LinuxDash\Exception\MetricReadException;
use EasySwoole\LinuxDash\Exception\UnsupportedMetricException;

final class MacOsDriver implements DriverInterface
{
    private \FFI $ffi;

    public function __construct(private readonly mixed $sleeper = null)
    {
        if (!extension_loaded('FFI')) throw new MetricReadException('The FFI extension is required on macOS');
        if (PHP_OS_FAMILY !== 'Darwin') throw new MetricReadException('MacOsDriver can only run on macOS');
        try { $this->ffi = \FFI::cdef(self::CDEF, '/usr/lib/libSystem.B.dylib'); }
        catch (\Throwable $e) { throw new MetricReadException('Unable to initialize macOS system APIs through FFI', 0, $e); }
    }

    public function currentRam(): RamInfo
    {
        $total = $this->ffi->new('uint64_t[1]'); $size = $this->ffi->new('size_t[1]'); $size[0] = 8;
        if ($this->ffi->sysctlbyname('hw.memsize', $total, $size, null, 0) !== 0) throw new MetricReadException('sysctl hw.memsize failed');
        $stats = $this->ffi->new('struct vm_statistics64[1]'); $count = $this->ffi->new('uint32_t[1]'); $count[0] = intdiv(\FFI::sizeof($stats[0]), 4);
        if ($this->ffi->host_statistics64($this->ffi->mach_host_self(), 4, $stats, $count) !== 0) throw new MetricReadException('host_statistics64 failed');
        $pageSize = $this->ffi->new('uint32_t[1]');
        if ($this->ffi->host_page_size($this->ffi->mach_host_self(), $pageSize) !== 0) throw new MetricReadException('host_page_size failed');
        $available = ((int) $stats[0]->free_count + (int) $stats[0]->inactive_count) * (int) $pageSize[0];
        $available = max(0, min((int) $total[0], $available));
        return new RamInfo((int) $total[0], (int) $total[0] - $available, $available);
    }

    public function cpu(float $sampleSeconds = 0.1): CpuInfo
    {
        $before = $this->cpuSnapshot(); $this->sleep($sampleSeconds); $after = $this->cpuSnapshot();
        $delta = []; foreach ($after as $key => $value) $delta[$key] = $this->counterDelta($before[$key], $value);
        $total = array_sum($delta); if ($total <= 0) throw new MetricReadException('CPU counters did not advance during the sample');
        return new CpuInfo(round(100 * ($total - $delta['idle']) / $total, 2), round(100 * ($delta['user'] + $delta['nice']) / $total, 2), round(100 * $delta['system'] / $total, 2), round(100 * $delta['idle'] / $total, 2));
    }

    public function network(float $sampleSeconds = 0.1): array
    {
        $before = $this->networkSnapshot(); $started = hrtime(true); $this->sleep($sampleSeconds); $after = $this->networkSnapshot();
        $elapsed = max((hrtime(true) - $started) / 1_000_000_000, $sampleSeconds, 0.000001); $result = [];
        foreach ($after as $name => $now) { $old = $before[$name] ?? $now; $result[] = new NetworkInfo($name, $now['rx'], $now['tx'], round($this->counterDelta($old['rx'], $now['rx']) / $elapsed, 2), round($this->counterDelta($old['tx'], $now['tx']) / $elapsed, 2)); }
        return $result;
    }

    public function disks(): array
    {
        $buffer = $this->ffi->new('struct statfs *[1]'); $count = $this->ffi->getmntinfo($buffer, 2);
        if ($count < 0) throw new MetricReadException('getmntinfo failed');
        $result = [];
        for ($i = 0; $i < $count; $i++) {
            $item = $buffer[0][$i]; $size = (int) $item->f_blocks * (int) $item->f_bsize; $avail = (int) $item->f_bavail * (int) $item->f_bsize;
            if ($size <= 0) continue; $used = $size - ((int) $item->f_bfree * (int) $item->f_bsize);
            $result[] = new DiskInfo(\FFI::string($item->f_mntfromname), $size, max(0, $used), max(0, $avail), round(max(0, $used) * 100 / $size, 2), \FFI::string($item->f_mntonname));
        }
        return $result;
    }

    public function load(): LoadInfo
    {
        $values = $this->ffi->new('double[3]');
        if ($this->ffi->getloadavg($values, 3) !== 3) throw new MetricReadException('getloadavg failed');
        return new LoadInfo((float) $values[0], (float) $values[1], (float) $values[2]);
    }

    public function swap(): SwapInfo
    {
        $usage = $this->ffi->new('struct xsw_usage[1]'); $size = $this->ffi->new('size_t[1]'); $size[0] = \FFI::sizeof($usage[0]);
        if ($this->ffi->sysctlbyname('vm.swapusage', $usage, $size, null, 0) === 0) return new SwapInfo((int) $usage[0]->xsu_total, (int) $usage[0]->xsu_used, (int) $usage[0]->xsu_avail);
        // Some sandboxed macOS processes cannot read vm.swapusage. Mach still exposes current swap pages and lifetime counters.
        $stats = $this->ffi->new('struct vm_statistics64[1]'); $count = $this->ffi->new('uint32_t[1]'); $count[0] = intdiv(\FFI::sizeof($stats[0]), 4);
        if ($this->ffi->host_statistics64($this->ffi->mach_host_self(), 4, $stats, $count) !== 0) throw new MetricReadException('Unable to read macOS swap metrics');
        $pageSize = $this->ffi->new('uint32_t[1]'); $this->ffi->host_page_size($this->ffi->mach_host_self(), $pageSize);
        $used = (int) $stats[0]->swapped_count * (int) $pageSize[0];
        return new SwapInfo($used, $used, 0, (int) $stats[0]->swapins, (int) $stats[0]->swapouts);
    }

    public function uptime(): UptimeInfo
    {
        $boot = $this->ffi->new('struct timeval[1]'); $size = $this->ffi->new('size_t[1]'); $size[0] = \FFI::sizeof($boot[0]);
        if ($this->ffi->sysctlbyname('kern.boottime', $boot, $size, null, 0) === 0) {
            $bootedAt = (int) $boot[0]->tv_sec;
            return new UptimeInfo(max(0, microtime(true) - ($bootedAt + ((int) $boot[0]->tv_usec / 1_000_000))), $bootedAt);
        }
        $timebase = $this->ffi->new('struct mach_timebase_info[1]');
        if ($this->ffi->mach_timebase_info($timebase) !== 0 || $timebase[0]->denom === 0) throw new MetricReadException('Unable to read macOS uptime');
        $seconds = (int) $this->ffi->mach_continuous_time() * (int) $timebase[0]->numer / (int) $timebase[0]->denom / 1_000_000_000;
        return new UptimeInfo($seconds, (int) floor(time() - $seconds));
    }

    public function system(): SystemInfo
    {
        try { $logicalCpus = $this->sysctlInteger('hw.logicalcpu'); }
        catch (MetricReadException) {
            $basic = $this->ffi->new('struct host_basic_info[1]'); $count = $this->ffi->new('uint32_t[1]'); $count[0] = intdiv(\FFI::sizeof($basic[0]), 4);
            if ($this->ffi->host_info($this->ffi->mach_host_self(), 1, $basic, $count) !== 0) throw new MetricReadException('Unable to read macOS CPU count');
            $logicalCpus = (int) $basic[0]->avail_cpus;
        }
        return new SystemInfo(gethostname() ?: php_uname('n'), 'macOS', php_uname('r'), php_uname('m'), $logicalCpus, $this->sysctlString('machdep.cpu.brand_string'));
    }

    public function diskIo(float $sampleSeconds = 0.1): array
    {
        throw new UnsupportedMetricException('Disk I/O counters require IOKit support, which is not available yet');
    }

    public function tcp(): TcpInfo
    {
        throw new UnsupportedMetricException('TCP state collection is not available on macOS yet');
    }

    public function pressure(): PressureInfo
    {
        throw new UnsupportedMetricException('PSI pressure metrics are Linux-specific');
    }

    public function supportsPressure(): bool { return false; }

    private function sysctlInteger(string $name): int
    {
        $value = $this->ffi->new('uint32_t[1]'); $size = $this->ffi->new('size_t[1]'); $size[0] = 4;
        if ($this->ffi->sysctlbyname($name, $value, $size, null, 0) !== 0) throw new MetricReadException("sysctl {$name} failed");
        return (int) $value[0];
    }

    private function sysctlString(string $name): ?string
    {
        $size = $this->ffi->new('size_t[1]');
        if ($this->ffi->sysctlbyname($name, null, $size, null, 0) !== 0 || $size[0] < 1) return null;
        $value = $this->ffi->new('char[' . (int) $size[0] . ']');
        return $this->ffi->sysctlbyname($name, $value, $size, null, 0) === 0 ? \FFI::string($value) : null;
    }

    private function cpuSnapshot(): array
    {
        $info = $this->ffi->new('int32_t[4]'); $count = $this->ffi->new('uint32_t[1]'); $count[0] = 4;
        if ($this->ffi->host_statistics($this->ffi->mach_host_self(), 3, $info, $count) !== 0) throw new MetricReadException('host_statistics failed');
        return ['user' => (int) (sprintf('%u', $info[0])), 'system' => (int) (sprintf('%u', $info[1])), 'idle' => (int) (sprintf('%u', $info[2])), 'nice' => (int) (sprintf('%u', $info[3]))];
    }

    private function networkSnapshot(): array
    {
        $head = $this->ffi->new('struct ifaddrs *[1]'); if ($this->ffi->getifaddrs($head) !== 0) throw new MetricReadException('getifaddrs failed');
        $result = [];
        try {
            for ($node = $head[0]; $node !== null; $node = $node->ifa_next) {
                if ($node->ifa_addr === null || $node->ifa_data === null || $node->ifa_addr->sa_family !== 18) continue;
                $name = \FFI::string($node->ifa_name); $data = $this->ffi->cast('struct if_data *', $node->ifa_data);
                $result[$name] = ['rx' => (int) (sprintf('%u', $data->ifi_ibytes)), 'tx' => (int) (sprintf('%u', $data->ifi_obytes))];
            }
        } finally { $this->ffi->freeifaddrs($head[0]); }
        return $result;
    }

    private function sleep(float $seconds): void
    {
        if ($seconds < 0) throw new \InvalidArgumentException('Sample duration must not be negative');
        if ($this->sleeper !== null) { ($this->sleeper)($seconds); return; }
        if ($seconds > 0) usleep((int) round($seconds * 1_000_000));
    }

    private function counterDelta(int $before, int $after): int
    {
        return $after >= $before ? $after - $before : (4_294_967_296 - $before) + $after;
    }

    private const CDEF = <<<'C'
typedef unsigned char uint8_t; typedef unsigned int uint32_t; typedef int int32_t; typedef unsigned long size_t; typedef unsigned long long uint64_t;
struct timeval { long long tv_sec; int32_t tv_usec; };
struct xsw_usage { uint64_t xsu_total, xsu_avail, xsu_used; uint32_t xsu_pagesize; uint8_t xsu_encrypted; };
int getloadavg(double *, int);
struct vm_statistics64 { uint32_t free_count, active_count, inactive_count, wire_count; uint64_t zero_fill_count, reactivations, pageins, pageouts, faults, cow_faults, lookups, hits, purges; uint32_t purgeable_count, speculative_count; uint64_t decompressions, compressions, swapins, swapouts; uint32_t compressor_page_count, throttled_count, external_page_count, internal_page_count; uint64_t total_uncompressed_pages_in_compressor, swapped_count; };
struct host_basic_info { int32_t max_cpus, avail_cpus; uint32_t memory_size; int32_t cpu_type, cpu_subtype, cpu_threadtype, physical_cpu, physical_cpu_max, logical_cpu, logical_cpu_max; uint64_t max_mem; };
struct mach_timebase_info { uint32_t numer, denom; };
uint32_t mach_host_self(void); int host_statistics(uint32_t, int, int32_t *, uint32_t *); int host_statistics64(uint32_t, int, struct vm_statistics64 *, uint32_t *); int host_page_size(uint32_t, uint32_t *);
int host_info(uint32_t, int, struct host_basic_info *, uint32_t *); uint64_t mach_continuous_time(void); int mach_timebase_info(struct mach_timebase_info *);
int sysctlbyname(const char *, void *, size_t *, void *, size_t);
struct sockaddr { uint8_t sa_len; uint8_t sa_family; char sa_data[14]; };
struct ifaddrs { struct ifaddrs *ifa_next; char *ifa_name; uint32_t ifa_flags; struct sockaddr *ifa_addr; struct sockaddr *ifa_netmask; struct sockaddr *ifa_dstaddr; void *ifa_data; };
struct timeval32 { int32_t tv_sec; int32_t tv_usec; };
struct if_data { uint8_t ifi_type, ifi_typelen, ifi_physical, ifi_addrlen, ifi_hdrlen, ifi_recvquota, ifi_xmitquota, ifi_unused1; uint32_t ifi_mtu, ifi_metric, ifi_baudrate, ifi_ipackets, ifi_ierrors, ifi_opackets, ifi_oerrors, ifi_collisions, ifi_ibytes, ifi_obytes, ifi_imcasts, ifi_omcasts, ifi_iqdrops, ifi_noproto, ifi_recvtiming, ifi_xmittiming; struct timeval32 ifi_lastchange; uint32_t ifi_unused2, ifi_hwassist, ifi_reserved1, ifi_reserved2; };
int getifaddrs(struct ifaddrs **); void freeifaddrs(struct ifaddrs *);
struct fsid { int32_t val[2]; }; struct statfs { uint32_t f_bsize; int32_t f_iosize; uint64_t f_blocks, f_bfree, f_bavail, f_files, f_ffree; struct fsid f_fsid; uint32_t f_owner, f_type, f_flags, f_fssubtype; char f_fstypename[16], f_mntonname[1024], f_mntfromname[1024]; uint32_t f_flags_ext, f_reserved[7]; };
int getmntinfo(struct statfs **, int);
C;
}
