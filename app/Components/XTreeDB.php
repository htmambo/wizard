<?php
namespace App\Components;

/* ----------------------------------------------------------------------- *\
   PHP4代码版XDB - (XTreeDB.class.php)
   -----------------------------------------------------------------------
   作者: 马明练(hightman) (MSN: MingL_Mar@msn.com) (php-QQ群: 17708754)
   网站: http://www.hi-php.com
   时间: 2007/05/01 (update: 2007/05/29)
   版本: 0.1
   目的: 取代 cdb/gdbm 快速存取分词词典, 因大部分用户缺少这些基础配件和知识
		 xdb 改自前身 hdb 为了更好的和C版兼容, 故修改. 目前此版产生了机器字
		 节序依赖, 故打开词典时会自做检查
   功能:		
         这是一个类似于 cdb/gdbm 的 PHP 代码级数据类库, 通过 key, value 的方
		 式存取数据, 使用非常简单.

		 适用于快速根据唯一主键查找数据

   效能:
         1. 效率高(20万记录以上比php内建的cdb还要快), 经过优化后 35万记录时
		    树的最大深度为5, 查找效率高,单个文件
		 2. 文件小(缺省设置下, 基础数据约 100KB, 之后每条记录为 key, value的
		    总长度+13bytes
		 4. PHP 代码级, 修改维护方便
		 5. 提供内建二叉树优化函数, 提供存取结构图绘制接口, 提供遍历接口
		 6. 数据可快速更新, 而 cdb 是只读的或只写的

   缺点:
         1. 对于unique key来说, 一经增加不可清除 (可以将value设为空值)
		 2. 当更新 value 时, 如果新 value 较长则旧的记录直接作废, 长期修改
		    可能会导致文件有一些无用的膨胀, 这类情况可以调用遍历接口完全重
			建整个数据库
		 3. 由于是 php 代码级的引擎, 性能上比 gdbm 没有什么优势
		 4. IO操作, 可以考虑将数据文件放到 memfs 上 (linux/bsd)
		 5. key 最大长度为 240bytes, value 最大长度为 65279 bytes, 整个文件最大为 4G
		 6. 不可排序和随机分页读取

   用法: (主要的方法)

   1. 建立类操作句柄, 构造函数: XTreeDB([int mask [, int base ]])
	  可选参数(仅针对新建数据有效): mask, base 均为整型数, 其中
	    mask 是 hash 求模的基数, 建议选一个质数, 大约为总记录数的 1/10 即可.
		base 是 hash 数据计算的基数, 建议使用默认值. ``h = ((h << 5) + h) ^ c''

      $XDB = new XTreeDB;

   2. 打开数据文件, Bool Open(string fpath [, string mode])
      必要参数 fpath 为数据文件的路径, 可选参数 mode 的值为 r 或 w, 分别表示只
	  读或读写方式打开数据库. 成功返回 true, 失败返回 false.

      缺省情况下是以只读方式打开, 即 mode 的缺省值为 'r'
      $XDB->Open('/path/to/dict.xdb');

	  或以读写方式打开(新建数据时必须), mode 值为 'w', 此时数据库可读可写, 并锁定写
	  $XDB->Open('/path/to/dict.xdb', 'w');

   3. 根据 key 读取数据 mixed Get(string key [, bool verbose])
      成功查找到 key 所对应的数据时返回数据内容, 类型为 string
	  当 key 不存在于数据库中时或产生错误直接返回 false
	  (*注* 当 verbose 被设为 true 时, 则返回一个完整的记录数组, 含 key&value, 仅用于调试目的)

      $value = $XDB->Get($key);
	  或
	  $debug = $XDB->Get($key, true); print_r($debug);

   4. 存入数据 bool Put(string key [, string value])
      成功返回 true, 失败或出错返回 false , 必须以读写方式打开才可调用
	  注意存入的数据目前只支持 string 类型, 有特殊需要可以使用 php 内建的 serialize 将 array 转换
	  成 string 取出时再用 unserialize() 还原

	  $result = $XDB->Put($key, $value);

   5. 关闭数据库, void Close()
      $XDB->Close();

   6. 查询文件版本号, string Version()
      返回类似 XDB/0.1 之类的格式, 是当前文件的版本号

   7. 记录遍历, mixed Next()
      返回一条记录key, value 组成的数组, 并将内部指针往后移一位, 可调用 Reset() 重置指针
	  当没有记录时会返回 false, 典型应用如下

	  $XDB->Reset();
	  while ($tmp = $XDB->Next())
	  {
		  echo "$tmp[key] => $tmp[value]\n";
	  }
	  也可用于导出数据库重建新的数据库, 以清理过多的重写导致的文件空档.

   8. 遍历指针复位, void Reset()
      此函数仅为搭配 Next() 使用
	  $XDB->Reset();

   9. 优化数据库, 将数据库中的 btree 转换成完全二叉树. void Optimize([int index])
      由于数据库针对 key 进行 hash 分散到 mask 颗二叉树中, 故这里的 index 为 0~[mask-1]
	  缺省情况下 index 值为 -1 会优化整个数据库, 必须以读写方式打开的数据库才能用该方法

	  $XDB->Optimize();

  10. 打印分析树, 绘出存贮结构的树状图, void Draw([int index])
      参数 index 同 Optimize() 的参数, 本函数无返回值, 直接将结果 echo 出来, 仅用于调试和观看
	  分析

	  $XDB->Draw(0);
	  $XDB->Draw(1);
	  ...

　 　在这里要解释下TF和IDF的意思，它们合起来称作TF-IDF（term frequency–inverse document frequency），
    是一种用于资讯检索与资讯探勘的常用加权技术，用以评估一字词对于一个文件集或一个语料库中的其中一份文件
    的重要程度。TFIDF 的主要思想是：如果某个词或短语在一篇文章中出现的频率TF高，并且在其他文章中很少出
    现，则认为此词或者短语具有很好的类别区分能力，适合用分类。说起 来很不好理解，其实也不需要理解，SCWS
    也提供了新词生词的TF/IDF计算器，可以自动获得词语的权重值。
　　 ATTR是词性，也就是标示词语是名字、动词、形容词等等词性的。
    详细的词性标示方法请看SCWS的说明：词典词性标注详解

\* ----------------------------------------------------------------------- */

