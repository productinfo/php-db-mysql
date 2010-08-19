<?php
/**
 *
 *
 * @author Yecheng Fu <cofyc.jackson@gmail.com>
 */

class DB {

    /**
     *
     * @var DB
     */
    private static $instance;

    /**
     *
     * @var DB
     */
    private static $instances = array();

    /**
     *
     * @var array
     */
    private static $config;

    /**
     *
     * @var mysqli
     */
    private $link;

    /**
     * Link pool
     *
     * @var array, array of mysqlis
     */
    private static $links;

    /**
     *
     * @var string
     */
    private $dsn;

    /**
     *
     * @var string
     */
    private $host;

    /**
     *
     * @var integer
     */
    private $port;

    /**
     *
     * @var string
     */
    private $username;

    /**
     *
     * @var string
     */
    private $passwd;

    /**
     *
     * @var string
     */
    private $dbname;

    /**
     *
     * @var string
     */
    private $sql;

    /**
     *
     * @var mysqli_result
     */
    private $result;

    /**
     *
     * @var Memcached
     */
    private static $objShardingIndexCacher;

    /**
     *
     * @var mysqli
     */
    private static $objShardingMaster;

    /**
     *
     * @var array
     */
    private static $shard_indices = array();

    /**
     *
     * @var boolean
     */
    private static $debug = false;

    /**
     *
     * @var array
     */
    private static $debug_infos = array();

    /**
     *
     * @param string $dsn
     * @return DB
     */
    private function __construct($dsn) {
        $this->dsn = $dsn;
        return $this;
    }

    /**
     *
     * @param integer $shard_key, optional
     * @throws Exception
     * @return DB
     */
    private static function factory($shard_key = null) {
        if (!isset($shard_key)) {
            return new self(self::getConfig('global.master'));
        }

        // sharding
        try {
            $shard = self::sharding($shard_key);
        } catch (Exception $e) {
            throw new $e();
        }

        return new self($shard['dsn']);
    }

    /**
     *
     * @param integer $shard_id
     * @throws Exception
     * @return DB
     */
    public static function factoryByShardId($shard_id) {
        if (!is_int($shard_id)) {
            throw new Exception();
        }
        $shards = self::getConfig('shards');
        if (!isset($shards[$shard_id])) {
            throw new Exception();
        }
        return new self($shards[$shard_id]['dsn']);
    }

    /**
     *
     * @param integer $shard_key
     * @throws Exception
     * @return array $shard
     */
    private static function sharding($shard_key) {
        if (!is_int($shard_key)) {
            throw new Exception();
        }

        $shards = self::getConfig('shards');
        if (!is_array($shards)) {
            throw new Exception();
        }

        if (!isset(self::$shard_indices[$shard_key])) {
            self::xShardingIndexCacher();

            $shard_id = self::$objShardingIndexCacher->get(self::getIndexCacheKey($shard_key));
            if ($shard_id === false) {
                self::xShardingMaster();

                // try to read from index db
                try {
                    $result = self::$objShardingMaster->query("SELECT shard_id FROM index_user WHERE uid = " . (int)$shard_key);
                    if (!$result) {
                        throw new Exception('failed to get index');
                    }
                    $row = $result->fetch_assoc();
                    if (!$row || !isset($row['shard_id'])) {
                        throw new Exception('failed to get shard id');
                    }
                    $shard_id = (int)$row['shard_id'];
                } catch (Exception $e) {
                    // or random allocates and stores it right now
                    $shard_id = self::random_sharding($shards);
                    $sql = 'INSERT INTO index_user
                    	( `uid`
                    	, `shard_id`
                    	) VALUE
                    	( ' . (int)$shard_key . '
                    	, ' . (int)$shard_id . '
                    	)
    				';
                    if (!self::$objShardingMaster->query($sql)) {
                        throw new Exception('query failed');
                    }
                }

                @self::$objShardingIndexCacher->set(self::getIndexCacheKey($shard_key), $shard_id, 60 * 60 * 24 * 30); // ignore this error
            }

            self::$shard_indices[$shard_key] = $shard_id;
        }

        if (!isset($shards[self::$shard_indices[$shard_key]])) {
            throw new Exception('shard id does not exist');
        }

        return $shards[self::$shard_indices[$shard_key]];
    }

