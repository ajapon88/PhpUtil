<?php
//class MySsh_Exception extends Common_Exception_Abstract
class MySsh_Exception extends Exception
{
}

class MySsh
{
    const PORTFOWARD_ADDRESS = 'localhost'; // ポートフォワーディングしたときにアクセスするアドレス
    
    // TARGET
    public $host = null;
    public $port = 22;
    public $user = null;
    public $identityFile = null;
    // GATEWAY
    public $gatewayHost = null;
    public $gatewayPort = null;
    public $gatewayUser = null;
    public $gatewayIdentityFile = null;
    public $defaultForwardPort = null;
    // known_hosts check
    public $hostRegistrationCheck = true;
    
    
    public function __construct($host, $user, $identityFile, $port = 22)
    {
        $this->host = $host;
        $this->user = $user;
        $this->identityFile = $identityFile;
        $this->port = $port;
    }
    
    /**
     * 踏み台サーバ設定
     * 
     * @param string $gatewayHost 踏み台サーバアドレス
     * @param int $gatewayPort 踏み台サーバポート
     * @param int $defaultForwardPort 踏み台サーバ接続時にデフォルトでバインドするポート
     */
    public function setGateway($gatewayHost, $gatewayPort = null, $defaultForwardPort = null)
    {
        $this->gatewayHost = $gatewayHost;
        $this->gatewayPort = $gatewayPort;
        $this->defaultForwardPort = $defaultForwardPort;
    }
    
    /**
     * 踏み台サーバのユーザ設定
     * 接続先と踏み台でユーザが違う場合に使用する。たぶん使うことはない
     * 
     * @param string $gatewayUser 踏み台サーバユーザ
     * @param string $gatewayIdentityFile 踏み台サーバRSAキーファイル
     */
    public function setGatewayUser($gatewayUser, $gatewayIdentityFile)
    {
        $this->gatewayUser = $gatewayUser;
        $this->gatewayIdentityFile = $gatewayIdentityFile;
    }
    
    /**
     * 使用可能なポート番号を生成
     * 
     * 使用可能なポートをランダムに選出する（はず）
     * 
     * @param int defaultPort デフォルトで使用するポート
     * @return int 使用可能なポート
     */
    function createRandomPort($defaultPort = null)
    {
        $portRange = null;
        // 使用可能ポート範囲を取得
        if (file_exists('/proc/sys/net/ipv4/ip_local_port_range')) {
            $ip_local_port_range = file_get_contents('/proc/sys/net/ipv4/ip_local_port_range');
            $portRange = preg_split('/\s/', $ip_local_port_range);
            if (count($portRange) != 2) {
                $portRange = null;
            }
        }
        // デフォルト範囲
        if (!$portRange) {
            $portRange = array(32768, 61000);
        }
        
        // 使用中ポートリスト作成
        $usedPort = array();
        exec('netstat -lanut', $output, $ret);
        foreach($output as $line) {
            if (preg_match('/^(tcp|udp)\s+([^\s]+\s+){2}([^\s]+)/i', $line, $match)) {
                $localAddress = explode(':', $match[3]);
                if (count($localAddress) > 0) {
                    $p = end($localAddress);
                    $usedPort[$p] = $p;
                }
            }
        }
        
//        echo implode(',', $usedPort),PHP_EOL;
        // デフォルトに設定されたポートが使用できるかどうか
        if ($defaultPort && !in_array($defaultPort, $usedPort)) {
            return $defaultPort;
        }
        
        // 使用可能ポート生成
        // ポートが枯渇することは考えづらいが、念のため回数に制限を付けておく
        for($i = 0; $i < 100; $i++) {
            $port = mt_rand($portRange[0], $portRange[1]);
            if (!in_array($port, $usedPort)) {
                break;
            }
//            echo 'Port ', $port, ' は使用中', PHP_EOL;
        }
        
        return $port;
    }
    
    /**
     * hostがknown hostsに登録されているかどうかチェックする
     * ドメインとIPは別扱いなのに注意！
     * 
     * @param $host チェックするホスト
     * @return bool true：登録済み/false 未登録
     */
    function isRegisterKnownHosts($host)
    {
        exec("ssh-keygen -F {$host}", $output, $ret);
        // 登録済みなら鍵が表示される
        if (0 == $ret && $output) {
            return true;
        }
        
        return false;
    }
    
