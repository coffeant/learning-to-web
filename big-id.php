<?php

/**
 * 64位BigID生成解析类,满足以下要求：
 * 一）全局ID概念 全局ID是适用不同项目的统一格式的ID，适用于分布式项目与平台项目，可做到全局的统一管理
 * 二）要求 不能产生冲突，或者产生冲突的可能性非常小，足够小。 生成机制简单快速，否则可能成为系统平台的瓶颈。 对现有项目的数据库自动生成的ID有一定兼容性。 足够多的扩容空间
 * 三）全局ID机制与特征 在框架中，全局ID分为兼容格式ID与新格式两种。 新格式是新项目和迁移到框架的项目必须使用的格式。 兼容格式ID是为项目迁移准备的，会包括原项目的信息
 * 新全局ID格式为64位无符号整数，分为三段：时间戳(毫秒），分库ID，循环自增ID
 * 所占用位数为： (42B microtime*1000) + (12B vsid) + (10B autoinc)
 * 兼容格式全局ID格式为64位无符号整数，分为四段，空标识，原应用数据标识，分库ID，原应用记录ID
 * 所占用位数为：  (4B 0) + (12B flag) + (12B vsid) + (36B old id) 
 * 这种格式的ID便于分布式生成，不要求有唯一的全局ID生成器，在机制上保证了将ID冲突的可能性降到最低
 * 对于新的全局ID，时间字段能标识出这个设计最长可以使用到2038年
 * 12位的分库ID定义了整个集群的最大规模为4096台物理服务器
 * 对于生成全局ID的概率，每个节点为1毫秒1000个，1秒为1000*1000=1000000个不重复ID
 *
 */

class BigID
{
    // 当前使用的虚拟shard编号
    private $_virtShardId = '';

    // 内部使用生成全局序列ID的常量值
    private $_shardAutoSerialKey = '';
    private $_shardAutoSerialIntKey = '';

    public function __construct()
    {
        $this->_shardAutoSerialKey = 'global_serial_generator_seed_'
            . (isset($_SERVER['SERVER_ADDR']) ? $_SERVER['SERVER_ADDR'] : '127.0.0.1');
        $this->_shardAutoSerialIntKey = crc32($this->_shardAutoSerialKey);

        $this->_virtShardId = 0;

        if (!function_exists('shm_attach')) {
        }
    }

    /**
     * 生成一个新格式全局序列ID
     *
     * 序列类似MySQL的auto_increment。
     * @return 64-integer $nextval | false
     */
    /**
     * 格式：
     * (38B microtime) + (8B flag) + (8B vsid) + (10B autoinc)
     */
    public function makeSerialId($vsid, $flag)
    {
        if (empty($vsid) || !is_numeric($vsid)) {
            return false;
        }

        if (empty($flag) || !is_numeric($flag)) {
            return false;
        }

        if (!($vsid > 0 && $vsid <= 256)) {
            return false;
        }

        if (!($flag > 0 && $flag <= 256)) {
            return false;
        }

        $serial_key = $this->_shardAutoSerialKey;
        $auto_inc_sig = false;
        if (1) {
            $auto_inc_sig = $this->_getNextValueByLocalFile();
        } else {
            $auto_inc_sig = $this->_getNextValueByShareMemory();
        }

        if (empty($auto_inc_sig)) {
            return false;
        }

        $ntime = microtime(true) - mktime(0, 0, 0, 1, 1, 2013);
        $time_sig = intval($ntime * 1000);

        $serial_id = $time_sig << 8 | $flag;
        $serial_id = $serial_id << 8 | $vsid;
        $serial_id = $serial_id << 10 | ($auto_inc_sig % 1024);

        return $serial_id;
    }

    /**
     * 从新格式全局序列ID反解析出虚拟shard编号
     *
     * @param 64-integer $serialId 新格式全局序列ID
     * @return integer $vsid 虚拟shard编号，或者false
     */
    public function extractVirtShardId($serialId)
    {
        if (empty($serialId) || !is_numeric($serialId)) {
            return false;
        }

        if ($this->isCompatSerialId($serialId)) {
            $oldId = 0;
            $flag = 0;
            $vsid = 0;

            if (!$this->extractCompatSerialInfo($serialId, $oldId, $flag, $vsid)) {
                return false;
            } else {
                return $vsid;
            }
        } else if ($this->isGlobalSerialId($serialId)) {
            $vsid = $serialId >> 10 & (0xFF);
        } else {
            return false;
        }

        return $vsid;
    }

    /**
     * 判断是否是新格式的新格式的全局序列id
     */
    public function isGlobalSerialId($serialId)
    {
        if (empty($serialId) || !is_numeric($serialId)) {
            return false;
        }

        $high28b = $serialId >> 44;
        if ($high28b == 0) {
            return false;
        }
        $high4b = $serialId >> 60 & 0xF; // 最高2位的值
        return $high4b != 0;
    }

