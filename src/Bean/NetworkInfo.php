<?php
namespace EasySwoole\LinuxDash\Bean;
final class NetworkInfo implements \JsonSerializable
{
    public function __construct(public readonly string $interface, public readonly int $rx, public readonly int $tx, public readonly float $rxPerSecond = 0.0, public readonly float $txPerSecond = 0.0) {}
    public function jsonSerialize(): array { return get_object_vars($this); }
}