/**
 * 常量定义
 */
// 用于文件格式检查的浮点数标识
defined('XDB_FLOAT_CHECK') || define('XDB_FLOAT_CHECK', 3.14);

// 哈希基数，用于计算键的哈希值
defined('XDB_HASH_BASE') || define('XDB_HASH_BASE', 0xf422f);

// 哈希表大小，应为质数，用于控制哈希表的大小
defined('XDB_HASH_PRIME') || define('XDB_HASH_PRIME', 2047);

// 数据库文件版本号
defined('XDB_VERSION') || define('XDB_VERSION', 34);

// 数据库文件标识
defined('XDB_TAGNAME') || define('XDB_TAGNAME', 'XDB');

// 键的最大长度(240字节)
defined('XDB_MAXKLEN') || define('XDB_MAXKLEN', 0xf0);

// Class object Declare
class XTreeDB
{
    // 文件句柄
    var $fileHandle = false;

    // 打开模式：r-只读，w-读写
    var $mode = 'r';

    // 哈希计算基数
    var $hashBase = XDB_HASH_BASE;

    // 哈希表大小
    var $hashPrime = XDB_HASH_PRIME;

    // 数据库版本号
    var $version = XDB_VERSION;

    // 文件大小
    var $fileSize = 0;

    var $headerInfo = [];

    // 遍历堆栈
    private $traverseStack = [];
    // 遍历索引
    private $traverseIndex = -1;
    private $_sync_nodes = [];

    // 调试用：IO操作次数统计
    private $_io_times = 0;
    private $headerUnPack = 'a3tag/Cver/Ibase/Iprime/IfileSize/a16reversed';
    private $headerPack = 'a3CiiIa16';
    private $reversedPack = '';
    private $reversedUnPack = '';

