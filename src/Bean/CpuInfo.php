<?php
namespace EasySwoole\LinuxDash\Bean;
final class CpuInfo implements \JsonSerializable
{
    /** @param list<CpuCoreInfo> $cores */
    public function __construct(public readonly float $usage, public readonly float $user, public readonly float $system, public readonly float $idle, public readonly float $iowait = 0.0, public readonly float $steal = 0.0, public readonly float $irq = 0.0, public readonly array $cores = []) {}
    public function jsonSerialize(): array { return get_object_vars($this); }
}
