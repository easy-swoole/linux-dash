<?php
namespace EasySwoole\LinuxDash\Bean;
final class SystemInfo implements \JsonSerializable
{
    public function __construct(public readonly string $hostname, public readonly string $os, public readonly string $kernel, public readonly string $architecture, public readonly int $logicalCpus, public readonly ?string $cpuModel = null, public readonly ?int $physicalCpus = null, public readonly ?int $cpuFrequencyHz = null, public readonly ?int $performanceCpus = null, public readonly ?int $efficiencyCpus = null) {}
    public function jsonSerialize(): array { return get_object_vars($this); }
}
