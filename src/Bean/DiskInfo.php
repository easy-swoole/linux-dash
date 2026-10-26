<?php
namespace EasySwoole\LinuxDash\Bean;
final class DiskInfo implements \JsonSerializable
{
    public function __construct(public readonly string $fileSystem, public readonly int $size, public readonly int $used, public readonly int $avail, public readonly float $usedPercentage, public readonly string $mounted) {}
    public function jsonSerialize(): array { return get_object_vars($this); }
}
