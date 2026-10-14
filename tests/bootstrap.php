<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

/*
 * Start the fake API (tests/Fixtures/server.php) on a free local port for the
 * whole run. Nothing in the suite talks to the real API.
 */
$socket = stream_socket_server('tcp://127.0.0.1:0');
if ($socket === false) {
    throw new RuntimeException('No free local port for the fake API.');
}
$address = (string) stream_socket_get_name($socket, false);
fclose($socket);
$port = (int) substr($address, strrpos($address, ':') + 1);

$stateFile = sys_get_temp_dir().DIRECTORY_SEPARATOR.'myadion-fake-'.$port.'.json';
@unlink($stateFile);

$env = getenv();
$env['MYADION_FAKE_STATE'] = $stateFile;
$null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
$process = proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:'.$port, __DIR__.'/Fixtures/server.php'],
    [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']],
    $pipes,
    null,
    $env,
);
if (! is_resource($process)) {
    throw new RuntimeException('Could not start the fake API.');
}

$deadline = microtime(true) + 10;
while (true) {
    $probe = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
    if ($probe !== false) {
        fclose($probe);
        break;
    }
    if (microtime(true) > $deadline) {
        proc_terminate($process);
        throw new RuntimeException('The fake API did not start.');
    }
    usleep(50_000);
}

define('MYADION_FAKE_URL', 'http://127.0.0.1:'.$port);

register_shutdown_function(static function () use ($process, $stateFile): void {
    proc_terminate($process);
    proc_close($process);
    @unlink($stateFile);
    @unlink($stateFile.'.lock');
});