    /**
     *
     * @param array $shards
     * @throws Exception
     * @return integer
     */
    private static function random_sharding($shards) {
        if (!is_array($shards)) {
            throw new Exception();
        }
        $total_weight = 0;
        foreach ($shards as $shard_id => $shard) {
            $total_weight += $shard['weight'];
            $shards[$shard_id]['tmp_weight'] = $total_weight;
        }
        $random_weight = mt_rand(0, $total_weight - 1);
        foreach ($shards as $shard_id => $shard) {
            if ($random_weight < $shard['tmp_weight']) {
                return $shard_id;
            }
        }
        throw new Exception('failed');
    }

    /**
     *
     * @param integer $shard_key, optional
     * @throws Exception
     * @return DB
     */
    public static function getInstance($shard_key = null) {
        if (!isset($shard_key)) {
            if (!isset(self::$instance)) {
                self::$instance = self::factory();
            }
            return self::$instance;
        }

        if (!isset(self::$instances[$shard_key])) {
            self::$instances[$shard_key] = self::factory($shard_key);
        }
        return self::$instances[$shard_key];
    }

    /**
     *
     * @return boolean
     */
    public static function startDebug() {
        self::$debug = true;
        self::$debug_infos = array();
    }

    /**
     *
     * @return array
     */
    public static function endDebug() {
        self::$debug = false;
        return self::$debug_infos;
    }


    /**
     *
     * @param integer $shard_key
     * @throws Exception
     * @return string
     */
    private static function getIndexCacheKey($shard_key) {
        if (!is_int($shard_key)) {
            throw new Exception();
        }
        return sprintf('db/sharding/%d', $shard_key);
    }

    /**
     * Index Cache Warm-Up
     *
     * @throws Exception
     * @return array
     * - total: integer
     * - cached: integer
     * - ignored: integer
     */
    public static function warmUpIndexCache() {
        self::xShardingMaster();
        self::xShardingIndexCacher();

        $result = self::$objShardingMaster->query('SELECT uid, locked, shard_id FROM index_user');
        if (!$result) {
            throw new Exception('faild to read index from db');
        }

        $stats = array(
            'total' => 0,
            'cached' => 0,
            'failed' => 0,
            'ignored' => 0
        );
        while ($row = mysqli_fetch_assoc($result)) {
            $stats['total']++;
            if ($row['locked']) {
                $stats['ignored']++;
                continue;
            }

            if (!self::$objShardingIndexCacher->set(self::getIndexCacheKey((int)$row['uid']), $row['shard_id'], 60 * 60 * 24 * 30)) {
                $stats['failed']++;
            } else {
                $stats['cached']++;
            }
        }

        return $stats;
    }

    /**
     *
     * @throws Exception
     * @return void
     */
    private static function xShardingMaster() {
        $infos = self::parseDSN(self::getConfig('master.dsn'));
        if (!$infos) {
            throw new Exception('master config is wrong');
        }

        if (!isset(self::$objShardingMaster)) {
            self::$objShardingMaster = self::_xconnect($infos['host'], $infos['port'], $infos['username'], $infos['passwd'], $infos['dbname']);
            if (!self::$objShardingMaster) {
                $objShardingMaster = null;
                throw new Exception('cannot connect to master');
            }
        }

        if (!self::$objShardingMaster->select_db($infos['dbname'])) {
            throw new Exception(sprintf('cannot select to db (%s)', $infos['dbname']));
        }
    }

