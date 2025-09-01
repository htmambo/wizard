<?php
namespace App\Components\Segmentation;

/**
 * 基于Unicode编码词典的PHP分词器
 *
 * 功能特点：
 * 1. 需要iconv扩展
 * 2. 使用RMM（逆向最大匹配）算法进行分词
 * 3. 支持特殊格式编码的词典，无需将词典完全载入内存，提供编译(MakeDict)以及导出词典(ExportDict)
 * 4. 支持人名、地名、数字等特殊词汇识别
 * 5. 支持多种分词模式和结果过滤
 *
 * 使用流程：setSourceText -> StartAnalysis -> Get***Result
 *
 * @version 3.0
 * @author IT柏拉图
 * @contact QQ: 2500875 Email: 2500875@qq.com
 *
 * 基本词性标记：
 *  - 副词（adverb）:r
 *  - 介词（preposition）:c
 *
 *  - 名词（noun）:n
 *  - 动词（verb）:v
 *  - 形容词（adjective）:adj
 *  - 代词（pronoun）:pron
 *  - 数词（numeral）:num
 *  - 方位词（localizer）:loc
 *  - 连词（conjunction）:conj
 *  - 助词（auxiliary）:aux
 *  特殊词性标记：
 *  - 人名/姓：name
 *  - 专有名词：sp
 *  - 成语/习语/俗语：i
 *  扩展标记：
 *  - j:简称/缩写
 *  - unit:数字后缀
 *  - x:其他/未分类
 */

// 字符分隔符定义
defined('_SP_') || define('_SP_', chr(0xFF) . chr(0xFE));
defined('UCS2') || define('UCS2', 'ucs-2be');

// 哈希算法参数
defined('CDB_HASH_BASE') || define('CDB_HASH_BASE', 0xf422f);
defined('CDB_HASH_PRIME') || define('CDB_HASH_PRIME', 32141);

