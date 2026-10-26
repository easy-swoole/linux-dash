<?php
namespace EasySwoole\LinuxDash\Bean;
final class DiskInfo implements \JsonSerializable
{
    public function __construct(public readonly string $fileSystem, public readonly int $size, public readonly int $used, public readonly int $avail, public readonly float $usedPercentage, public readonly string $mounted, public readonly ?int $inodes = null, public readonly ?int $inodesUsed = null, public readonly ?int $inodesAvailable = null, public readonly ?float $inodesUsedPercentage = null) {}
    public function jsonSerialize(): array { return get_object_vars($this); }
}
