<?php
require_once dirname(__FILE__) . '/MyUtil.php';
require_once dirname(__FILE__) . '/MySsh.php';

// SSH
$ssh = new MySsh('target.com', 'user', '/home/user/.ssh/id_rsa');
$ssh->setGateway('gateway.com');

$ssh->registerKnownHosts(true);

if ($ssh->ssh('mkdir ssh_dir > dev/null 2>&1')) {
    echo 'ディレクトリの作成に成功', PHP_EOL;
}
if ($ssh->ssh('test -d ssh_dir')) {
    $compressFile = MyUtil::targz(__FILE__);
    if ($ssh->scp($compressFile, '~/')) {
        echo 'ファイルの転送に成功', PHP_EOL;
    }
    $ssh->ssh('ls ssh_dir', $output);
    echo 'ssh_dir:', PHP_EOL;
    foreach ($output as $line) {
        echo '    ', $line, PHP_EOL;
    }
}