// 词语类型常量定义
//中/韩/日文
defined('CDB_WORD_TYPE_CN') || define('CDB_WORD_TYPE_CN', 1);
//英文/数字/符号('.', '@', '#', '+')
defined('CDB_WORD_TYPE_EN') || define('CDB_WORD_TYPE_EN', 2);
//ANSI符号
defined('CDB_WORD_TYPE_ANSI') || define('CDB_WORD_TYPE_ANSI', 3);
//纯数字
defined('CDB_WORD_TYPE_NUM') || define('CDB_WORD_TYPE_NUM', 4);
//非ANSI符号或不支持字符
defined('CDB_WORD_TYPE_OTHER') || define('CDB_WORD_TYPE_OTHER', 5);

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
     * 计算公式：字符数 * 2
     * @var int
     */
    private $maxWordLength = 14;

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
    private $headerInfo = [
        'base' => CDB_HASH_BASE,
        'prime' => CDB_HASH_PRIME,
    ];

    /**
     * 构造函数
     *
     * @return void
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

    public function getIoTimes()
    {
        return $this->mainDictionaryHandle->_io_times;
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
            $databaseValue = $this->mainDictionaryHandle->Get($wordKey, true);
            if (!is_array($databaseValue) || !isset($databaseValue[$wordKey])) {
                $databaseValue = false;
                $wordInformation = false;
            }
            if($databaseValue) {
                foreach($databaseValue as $k1 => $v1) {
                    $isNumeric = true;
                    $strLen = strlen($k1);
                    for($i=0;$i<$strLen;$i+=2) {
                        $c = $k1[$i] . $k1[$i+1];
                        if(!$this->isNumeric($c)) {
                            $isNumeric = false;
                            break;
                        }
                    }
                    if ($isNumeric) {
                        $databaseValue[$k1] = [999, 'num'];
                    }
                    $this->mainDictionaryCache[$k1] = ['attr' => $databaseValue[$k1][1], 'tf' => $databaseValue[$k1][0]];
                }
                $wordInformation = $databaseValue[$wordKey];
            } else {
                $this->mainDictionaryCache[$wordKey] = false;
            }
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
        $this->unicodeSourceString = iconv('utf-8', UCS2, $sourceText);
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
            $this->mainDictionaryFile = dirname(__FILE__) . '/' . $this->mainDictionaryFile;
        } else {
            $this->mainDictionaryFile = $mainDictionaryPath;
        }

        // 初始化主词典数据库
        $databaseInstance = new XTreeDB(CDB_HASH_BASE, CDB_HASH_PRIME);
        $databaseInstance->setReversedCfg('iiii', 'ItfTotal/ItotalWords/ImaxWordLength/Ireversed');
        if (!$databaseInstance->Open($this->mainDictionaryFile)) {
            throw new \Exception("无法打开XDB数据文件：{$this->mainDictionaryFile}");
        } else {
            $this->mainDictionaryHandle = $databaseInstance;
        }
        $this->headerInfo = $databaseInstance->headerInfo;
        if(is_numeric($this->headerInfo['maxWordLength']) && $this->headerInfo['maxWordLength']>0) {
            $this->maxWordLength = $this->headerInfo['maxWordLength'];
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
    public function StartAnalysis($enableOptimization = true)
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
        $sbcArr = [];
        $j = 0;

        // 全角与半角字符对照表
        for ($i = 0xFF00; $i < 0xFF5F; ++$i) {
            $scb = 0x20 + $j;
            ++$j;
            $sbcArr[$i] = $scb;
        }

        // 对字符串进行粗分
        $wordBuffer = '';
        $lastCharacterType = CDB_WORD_TYPE_CN;

        for ($i = 0; $i < $sourceStringLength; ++$i) {
            $character = $this->unicodeSourceString[$i] . $this->unicodeSourceString[++$i];
            $characterCode = hexdec(bin2hex($character));
            $characterCode = isset($sbcArr[$characterCode]) ? $sbcArr[$characterCode] : $characterCode;

            // 处理ANSI字符
            if ($characterCode < 0x80) {
                $this->processAnsiCharacter(
                    $characterCode,
                    $wordBuffer,
                    $lastCharacterType,
                    $enableOptimization
                );
            }
            // 处理Unicode字符
            else {
                $this->processUnicodeCharacter(
                    $characterCode,
                    $character,
                    $wordBuffer,
                    $lastCharacterType,
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
     * @param bool $enableOptimization 是否启用优化
     */
    private function processAnsiCharacter($characterCode, &$wordBuffer, &$lastType, $enableOptimization)
    {
        $ansiPattern = "[0-9a-z@#%\+\.-]";
        $nonNumberPattern = "[a-z@#%\+]";
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
     * @param bool $enableOptimization 是否启用优化
     * @param int &$currentIndex 当前索引
     * @param int $totalLength 总长度
     */
    private function processUnicodeCharacter($characterCode, $character, &$wordBuffer, &$lastType,
                                             $enableOptimization, &$currentIndex, $totalLength)
    {
        $nonNumberPattern = "[a-z@#%\+]";
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
                        if(isset($this->additionalDictionary['unit'][$character])) {
                            $wordBuffer .= $character;
                            $character = '';
                        }
                    }
                }
                $this->addToSegmentationResults($wordBuffer, $lastType, $enableOptimization);
                $wordBuffer = '';
            }
            if (!$character) return;
            $wordBuffer .= $character;
            $lastType = CDB_WORD_TYPE_CN;
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
        // prevWord
        $prevWord = end($this->segmentationResults);
        // 连续的数字当一个词处理
        if($type == CDB_WORD_TYPE_NUM && $prevWord && $prevWord['t'] == CDB_WORD_TYPE_NUM) {
            $prevWord = array_pop($this->segmentationResults);
            $prevWord['w'] .= $word;
            $prevWord['segments'] = [$prevWord['w']];
            $this->mainDictionaryCache[$prevWord['w']] = ['attr' => 'name', 'tf' => 999];
            $this->segmentationResults[] = $prevWord;
            return;
        }

        // 创建基本的分词结果项
        $resultItem = [
            'w' => $word,
            't' => $type,
            'segments' => null
        ];

        // 如果需要深度分析
        if ($needOptimization && $type == CDB_WORD_TYPE_CN) {
            $this->segmentationResults[] = $resultItem;
            $this->currentSentenceIndex++;
            $this->performDeepAnalysis($word, $type, $needOptimization);
        } else {
            // 非中文或不需要优化的词汇直接添加
            if ($type != CDB_WORD_TYPE_NUM || $needOptimization) {
                if ($this->convertToLowerCase && $type == CDB_WORD_TYPE_EN) {
                    $resultItem['segments'] = [strtolower($word)];
                } else {
                    $resultItem['segments'] = [$word];
                }
                $this->segmentationResults[] = $resultItem;
                $this->currentSentenceIndex++;
            }
        }
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
                $this->segmentationResults[$currentIndex]['segments'] = [$textToAnalyze];
            } else {
                // 长句子的深度分词
                $this->performChineseDeepAnalysis($textToAnalyze, $characterType, $textLength, $enableOptimization);
            }
        }
        else {
            // 英文文本处理
            $currentIndex = $this->currentSentenceIndex - 1;
            if ($this->convertToLowerCase) {
                $this->segmentationResults[$currentIndex]['segments'] = [strtolower($textToAnalyze)];
            } else {
                $this->segmentationResults[$currentIndex]['segments'] = [$textToAnalyze];
            }
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
        $previousWord = isset($this->segmentationResults[$currentIndex - 1]['w']) ? $this->segmentationResults[$currentIndex - 1]['w'] : '';

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

                if (!isset($potentialWord[3])) {
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
     * 该方法是分词系统的核心优化模块，主要功能包括：
     * 1. 数量词识别与合并（如："三" + "个" → "三个"）
     * 2. 人名识别与合并（如："张" + "三" → "张三"）
     * 3. 地名/后缀词识别与合并（如："北京" + "市" → "北京市"）
     * 4. 新词发现与合并（基于规则的两字词组合）
     * 5. 二元歧义消解处理（最大切分模式）
     *
     * 词典结构说明：
     * - $this->additionalDictionary['num']: 数字词典
     * - $this->additionalDictionary['unit']: 单位词典
     * - $this->additionalDictionary['name']: 姓氏词典
     * - $this->additionalDictionary['suffix']: 后缀词典（地名等）
     * - $this->additionalDictionary['stop']: 停用词典
     * - $this->additionalDictionary['region']: 时间词典
     *
     * @param array &$segmentArray 分词结果数组（引用传递）
     * @param int $currentPosition 当前处理位置
     * @return void
     */
    private function optimizeSegmentationResults(&$segmentArray, $currentPosition) {
        $optimizedResultArray = [];
        $previousPosition = $currentPosition - 1;
        $arrayLength = count($segmentArray);
        $currentIndex = $resultIndex = 0;

        // 2 处理词汇优化
        for (; $currentIndex < $arrayLength; ++$currentIndex) {
            if (!isset($segmentArray[$currentIndex + 1])) {
                $optimizedResultArray[$resultIndex] = $segmentArray[$currentIndex];
                break;
            }

            $currentWord = $segmentArray[$currentIndex];
            $nextWord = $segmentArray[$currentIndex + 1];
            $hasMatched = false;

            // 2.1 数字识别与合并
            if ($this->isNumeric($currentWord, true)) {
                $nextIndex = $currentIndex;
                $combinedNumber = '';

                while ($nextIndex < $arrayLength) {
                    if (!$this->isNumeric($segmentArray[$nextIndex])) {
                        break;
                    }
                    $combinedNumber .= $segmentArray[$nextIndex];
                    $currentIndex++;
                    $nextIndex++;
                }

                $currentIndex--;
                $optimizedResultArray[$resultIndex] = $combinedNumber;
                $resultIndex++;
                $hasMatched = true;
            }
            // 2.2 人名识别与合并
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
                    !isset($nextWord[5]) &&
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

                    $optimizedResultArray[$resultIndex] = $recognizedName;
                    $this->mainDictionaryCache[$recognizedName] = ['attr' => 'name', 'tf' => 999];
                    $resultIndex++;
                    $currentIndex++;
                    $hasMatched = true;
                }
            }
            // 2.3 地名/后缀词识别与合并
            else if (isset($this->additionalDictionary['suffix'][$nextWord])) {
                $shouldSkipSuffixRecognition = false;

                // 检查当前词是否为副词、介词等高频词
                if (isset($currentWord[2])) {
                    $wordInfo = $this->getWordInformation($currentWord);
                    if (isset($wordInfo['attr']) &&
                        ($wordInfo['attr'] == 'a' || $wordInfo['attr'] == 'r' ||
                         $wordInfo['attr'] == 'c' || $wordInfo['tf'] > 500)) {
                        $shouldSkipSuffixRecognition = true;
                    }
                }

                if (!isset($this->additionalDictionary['stop'][$currentWord]) && !$shouldSkipSuffixRecognition) {
                    // 合并地名
                    $optimizedResultArray[$resultIndex] = $currentWord . $nextWord;
                    $currentIndex++;
                    $resultIndex++;
                    $hasMatched = true;
                }
            }
            // 2.4 新词发现（基于规则的两字词组合）
            else if ($this->enableSingleWordMerging) {
                if (
                    strlen($currentWord) == 2 &&
                    strlen($nextWord) == 2 &&
                    !isset($this->additionalDictionary['stop'][$currentWord]) &&
                    !isset($this->additionalDictionary['region'][$currentWord]) &&
                    !isset($this->additionalDictionary['suffix'][$currentWord]) &&
                    !isset($this->additionalDictionary['stop'][$nextWord]) &&
                    !isset($this->additionalDictionary['num'][$nextWord])
                ) {

                    $newWord = $currentWord . $nextWord;

                    // 尝试识别三字新词（后缀为地名或单位词）
                    if (isset($segmentArray[$currentIndex + 2]) &&
                        strlen($segmentArray[$currentIndex + 2]) == 2 &&
                        (
                            isset($this->additionalDictionary['suffix'][$segmentArray[$currentIndex + 2]])
                            // ||
                            // isset($this->additionalDictionary['unit'][$segmentArray[$currentIndex + 2]])
                        )
                    ) {

                        $newWord .= $segmentArray[$currentIndex + 2];
                        $currentIndex++;
                    }

                    $optimizedResultArray[$resultIndex] = $newWord;
                    $currentIndex++;
                    $resultIndex++;
                    $hasMatched = true;
                }
            }

            // 3 处理未匹配的词汇
            if (!$hasMatched) {
                $optimizedResultArray[$resultIndex] = $currentWord;
                $resultIndex++;

                // 二元歧义消解处理（最大切分模式）
                $nextWordLength = strlen($nextWord);
                if ($this->enableMaximumSegmentation &&
                    !isset($this->additionalDictionary['stop'][$currentWord]) &&
                    strlen($currentWord) < 5 &&
                    $nextWordLength > 2 &&
                    $nextWordLength < 7) {

                    // 尝试不同的切分方式
                    for ($splitPosition = 0; $splitPosition <= $nextWordLength; $splitPosition += 2) {
                        $wordHead = substr($nextWord, $splitPosition, 2);
                        $combinedWord = $currentWord . substr($nextWord, 0, $splitPosition);

                        // 检查组合后的词是否在词典中
                        if ($this->isWordInDictionary($combinedWord . $wordHead)) {
                            if (strlen($currentWord) > 2) {
                                ++$resultIndex;
                            }
                            $optimizedResultArray[$resultIndex] = chr(0) . chr(0x28) . $combinedWord . $wordHead . chr(0) . chr(0x29);
                        }
                    }
                }
                ++$resultIndex;
            }
        }

        // 更新分词结果
        $segmentArray = $optimizedResultArray;
    }

    /**
     * 获取简化的分词结果
     *
     * @return array 分词结果数组
     */
    public function GetSimpleResult() {
        $rearr = [];
        foreach ($this->segmentationResults as $k => $v) {
            $w = $this->_out_string_encoding($v['w']);
            if ($w != ' ') {
                $rearr[$k]['w'] = $w;
                $rearr[$k]['t'] = $v['t'];
            }
        }
        return $rearr;
    }

    /**
     * 把uncode字符串转换为输出字符串
     *
     * @parem str
     * return string
     */
    private function _out_string_encoding($str){
        return iconv(UCS2, 'utf-8', $str);
    }

    /**
     * 获取最终分词结果
     *
     * @param string $delimiter 分隔符
     * @return string|array 格式化的分词结果
     */
    public function GetFinallyResult($delimiter = ' ', $num = 0)
    {
        $filteredResults = [];
        $i = 0;
        $findLeft = false;

        foreach ($this->segmentationResults as $v) {
            if (empty($v['segments'])) {
                continue;
            }

            foreach ($v['segments'] as $word) {
                if ($this->resultType == 2 && ($v['t'] == 3 || $v['t'] == 5)) {
                    continue;
                }

                $w = $this->_out_string_encoding($word);
                if ($w != ' ' && (strlen($w) > 3 || $this->resultType == 1)) {
                    if ($w == '(' && $v['t'] == 20) {
                        $findLeft = true;
                    }
                    ++$i;
                    if (!$findLeft) {
                        $filteredResults[] = $w;
                    }
                }
                if ($w == ')') {
                    $findLeft = false;
                }
                if ($num > 0 && $i >= $num) {
                    break 2;
                }
            }
        }
        if(is_null($delimiter)) {
            return $filteredResults;
        }
        return implode($delimiter, $filteredResults);
    }

    /**
     * 检查是否为数字字符
     *
     * @param string $char 字符
     *
     * @return bool 是否为数字
     */
    private function isNumeric($word, $skipUnits = false) {
        if(is_numeric($word)) {
            return true;
        }
        if(isset($this->additionalDictionary['num'][$word])) {
            return true;
        }
        if(!$skipUnits && isset($this->additionalDictionary['unit'][$word])) {
            return true;
        }
        if(strlen($word)>2) {
            $info = $this->getWordInformation($word);
            if(isset($info['attr']) && $info['attr'] == 'num') {
                return true;
            }
        }
        return false;
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
     * 编译词典
     * @parem $sourcefile utf-8编码的文本词典数据文件
     * 注意, 需要PHP开放足够的内存才能完成操作
     *
     * @return void
     */
    public function MakeDict($source_file, $target_file = '', $base = 0, $prime = 0){
        ini_set('memory_limit', '512M');
        if ($base>0) {
            $this->headerInfo['base'] = $base;
        }
        if ($prime>0) {
            $this->headerInfo['prime'] = $prime;
        }
        $target_file = ($target_file == '' ? $this->mainDictionaryFile : $target_file);
        $words = $allk = [];
        $fp          = fopen($source_file, 'r');
        $maxWordLength = 0;
        $totalWords = $tfTotal = $maxTf = $minTf = 0;
        while ($line = fgets($fp, 512)) {
            $line = trim($line);
            if (!$line || $line[0] == '@') {
                continue;
            }
            list($w, $r, $a) = explode(',', $line);
            // 过滤 1. 重复词 2. 非utf-8编码（中英混合等）
            if(isset($words[$w]) || strlen($w)!=mb_strlen($w, 'utf-8')*3) {
                continue;
            }
            $w = trim($w);
            $wordLength = mb_strlen($w, 'utf-8') * 2;
            if($wordLength > $maxWordLength) {
                $maxWordLength = $wordLength;
            }
            $words[$w] = $r . ','. $a;
            ++$totalWords;
            $r = $r % 1000;
            $tfTotal += $r;
            if($maxTf === 0) {
                $maxTf = $r;
            } else if ($r>$maxTf) {
                $maxTf = $r;
            }
            if($minTf === 0) {
                $minTf = $r;
            } else if ($r<$minTf) {
                $minTf = $r;
            }
            $a            = trim($a);
            $w            = iconv('utf-8', UCS2, $w);
            $k            = $this->_get_index($w);
            $allk[$k][$w] = [$r, $a];
        }
        ksort($words);
        $lastw = '';
        foreach($words as $k => $v) {
            if ($lastw && strpos($k, $lastw) === 0) {
                $v .= ',xxxxx';
            }
            $lastw = $k;
            file_put_contents('newdict.txt', $k. ','. $v. "\n", FILE_APPEND);
        }
        echo '共有'.$totalWords.'个词, 平均词频: '.round($tfTotal/$totalWords, 2).', 最大词频: '.$maxTf.', 最小词频: '.$minTf.'，正在写入词典文件...' . PHP_EOL;
        fclose($fp);
        if ($target_file == $this->mainDictionaryFile && $this->mainDictionaryHandle) {
            $this->mainDictionaryHandle->close();
        }
        $fp = fopen($target_file, 'w');
        $buf = pack('a3CIIIIIII', XDB_TAGNAME, 34,
            $this->headerInfo['base'], $this->headerInfo['prime'], 0, $tfTotal, $totalWords, $maxWordLength, 0);

        fseek($fp, 0, SEEK_SET);
        fwrite($fp, $buf, 32);

        $heade_rarr = [];
        $alldat     = '';
        // 32为文件头尺寸
        $start_pos = $this->headerInfo['prime'] * 8 + 32;
        foreach ($allk as $k => $v) {
            $dat    = serialize($v);

            $dlen   = strlen($dat);
            $alldat .= $dat;

            $heade_rarr[$k][0] = $start_pos;
            $heade_rarr[$k][1] = $dlen;
            $heade_rarr[$k][2] = count($v);

            $start_pos += $dlen;
        }
        unset($allk);
        for ($i = 0; $i < $this->headerInfo['prime']; $i++) {
            if (!isset($heade_rarr[$i])) {
                $data = [0, 0, 0];
            } else {
                $data = $heade_rarr[$i];
            }
            fwrite($fp, pack("Inn", $data[0], $data[1], $data[2]));
        }
        fwrite($fp, $alldat);
        fclose($fp);
        if ($target_file == $this->mainDictionaryFile && $this->mainDictionaryHandle) {
            //重新加载主词典（只打开）
            $this->mainDictionaryHandle = fopen($this->mainDictionaryFile, 'r');
        }
    }

    /**
     * 导出词典的词条
     * @parem $targetfile 保存位置
     *
     * @return void
     */
    public function ExportDict($targetfile){
        $op = fopen($this->mainDictionaryFile, 'r');
        $fp = fopen($targetfile, 'w');
        for ($i = 0; $i <= $this->headerInfo['prime']; $i++) {
            $move_pos = $i * 8 + 32;
            fseek($op, $move_pos, SEEK_SET);
            $dat = fread($op, 8);
            $arr = unpack('I1s/n1l/n1c', $dat);
            if ($arr['l'] == 0) {
                continue;
            }
            fseek($op, $arr['s'], SEEK_SET);
            $data = @unserialize(fread($op, $arr['l']));
            if (!is_array($data)) {
                continue;
            }
            foreach ($data as $k => $v) {
                $w = iconv(UCS2, 'utf-8', $k);
                fwrite($fp, "{$w},{$v[0]},{$v[1]}\n");
            }
        }
        fclose($fp);
        return true;
    }
}