    /**
     *
     * @throws Exception
     * @return void
     */
    private static function xShardingIndexCacher() {
        if (self::$objShardingIndexCacher) {
            return;
        }
        self::$objShardingIndexCacher = new Memcached();
        if (!self::$objShardingIndexCacher->addServer(self::getConfig('master.memcache_host'), self::getConfig('master.memcache_port'))) {
            self::$objShardingIndexCacher = null;
            throw new Exception('failed to add cache server');
        }
    }

    /**
     * Start a sharding transfer transaction.
     * @param integer $shard_key
     * @throws Exception
     */
    public static function startTransfer($shard_key) {
        if (!is_int($shard_key)) {
            throw new Exception();
        }

        self::xShardingIndexCacher();
        self::xShardingMaster();

        if (!self::$objShardingIndexCacher->delete(self::getIndexCacheKey($shard_key))) {
            if (self::$objShardingIndexCacher->getResultCode() !== Memcached::RES_NOTFOUND) {
                throw new Exception();
            }
        }

        $sql = 'UPDATE index_user
        	SET locked = 1
        	WHERE uid = ' . (int)$shard_key . '
        		&& locked = 0
        ';
        if (!self::$objShardingMaster->query($sql)) {
            throw new Exception(sprintf('failed to lock shard_key (%d)', $shard_key));
        }
    }

    /**
     *
     * @param integer $shard_key
     * @throws Exception
     */
    public static function resetTransfer($shard_key) {
        if (!is_int($shard_key)) {
            throw new Exception();
        }

        self::xShardingIndexCacher();
        self::xShardingMaster();

        if (!self::$objShardingIndexCacher->delete(self::getIndexCacheKey($shard_key))) {
            if (self::$objShardingIndexCacher->getResultCode() !== Memcached::RES_NOTFOUND) {
                throw new Exception();
            }
        }

        $sql = 'UPDATE index_user
        	SET locked = 0
        	WHERE uid = ' . (int)$shard_key . '
        		&& locked = 1
		';
        if (!self::$objShardingMaster->query($sql)) {
            throw new Exception(sprintf('failed to unlock shard_key (%d)', $shard_key));
        }
    }

    /**
     *
     * @param integer $shard_key
     * @param integer $shard_id
     * @throws Exception
     */
    public static function endTransfer($shard_key, $shard_id) {
        if (!is_int($shard_key)) {
            throw new Exception();
        }

        if (!is_int($shard_id)) {
            throw new Exception();
        }

        self::xShardingIndexCacher();
        self::xShardingMaster();

        if (!self::$objShardingIndexCacher->delete(self::getIndexCacheKey($shard_key))) {
            if (self::$objShardingIndexCacher->getResultCode() !== Memcached::RES_NOTFOUND) {
                throw new Exception();
            }
        }

        $sql = 'UPDATE index_user
        	SET locked = 0, shard_id = ' . (int)$shard_id . '
        	WHERE uid = ' . (int)$shard_key . '
        		&& locked = 1
		';
        if (!self::$objShardingMaster->query($sql)) {
            throw new Exception(sprintf('failed to unlock shard_key (%d)', $shard_key));
        }
    }

    /**
     *
     * @param array $config
     * @throws Exception
     */
    public static function setConfig($config) {
        if (!is_array($config)) {
            throw new Exception();
        }
        self::$config = $config;
    }

    /**
     *
     * @param string $name
     * @param string | null $default, optional, defaults to null
     */
    private static function getConfig($name, $default = NULL) {
        if (!is_string($name)) {
            throw new Exception();
        }
        $sections = explode('.', $name);
        $config = self::$config;
        while ($section = array_shift($sections)) {
            if (isset($config[$section])) {
                $config = $config[$section];
            } else {
                return $default;
            }
        }
        return $config;
    }

    /**
     *
     * @throws Exception, if we cannot connect to db
     * @return void
     */
    private function xconnect() {
        $infos = self::parseDSN($this->dsn);
        if (!$infos) {
            throw new Exception('dsn format is wrong');
        }

        $this->host = $infos['host'];
        $this->port = $infos['port'];
        $this->username = $infos['username'];
        $this->passwd = $infos['passwd'];
        $this->dbname = $infos['dbname'];

        $link = self::_xconnect($this->host, $this->port, $this->username, $this->passwd, $this->dbname);
        if ($link === false) {
            throw new Exception();
        }

        if (!$link->select_db($this->dbname)) {
            throw new Exception('select db failed');
        }

        $this->link = $link;
    }