    /**
     * 构造函数
     *
     * @param int $hashBase  哈希基数
     * @param int $hashPrime 哈希表大小(质数)
     *
     * @return void
     */
    public function __construct($hashBase = 0, $hashPrime = 0)
    {
        //'a3tag/Cver/Ibase/Iprime/IfileSize/a16reversed'
        $this->headerInfo = [
            'tag' => XDB_TAGNAME,
            'ver' => XDB_VERSION,
            'base' => XDB_HASH_BASE,
            'prime' => XDB_HASH_PRIME,
            'fileSize' => 0,
            'reversed' => ''
        ];
        if (0 != $hashBase) {
            $this->headerInfo['base'] = $hashBase;
        };
        if (0 != $hashPrime) {
            $this->headerInfo['prime'] = $hashPrime;
        }
    }

    /**
     * 设置保留信息的打包和解包格式
     *
     * @param string $pack   保留信息的打包格式
     * @param string $unpack 保留信息的解包格式
     * @return bool 设置成功返回true，失败返回false
     * @throws Exception 当格式无效时抛出异常
     */
    public function setReversedCfg($pack, $unpack)
    {
        // 检查参数是否为空
        if (empty($pack) || empty($unpack)) {
            throw new Exception('Pack and unpack formats cannot be empty');
        }
        $fields         = explode('/', $unpack);
        $requiredFields = count($fields);
        $reserved = [];
        // 如果$reserved数组项数不足,则用空值补充
        while (count($reserved) < $requiredFields) {
            $reserved[] = '';
        }

        try {
            // 1. 验证打包格式长度
            $packedStr = pack($pack, ...array_values($reserved));
            if (strlen($packedStr) > 16) {
                throw new Exception('Packed data exceeds 16 bytes limit');
            }

            // 2. 验证解包格式
            $unpackedData = unpack($unpack, $packedStr);
            if ($unpackedData === false) {
                throw new Exception('Invalid unpack format');
            }

            // 3. 验证pack和unpack是否匹配
            $repackedStr = pack($pack, ...array_values($unpackedData));
            if ($packedStr !== $repackedStr) {
                throw new Exception('Pack and unpack formats do not match');
            }

            // 4. 更新类的打包和解包格式
            // $this->headerPack = substr($this->headerPack, 0, -3) . $pack;
            $this->headerUnPack = substr($this->headerUnPack, 0, strrpos($this->headerUnPack, '/')) . '/' . $unpack;
            $this->reversedPack = $pack;
            $this->reversedUnPack = $unpack;

            return true;

        } catch (\Exception $e) {
            throw new \Exception('Invalid format configuration: ' . $e->getMessage());
        }
    }

    /**
     * 析构函数，关闭文件句柄
     *
     * @return void
     */
    public function __destruct()
    {
        $this->Close();
    }

    /**
     * 打开数据库文件
     *
     * @param string $filePath 数据库文件路径
     * @param string $mode     打开模式 'r'-只读 'w'-读写
     *
     * @return bool 成功返回true，失败返回false
     */
    public function Open($filePath, $mode = 'r')
    {
        // open the file
        $this->Close();

        $newDatabase = false;
        if ($mode == 'w') {
            // write and read-only
            if (!($fileHandle = @fopen($filePath, 'rb+'))) {
                if (!($fileHandle = @fopen($filePath, 'wb+'))) {
                    trigger_error("XDB::Open(" . basename($filePath) . ",w) failed.", E_USER_WARNING);
                    return false;
                }
                // create the header
                $this->_write_header($fileHandle);

                // 32 = header, 8 = Pointer
                $this->fileSize = 32 + 8 * $this->headerInfo['prime'];
                $newDatabase = true;
            }
        } else {
            // read-only
            if (!($fileHandle = @fopen($filePath, 'rb'))) {
                trigger_error("XDB::Open(" . ($filePath) . ",r) failed.", E_USER_WARNING);
                return false;
            }
        }

        // check the header
        if (!$newDatabase && !$this->_check_header($fileHandle)) {
            trigger_error("XDB::Open(" . basename($filePath) . "), invalid xdb format.", E_USER_WARNING);
            fclose($fileHandle);
            return false;
        }

        // set the variable
        $this->fileHandle = $fileHandle;
        $this->mode       = $mode;
        $this->Reset();

        // lock the file description until close
        if ($mode == 'w')
            flock($this->fileHandle, LOCK_EX);

        return true;
    }

