<?php
namespace EasySwoole\LinuxDash\Bean;
final class LoadInfo implements \JsonSerializable
{
    public function __construct(public readonly float $one, public readonly float $five, public readonly float $fifteen, public readonly ?int $running = null, public readonly ?int $processes = null) {}
    public function jsonSerialize(): array { return get_object_vars($this); }
}
