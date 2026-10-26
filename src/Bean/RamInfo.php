<?php

namespace EasySwoole\LinuxDash\Bean;

final class RamInfo implements \JsonSerializable
{
    public function __construct(
        public readonly int $total,
        public readonly int $used,
        public readonly int $available,
    ) {}

    public function jsonSerialize(): array { return get_object_vars($this); }
}
