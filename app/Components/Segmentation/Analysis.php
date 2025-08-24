<?php
namespace App\Components\Segmentation;

/**
 * 基于Unicode编码词典的PHP分词器
 *
 * 功能特点：
 * 1. 仅适用于PHP5，需要iconv扩展
 * 2. 使用RMM（逆向最大匹配）算法进行分词
 * 3. 支持特殊格式编码的词典，无需将词典完全载入内存
 * 4. 支持人名、地名、数字等特殊词汇识别
 * 5. 支持多种分词模式和结果过滤
 *
 * 使用流程：SetSource -> StartAnalysis -> GetResult
 *
 * @version 3.0
 * @author IT柏拉图
 * @contact QQ: 2500875 Email: 2500875@qq.com
 *
 * 词性标记说明：
 * 基础词性：n(名词), v(动词), adj(形容词), r(副词), c(介词),
 *          pron(代词), num(数词), loc(方位词), conj(连词), aux(助词)
 * 特殊词性：name(人名), sp(专有名词), i(成语/习语), j(简称),
 *          un(数字后缀), x(其他/未分类)
 */

// 字符分隔符定义
defined('_SP_') || define('_SP_', chr(0xFF) . chr(0xFE));
defined('UCS2') || define('UCS2', 'ucs-2be');

// 哈希算法参数
defined('CDB_HASH_BASE') || define('CDB_HASH_BASE', 0x238f13af);
defined('CDB_HASH_PRIME') || define('CDB_HASH_PRIME', 0xFFFF);

// 词语类型常量定义
defined('CDB_WORD_TYPE_CN') || define('CDB_WORD_TYPE_CN', 1);           // 中/韩/日文
defined('CDB_WORD_TYPE_EN') || define('CDB_WORD_TYPE_EN', 2);           // 英文/数字/符号
defined('CDB_WORD_TYPE_ANSI') || define('CDB_WORD_TYPE_ANSI', 3);       // ANSI符号
defined('CDB_WORD_TYPE_NUM') || define('CDB_WORD_TYPE_NUM', 4);         // 纯数字
defined('CDB_WORD_TYPE_OTHER') || define('CDB_WORD_TYPE_OTHER', 5);     // 其他字符
defined('CDB_WORD_TYPE_QUOTE') || define('CDB_WORD_TYPE_QUOTE', 6);     // 引号
defined('CDB_WORD_TYPE_BOOK') || define('CDB_WORD_TYPE_BOOK', 7);       // 书名号

/**
 * PhpAnalysisXdb 主分词类
 *
 * 负责中文分词处理的核心类，提供完整的分词功能
 */
class Analysis
{
    /**
     * 分词结果数据类型
     * 1: 全部词汇  2: 词典词汇+单字符+英文  3: 仅词典词汇+英文
     * @var int
     */
    public $resultType = 1;

    /**
     * 句子长度阈值 - 小于此值时不进行拆分
     * 计算公式：字符数 * 2 + 1
     * @var int
     */
    public $minSplitLength = 5;

    /**
     * 是否将英文单词转换为小写
     * @var bool
     */
    public $convertToLowerCase = false;

    /**
     * 是否使用最大切分模式进行二元词消歧
     * 建立索引时建议true，仅分词时可设为false
     * @var bool
     */
    public $enableMaximumSegmentation = false;

    /**
     * 是否尝试合并单字成词
     * @var bool
     */
    public $enableSingleWordMerging = true;

    /**
     * 转换为Unicode格式的源字符串
     * @var string
     */
    private $unicodeSourceString = '';

    /**
     * 附加词典数据存储
     * @var array
     */
    public $additionalDictionary = [];

    /**
     * 附加词典文件路径
     * @var string
     */
    public $additionalDictionaryFile = 'dict/analysis_addons.dic';

    /**
     * 主词典数据库句柄
     * @var XTreeDB|false
     */
    public $mainDictionaryHandle = false;

    /**
     * 主词典文件路径
     * @var string
     */
    public $mainDictionaryFile = 'dict/base_dic_full.dic';

    /**
     * 主词典中词语的最大长度（字节数）
     * @var int
     */
    private $maxWordLength = 14;

    /**
     * 粗分后的结果数组
     * @var array
     */
    private $roughSegmentationResults = [];

    /**
     * 最终分词结果（空格分隔的词汇列表）
     * @var array
     */
    private $finalSegmentationResults = [];

    /**
     * 词典加载状态标识
     * @var bool
     */
    public $isDictionaryLoaded = false;

    /**
     * 词典加载耗时（秒）
     * @var float
     */
    public $dictionaryLoadTime = 0;