    /**
     * sshポートフォワーディング
     * 
     * ポートフォワーディングを行い、バインドしたポート番号を返す
     * 取得したポート番号でlocalhostにアクセスするとトンネル接続できる
     * 指定したタイムアウトの間にポートに接続がなければ自動で切断される
     * フォワードするポートは被らないようにすること！！
     * 
     * @param int $forwardPort バインドするポート
     * @param int $timeout 接続のタイムアウトするまでの時間。この時間内に接続がなければフォワーディングを終了する
     * @param int $maxRetryCount 試行回数
     * @return int バインドしたポート
     */
    function sshPortForwardGateway($forwardPort = null, $timeout = 1, $maxRetryCount = 1)
    {
        if (!$this->gatewayHost) {
            throw new MySsh_Exception(sprintf("ポートフォワード失敗 踏み台が設定されていません。"));
        }
        // KnownHosts確認
        if ($this->hostRegistrationCheck && !$this->isRegisterKnownHosts($this->gatewayHost)) {
            throw new MySsh_Exception(sprintf("ポートフォワード失敗 接続先ホストが登録されていません  host='%s'", $this->gatewayHost));
        }
        if (!$forwardPort) {
            $forwardPort = $this->defaultForwardPort;
        }
        $gatewayPort = $this->gatewayPort?:$this->port;
        $gatewayUser = $this->gatewayUser?:$this->user;
        $gatewayIdentityFile = $this->gatewayIdentityFile?:$this->identityFile;
        
        $tryCount = 0;
        while(true) {
            $tryCount++;
            
            // 使用チェックも兼ねて使用ポート取得
            $forwardPort = $this->createRandomPort($forwardPort);
            // sshコマンドでポートフォワーディング
            // フォワーディング開始してから$timeout秒だけ接続を待つ
            $command = "ssh -i '{$gatewayIdentityFile}' -fCL {$forwardPort}:{$this->host}:{$this->port} -p {$gatewayPort} {$gatewayUser}@{$this->gatewayHost} sleep {$timeout} > /dev/null";
//            echo $command,PHP_EOL;
            exec($command, $output, $ret);
            if (0 != $ret) {
                // リトライ回数チェック
                if ($tryCount >= $maxRetryCount) {
                    $errMsg = sprintf("ポートフォワード失敗 リトライ回数を超えました\n    command=`%s`\nLastErrorMessage:\n-----\n%s\n-----\n", $command, implode("\n", $output));
                    throw new MySsh_Exception($errMsg);
                }
                // ポートが被っていた可能性があるので念のため削除
                $forwardPort = null;
            } else {
                // バインド成功
                return $forwardPort;
            }
        }
    }
    
    /**
     * ssh
     * 
     * @param string $sshCommand 実行するコマンド
     * @param string $sshOutput 出力
     * @param string $sshReturn 実行結果
     * @return bool コマンドの成否
     */
    function ssh($sshCommand, &$sshOutput = null, &$sshReturn = null)
    {
        $port = $this->port;
        $host = $this->host;
        // 踏み台
        if ($this->gatewayHost) {
            $port = $this->sshPortForwardGateway();
            $host = self::PORTFOWARD_ADDRESS;
        }
        // KnownHosts確認
        if ($this->hostRegistrationCheck && !$this->isRegisterKnownHosts($host)) {
            throw new MySsh_Exception(sprintf("SSH失敗 接続先ホストが登録されていません host='%s'", $host));
        }
        // sshコマンドで接続
        $command = "ssh -i '{$this->identityFile}' -p {$port} {$this->user}@{$host} '{$sshCommand}'";
//        echo $command,PHP_EOL;
        exec($command, $output, $ret);
        // 引数指定があったら結果を代入する
        if (func_num_args() >= 1) {
            $sshOutput = $output;
        }
        if (func_num_args() >= 2) {
            $sshReturn = $ret;
        }
        if (0 != $ret) {
            return false;
        }
        return true;
    }
    
    /**
     * SCP転送
     * 
     * @param string $sendFile 送信するファイル
     * @param string $sendPath 送信するパス
     * @return bool 成否
     */
    function scp($sendFile, $sendPath)
    {
        $port = $this->port;
        $host = $this->host;
        // 踏み台
        if ($this->gatewayHost) {
            $port = $this->sshPortForwardGateway();
            $host = self::PORTFOWARD_ADDRESS;
        }
        // KnownHosts確認
        if ($this->hostRegistrationCheck && !$this->isRegisterKnownHosts($host)) {
            throw new MySsh_Exception(sprintf("SSH失敗 接続先ホストが登録されていません host='%s'", $host));
        }
        // 転送
        $command = "scp -BC -i '{$this->identityFile}' -P {$port} '{$sendFile}' {$this->user}@{$host}:'{$sendPath}'";
//        echo $command,PHP_EOL;
        exec($command, $output, $ret);
        if (0 != $ret) {
            return false;
        }
        return true;
    }
    
    /**
     * ホストのRSAキー登録
     * sshコマンドでホストを登録する
     * SSH/SCPを使用するバッチ初回実行前もしくは接続先が変更になった場合に使用
     * 
     * @param $reRegistration 登録情報を削除して再登録する 
     * @return bool 成否
     */
    function registerKnownHosts($reRegistration = false)
    {
        // 一旦登録を削除
        $host = $this->host;
        if ($this->gatewayHost) {
            if ($reRegistration) {
                exec("ssh-keygen -R {$this->gatewayHost}", $output, $ret);
            }
            $host = self::PORTFOWARD_ADDRESS;
        }
        if ($reRegistration) {
            exec("ssh-keygen -R {$host}", $output, $ret);
        }
        // ホストに接続
        // known_hostsから登録削除しているのでknown_hostsチェックはしない
        $prevHostRegistrationCheck = $this->hostRegistrationCheck;
        $this->hostRegistrationCheck = false;
        $ret = $this->ssh('sleep 0', $output);
        $this->hostRegistrationCheck = $prevHostRegistrationCheck;

        return $ret;
    }
}
