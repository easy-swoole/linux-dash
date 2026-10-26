<?php
namespace EasySwoole\LinuxDash\Bean;
final class PressureInfo implements \JsonSerializable
{
    /** @param array<string,array<string,float|int>> $cpu @param array<string,array<string,float|int>> $memory @param array<string,array<string,float|int>> $io */
    public function __construct(public readonly array $cpu, public readonly array $memory, public readonly array $io) {}
    public function jsonSerialize(): array { return get_object_vars($this); }
}
