<?php

namespace EasySwoole\LinuxDash\AbstractInterface;

use EasySwoole\LinuxDash\Bean\CpuInfo;
use EasySwoole\LinuxDash\Bean\RamInfo;
use EasySwoole\LinuxDash\Bean\LoadInfo;
use EasySwoole\LinuxDash\Bean\SwapInfo;
use EasySwoole\LinuxDash\Bean\SystemInfo;
use EasySwoole\LinuxDash\Bean\TcpInfo;
use EasySwoole\LinuxDash\Bean\UptimeInfo;

interface DriverInterface
{
    public function currentRam(): RamInfo;
    public function cpu(float $sampleSeconds = 0.1): CpuInfo;
    /** @return list<\EasySwoole\LinuxDash\Bean\NetworkInfo> */
    public function network(float $sampleSeconds = 0.1): array;
    /** @return list<\EasySwoole\LinuxDash\Bean\DiskInfo> */
    public function disks(): array;
    public function load(): LoadInfo;
    public function swap(): SwapInfo;
    public function uptime(): UptimeInfo;
    public function system(): SystemInfo;
    /** @return list<\EasySwoole\LinuxDash\Bean\DiskIoInfo> */
    public function diskIo(float $sampleSeconds = 0.1): array;
    public function tcp(): TcpInfo;
    public function pressure(): \EasySwoole\LinuxDash\Bean\PressureInfo;
    public function supportsPressure(): bool;
}