    /**
     * 词典查询次数统计
     * @var int
     */
    public $dictionaryQueryCount = 0;

    /**
     * 主词典查询结果缓存
     * @var array
     */
    private $mainDictionaryCache = [];

    /**
     * 分词处理结果存储
     * @var array
     */
    private $segmentationResults = [];

    /**
     * 当前处理的句子索引
     * @var int
     */
    private $currentSentenceIndex = 0;
    private $_ztab;


    /**
     * 构造函数
     * 初始化分词器实例
     */
    public function __construct()
    {
        // 字符长度映射表 utf-8 & gbk(big5)
        // 0x00-0x7f: 1字节字符（ASCII字符（0x00-0x80）都是单字节字符）
        $this->_ztab = array_fill(0, 0x81, 1);
        // 1. UTF8 字符集
        // 0x80-0xbf: 1字节字符
        $this->_ztab   = array_pad($this->_ztab, 0xc0, 1);
        // 0xc0-0xdf: 2字节字符
        $this->_ztab   = array_pad($this->_ztab, 0xe0, 2);
        // 0xe0-0xef: 3字节字符
        $this->_ztab   = array_pad($this->_ztab, 0xf0, 3);
        // 0xf0-0xff: 4字节字符
        $this->_ztab   = array_pad($this->_ztab, 0xf8, 4);
        // 0xf8-0xfb: 5字节字符
        $this->_ztab   = array_pad($this->_ztab, 0xfc, 5);
        // 0xfc-0xfd: 6字节字符
        $this->_ztab   = array_pad($this->_ztab, 0xfe, 6);
        $this->_ztab[] = 1;

        // 2. GBK & BIG5 字符集 2字节字符
        $this->_ztab = array_pad($this->_ztab, 0xff, 2);

        $this->_ztab[] = 1;
    }

    /**
     * 析构函数
     * 释放资源，关闭数据库连接
     */
    public function __destruct()
    {
        if ($this->mainDictionaryHandle !== false) {
            $this->mainDictionaryHandle->Close();
        }
    }

    /**
     * 从主词典获取词汇信息
     *
     * @param string $wordKey 词汇的Unicode编码键值
     * @return array|false 词汇信息数组或false
     */
    public function getWordInformation($wordKey)
    {
        // 检查缓存
        if (isset($this->mainDictionaryCache[$wordKey])) {
            $wordInformation = $this->mainDictionaryCache[$wordKey];
        } else {
            // 查询数据库
            $this->dictionaryQueryCount++;
            $unicodeWord = iconv(UCS2, 'utf-8', $wordKey);
            $databaseValue = $this->mainDictionaryHandle->Get($unicodeWord);
            $wordInformation = false;

            if ($databaseValue) {
                // 解包二进制数据：词频、权重、标志位、词性属性
                $wordInformation = unpack('ftf/faf/Cflag/a3attr', $databaseValue);

                // 检查词汇有效性标志位
                if (!($wordInformation['flag'] & 0x01)) {
                    $wordInformation = false;
                }
            }

            // 清理不需要的字段
            if ($wordInformation) {
                unset($wordInformation['flag'], $wordInformation['af']);
            }

            // 数字识别处理
            $isNumericWord = true;
            for ($i = 0; $i < strlen($wordKey); $i += 2) {
                $characterCode = $wordKey[$i] . $wordKey[$i + 1];
                if (!$this->isNumericCharacter($characterCode)) {
                    $isNumericWord = false;
                    break;
                }
            }

            // 为数字词汇设置默认属性
            if ($isNumericWord) {
                $wordInformation = ['attr' => 'num', 'tf' => 999];
            }

            // 缓存结果
            $this->mainDictionaryCache[$wordKey] = $wordInformation;
        }

        return $wordInformation;
    }

    /**
     * 设置待分词的源字符串
     *
     * @param string $sourceText 待分词的文本
     * @return bool 设置成功返回true，否则返回false
     */
    public function setSourceText($sourceText)
    {
        // 重置处理状态
        $this->segmentationResults = [];
        $this->currentSentenceIndex = 0;
        $this->unicodeSourceString = $sourceText;
        return true;
    }

    /**
     * 设置分词结果类型
     *
     * @param int $resultType 结果类型：1=全部, 2=去除特殊符号
     */
    public function setResultType($resultType)
    {
        $this->resultType = $resultType;
    }

