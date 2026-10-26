<?php
namespace EasySwoole\LinuxDash\Bean;
final class TcpInfo implements \JsonSerializable
{
    /** @param array<string,int> $states */
    public function __construct(public readonly array $states, public readonly int $total) {}
    public function jsonSerialize(): array { return get_object_vars($this); }
}
