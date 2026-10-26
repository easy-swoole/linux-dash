<?php

declare(strict_types=1);

namespace EasySwoole\LinuxDash\Tests;

use EasySwoole\LinuxDash\Driver\LinuxDriver;
use PHPUnit\Framework\TestCase;

final class LinuxDriverTest extends TestCase
{
    private string $root;
    private string $sysRoot;
    private LinuxDriver $driver;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/linux-dash-phpunit-' . bin2hex(random_bytes(6));
        $this->sysRoot = $this->root . '/sys';
        mkdir($this->root . '/net', 0777, true); mkdir($this->root . '/self'); mkdir($this->root . '/pressure'); mkdir($this->sysRoot . '/class/block/sda/queue', 0777, true); mkdir($this->sysRoot . '/class/net/eth0', 0777, true);
        $this->put('meminfo', "MemTotal: 1000 kB\nMemFree: 100 kB\nMemAvailable: 400 kB\nBuffers: 20 kB\nCached: 250 kB\nSwapTotal: 200 kB\nSwapFree: 150 kB\n");
        $this->put('stat', "cpu  100 10 40 800 20 4 6 0 0 0\ncpu0 50 5 20 400 10 2 3 0\ncpu1 50 5 20 400 10 2 3 0\n");
        $this->put('net/dev', $this->networkFixture(1000, 2000));
        $this->put('self/mounts', "proc /proc proc rw 0 0\n/dev/test / ext4 rw 0 0\n");
        $this->put('loadavg', "0.10 0.20 0.30 2/100 123\n"); $this->put('vmstat', "pswpin 12\npswpout 34\n"); $this->put('uptime', "123.50 50.00\n");
        $this->put('cpuinfo', "processor : 0\nphysical id : 0\ncore id : 0\nmodel name : Test CPU\ncpu MHz : 2400.000\n\nprocessor : 1\nphysical id : 0\ncore id : 1\n"); $this->put('diskstats', "8 0 sda 10 0 20 100 30 0 40 200 1 50 300\n");
        file_put_contents($this->sysRoot . '/class/block/sda/queue/logical_block_size', "512\n");
        file_put_contents($this->sysRoot . '/class/net/eth0/mtu', "1500\n"); file_put_contents($this->sysRoot . '/class/net/eth0/address', "00:11:22:33:44:55\n"); file_put_contents($this->sysRoot . '/class/net/eth0/operstate', "up\n");
        $this->put('net/tcp', "  sl  local_address rem_address   st\n   0: 00000000:0016 00000000:0000 0A\n   1: 0100007F:0016 0100007F:1234 01\n"); $this->put('net/tcp6', "  sl  local_address rem_address   st\n");
        $this->put('pressure/cpu', "some avg10=1.00 avg60=2.00 avg300=3.00 total=400\n"); $this->put('pressure/memory', "some avg10=0.10 avg60=0.20 avg300=0.30 total=40\nfull avg10=0.01 avg60=0.02 avg300=0.03 total=4\n"); $this->put('pressure/io', "some avg10=0.50 avg60=0.60 avg300=0.70 total=80\nfull avg10=0.05 avg60=0.06 avg300=0.07 total=8\n");
        $sleeper = function (): void { $this->put('stat', "cpu  120 10 50 860 20 4 6 0 0 0\ncpu0 60 5 25 430 10 2 3 0\ncpu1 60 5 25 430 10 2 3 0\n"); $this->put('net/dev', $this->networkFixture(1300, 2600)); $this->put('diskstats', "8 0 sda 14 0 28 112 35 0 50 220 2 70 340\n"); };
        $this->driver = new LinuxDriver($this->root, $sleeper, $this->sysRoot);
    }

    protected function tearDown(): void { $this->removeTree($this->root); }

    public function testMemoryAndSwap(): void
    {
        $ram = $this->driver->currentRam(); self::assertSame(1_024_000, $ram->total); self::assertSame(409_600, $ram->available); self::assertSame(614_400, $ram->used);
        $swap = $this->driver->swap(); self::assertSame(204_800, $swap->total); self::assertSame(51_200, $swap->used); self::assertSame(12, $swap->pagesIn); self::assertSame(34, $swap->pagesOut);
    }

    public function testCpuAndNetworkDeltas(): void
    {
        $cpu = $this->driver->cpu(1); self::assertSame(33.33, $cpu->usage); self::assertSame(66.67, $cpu->idle); self::assertCount(2, $cpu->cores); self::assertSame(0.0, $cpu->iowait);
        $this->put('net/dev', $this->networkFixture(1000, 2000)); $network = $this->driver->network(1)[0];
        self::assertSame(1300, $network->rx); self::assertSame(2600, $network->tx); self::assertSame(300.0, $network->rxPerSecond); self::assertSame(600.0, $network->txPerSecond); self::assertSame(1, $network->rxPackets); self::assertSame(1500, $network->mtu); self::assertSame('00:11:22:33:44:55', $network->mac); self::assertSame('up', $network->state);
    }

    public function testLoadUptimeSystemTcpAndPressure(): void
    {
        $load = $this->driver->load(); self::assertSame(0.1, $load->one); self::assertSame(2, $load->running); self::assertSame(100, $load->processes);
        self::assertSame(123.5, $this->driver->uptime()->seconds); $system = $this->driver->system(); self::assertSame(2, $system->logicalCpus); self::assertSame('Test CPU', $system->cpuModel); self::assertSame(2, $system->physicalCpus); self::assertSame(2_400_000_000, $system->cpuFrequencyHz);
        $tcp = $this->driver->tcp(); self::assertSame(2, $tcp->total); self::assertSame(1, $tcp->states['LISTEN']); self::assertTrue($this->driver->supportsPressure()); self::assertSame(4, $this->driver->pressure()->memory['full']['total']);
    }

    public function testDiskIoDelta(): void
    {
        $io = $this->driver->diskIo(1)[0]; self::assertSame('sda', $io->device); self::assertSame(4.0, $io->readsPerSecond); self::assertSame(5120.0, $io->writeBytesPerSecond); self::assertSame(2.0, $io->busyPercentage); self::assertSame(3.0, $io->readLatencyMs); self::assertSame(4.0, $io->writeLatencyMs); self::assertSame(0.04, $io->queueDepth); self::assertSame(2, $io->inProgress);
        $disk = $this->driver->disks()[0]; self::assertNotNull($disk->inodes); self::assertNotNull($disk->inodesUsedPercentage);
    }

    private function put(string $path, string $contents): void { file_put_contents($this->root . '/' . $path, $contents); }
    private function networkFixture(int $rx, int $tx): string { return "Inter-| Receive | Transmit\n face |bytes packets errs drop fifo frame compressed multicast|bytes packets errs drop fifo colls carrier compressed\neth0: {$rx} 1 0 0 0 0 0 0 {$tx} 1 0 0 0 0 0 0\n"; }
    private function removeTree(string $path): void { if (!is_dir($path)) return; foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $item) { $target = $path . '/' . $item; is_dir($target) ? $this->removeTree($target) : unlink($target); } rmdir($path); }
}