    /**
     * 加载词典文件
     *
     * @param string $mainDictionaryPath 主词典文件路径（可选）
     * @throws Exception 当无法加载词典时抛出异常
     */
    public function loadDictionaries($mainDictionaryPath = '')
    {
        $startTime = microtime(true);

        // 确定主词典文件路径
        $additionalDictionaryPath = dirname(__FILE__) . '/' . $this->additionalDictionaryFile;
        if ($mainDictionaryPath == '' || !file_exists($mainDictionaryPath)) {
            $this->mainDictionaryFile = dirname(__FILE__) . '/dict/dict.utf8.xdb';
        } else {
            $this->mainDictionaryFile = $mainDictionaryPath;
        }

        // 初始化主词典数据库
        $databaseInstance = new \App\Components\XTreeDB(CDB_HASH_BASE, CDB_HASH_PRIME);
        if (!$databaseInstance->Open($this->mainDictionaryFile)) {
            trigger_error("无法打开XDB数据文件：{$this->mainDictionaryFile}", E_USER_ERROR);
        } else {
            $this->mainDictionaryHandle = $databaseInstance;
        }

        // 加载附加词典
        $this->loadAdditionalDictionaries($additionalDictionaryPath);

        // 记录加载时间
        $this->dictionaryLoadTime = microtime(true) - $startTime;
        $this->isDictionaryLoaded = true;
    }

    /**
     * 加载附加词典文件
     *
     * @param string $dictionaryPath 附加词典文件路径
     */
    private function loadAdditionalDictionaries($dictionaryPath)
    {
        $currentWordType = '';
        $dictionaryLines = file($dictionaryPath);

        foreach ($dictionaryLines as $line) {
            $line = trim($line);

            // 跳过空行和注释行
            if ($line == '' || $line[0] == '#' || $line[0] == ';') {
                continue;
            }

            // 解析词典类型标识行
            if ($colonPosition = strpos($line, ':')) {
                $currentWordType = substr($line, 0, $colonPosition);
            } else {
                // 处理词汇数据行
                $separatorString = _SP_;
                $separatorString = iconv(UCS2, 'utf-8', $separatorString);
                $wordsList = explode(',', $line);
                $allWordsString = iconv('utf-8', UCS2, join($separatorString, $wordsList));
                $unicodeWordsList = explode(_SP_, $allWordsString);

                foreach ($unicodeWordsList as $unicodeWord) {
                    $this->additionalDictionary[$currentWordType][$unicodeWord] = true;
                }
            }
        }
    }

    /**
     * 检查指定词汇是否存在于词典中
     *
     * @param string $wordToCheck 要检查的词汇
     * @return bool 存在返回true，否则返回false
     */
    public function isWordInDictionary($wordToCheck)
    {
        $wordInformation = $this->getWordInformation($wordToCheck);
        return ($wordInformation !== false);
    }

    /**
     * 开始执行分词分析
     *
     * @param bool $enableOptimization 是否对结果进行优化
     * @return bool 处理成功返回true
     */
    public function startSegmentationAnalysis($enableOptimization = true)
    {
        // 重置查询计数
        $this->dictionaryQueryCount = 0;

        // 检查词典加载状态
        if (!$this->isDictionaryLoaded) {
            $this->loadDictionaries();
        }

        // 初始化处理状态
        $this->segmentationResults = [];
        $this->currentSentenceIndex = 0;
        $this->unicodeSourceString .= chr(0) . chr(32);

        $sourceStringLength = strlen($this->unicodeSourceString);
        $fullWidthToHalfWidthMap = [];

        // 构建全角半角字符映射表
        for ($i = 0; $i< 0x5F; $i++) {
            $halfWidthChar = chr(0x20 + $i);
            $fullWidthChar = iconv(UCS2, 'UTF-8', hex2bin(dechex(0xFF00 + $i)));
            $fullWidthToHalfWidthMap[$fullWidthChar] = $halfWidthChar;
        }

        // 字符串粗分处理
        $currentWordBuffer = '';
        $lastCharacterType = CDB_WORD_TYPE_CN;
        $ansiWordPattern = "[0-9a-z@#%\+\.-]";
        $nonNumberPattern = "[a-z@#%\+]";

        // 逐字符处理
        $i = 0;
        while($i < $sourceStringLength) {
            $currentChar = substr($this->unicodeSourceString, $i, 1);
            $charCode = ord($currentChar);
            // 字符长度
            $charLen = $this->_ztab[$charCode];
            $currentCharacter = substr($this->unicodeSourceString, $i, $charLen);
            $i += $charLen;
            if(isset($fullWidthToHalfWidthMap[$currentCharacter])) {
                $currentCharacter = $fullWidthToHalfWidthMap[$currentCharacter];
            }
            $currentCharacter = iconv('UTF-8', UCS2, $currentCharacter);
            $characterCode = hexdec(bin2hex($currentCharacter));

            // 处理ANSI字符
            if ($charLen == 1) {
                $this->processAnsiCharacter(
                    $characterCode,
                    $currentWordBuffer,
                    $lastCharacterType,
                    $ansiWordPattern,
                    $nonNumberPattern,
                    $enableOptimization
                );
            }
            // 处理Unicode字符
            else {
                $this->processUnicodeCharacter(
                    $characterCode,
                    $currentCharacter,
                    $currentWordBuffer,
                    $lastCharacterType,
                    $nonNumberPattern,
                    $enableOptimization,
                    $i,
                    $sourceStringLength
                );
            }
        }

        return true;
    }