    /**
     * 将键值对写入数据库
     *
     * 该方法用于向数据库写入或更新键值对数据。如果键已存在且新值长度小于等于原值长度，
     * 则直接更新原位置；否则在文件末尾追加新记录。
     *
     * @param string $key 要写入的键
     * @param string $value 要写入的值
     * @return bool 写入成功返回true，失败返回false
     */
    public function Put($key, $value)
    {
        // 检查文件句柄和写入权限
        if (!$this->fileHandle || $this->mode != 'w') {
            trigger_error("XDB::Put(), null db handler or readonly.", E_USER_WARNING);
            return false;
        }

        // 检查键长度
        $keyLength = strlen($key);
        $valueLength = strlen($value);
        if (!$keyLength || $keyLength > XDB_MAXKLEN) {
            return false;
        }

        // 尝试查找已存在的记录
        $record = $this->_get_record($key);
        if (isset($record['valueLength']) && ($valueLength <= $record['valueLength'])) {
            // 更新已存在记录的值
            if ($valueLength > 0) {
                fseek($this->fileHandle, $record['valueOffset'], SEEK_SET);
                fwrite($this->fileHandle, $value, $valueLength);
            }

            // 如果新值比原值短，更新记录长度
            if ($valueLength < $record['valueLength']) {
                $newLength = $record['length'] + $valueLength - $record['valueLength'];
                $newBuffer = pack('I', $newLength);
                fseek($this->fileHandle, $record['parentOffset'] + 4, SEEK_SET);
                fwrite($this->fileHandle, $newBuffer, 4);
            }
            return true;
        }

        // 构造新记录数据
        $newRecord = [
            'leftOffset' => 0,
            'leftLength' => 0,
            'rightOffset' => 0,
            'rightLength' => 0
        ];

        if (isset($record['valueLength'])) {
            $newRecord['leftOffset'] = $record['leftOffset'];
            $newRecord['leftLength'] = $record['leftLength'];
            $newRecord['rightOffset'] = $record['rightOffset'];
            $newRecord['rightLength'] = $record['rightLength'];
        }

        // 打包记录数据
        $buffer = pack('IIIIC',
            $newRecord['leftOffset'],
            $newRecord['leftLength'],
            $newRecord['rightOffset'],
            $newRecord['rightLength'],
            $keyLength
        );
        $buffer .= $key . $value;
        $length = $keyLength + $valueLength + 17;

        // 写入新记录
        $offset = $this->fileSize;
        fseek($this->fileHandle, $offset, SEEK_SET);
        fwrite($this->fileHandle, $buffer, $length);
        $this->fileSize += $length;

        // 更新父记录指针
        $parentBuffer = pack('II', $offset, $length);
        fseek($this->fileHandle, $record['parentOffset'], SEEK_SET);
        fwrite($this->fileHandle, $parentBuffer, 8);

        return true;
    }

    /**
     * 根据键名获取数据库中的值
     *
     * 此方法用于从数据库中检索指定键的值。可以选择返回完整记录或仅返回值。
     *
     * @param string $key 要查找的键名
     * @param bool $returnBulkInfo 是否返回完整记录信息，默认为false仅返回值
     * @return mixed 如果找到则返回值或记录信息，否则返回false
     * @throws E_USER_WARNING 当数据库句柄为空时触发警告
     */
    public function Get($key, $returnBulkInfo = false)
    {
        // 检查数据库句柄是否可用
        if (!$this->fileHandle) {
            trigger_error("XDB::Get(), null db handler.", E_USER_WARNING);
            return false;
        }

        // 验证键的长度是否在有效范围内
        $keyLength = strlen($key);
        if ($keyLength == 0 || $keyLength > XDB_MAXKLEN) {
            return false;
        }

        // 获取记录信息
        $record = $this->_get_record($key, $returnBulkInfo);

        // 如果请求完整记录信息，直接返回
        if ($returnBulkInfo) {
            return $record;
        }

        // 检查记录是否存在且有值
        if (!isset($record['valueLength']) || $record['valueLength'] == 0) {
            return false;
        }

        return $record['value'];
    }

