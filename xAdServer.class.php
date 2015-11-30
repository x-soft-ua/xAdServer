<?php
/**
 * Small directory synchronizer
 *
 */

class xAdServer
{
    //Redis host
    const REDIS_HOST = '127.0.0.1';
    const REDIS_PORT = '6392';
    
    
    private static $redisConnections = [];
  
    
    public static function getConnections()
    {
        if(empty(self::$redisConnections))
        {
            self::$redisConnections['redis_info_master'] = new Redis();
            self::$redisConnections['redis_info_master']->connect(self::REDIS_HOST, self::REDIS_PORT);
        }
        
    
        return self::$redisConnections;
    }
    
    public function hashDir($dir, &$pointer, $relativeDir = '', $inner = false)
    {
        if(!$inner)
        {
            $pointer = [];
            $relativeDir = ''; 
        }
        echo "$relativeDir\r";
        
        if (!is_dir($dir))
        {
            return false;
        }
        
        $filehashs = array();
        $d = dir($dir);
        while (false !== ($entry = $d->read()))
        {                                   
            if ($entry != '.' && $entry != '..' && preg_match('/^[^\'\#\\%\&\{\}\/\<\>\*\?\$!\'\"\`\:\@\+\|\=\[\]\(\)]+$/i', $entry)                                                       )
            {
                if (is_dir($dir.'/'.$entry))
                {
                    $hash = $this->hashDir($dir.'/'.$entry, $pointer, $relativeDir.'/'.$entry, true);
                    $filehashs[] = $hash;
                }
                else
                {
                    if(is_readable($dir.'/'.$entry) && filesize($dir.'/'.$entry)>0)
                    {
                        $hash = hash_file('md4', $dir.'/'.$entry);
                        $pointer[$relativeDir.'/'.$entry] = $hash;
                        $filehashs[] = $hash;
                    }
                }
             }
        }
        $d->close();
        return hash('md4', implode('', $filehashs));
    }
    
 
    
    public function scanMainAdServer($dir, $masterHashKey = 'adServer')
    {
        extract(self::getConnections());
        
        $redis_info_master->del($masterHashKey);
        
        $mainhash = $this->hashDir($dir, $struct);
        
        if(empty($struct) || empty($mainhash))
            throw new Exception ('scanMainAdServer Error!');
        
        $struct['__MAIN_hash'] = $mainhash;
        $jsonnedStruct = json_encode($struct, true);
        $redis_info_master->hMSet($masterHashKey, $struct);
        echo 'Scanning complete :-)'.PHP_EOL;
        echo 'Main hash: '.$mainhash.PHP_EOL;
        echo 'Struct LEN: '.round(strlen($jsonnedStruct)/1024/1024, 2).' Mb'.PHP_EOL;
    }
    
    public function syncWithAdServer($baseUrl = '', $saveDir, $nodeHashKey = '', $masterHashKey = 'adServer')
    {
        if(empty($nodeHashKey))
            throw new Exception ('nodeHashKey is empty');
        if(empty($baseUrl))
            throw new Exception ('baseUrl is empty');
        
        extract(self::getConnections());
        

        
        $nodeStruct = $redis_info_master->hGetAll($nodeHashKey);
        if(empty($nodeStruct))
        {
                echo "Scanning node Dir...\r\n";
                $this->scanMainAdServer($saveDir, $nodeHashKey);
                $nodeStruct = $redis_info_master->hGetAll($nodeHashKey);
        }
        
        $origStruct = $redis_info_master->hGetAll($masterHashKey);
        $origMainhash = $origStruct['__MAIN_hash'];
        
        if(empty($origMainhash) || empty($origStruct))
            throw new Exception ('mainAdServer check error');
        
        if(empty($nodeStruct))
            $nodeStruct = [];
            
        /* Sync with master */
        foreach($origStruct as $origFile => $origFilehash)
        {
            if($origFile=='__MAIN_hash')
                continue;
            
            $syncedHash = isset($nodeStruct[$origFile]) ? $nodeStruct[$origFile] : '';
            //if not exist or is modified
            if(empty($syncedHash) || $syncedHash!=$origFilehash)
            {
                $downloadUrl = $baseUrl.($origFile);
                $savePath = $saveDir.$origFile;
                echo "D: $downloadUrl S: $savePath\r\n";
                exec("curl \"$downloadUrl\" --create-dirs -o \"$savePath\" 2>&1");
                //file_put_contents($savePath, fopen($downloadUrl, 'r'));                                                                          
                if(file_exists($savePath))
                {
                    $syncedHash = hash_file('md4', $savePath);
                    $redis_info_master->hSet($nodeHashKey, $origFile, $syncedHash);
                }
                if($syncedHash==$origFilehash)
                    $redis_info_master->sAdd($nodeHashKey.'_synced', $origFile);
                else
                    $redis_info_master->sRem($nodeHashKey.'_synced', $origFile);
            }
            else
                $redis_info_master->sAdd($nodeHashKey.'_synced', $origFile);
            
            if(isset($nodeStruct[$origFile]))
                unset($nodeStruct[$origFile]);
            
        }
        /* remove expired files */
        foreach($nodeStruct as $nodeFile => $nodeFilehash)
        {
            
            if(!isset($origStruct[$nodeFile]))
            {
                $savePath = $saveDir.$nodeFile;
                if(file_exists($savePath))
                {
                    unlink($savePath);
                    echo "Remove: $savePath\r\n";
                    $redis_info_master->hDel($nodeHashKey, $origFile);
                    $redis_info_master->sRem($nodeHashKey.'_synced', $origFile);
                }
            }
        }
        
        //echo "Scanning node Dir...\r\n";
        //$this->scanMainAdServer($saveDir, $nodeHashKey);
        
    }
    
}

/*
 * $o = new xAdServer();
 * //Master sync
 * $o->scanMainAdServer('/path/to/repository');
 * //Slave sync
 * $o->syncWithAdServer('http://url_to_master', '/path/to/repository', 'adServer_node1');
 */

?>