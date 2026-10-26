<?php

declare(strict_types=1);

namespace EasySwoole\LinuxDash\Tests;

use EasySwoole\LinuxDash\Driver\MacOsDriver;
use EasySwoole\LinuxDash\Exception\UnsupportedMetricException;
use PHPUnit\Framework\TestCase;

final class MacOsDriverTest extends TestCase
{
    private MacOsDriver $driver;

    protected function setUp(): void
    {
        if (PHP_OS_FAMILY !== 'Darwin' || !extension_loaded('FFI')) self::markTestSkipped('Requires macOS with FFI');
        $this->driver = new MacOsDriver();
    }

    public function testNativeMetrics(): void
    {
        self::assertGreaterThan(0, $this->driver->currentRam()->total); $cpu = $this->driver->cpu(0.02); self::assertGreaterThanOrEqual(0, $cpu->usage); self::assertNotEmpty($cpu->cores);
        $network = $this->driver->network(0.01); self::assertNotEmpty($network); self::assertGreaterThan(0, $network[0]->mtu); self::assertNotEmpty($this->driver->disks()); self::assertGreaterThanOrEqual(0, $this->driver->load()->one);
        self::assertGreaterThan(0, $this->driver->uptime()->seconds); $system = $this->driver->system(); self::assertGreaterThan(0, $system->logicalCpus); self::assertNotNull($system->physicalCpus); self::assertNotNull($system->performanceCpus); self::assertNotNull($system->efficiencyCpus); self::assertGreaterThanOrEqual(0, $this->driver->swap()->used);
    }

    public function testPressureIsExplicitlyUnsupported(): void
    {
        self::assertFalse($this->driver->supportsPressure()); $this->expectException(UnsupportedMetricException::class); $this->driver->pressure();
    }
}