    /**
     * 遍历获取数据库中的下一条记录
     *
     * 该方法实现了树形结构的深度优先遍历，通过遍历栈来管理节点的访问顺序。
     * 遍历顺序：当前节点 -> 左子树 -> 右子树
     *
     * @return array|false 成功返回记录数组，失败或遍历结束返回false
     * @throws Exception 当数据库句柄为空时触发警告
     */
    public function Next()
    {
        // 检查数据库句柄是否可用
        if (!$this->fileHandle) {
            throw new Exception("XDB::Next(), null db handler.", E_USER_WARNING);
        }

        // 从遍历栈中获取下一个节点
        $currentNode = array_pop($this->traverseStack);
        if (!$currentNode) {
            // 如果栈为空，则从哈希表中查找下一个有效节点
            do {
                $this->traverseIndex++;
                if ($this->traverseIndex >= $this->headerInfo['prime']) {
                    break;
                }

                // 计算节点在文件中的偏移位置
                $positionOffset = $this->traverseIndex * 8 + 32;
                fseek($this->fileHandle, $positionOffset, SEEK_SET);
                $buffer = fread($this->fileHandle, 8);

                if (strlen($buffer) != 8) {
                    $currentNode = false;
                    break;
                }

                $currentNode = unpack('Ioffset/Ilength', $buffer);
            } while ($currentNode['length'] == 0);
        }

        // 检查是否遍历结束
        if (!$currentNode || $currentNode['length'] == 0) {
            return false;
        }

        // 读取当前节点的记录
        $record = $this->_tree_get_record($currentNode['offset'], $currentNode['length']);

        // 将左右子节点压入遍历栈
        if ($record['leftLength'] != 0) {
            $leftNode = [
                'offset' => $record['leftOffset'],
                'length' => $record['leftLength']
            ];
            $this->traverseStack[] = $leftNode;
        }

        if ($record['rightLength'] != 0) {
            $rightNode = [
                'offset' => $record['rightOffset'],
                'length' => $record['rightLength']
            ];
            $this->traverseStack[] = $rightNode;
        }

        return $record;
    }

    /**
     * 重置遍历指针
     *
     * @return void
     */
    public function Reset()
    {
        $this->traverseStack = [];
        $this->traverseIndex = -1;
    }

    /**
     * 输出数据库版本信息
     *
     * @return string 版本信息字符串
     */
    public function Version()
    {
        $version = (is_null($this) ? XDB_VERSION : $this->headerInfo['ver']);
        $str     = sprintf("%s/%d.%d", XDB_TAGNAME, ($version >> 5), ($version & 0x1f));
        if (!is_null($this)) $str .= " <base={$this->headerInfo['base']}, prime={$this->headerInfo['prime']}>";
        return $str;
    }

    /**
     * 关闭数据库
     *
     * @return void
     */
    public function Close($reserved = [])
    {
        if (!$this->fileHandle)
            return;

        if ($this->mode == 'w') {
            $buf = pack('I', $this->fileSize);
            fseek($this->fileHandle, 12, SEEK_SET);
            fwrite($this->fileHandle, $buf, 4);
            if($reserved && $this->reversedPack){
                echo 'set reversed info' . PHP_EOL;
                // 分析一下 reversedUnPack中共有多少项，如果$reserved个数不足则补充上空值
                $fields         = explode('/', $this->reversedUnPack);
                $requiredFields = count($fields);

                // 如果$reserved数组项数不足,则用空值补充
                while (count($reserved) < $requiredFields) {
                    $reserved[] = '';
                }
                print_r($reserved);
                $buf = pack($this->reversedPack, ...array_values($reserved));
                print_r(unpack($this->reversedUnPack, $buf));
                fseek($this->fileHandle, 16, SEEK_SET);
                fwrite($this->fileHandle, $buf, strlen($buf));
            }
            flock($this->fileHandle, LOCK_UN);
        }
        fclose($this->fileHandle);
        $this->fileHandle = false;
    }