    /**
     * 生成一个兼容老序列的新格式全局序列ID
     *
     * 序列类似MySQL的auto_increment。
     * @param integer $flag 原ID所属表编号，防止新兼容ID冲突
     * @return 64-integer $nextval | false
     */
    /**
     * 格式：
     * (4B 0) + (8B flag) + (8B vsid) + (44B old id)
     */
    static public function makeCompatSerialId($oldId, $flag, $vsid)
    {
        if (empty($vsid) || !is_numeric($vsid)) {
            return false;
        }

        if (empty($flag) || !is_numeric($flag)) {
            return false;
        }

        if (!($vsid > 0 && $vsid <= 256)) {
            return false;
        }

        if (!($flag > 0 && $flag <= 256)) {
            return false;
        }

        $serial_id = $flag << 8 | $vsid;
        $serial_id = $serial_id << 44 | $oldId;

        return $serial_id;
    }

    /**
     * 是否是兼容格式全局序列ID
     *
     * @param integer $serialId
     * @return true | false
     */
    public function isCompatSerialId($serialId)
    {
        $high28b = $serialId >> 36;
        if ($high28b == 0) {
            return false;
        }
        $high4b = $serialId >> 60 & 0xF; // 最高4位的值
        return $high4b == 0;
    }

    /**
     * 解析是兼容格式全局序列ID获取对应的信息
     *
     * @param integer $serialId
     * @param integer $oldId  老式44-integer
     * @param integer $flag   老式8-integer ID的类型标识
     * @param integer @vsid   该ID记录的虚拟shard编号,8-integer
     * (4B 0) + (8B flag) + (8B vsid) + (44B old id)
     * @return true | false
     */
    public function extractCompatSerialInfo($serialId, &$oldId, &$flag, &$vsid)
    {
        if (!$this->isCompatSerialId($serialId)) {
            return false;
        }

        $oldId = $serialId & 0x8FFFFFFFF;
        $vsid = $serialId >> 44 & 0xFF;
        $flag = $serialId >> 52 & 0xFF;

        return true;
    }


    /**
     * 通过本地文件来生成一个auto_increment序列，
     *
     * 序列类似MySQL的auto_increment。
     * @return integer $nextval | false
     */
    private function _getNextValueByLocalFile()
    {
        $serial_key = $this->_shardAutoSerialKey;
        $autoinc_state_file = '';
        if (defined('_CACHE_DIR_')) {
            $autoinc_state_file = _CACHE_DIR_ . DIRECTORY_SEPARATOR . $serial_key;
        } else {
            $autoinc_state_file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $serial_key;
        }

        $next_value = 0;
        $fp = fopen($autoinc_state_file, "c+");
        if (!$fp) {
            $this->_error = 'Can not create counter file.';
            return false;
        }

        fseek($fp, 0, SEEK_SET);
        if (flock($fp, LOCK_EX)) {
            $next_value = fread($fp, 32);
            fseek($fp, 0, SEEK_SET);
            if (empty($next_value)) {
                $next_value = 1;
                fwrite($fp, $next_value);
            } else {
                $next_value = intval($next_value) + 1;
                $nvl = strlen($next_value);
                $bret = fwrite($fp, "{$next_value}", $nvl);
                ftruncate($fp, $nvl);
            }
        } else {
            fclose($fp);
            return false;
        }

        fclose($fp);

        return $next_value;
    }

    /**
     * 通过本机共享内存件来生成一个auto_increment序列，
     *
     * 序列类似MySQL的auto_increment。
     * @return integer $nextval | false
     */
    private function _getNextValueByShareMemory()
    {
        $serial_key = $this->_shardAutoSerialIntKey;

        if (empty($serial_key)) {
            $this->_error = 'Invalid serial key' . $this->_shardAutoSerialKey . 'abc';
            return false;
        }

        $sem = $shm = null;
        $retry_times = 1;
        do {
            $sem = sem_get($serial_key, 1, 0777);
            $shm = shm_attach($serial_key, 128, 0777);

            if (is_resource($sem) && is_resource($shm)) {
                break;
            }

            $cmd = "ipcrm -M 0x00000000; ipcrm -S 0x00000000; ipcrm -M {$serial_key} ; ipcrm -S {$serial_key}";
            $last_line = exec($cmd, $output, $retval);

            // var_dump($last_line, $cmd, $output, $retval);

            if ($retval !== 0) {
                $this->_error = 'Can not create sem/shm resource.';
            }
        } while ($retry_times-- > 0);

        if (!sem_acquire($sem)) {
            $this->_error = 'System sem error.';
            return false;
        }

        $next_value = false;
        if (shm_has_var($shm, $serial_key)) {
            $next_value = shm_get_var($shm, $serial_key) + 1;
            shm_put_var($shm, $serial_key, $next_value);
        } else {
            $next_value = 1;
            shm_put_var($shm, $serial_key, $next_value);
        }

        shm_detach($shm);
        sem_release($sem);

        return $next_value;
    }

};
