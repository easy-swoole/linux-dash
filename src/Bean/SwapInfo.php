<?php
namespace EasySwoole\LinuxDash\Bean;
final class SwapInfo implements \JsonSerializable
{
    public function __construct(public readonly int $total, public readonly int $used, public readonly int $available, public readonly int $pagesIn = 0, public readonly int $pagesOut = 0) {}
    public function jsonSerialize(): array { return get_object_vars($this); }
}