    /**
     * 优化数据库索引
     *
     * 对指定范围内的索引进行优化处理。如果未指定索引，则优化所有索引。
     * 优化过程会清理和重组索引结构，提高数据库性能。
     *
     * @param int $indexNumber 要优化的索引编号，默认为-1表示优化所有索引
     * @return void
     * @throws E_USER_WARNING 当数据库句柄为空或处于只读模式时触发警告
     */
    public function Optimize($indexNumber = -1)
    {
        // 检查数据库句柄和写入权限
        if (!$this->fileHandle || $this->mode != 'w') {
            trigger_error("XDB::Optimize(), null db handler or readonly.", E_USER_WARNING);
            return;
        }

        // 确定优化范围
        if ($indexNumber < 0 || $indexNumber >= $this->headerInfo['prime']) {
            $startIndex = 0;
            $endIndex = $this->headerInfo['prime'];
        } else {
            $startIndex = $indexNumber;
            $endIndex = $indexNumber + 1;
        }

        // 依次优化指定范围内的每个索引
        while ($startIndex < $endIndex) {
            $this->_optimize_index($startIndex);
            $startIndex++;
        }
    }

    /**
     * 优化指定索引的节点结构
     *
     * 该私有方法通过以下步骤优化索引节点：
     * 1. 将所有节点加载到内存中
     * 2. 按键值对节点进行排序
     * 3. 重新构建树形结构
     *
     * @param int $indexNumber 要优化的索引编号
     * @return void 节点数量少于3个时直接返回
     */
    private function _optimize_index($indexNumber)
    {
        // 静态比较函数，仅初始化一次
        static $comparator = false;

        // 计算索引在文件中的偏移位置
        $positionOffset = $indexNumber * 8 + 32;

        // 将所有节点加载到临时数组中
        $this->_sync_nodes = [];
        $this->_load_tree_nodes($positionOffset);

        // 如果节点数量太少，无需优化
        $nodeCount = count($this->_sync_nodes);
        if ($nodeCount < 3) {
            return;
        }

        // 初始化比较函数并对节点按键值排序
        if (!$comparator) {
            $comparator = create_function('$a,$b', 'return strcmp($a[key],$b[key]);');
        }
        usort($this->_sync_nodes, $comparator);

        // 重新构建树结构
        $this->_reset_tree_nodes($positionOffset, 0, $nodeCount - 1);

        // 清理临时数组
        unset($this->_sync_nodes);
    }

    /**
     * 递归加载树节点
     *
     * 从指定的文件位置读取节点数据并构建树形结构。该方法会递归处理左右子节点。
     *
     * @param int $positionOffset 文件中的位置偏移量
     * @return void
     */
    private function _load_tree_nodes($positionOffset)
    {
        // 定位到指定位置并读取节点数据头
        fseek($this->fileHandle, $positionOffset, SEEK_SET);
        $buffer = fread($this->fileHandle, 8);
        if (strlen($buffer) != 8) {
            return;
        }

        // 解析节点数据头
        $nodeHeader = unpack('Ioffset/Ilength', $buffer);
        if ($nodeHeader['length'] == 0) {
            return;
        }

        // 读取节点数据
        fseek($this->fileHandle, $nodeHeader['offset'], SEEK_SET);
        $readLength = min(XDB_MAXKLEN + 17, $nodeHeader['length']);
        $buffer = fread($this->fileHandle, $readLength);

        // 解析节点记录
        $nodeRecord = unpack('IleftOffset/IleftLength/IrightOffset/IrightLength/CkeyLength',
                             substr($buffer, 0, 17));
        $nodeRecord['offset'] = $nodeHeader['offset'];
        $nodeRecord['length'] = $nodeHeader['length'];
        $nodeRecord['key'] = substr($buffer, 17, $nodeRecord['keyLength']);

        // 保存节点数据
        $this->_sync_nodes[] = $nodeRecord;
        unset($buffer);

        // 递归处理左子节点
        if ($nodeRecord['leftLength'] != 0) {
            $this->_load_tree_nodes($nodeHeader['offset']);
        }

        // 递归处理右子节点
        if ($nodeRecord['rightLength'] != 0) {
            $this->_load_tree_nodes($nodeHeader['offset'] + 8);
        }
    }

