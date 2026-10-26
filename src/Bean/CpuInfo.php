<?php
namespace EasySwoole\LinuxDash\Bean;
final class CpuInfo implements \JsonSerializable
{
    public function __construct(public readonly float $usage, public readonly float $user, public readonly float $system, public readonly float $idle) {}
    public function jsonSerialize(): array { return get_object_vars($this); }
}