    /**
     * 处理ANSI字符
     *
     * @param int $characterCode 字符编码
     * @param string &$wordBuffer 词汇缓冲区
     * @param int &$lastType 上一个字符类型
     * @param string $ansiPattern ANSI字符模式
     * @param string $nonNumberPattern 非数字字符模式
     * @param bool $enableOptimization 是否启用优化
     */
    private function processAnsiCharacter($characterCode, &$wordBuffer, &$lastType,
                                          $ansiPattern, $nonNumberPattern, $enableOptimization)
    {
        if (preg_match('/' . $ansiPattern . '/i', chr($characterCode))) {
            // 处理英文数字字符
            if ($lastType != CDB_WORD_TYPE_EN && $wordBuffer != '') {
                $this->addToSegmentationResults($wordBuffer, $lastType, $enableOptimization);
                $wordBuffer = '';
            }
            $lastType = CDB_WORD_TYPE_EN;
            $wordBuffer .= chr(0) . chr($characterCode);
        } else {
            // 处理ANSI符号
            if ($wordBuffer != '') {
                if ($lastType == CDB_WORD_TYPE_EN) {
                    if (!preg_match('/' . $nonNumberPattern . '/i', iconv(UCS2, 'utf-8', $wordBuffer))) {
                        $lastType = CDB_WORD_TYPE_NUM;
                    }
                }
                $this->addToSegmentationResults($wordBuffer, $lastType, $enableOptimization);
            }

            $wordBuffer = '';
            $lastType = CDB_WORD_TYPE_ANSI;

            if ($characterCode < 31) {
                $this->addToSegmentationResults(chr(0) . chr($characterCode), CDB_WORD_TYPE_EN, false);
            } else {
                $this->addToSegmentationResults(chr(0) . chr($characterCode), CDB_WORD_TYPE_ANSI, false);
            }
        }
    }

    /**
     * 处理Unicode字符
     *
     * @param int $characterCode 字符编码
     * @param string $character 字符
     * @param string &$wordBuffer 词汇缓冲区
     * @param int &$lastType 上一个字符类型
     * @param string $nonNumberPattern 非数字字符模式
     * @param bool $enableOptimization 是否启用优化
     * @param int &$currentIndex 当前索引
     * @param int $totalLength 总长度
     */
    private function processUnicodeCharacter($characterCode, $character, &$wordBuffer, &$lastType,
                                             $nonNumberPattern, $enableOptimization, &$currentIndex, $totalLength)
    {
        // 判断是否为正常中日韩文字
        if (($characterCode > 0x3FFF && $characterCode < 0x9FA6) ||
            ($characterCode > 0xF8FF && $characterCode < 0xFA2D) ||
            ($characterCode > 0xABFF && $characterCode < 0xD7A4) ||
            ($characterCode > 0x3040 && $characterCode < 0x312B)) {

            // 处理中日韩文字
            if ($lastType != CDB_WORD_TYPE_CN && $wordBuffer != '') {
                if ($lastType == CDB_WORD_TYPE_EN) {
                    if (!preg_match('/' . $nonNumberPattern . '/i', iconv(UCS2, 'utf-8', $wordBuffer))) {
                        $lastType = CDB_WORD_TYPE_NUM;
                    }
                }
                $this->addToSegmentationResults($wordBuffer, $lastType, $enableOptimization);
                $wordBuffer = '';
            }
            $lastType = CDB_WORD_TYPE_CN;
            $wordBuffer .= $character;
        } else {
            // 处理特殊符号
            $this->processSpecialSymbol($character, $characterCode, $wordBuffer, $lastType,
                                        $nonNumberPattern, $enableOptimization, $currentIndex, $totalLength);
        }
    }