    /**
     * 重置树节点结构
     *
     * 使用二分查找法重新构建平衡的树结构。
     * 该方法采用递归方式处理左右子树，确保树的平衡性。
     *
     * @param int $positionOffset 文件中的位置偏移量
     * @param int $startIndex 当前处理的节点起始索引
     * @param int $endIndex 当前处理的节点结束索引
     * @return void
     */
    private function _reset_tree_nodes($positionOffset, $startIndex, $endIndex)
    {
        if ($startIndex <= $endIndex) {
            // 计算中间节点位置
            $middleIndex = ($startIndex + $endIndex) >> 1;
            $currentNode = $this->_sync_nodes[$middleIndex];

            // 打包当前节点的偏移量和长度信息
            $buffer = pack('II', $currentNode['offset'], $currentNode['length']);

            // 递归处理左子树
            $this->_reset_tree_nodes($currentNode['offset'], $startIndex, $middleIndex - 1);

            // 递归处理右子树
            $this->_reset_tree_nodes($currentNode['offset'] + 8, $middleIndex + 1, $endIndex);
        } else {
            // 处理空节点情况
            $buffer = pack('II', 0, 0);
        }

        // 写入节点数据到文件
        fseek($this->fileHandle, $positionOffset, SEEK_SET);
        fwrite($this->fileHandle, $buffer, 8);
    }

    private function _get_index($key)
    {
        $l = strlen($key);
        $h = $this->headerInfo['base'];
        while ($l--) {
            $h += ($h << 5);
            $h ^= ord($key[$l]);
            $h &= 0x7fffffff;
        }
        return ($h % $this->headerInfo['prime']);
    }

    /**
     * 检查数据库文件头信息
     *
     * 此方法用于验证数据库文件的有效性和基本信息，包括:
     * - 验证文件标识符(tag)
     * - 检查文件大小
     * - 读取哈希算法参数
     * - 读取版本信息
     *
     * @param resource $fileHandle 数据库文件句柄
     * @return bool 文件头信息验证成功返回true，失败返回false
     */
    private function _check_header($fileHandle)
    {
        // 定位到文件开始位置
        fseek($fileHandle, 0, SEEK_SET);

        // 读取32个字节的头信息
        $headerBuffer = fread($fileHandle, 32);
        if (strlen($headerBuffer) !== 32) {
            return false;
        }

        // 解析文件头信息
        // 格式: tag(3字节), 版本(1字节), hash_base(4字节), hash_prime(4字节),
        //       文件大小(4字节), 校验值(4个字节), 保留字段(12个字节)
        $headerInfo = unpack($this->headerUnPack, $headerBuffer);

        // 验证文件标识
        if ($headerInfo['tag'] != XDB_TAGNAME) {
            return false;
        }

        // 验证文件大小
        $fileStat = fstat($fileHandle);
        if ($headerInfo['fileSize'] && $fileStat['size'] != $headerInfo['fileSize']) {
            return false;
        }

        // 更新对象属性
        $this->headerInfo = $headerInfo;
        $this->fileSize = $headerInfo['fileSize'];

        return true;
    }

    private function _write_header($fileHandle)
    {
        $buf = pack($this->headerPack, XDB_TAGNAME, $this->headerInfo['ver'],
                    $this->hashBase, $this->hashPrime, 0, '');

        fseek($fileHandle, 0, SEEK_SET);
        fwrite($fileHandle, $buf, 32);
    }

