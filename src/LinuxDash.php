<?php


namespace EasySwoole\LinuxDash;




use EasySwoole\LinuxDash\AbstractInterface\DriverInterface;
use EasySwoole\LinuxDash\Driver\LinuxDriver;
use EasySwoole\LinuxDash\Driver\MacOsDriver;
use EasySwoole\LinuxDash\Bean\CpuInfo;
use EasySwoole\LinuxDash\Bean\RamInfo;
use EasySwoole\LinuxDash\Bean\LoadInfo;
use EasySwoole\LinuxDash\Bean\PressureInfo;
use EasySwoole\LinuxDash\Bean\SwapInfo;
use EasySwoole\LinuxDash\Bean\SystemInfo;
use EasySwoole\LinuxDash\Bean\TcpInfo;
use EasySwoole\LinuxDash\Bean\UptimeInfo;

class LinuxDash
{
    private DriverInterface $driver;
    public function __construct(?OsType $osType = null)
    {
        $osType ??= OsType::tryFrom(PHP_OS_FAMILY) ?? throw new \RuntimeException('Unsupported operating system: ' . PHP_OS_FAMILY);
        $this->driver = $osType === OsType::LINUX ? new LinuxDriver() : new MacOsDriver();
    }

    public function currentRam(): RamInfo { return $this->driver->currentRam(); }
    public function cpu(float $sampleSeconds = 0.1): CpuInfo { return $this->driver->cpu($sampleSeconds); }
    public function network(float $sampleSeconds = 0.1): array { return $this->driver->network($sampleSeconds); }
    public function disks(): array { return $this->driver->disks(); }
    public function load(): LoadInfo { return $this->driver->load(); }
    public function swap(): SwapInfo { return $this->driver->swap(); }
    public function uptime(): UptimeInfo { return $this->driver->uptime(); }
    public function system(): SystemInfo { return $this->driver->system(); }
    public function diskIo(float $sampleSeconds = 0.1): array { return $this->driver->diskIo($sampleSeconds); }
    public function tcp(): TcpInfo { return $this->driver->tcp(); }
    public function pressure(): PressureInfo { return $this->driver->pressure(); }
    public function supportsPressure(): bool { return $this->driver->supportsPressure(); }
}