    /**
     * 处理特殊符号
     *
     * @param string $character 字符
     * @param int $characterCode 字符编码
     * @param string &$wordBuffer 词汇缓冲区
     * @param int &$lastType 上一个字符类型
     * @param string $nonNumberPattern 非数字字符模式
     * @param bool $enableOptimization 是否启用优化
     * @param int &$currentIndex 当前索引
     * @param int $totalLength 总长度
     */
    private function processSpecialSymbol($character, $characterCode, &$wordBuffer, &$lastType,
                                          $nonNumberPattern, $enableOptimization, &$currentIndex, $totalLength)
    {
        // 处理当前词汇缓冲区
        if ($wordBuffer != '') {
            if ($lastType == CDB_WORD_TYPE_EN) {
                if (!preg_match('/' . $nonNumberPattern . '/i', iconv(UCS2, 'utf-8', $wordBuffer))) {
                    $lastType = CDB_WORD_TYPE_NUM;
                }
            }
            $this->addToSegmentationResults($wordBuffer, $lastType, $enableOptimization);
        }

        // 处理省略号
        if ($characterCode == 0x2026) {
            $this->processEllipsis($character, $currentIndex, $totalLength);
            $wordBuffer = '';
            $lastType = CDB_WORD_TYPE_OTHER;
            return;
        }

        // 处理书名号
        if ($characterCode == 0x300A) {
            $this->processBookTitle($character, $currentIndex, $totalLength, $enableOptimization);
            $wordBuffer = '';
            $lastType = CDB_WORD_TYPE_OTHER;
            return;
        }

        // 处理其他符号
        $wordBuffer = '';
        $lastType = CDB_WORD_TYPE_OTHER;

        if ($characterCode == 0x3000) {
            // 跳过全角空格
            return;
        } else {
            $this->addToSegmentationResults($character, CDB_WORD_TYPE_OTHER, false);
        }
    }

    /**
     * 处理省略号
     *
     * @param string $character 字符
     * @param int &$currentIndex 当前索引
     * @param int $totalLength 总长度
     */
    private function processEllipsis($character, &$currentIndex, $totalLength)
    {
        $ellipsisSequence = $character;
        $ellipsisChar = chr(0x20) . chr(0x26);
        $offset = 1;

        // 查找连续的省略号
        while ($currentIndex + $offset < $totalLength) {
            $nextChar = $this->unicodeSourceString[$currentIndex + $offset] .
                        $this->unicodeSourceString[$currentIndex + $offset + 1];

            if ($nextChar != $ellipsisChar) {
                break;
            }

            $ellipsisSequence .= $nextChar;
            $offset += 2;
        }

        $currentIndex += $offset - 1;
        $this->addToSegmentationResults($ellipsisSequence, 14, false);
    }

    /**
     * 处理书名号
     *
     * @param string $character 字符
     * @param int &$currentIndex 当前索引
     * @param int $totalLength 总长度
     * @param bool $enableOptimization 是否启用优化
     */
    private function processBookTitle($character, &$currentIndex, $totalLength, $enableOptimization)
    {
        $bookTitle = '';
        $offset = 1;
        $isValidBook = false;
        $closingBookMark = chr(0x30) . chr(0x0B);

        // 查找书名内容
        while ($currentIndex + $offset < $totalLength) {
            $nextChar = $this->unicodeSourceString[$currentIndex + $offset] .
                        $this->unicodeSourceString[$currentIndex + $offset + 1];

            if ($nextChar == $closingBookMark) {
                $this->addToSegmentationResults($character, CDB_WORD_TYPE_OTHER, false);
                $this->addToSegmentationResults($bookTitle, 13, $enableOptimization);

                // 最大切分模式下继续分词
                if ($this->enableMaximumSegmentation) {
                    $this->performDeepAnalysis($bookTitle, CDB_WORD_TYPE_CN, $enableOptimization);
                }

                $this->addToSegmentationResults($closingBookMark, CDB_WORD_TYPE_OTHER, false);
                $currentIndex += $offset + 1;
                $isValidBook = true;
                break;
            } else {
                $bookTitle .= $nextChar;
                $offset += 2;

                // 避免过长的书名
                if (strlen($bookTitle) > 60) {
                    break;
                }
            }
        }

        if (!$isValidBook) {
            $this->addToSegmentationResults($character, CDB_WORD_TYPE_OTHER, false);
        }
    }

