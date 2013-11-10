<?php
class MyUtil_Exception extends Exception
{
}

class MyUtil
{
    /**
     * ファイル圧縮(.tar.gz)
     * 
     * @param mixid $rawFiles 圧縮するファイル名
     * @param string $compFile 出力するファイル名．省略した場合は カレントディレクトリ/1番目のファイル名.tar.gz
     * @param bool $orverwrite ファイルがあった場合上書き保存するか
     * @return string 圧縮ファイル名
     */
    static function targz($rawFiles, $compressFile = null, $orverwrite = true)
    {
        if (!is_array($rawFiles)) {
            $rawFiles = array($rawFiles);
        }
        // 出力先生成などに使用するファイル名
        $basefile = current($rawFiles);
        
        // 圧縮ファイル名生成
        if (!$compressFile) {
            // ファイル名が未指定ならカレントディレクトリに出力される
            $compressFile = basename($basefile) . '.tar.gz';
        }
        // 拡張子付与
        if (!preg_match('/\.tar\.gz$/', $compressFile)) {
            $compressFile .= '.tar.gz';
        }
        
        // ファイルチェック
        if (!$orverwrite && file_exists($compressFile)) {
            throw new MyUtil_Exception(sprintf("圧縮ファイルが既に存在します compFile=`%s`",  $compressFile));
        }
        
        // 圧縮
        $command = "tar zcvf '{$compressFile}' ";
        foreach($rawFiles as $file) {
            $dir = realpath(dirname($file));
            $filename = basename($file);
            $command .= " -C '{$dir}' '{$filename}'";
        }
        exec($command, $output, $ret);
        if (0 != $ret) {
            throw new MyCommandUtil_Exception(sprintf("ファイル圧縮に失敗しました rawFiles=%s\n    compFile=`%s`", print_r($rawFiles, true), $compressFile));
        }
        // 念のためファイルの存在とサイズをチェック
        if (!file_exists($compressFile) || filesize($compressFile) <= 0) {
            throw new MyUtil_Exception(sprintf("圧縮ファイルが正しく生成されていません rawFiles=%s\n    compFile=`%s`", print_r($rawFiles, true), $compressFile));
        }
        
        return $compressFile;
    }
}
