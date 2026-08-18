<?php
declare(strict_types=1);
namespace Core;
final class Logger {
    public static function error(string $message, array $context=[]): void { self::write('ERROR',$message,$context); }
    public static function warning(string $message, array $context=[]): void { self::write('WARNING',$message,$context); }
    public static function info(string $message, array $context=[]): void { self::write('INFO',$message,$context); }
    private static function write(string $level,string $message,array $context): void {
        $path=\configValue('LOG_PATH',__DIR__.'/../../storage/logs/app.log');
        $dir=dirname($path); if(!is_dir($dir)) @mkdir($dir,0750,true);
        $safe=[]; foreach($context as $k=>$v) $safe[$k]=is_scalar($v)?$v:'[redacted]';
        @file_put_contents($path,json_encode(['ts'=>gmdate('c'),'level'=>$level,'message'=>$message,'context'=>$safe],JSON_UNESCAPED_SLASHES).PHP_EOL,FILE_APPEND|LOCK_EX);
    }
}