    /**
     * 添加分词结果的统一方法
     *
     * @param string $word 词汇
     * @param int $type 词汇类型
     * @param bool $needOptimization 是否需要优化
     */
    private function addToSegmentationResults($word, $type, $needOptimization = true)
    {
        if (empty($word)) {
            return;
        }

        // 创建分词结果项
        $resultItem = [
            'w' => $word,
            't' => $type,
            'segments' => null
        ];

        // 中文词汇进行深度分析
        if ($needOptimization && $type == CDB_WORD_TYPE_CN) {
            $this->performDeepAnalysis($word, $type, $needOptimization);
        } else {
            // 非中文词汇的处理
            if ($type != CDB_WORD_TYPE_NUM || $needOptimization) {
                if ($this->convertToLowerCase && $type == CDB_WORD_TYPE_EN) {
                    $resultItem['segments'] = [strtolower($word)];
                } else {
                    $resultItem['segments'] = [$word];
                }
            } else {
                $resultItem['segments'] = [$word];
            }
        }

        $this->segmentationResults[] = $resultItem;
        $this->currentSentenceIndex++;
    }

    /**
     * 执行深度分析（中文分词的核心算法）
     *
     * @param string $textToAnalyze 待分析文本
     * @param int $characterType 字符类型
     * @param bool $enableOptimization 是否启用优化
     */
    private function performDeepAnalysis($textToAnalyze, $characterType, $enableOptimization = true)
    {
        // 仅处理中文文本
        if ($characterType == CDB_WORD_TYPE_CN) {
            $textLength = strlen($textToAnalyze);
            $currentIndex = $this->currentSentenceIndex - 1;

            // 短句子处理
            if ($textLength < $this->minSplitLength) {
                $this->processShortSentence($textToAnalyze, $currentIndex);
            }
            // 长句子的深度分词
            else {
                $this->performChineseDeepAnalysis($textToAnalyze, $characterType, $textLength, $enableOptimization);
            }
        }
        // 英文文本处理
        else {
            $currentIndex = $this->currentSentenceIndex - 1;
            if ($this->convertToLowerCase) {
                $this->segmentationResults[$currentIndex]['segments'] = [strtolower($textToAnalyze)];
            } else {
                $this->segmentationResults[$currentIndex]['segments'] = [$textToAnalyze];
            }
        }
    }

    /**
     * 处理短句子
     *
     * @param string $shortText 短文本
     * @param int $currentIndex 当前索引
     */
    private function processShortSentence($shortText, $currentIndex)
    {
        $lastType = 0;
        if ($currentIndex > 0 && isset($this->segmentationResults[$currentIndex - 1]['t'])) {
            $lastType = $this->segmentationResults[$currentIndex - 1]['t'];
        }

        // 处理数字+单位的组合
        if ($lastType == CDB_WORD_TYPE_NUM &&
            (isset($this->additionalDictionary['unit'][$shortText]) ||
             isset($this->additionalDictionary['unit'][substr($shortText, 0, 2)]))) {

            $remainingText = '';
            if (!isset($this->additionalDictionary['unit'][$shortText]) &&
                isset($this->additionalDictionary['stop'][substr($shortText, 2, 2)])) {
                $remainingText = substr($shortText, 2, 2);
                $shortText = substr($shortText, 0, 2);
            }

            $combinedWord = $this->segmentationResults[$currentIndex - 1]['w'] . $shortText;
            $this->segmentationResults[$currentIndex - 1]['w'] = $combinedWord;
            $this->segmentationResults[$currentIndex - 1]['t'] = CDB_WORD_TYPE_NUM;
            $this->segmentationResults[$currentIndex]['w'] = '';

            if ($remainingText != '') {
                $this->segmentationResults[$currentIndex - 1]['segments'] = [$combinedWord, $remainingText];
            } else {
                $this->segmentationResults[$currentIndex - 1]['segments'] = [$combinedWord];
            }
        } else {
            $this->segmentationResults[$currentIndex]['segments'] = [$shortText];
        }
    }

