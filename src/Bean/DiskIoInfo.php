<?php
namespace EasySwoole\LinuxDash\Bean;
final class DiskIoInfo implements \JsonSerializable
{
    public function __construct(public readonly string $device, public readonly int $reads, public readonly int $writes, public readonly int $readBytes, public readonly int $writeBytes, public readonly float $readsPerSecond, public readonly float $writesPerSecond, public readonly float $readBytesPerSecond, public readonly float $writeBytesPerSecond, public readonly float $busyPercentage) {}
    public function jsonSerialize(): array { return get_object_vars($this); }
}
