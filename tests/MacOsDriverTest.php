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
        self::assertGreaterThan(0, $this->driver->currentRam()->total); self::assertGreaterThanOrEqual(0, $this->driver->cpu(0.02)->usage);
        self::assertNotEmpty($this->driver->network(0.01)); self::assertNotEmpty($this->driver->disks()); self::assertGreaterThanOrEqual(0, $this->driver->load()->one);
        self::assertGreaterThan(0, $this->driver->uptime()->seconds); self::assertGreaterThan(0, $this->driver->system()->logicalCpus); self::assertGreaterThanOrEqual(0, $this->driver->swap()->used);
    }

    public function testPressureIsExplicitlyUnsupported(): void
    {
        self::assertFalse($this->driver->supportsPressure()); $this->expectException(UnsupportedMetricException::class); $this->driver->pressure();
    }
}