    /**
     * 中文深度分词分析
     *
     * @param string $chineseText 中文文本
     * @param int $lastCharacterType 上一个字符类型
     * @param int $textLength 文本长度
     * @param bool $enableOptimization 是否启用优化
     */
    private function performChineseDeepAnalysis($chineseText, $lastCharacterType, $textLength, $enableOptimization = true)
    {
        $leftQuote = chr(0x20) . chr(0x1C);
        $segmentedWords = [];
        $currentIndex = $this->currentSentenceIndex - 1;
        $previousWord = isset($this->segmentationResults[$currentIndex - 1]['w'])
            ? $this->segmentationResults[$currentIndex - 1]['w'] : '';

        // 特殊情况：前一个词为左引号且当前文本较短时
        if ($currentIndex > 0 && $textLength < 11 && $previousWord == $leftQuote) {
            $segmentedWords[] = $chineseText;
            if (!$this->enableMaximumSegmentation) {
                $this->segmentationResults[$currentIndex]['segments'] = [$chineseText];
                return;
            }
        }

        // 使用逆向最大匹配算法进行分词
        for ($i = $textLength - 1; $i > 0; $i -= 2) {
            $currentCharacter = $chineseText[$i - 1] . $chineseText[$i];

            if ($i <= 2) {
                $segmentedWords[] = $currentCharacter;
                break;
            }

            $wordFound = false;
            $i = $i + 1;

            // 从最大词长开始匹配
            for ($wordLength = $this->maxWordLength; $wordLength > 1; $wordLength -= 2) {
                if ($i < $wordLength) {
                    continue;
                }

                $potentialWord = substr($chineseText, $i - $wordLength, $wordLength);

                if (strlen($potentialWord) <= 2) {
                    $i = $i - 1;
                    break;
                }

                if ($this->isWordInDictionary($potentialWord)) {
                    $segmentedWords[] = $potentialWord;
                    $i = $i - $wordLength + 1;
                    $wordFound = true;
                    break;
                }
            }

            if (!$wordFound) {
                $segmentedWords[] = $currentCharacter;
            }
        }

        $wordCount = count($segmentedWords);
        if ($wordCount == 0) {
            return;
        }

        $finalSegments = array_reverse($segmentedWords);
        $this->segmentationResults[$currentIndex]['segments'] = $finalSegments;

        // 优化分词结果
        if ($enableOptimization) {
            $this->optimizeSegmentationResults($finalSegments, $currentIndex);
            $this->segmentationResults[$currentIndex]['segments'] = $finalSegments;
        }
    }

