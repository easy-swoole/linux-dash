<?php
namespace EasySwoole\LinuxDash\Bean;
final class NetworkInfo implements \JsonSerializable
{
    /** @param list<string> $addresses */
    public function __construct(public readonly string $interface, public readonly int $rx, public readonly int $tx, public readonly float $rxPerSecond = 0.0, public readonly float $txPerSecond = 0.0, public readonly int $rxPackets = 0, public readonly int $txPackets = 0, public readonly int $rxErrors = 0, public readonly int $txErrors = 0, public readonly int $rxDropped = 0, public readonly int $txDropped = 0, public readonly int $collisions = 0, public readonly ?int $mtu = null, public readonly ?string $mac = null, public readonly ?string $state = null, public readonly array $addresses = []) {}
    public function jsonSerialize(): array { return get_object_vars($this); }
}
