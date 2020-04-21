<?php


namespace EasySwoole\LinuxDash;


use EasySwoole\LinuxDash\Response\ArpCache;
use EasySwoole\LinuxDash\Response\Bandwidth;
use EasySwoole\LinuxDash\Response\CpuIntensiveProcesses;
use EasySwoole\LinuxDash\Response\CurrentRam;
use EasySwoole\LinuxDash\Response\DiskPartition;
use EasySwoole\LinuxDash\Response\GeneralInfo;
use EasySwoole\LinuxDash\Response\IoStats;
use EasySwoole\LinuxDash\Response\IpAddresses;
use EasySwoole\LinuxDash\Response\LoadAvg;
use EasySwoole\LinuxDash\Response\RamIntensiveProcesses;
use EasySwoole\LinuxDash\Response\Swap;
use EasySwoole\LinuxDash\Response\UserAccount;
use Swoole\Coroutine;

class LinuxDash
{
    /*
     * checked
     */
    public static function arpCache()
    {
        $ret = [];
        $json = self::exec('arpCache.sh');
        foreach ($json as $item){
            $ret[] = new ArpCache($item);
        }
        return $ret;
    }

    /*
     * 获取带宽信息
     * checked
     */
    public static function bandWidth():array
    {
        $ret = [];
        $json = self::exec('bandwidth.sh');
        foreach ($json as $item){
            $ret[] = new Bandwidth($item);
        }
        return $ret;
    }

    /*
     * checked
     */
    public static function cpuIntensiveProcesses()
    {
        $json = self::exec('cpuIntensiveProcesses.sh');
        $ret = [];
        foreach ($json as $item){
            $ret[] = new CpuIntensiveProcesses($item);
        }
        return $ret;
    }

    /*
     * checked
     */
    public static function diskPartitions()
    {
        $json = self::exec('diskPartitions.sh');
        $ret = [];
        foreach ($json as $item){
            $ret[] = new DiskPartition($item);
        }
        return $ret;
    }

    /*
     * checked
     */
    public static function currentRam()
    {
        $info = self::exec('currentRam.sh');
        return new CurrentRam($info);
    }

    /*
    * checked
    */
    public static function cpuInfo():array
    {
        return self::exec('cpuInfo.sh');
    }

    /*
     * checked
     */
    public static function generalInfo()
    {
        $info = self::exec('generalInfo.sh');
        return new GeneralInfo($info);
    }

    public static function ioStats():array
    {
        $list = [];
        $info = self::exec('ioStats.sh');
        foreach ($info as $value){
            $list[] = new IoStats($value);
        }
        return $list;
    }

    /*
     * checked
     */
    public static function ipAddresses()
    {
        $ret = [];
        $list = swoole_get_local_ip();
        foreach ($list as $key => $item){
            $ret[] = new IpAddresses([
                'interface'=>$key,
                'ip'=>$item
            ]);
        }
        return $ret;
    }

    /*
     * checked
     */
    public static function loadAvg()
    {
        $info = self::exec('loadAvg.sh');
        return new LoadAvg($info);
    }


    /*
     * checked,
     * 没bean
     */
    public static function memoryInfo()
    {
        $list = self::exec('memoryInfo.sh');
        foreach ($list as $key => $item){
            $list[$key] = trim($item);
        }
        return $list;
    }

    /*
     * checked
     */
    public static function ramIntensiveProcesses()
    {
        $info = self::exec('ramIntensiveProcesses.sh');
        $list = [];
        foreach ($info as $item){
            $list[] = new RamIntensiveProcesses($item);
        }
        return $list;
    }

    /*
     * checked
     */
    public static function swap()
    {
        $info = self::exec('swap.sh');
        $ret = [];
        foreach ($info as $item){
            $ret[] = new Swap($item);
        }
        return $ret;
    }

    /*
     * checked
     */
    public static function userAccounts()
    {
        $info = self::exec('userAccounts.sh');
        $ret = [];
        foreach ($info as $item){
            $ret[] = new UserAccount($item);
        }
        return $ret;
    }

    private static function exec($file):array
    {
        try{
            $js = trim(Coroutine::exec(file_get_contents(__DIR__ . "/Shell/{$file}"))['output']);
            $js = json_decode($js,true);
            if(is_array($js)){
                return $js;
            }
        }catch (\Throwable $throwable){

        }
        return [];
    }
}