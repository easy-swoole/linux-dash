<?php
namespace EasySwoole\LinuxDash\Bean;
final class UptimeInfo implements \JsonSerializable
{
    public function __construct(public readonly float $seconds, public readonly int $bootedAt) {}
    public function jsonSerialize(): array { return get_object_vars($this); }
}