    /**
     * DB connector with a connection pool.
     *
     * @param string $host
     * @param integer $port
     * @param string $username
     * @param string $passwd
     * @param string $dbname
     * @return mysqli, false on failure
     */
    private static function _xconnect($host, $port, $username, $passwd, $dbname) {
        $mysql_unique_id = $host . $port;

        if (isset(self::$links[$mysql_unique_id])) {
            return self::$links[$mysql_unique_id];
        }

        $link = new mysqli();
        if (!$link->real_connect($host, $username, $passwd, $dbname, $port, null, MYSQLI_CLIENT_COMPRESS)) {
            return false;
        }

        if (!$link->set_charset(self::getConfig('master.charset', 'utf8'))) { // TODO 错误处理
            return false;
        }

        self::$links[$mysql_unique_id] = $link;

        return $link;
    }

    /**
     *
     * @param string $sql
     * @throws Exception
     * @return DB
     */
    public function query($sql) {
        $this->xconnect();
        $result = $this->link->query($sql);
        if ($result === false) {
            throw new Exception('failed to query on db');
        }
        $this->sql = $sql;
        $this->result = $result;
        if (self::$debug) {
            @self::$debug_infos[] = $sql;
        }
        return $this;
    }

    /**
     *
     * @throws Exception
     */
    public function beginTransaction() {
        $this->xconnect();
        $this->link->autocommit(false);
    }

    /**
     *
     * @throws Exception
     */
    public function commit() {
        $this->xconnect();
        $this->link->autocommit(true);
        if (!$this->link->commit()) {
            throw new Exception('failed to commit');
        }
    }

    /**
     *
     * @throws Exception
     */
    public function rollBack() {
        $this->xconnect();
        $this->link->autocommit(true);
        if (!$this->link->rollback()) {
            throw new Exception('failed to rollback');
        }
    }

    /**
     *
     * @throws Exception
     * @return array, false
     */
    public function fetch() {
        if ($this->result instanceof mysqli_result) {
            $row = $this->result->fetch_assoc();
            $this->result->free();
            $this->result = null;
            return $row;
        } else {
            throw new Exception('result is not a mysqli_result');
        }
    }

    /**
     *
     * @throws Exception
     * @return array
     */
    public function fetchAll() {
        if (!($this->result instanceof mysqli_result)) {
            throw new Exception('result is not a mysqli_result');
        }
        $rows = array();
        while ($row = $this->result->fetch_assoc()) {
            $rows[] = $row;
        }
        $this->result->free();
        $this->result = null;
        return $rows;
    }

    /**
     *
     * @param mixed $value, can be int/float/string
     * @throws Exception
     * @return string
     */
    public function quote($value) {
        if (is_int($value)) {
            return (string)$value;
        } else if (is_float($value)) {
            return sprintf('%F', $value);
        } else if (is_string($value)) {
            return "'" . $this->escape($value) . "'";
        } else {
            throw new Exception();
        }
    }

    /**
     *
     * @param string $value
     * @throws Exception
     * @return string
     */
    private function escape($value) {
        if (!is_string($value)) {
            throw new Exception();
        }

        $this->xconnect();

        return mysqli_real_escape_string($this->link, $value);
    }

    /**
     *
     * @param array $dsn
     * @return array, false on failure
     */
    private static function parseDSN($dsn) {
        $infos = parse_url($dsn);
        if ($infos === false) {
            return false;
        }

        return array(
            'host' => $infos['host'],
            'port' => isset($infos['port']) ? $infos['port'] : 3306,
            'username' => $infos['user'],
            'passwd' => $infos['pass'],
            'dbname' => substr($infos['path'], 1)
        );
    }
}