    /**
     * 根据键值获取记录
     *
     * 从文件中读取指定键值对应的记录数据。支持两种模式：
     * 1. 二进制列表模式(BLT)：直接读取序列化数据
     * 2. 树形结构模式：递归查找记录
     *
     * @param string $key 要查找的键值
     * @param bool $isBinaryListMode 是否为二进制列表模式
     * @return mixed 查找到的记录数据，未找到则返回false
     */
    private function _get_record($key, $isBinaryListMode = false)
    {
        // 重置IO计数器
        $this->_io_times = 1;

        // 计算索引位置
        $indexPosition = ($this->headerInfo['prime'] > 1 ? $this->_get_index($key) : 0);
        $filePosition = $indexPosition * 8 + 32;

        // 读取数据头
        fseek($this->fileHandle, $filePosition, SEEK_SET);
        $buffer = fread($this->fileHandle, 8);

        if ($isBinaryListMode) {
            // 二进制列表模式处理
            $recordHeader = unpack('I1start/n1length/n1checksum', $buffer);
            if (!isset($recordHeader['length']) || $recordHeader['length'] == 0) {
                return false;
            }

            // 读取实际数据
            fseek($this->fileHandle, $recordHeader['start'], SEEK_SET);
            $data = fread($this->fileHandle, $recordHeader['length']);
            return @unserialize($data);
        }

        // 树形结构模式处理
        if (strlen($buffer) == 8) {
            $nodeData = unpack('Ioffset/Ilength', $buffer);
        } else {
            $nodeData = ['offset' => 0, 'length' => 0];
        }

        // 递归查找树形结构中的记录
        return $this->_tree_get_record(
            $nodeData['offset'],
            $nodeData['length'],
            $filePosition,
            $key
        );
    }

    /**
     * 在树形结构中查找记录
     *
     * 使用二分查找算法在二叉树结构中检索记录。
     * 递归遍历树节点，比较键值确定搜索方向，最终返回匹配记录或空结果。
     *
     * @param int $offset 文件中的记录偏移量
     * @param int $length 记录的长度
     * @param int $parentOffset 父节点的偏移量
     * @param string $searchKey 要搜索的键值
     * @return array 包含记录信息的数组或仅含父节点偏移量的数组
     */
    private function _tree_get_record($offset, $length, $parentOffset = 0, $searchKey = '')
    {
        // 处理空节点情况
        if ($length == 0) {
            return ['parentOffset' => $parentOffset];
        }
        $this->_io_times++;

        // 读取并解析节点数据
        fseek($this->fileHandle, $offset, SEEK_SET);
        $readLength = XDB_MAXKLEN + 17;
        if ($readLength > $length) {
            $readLength = $length;
        }
        $buffer = fread($this->fileHandle, $readLength);

        // 解析记录头信息
        $record = unpack('IleftOffset/IleftLength/IrightOffset/IrightLength/CkeyLength',
                         substr($buffer, 0, 17));
        $foundKey = substr($buffer, 17, $record['keyLength']);

        // 比较键值决定遍历方向
        $compareResult = ($searchKey ? strcmp($searchKey, $foundKey) : 0);

        if ($compareResult > 0) {
            // 向右子树搜索
            unset($buffer);
            return $this->_tree_get_record(
                $record['rightOffset'],
                $record['rightLength'],
                $offset + 8,
                $searchKey
            );
        } else if ($compareResult < 0) {
            // 向左子树搜索
            unset($buffer);
            return $this->_tree_get_record(
                $record['leftOffset'],
                $record['leftLength'],
                $offset,
                $searchKey
            );
        } else {
            // 找到匹配记录，构建完整的记录信息
            $record['parentOffset'] = $parentOffset;
            $record['offset'] = $offset;
            $record['length'] = $length;
            $record['valueOffset'] = $offset + 17 + $record['keyLength'];
            $record['valueLength'] = $length - 17 - $record['keyLength'];
            $record['key'] = $foundKey;

            // 读取记录值
            fseek($this->fileHandle, $record['valueOffset'], SEEK_SET);
            $record['value'] = fread($this->fileHandle, $record['valueLength']);
            return $record;
        }
    }
}
