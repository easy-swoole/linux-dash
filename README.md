# linux-dash

2.x 是面向 Linux 和 macOS 的轻量系统指标采集 API。Linux 直接读取 `/proc`（挂载点容量由 PHP 的文件系统 API 获取）；macOS 通过 PHP FFI 调用 `libSystem`，不执行 shell 命令。返回 Bean 使用公开只读属性并支持 JSON 序列化，不兼容 1.x 的 `SplBean` getter/setter 模型。

## 安装与初始化

```bash
composer require easyswoole/linux-dash:^2.0
```

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use EasySwoole\LinuxDash\LinuxDash;

$dash = new LinuxDash(); // 根据 PHP_OS_FAMILY 自动选择 Linux 或 macOS 驱动
```

所有 Bean 都使用公开只读属性，可直接访问，也可传给 `json_encode()`：

```php
$ram = $dash->currentRam();
echo $ram->used;
echo json_encode($ram, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
```

## API

### `currentRam(): RamInfo`

采集物理内存，所有容量字段的单位均为字节。

```php
$ram = $dash->currentRam();
```

| 字段 | 类型 | 说明 | 样例值 |
| --- | --- | --- | ---: |
| `total` | `int` | 物理内存总量 | `17179869184` |
| `used` | `int` | 已使用内存 | `11747164160` |
| `available` | `int` | 系统估算的可用内存 | `5432705024` |

```json
{"total":17179869184,"used":11747164160,"available":5432705024}
```

### `cpu(float $sampleSeconds = 0.1): CpuInfo`

在指定秒数内采集两次 CPU 累计计数器并计算差值。各字段单位均为百分比，范围为 `0`–`100`。

```php
$cpu = $dash->cpu(0.5);
```

| 字段 | 类型 | 说明 | 样例值 |
| --- | --- | --- | ---: |
| `usage` | `float` | CPU 总使用率 | `12.35` |
| `user` | `float` | 用户态使用率 | `7.41` |
| `system` | `float` | 内核态使用率 | `4.94` |
| `idle` | `float` | 空闲率，Linux 中包含 iowait | `87.65` |

```json
{"usage":12.35,"user":7.41,"system":4.94,"idle":87.65}
```

### `network(float $sampleSeconds = 0.1): NetworkInfo[]`

返回每张网卡的累计流量，以及采样区间内的平均速率。

```php
$interfaces = $dash->network(1.0);
foreach ($interfaces as $interface) {
    echo $interface->interface . ': ' . $interface->rxPerSecond . " B/s\n";
}
```

| 字段 | 类型 | 说明 | 单位/样例值 |
| --- | --- | --- | --- |
| `interface` | `string` | 网卡名称 | `eth0` |
| `rx` | `int` | 启动以来接收的累计字节 | `267561846` bytes |
| `tx` | `int` | 启动以来发送的累计字节 | `174725169` bytes |
| `rxPerSecond` | `float` | 采样区间平均接收速率 | `1197.39` B/s |
| `txPerSecond` | `float` | 采样区间平均发送速率 | `5787.40` B/s |

```json
[{"interface":"eth0","rx":267561846,"tx":174725169,"rxPerSecond":1197.39,"txPerSecond":5787.4}]
```

### `disks(): DiskInfo[]`

返回已挂载文件系统的容量信息。

```php
$partitions = $dash->disks();
```

| 字段 | 类型 | 说明 | 单位/样例值 |
| --- | --- | --- | --- |
| `fileSystem` | `string` | 设备或文件系统名称 | `/dev/vda3` |
| `size` | `int` | 总容量 | `42670731264` bytes |
| `used` | `int` | 已使用容量 | `8629985280` bytes |
| `avail` | `int` | 普通用户可用容量 | `34040745984` bytes |
| `usedPercentage` | `float` | 使用百分比 | `20.22` |
| `mounted` | `string` | 挂载点 | `/` |

```json
[{"fileSystem":"/dev/vda3","size":42670731264,"used":8629985280,"avail":34040745984,"usedPercentage":20.22,"mounted":"/"}]
```

### `load(): LoadInfo`

返回系统 1、5、15 分钟平均负载。macOS 不提供当前运行任务数和总任务数，因此相应字段为 `null`。

```php
$load = $dash->load();
```

| 字段 | 类型 | 说明 | 样例值 |
| --- | --- | --- | ---: |
| `one` | `float` | 1 分钟平均负载 | `0.34` |
| `five` | `float` | 5 分钟平均负载 | `0.18` |
| `fifteen` | `float` | 15 分钟平均负载 | `0.16` |
| `running` | `?int` | Linux 当前可运行任务数 | `1` |
| `processes` | `?int` | Linux 当前任务总数 | `233` |

```json
{"one":0.34,"five":0.18,"fifteen":0.16,"running":1,"processes":233}
```

### `swap(): SwapInfo`

返回 Swap 容量及累计换页次数。容量单位为字节；`pagesIn` 和 `pagesOut` 是系统启动以来的累计页数，不是字节数。

```php
$swap = $dash->swap();
```

| 字段 | 类型 | 说明 | 样例值 |
| --- | --- | --- | ---: |
| `total` | `int` | Swap 总容量 | `4294963200` |
| `used` | `int` | 已使用 Swap | `49152` |
| `available` | `int` | 可用 Swap | `4294914048` |
| `pagesIn` | `int` | 累计换入页数 | `0` |
| `pagesOut` | `int` | 累计换出页数 | `13` |

```json
{"total":4294963200,"used":49152,"available":4294914048,"pagesIn":0,"pagesOut":13}
```

在部分受限的 macOS 进程中，系统不允许读取 Swap 总容量。此时 `total` 等于当前 `used`，`available` 为 `0`，但换页累计值仍来自 Mach API。

### `uptime(): UptimeInfo`

```php
$uptime = $dash->uptime();
```

| 字段 | 类型 | 说明 | 单位/样例值 |
| --- | --- | --- | --- |
| `seconds` | `float` | 系统已运行时长 | `105570.22` seconds |
| `bootedAt` | `int` | 估算的启动时间 | `1792916221` Unix timestamp |

```json
{"seconds":105570.22,"bootedAt":1792916221}
```

### `system(): SystemInfo`

```php
$system = $dash->system();
```

| 字段 | 类型 | 说明 | 样例值 |
| --- | --- | --- | --- |
| `hostname` | `string` | 主机名 | `monitor-node-01` |
| `os` | `string` | 操作系统 | `Linux` |
| `kernel` | `string` | 内核版本 | `6.12.0-266.el10.x86_64` |
| `architecture` | `string` | CPU 架构 | `x86_64` |
| `logicalCpus` | `int` | 在线逻辑 CPU 数量 | `2` |
| `cpuModel` | `?string` | CPU 型号，无法读取时为 `null` | `Intel(R) Xeon(R) Platinum` |

```json
{"hostname":"monitor-node-01","os":"Linux","kernel":"6.12.0-266.el10.x86_64","architecture":"x86_64","logicalCpus":2,"cpuModel":"Intel(R) Xeon(R) Platinum"}
```

### `diskIo(float $sampleSeconds = 0.1): DiskIoInfo[]`

仅 Linux 支持。累计值来自 `/proc/diskstats`，速率和繁忙率由采样差值计算。

```php
$devices = $dash->diskIo(1.0);
```

| 字段 | 类型 | 说明 | 单位/样例值 |
| --- | --- | --- | --- |
| `device` | `string` | 块设备名称 | `vda` |
| `reads` | `int` | 累计完成读取次数 | `27312` |
| `writes` | `int` | 累计完成写入次数 | `101154` |
| `readBytes` | `int` | 累计读取量 | `880077824` bytes |
| `writeBytes` | `int` | 累计写入量 | `1646177792` bytes |
| `readsPerSecond` | `float` | 读取 IOPS | `12.50` ops/s |
| `writesPerSecond` | `float` | 写入 IOPS | `8.20` ops/s |
| `readBytesPerSecond` | `float` | 读取吞吐量 | `1048576.00` B/s |
| `writeBytesPerSecond` | `float` | 写入吞吐量 | `524288.00` B/s |
| `busyPercentage` | `float` | 采样期间设备繁忙率 | `3.50` |

```json
[{"device":"vda","reads":27312,"writes":101154,"readBytes":880077824,"writeBytes":1646177792,"readsPerSecond":12.5,"writesPerSecond":8.2,"readBytesPerSecond":1048576,"writeBytesPerSecond":524288,"busyPercentage":3.5}]
```

macOS 调用该方法会抛出 `UnsupportedMetricException`。

### `tcp(): TcpInfo`

仅 Linux 支持。统计 IPv4 与 IPv6 TCP 连接状态。

```php
$tcp = $dash->tcp();
```

| 字段 | 类型 | 说明 | 样例值 |
| --- | --- | --- | --- |
| `states` | `array<string,int>` | 仅包含数量大于零的 TCP 状态 | `{"ESTABLISHED":2,"TIME_WAIT":4,"LISTEN":3}` |
| `total` | `int` | 所有 TCP 状态数量之和 | `9` |

```json
{"states":{"ESTABLISHED":2,"TIME_WAIT":4,"LISTEN":3},"total":9}
```

macOS 调用该方法会抛出 `UnsupportedMetricException`。

### `supportsPressure(): bool` 与 `pressure(): PressureInfo`

PSI 是可选的 Linux 内核能力。必须先探测，再读取：

```php
if ($dash->supportsPressure()) {
    $pressure = $dash->pressure();
}
```

`PressureInfo` 包含 `cpu`、`memory` 和 `io` 三组数据。每组包含内核实际提供的 `some` 和/或 `full`；每种压力的字段为：

| 字段 | 类型 | 说明 | 样例值 |
| --- | --- | --- | ---: |
| `avg10` | `float` | 最近 10 秒受阻时间百分比 | `0.10` |
| `avg60` | `float` | 最近 60 秒受阻时间百分比 | `0.20` |
| `avg300` | `float` | 最近 300 秒受阻时间百分比 | `0.30` |
| `total` | `int` | 启动以来累计受阻时间，单位微秒 | `4000` |

```json
{
  "cpu":{"some":{"avg10":1.0,"avg60":2.0,"avg300":3.0,"total":4000}},
  "memory":{"some":{"avg10":0.1,"avg60":0.2,"avg300":0.3,"total":400},"full":{"avg10":0.01,"avg60":0.02,"avg300":0.03,"total":40}},
  "io":{"some":{"avg10":0.5,"avg60":0.6,"avg300":0.7,"total":800},"full":{"avg10":0.05,"avg60":0.06,"avg300":0.07,"total":80}}
}
```

macOS 或未暴露 `/proc/pressure` 的 Linux 主机会返回 `false`；此时直接调用 `pressure()` 会抛出 `UnsupportedMetricException`。

## 平台支持

| 方法 | Linux | macOS |
| --- | :---: | :---: |
| `currentRam()` | ✓ | ✓，FFI |
| `cpu()` | ✓ | ✓，FFI |
| `network()` | ✓ | ✓，FFI |
| `disks()` | ✓ | ✓，FFI |
| `load()` | ✓ | ✓，FFI |
| `swap()` | ✓ | ✓，FFI/受限时回退 Mach |
| `uptime()` | ✓ | ✓，FFI |
| `system()` | ✓ | ✓，FFI |
| `diskIo()` | ✓ | 暂不支持 |
| `tcp()` | ✓ | 暂不支持 |
| `pressure()` | 取决于内核 PSI 配置 | 不支持 |

macOS 需要启用 PHP FFI 扩展。CLI 环境通常可用，Web/FPM 环境还需确认 `ffi.enable` 配置。2.x 只采集宿主机指标，不包含 Docker、Kubernetes 或 cgroup 指标。

## 异常处理

```php
use EasySwoole\LinuxDash\Exception\MetricReadException;
use EasySwoole\LinuxDash\Exception\UnsupportedMetricException;

try {
    $cpu = $dash->cpu(0.5);
} catch (UnsupportedMetricException $exception) {
    // 当前平台不支持该指标
} catch (MetricReadException $exception) {
    // 当前平台支持，但本次读取失败
}
```

负数采样时间会抛出 `InvalidArgumentException`。采集失败不会静默返回伪造的零值。

## 测试

```bash
composer install
composer test
```