    /**
     * 优化分词结果
     *
     * @param array &$segmentArray 分词结果数组
     * @param int $currentPosition 当前位置
     */
    private function optimizeSegmentationResults(&$segmentArray, $currentPosition)
    {
        $optimizedResults = [];
        $previousPosition = $currentPosition - 1;
        $arrayLength = count($segmentArray);
        $currentIndex = $resultIndex = 0;

        // 处理与前一个词汇的数量词合并
        if ($previousPosition > -1 &&
            isset($this->segmentationResults[$previousPosition]['t']) &&
            !isset($this->segmentationResults[$previousPosition]['segments'])) {

            $previousWord = $this->segmentationResults[$previousPosition]['w'];
            $previousWordType = $this->segmentationResults[$previousPosition]['t'];

            if (($previousWordType == CDB_WORD_TYPE_NUM ||
                 isset($this->additionalDictionary['num'][$previousWord])) &&
                isset($this->additionalDictionary['unit'][$segmentArray[0]])) {

                $this->segmentationResults[$previousPosition]['w'] = $previousWord . $segmentArray[0];
                $this->segmentationResults[$previousPosition]['t'] = CDB_WORD_TYPE_NUM;
                $segmentArray[0] = '';
                $currentIndex++;
            }
        }

        // 处理词汇优化
        for (; $currentIndex < $arrayLength; $currentIndex++) {
            if (!isset($segmentArray[$currentIndex + 1])) {
                $optimizedResults[$resultIndex] = $segmentArray[$currentIndex];
                break;
            }

            $currentWord = $segmentArray[$currentIndex];
            $nextWord = $segmentArray[$currentIndex + 1];
            $hasMatched = false;

            // 数字识别与合并
            if ($this->isNumericCharacter($currentWord, true)) {
                $nextIndex = $currentIndex;
                $combinedNumber = '';

                while ($nextIndex < $arrayLength) {
                    if (!$this->isNumericCharacter($segmentArray[$nextIndex])) {
                        break;
                    }
                    $combinedNumber .= $segmentArray[$nextIndex];
                    $currentIndex++;
                    $nextIndex++;
                }

                $currentIndex--;
                $optimizedResults[$resultIndex] = $combinedNumber;
                $resultIndex++;
                $hasMatched = true;
            }
            // 人名识别与合并
            else if (isset($this->additionalDictionary['name'][$currentWord])) {
                $shouldSkipNameRecognition = false;

                // 检查是否为高频词汇，避免误识别
                if (strlen($nextWord) == 4) {
                    $wordInfo = $this->getWordInformation($nextWord);
                    if (isset($wordInfo['attr']) &&
                        ($wordInfo['attr'] == 'r' || $wordInfo['attr'] == 'c' || $wordInfo['tf'] > 500)) {
                        $shouldSkipNameRecognition = true;
                    }
                }

                // 进行人名识别
                if (!isset($this->additionalDictionary['stop'][$nextWord]) &&
                    strlen($nextWord) < 5 &&
                    !$shouldSkipNameRecognition) {

                    $recognizedName = $currentWord . $nextWord;

                    // 尝试识别三字人名
                    if (strlen($nextWord) == 2 &&
                        isset($segmentArray[$currentIndex + 2]) &&
                        strlen($segmentArray[$currentIndex + 2]) == 2 &&
                        !isset($this->additionalDictionary['stop'][$segmentArray[$currentIndex + 2]])) {

                        $recognizedName .= $segmentArray[$currentIndex + 2];
                        $currentIndex++;
                    }

                    $optimizedResults[$resultIndex] = $recognizedName;
                    $this->mainDictionaryCache[$recognizedName] = ['attr' => 'name', 'tf' => 999];
                    $resultIndex++;
                    $currentIndex++;
                    $hasMatched = true;
                }
            }
            // 地名/后缀词识别与合并
            else if (isset($this->additionalDictionary['suffix'][$nextWord])) {
                $shouldSkipSuffixRecognition = false;

                // 检查当前词是否为高频词
                if (strlen($currentWord) > 2) {
                    $wordInfo = $this->getWordInformation($currentWord);
                    if (isset($wordInfo['attr']) &&
                        ($wordInfo['attr'] == 'a' || $wordInfo['attr'] == 'r' ||
                         $wordInfo['attr'] == 'c' || $wordInfo['tf'] > 500)) {
                        $shouldSkipSuffixRecognition = true;
                    }
                }

                if (!isset($this->additionalDictionary['stop'][$currentWord]) &&
                    !$shouldSkipSuffixRecognition) {
                    $optimizedResults[$resultIndex] = $currentWord . $nextWord;
                    $currentIndex++;
                    $resultIndex++;
                    $hasMatched = true;
                }
            }
            // 新词发现（基于规则的两字词组合）
            else if ($this->enableSingleWordMerging) {
                if (strlen($currentWord) == 2 && strlen($nextWord) == 2 &&
                    !isset($this->additionalDictionary['stop'][$currentWord]) &&
                    !isset($this->additionalDictionary['region'][$currentWord]) &&
                    !isset($this->additionalDictionary['suffix'][$currentWord]) &&
                    !isset($this->additionalDictionary['stop'][$nextWord]) &&
                    !isset($this->additionalDictionary['num'][$nextWord])) {

                    $newWord = $currentWord . $nextWord;

                    // 尝试识别三字新词
                    if (isset($segmentArray[$currentIndex + 2]) &&
                        strlen($segmentArray[$currentIndex + 2]) == 2 &&
                        (isset($this->additionalDictionary['suffix'][$segmentArray[$currentIndex + 2]]) ||
                         isset($this->additionalDictionary['unit'][$segmentArray[$currentIndex + 2]]))) {

                        $newWord .= $segmentArray[$currentIndex + 2];
                        $currentIndex++;
                    }

                    $optimizedResults[$resultIndex] = $newWord;
                    $currentIndex++;
                    $resultIndex++;
                    $hasMatched = true;
                }
            }

            // 未匹配的词汇直接添加
            if (!$hasMatched) {
                $optimizedResults[$resultIndex] = $currentWord;
                $resultIndex++;
            }
        }

        // 更新分词结果
        $segmentArray = $optimizedResults;
    }

    /**
     * 检查字符是否为数字字符
     *
     * @param string $character 字符
     * @param bool $checkPattern 是否检查模式
     * @return bool 是数字字符返回true
     */
    private function isNumericCharacter($character, $checkPattern = false)
    {
        if ($checkPattern) {
            return preg_match('/^[0-9]+$/', iconv(UCS2, 'utf-8', $character));
        }

        $charCode = hexdec(bin2hex($character));
        return ($charCode >= 0x30 && $charCode <= 0x39);
    }

    /**
     * 获取分词结果
     *
     * @return array 分词结果数组
     */
    public function getSegmentationResults()
    {
        $results = [];
        foreach ($this->segmentationResults as $item) {
            if (isset($item['segments']) && is_array($item['segments'])) {
                $results = array_merge($results, $item['segments']);
            }
        }
        return $results;
    }

    /**
     * 获取格式化的分词结果字符串
     *
     * @param string $delimiter 分隔符
     * @return string 格式化的分词结果
     */
    public function getFormattedResults($delimiter = ' ')
    {
        $results = $this->getSegmentationResults();
        $filteredResults = [];

        foreach ($results as $word) {
            if (!empty($word)) {
                $filteredResults[] = iconv(UCS2, 'utf-8', $word);
            }
        }

        return implode($delimiter, $filteredResults);
    }
}