<?php
namespace App\Components\Segmentation;

/* ----------------------------------------------------------------------- *\
 PHP版简易中文分词第四版(PSCWS v4.0) - 分词核心类库代码
 -----------------------------------------------------------------------
 作者: 马明练(hightman) (MSN: MingL_Mar@msn.com) (php-QQ群: 17708754)
 网站: http://www.ftphp.com/scws/
 时间: 2007/05/20
 修订: 2008/12/20
 编辑: set number ; syntax on ; set autoindent ; set tabstop=4 (vim)
 -----------------------------------------------------------------------
 核心类的功能:

 这是 scws-1.0 (纯C实现) 的一个 PHP 实现方式, 算法和功能一样
 针对输入的字符串文本执行分词, 根据词典N-路径最大概率法分词.

 支持人名、地名、数字识别；能识别 .NET, C++, Q币 之类特殊词汇
 支持 UTF-8/GBK 编码, 特别为搜索引擎考量而支持长词再细分的复方分词法
 使用 UTF-8 可扩展到任何多字节语言分词(如日语，韩语等)

 用法(主要类方法, 与 scws 之 PHP 扩展版兼容用法):

 class PSCWS4 {
 void close(void);
 void set_charset(string charset);
 bool set_dict(string dict_path);
 void set_rule(string rule_path);
 void set_ignore(bool set);
 void set_multi(int level);
 void set_debug(bool set);
 void set_duality(bool set);

 void send_text(string text);
 mixed get_result(void);
 mixed get_tops( [int limit [, string attr]] );

 string version(void);
 };

 \* ----------------------------------------------------------------------- */

use App\Components\XTreeDB;

/**
 * 1. 规则集相关定义
 * 作用说明：
 * - : 用于标记特殊词汇（如C++、.NET等技术术语） `PSCWS4_RULE_SPECIAL`
 * - : 标记不需要统计的词汇（如英文停用词the、and等） `PSCWS4_RULE_NOSTATS`
 */
define('PSCWS4_RULE_MAX', 31);              // 规则最大数量限制 (PHP不支持无符号整数)
define('PSCWS4_RULE_SPECIAL', 0x80000000);  // 特殊词汇规则标记 (最高位标记)
define('PSCWS4_RULE_NOSTATS', 0x40000000);  // 不参与统计的词汇标记

/**
 * 2. 中文特殊规则定义
 * 作用说明：
 * - 用于人名识别、地名识别等特殊词汇的规则匹配
 * - 支持前缀匹配（识别姓氏）、后缀匹配（识别单位）等
 */
define('PSCWS4_ZRULE_NONE', 0x00);     // 无特殊规则
define('PSCWS4_ZRULE_PREFIX', 0x01);   // 前缀规则（如姓氏）
define('PSCWS4_ZRULE_SUFFIX', 0x02);   // 后缀规则（如单位词）
define('PSCWS4_ZRULE_INCLUDE', 0x04);  // 包含规则
define('PSCWS4_ZRULE_EXCLUDE', 0x08);  // 排除规则
define('PSCWS4_ZRULE_RANGE', 0x10);    // 范围规则（字数范围限制）

/**
 * 3. 分词模式设置 (≤ 0x800)
 * 作用说明：
 * - : 控制是否输出标点符号 `PSCWS4_IGN_SYMBOL`
 * - : 开启调试信息输出 `PSCWS4_DEBUG`
 * - : 启用二元分词（双向匹配） `PSCWS4_DUALITY`
 */
define('PSCWS4_IGN_SYMBOL', 0x01);  // 忽略符号标点
define('PSCWS4_DEBUG', 0x02);       // 调试模式
define('PSCWS4_DUALITY', 0x04);     // 二元分词模式

/**
 * 4. 多重分词策略 (>=0x1000)
 * 作用说明：
 * - 用于索引建立时的多重分词策略
 * - 例如"南京市长江大桥"可拆分为"南京"、"南京市"、"市长"、"长江"等多个词
 */
define('PSCWS4_MULTI_NONE', 0x0000);     // 不进行多重分词
define('PSCWS4_MULTI_SHORT', 0x1000);    // 将长词从左到右拆分为短词
define('PSCWS4_MULTI_DUALITY', 0x2000);  // 将长词(3字符以上)拆分为双字词
define('PSCWS4_MULTI_ZMAIN', 0x4000);    // 拆分为主要单字(词性限制为j|a|n?|v?)
define('PSCWS4_MULTI_ZALL', 0x8000);     // 拆分为所有单字(不限词性)
define('PSCWS4_MULTI_MASK', 0xf000);     // 多重分词检查掩码
define('PSCWS4_ZIS_USED', 0x8000000);    // 单字已使用标记

/**
 * 5. 单字节字符处理标志
 * 作用说明：
 * - 用于单字节字符（英文、数字）的处理过程中的状态标记
 * - 控制小数点、撇号等特殊符号的处理逻辑
 */
define('PSCWS4_PFLAG_WITH_MB', 0x01);  // 包含多字节字符
define('PSCWS4_PFLAG_ALNUM', 0x02);    // 字母数字字符
define('PSCWS4_PFLAG_VALID', 0x04);    // 有效字符
define('PSCWS4_PFLAG_DIGIT', 0x08);    // 数字字符
define('PSCWS4_PFLAG_ADDSYM', 0x10);   // 已添加符号(小数点或撇号)

/**
 * 6. 多字词状态标记
 * 作用说明：
 * - 用于词汇和单字在分词过程中的状态跟踪
 * - 支持人名识别、词汇组合等复杂语言处理
 */
define('PSCWS4_WORD_FULL', 0x01);  // 完整词汇
define('PSCWS4_WORD_PART', 0x02);  // 词汇片段/前缀
define('PSCWS4_WORD_USED', 0x04);  // 已使用标记
define('PSCWS4_WORD_RULE', 0x08);  // 规则自动识别的词汇

define('PSCWS4_ZFLAG_PUT', 0x02);      // 已输出标记
define('PSCWS4_ZFLAG_N2', 0x04);       // 双字名词头
define('PSCWS4_ZFLAG_NR2', 0x08);      // 双字人名头
define('PSCWS4_ZFLAG_WHEAD', 0x10);    // 词头字符
define('PSCWS4_ZFLAG_WPART', 0x20);    // 词尾或词中字符
define('PSCWS4_ZFLAG_ENGLISH', 0x40);  // 夹在中文中的英文
define('PSCWS4_ZFLAG_SYMBOL', 0x80);   // 符号字符

/**
 * 7. 长度限制定义
 * 作用说明：
 * - 防止异常长词导致的性能问题
 * - 控制分词处理的内存使用
 */
define('PSCWS4_MAX_EWLEN', 16);   // 英文词最大长度
define('PSCWS4_MAX_ZLEN', 128);   // 中文句子最大长度

/** 主类库代码 */
class PSCWS
{
    /**
     * XDB - 词典数据库对象
     *
     * @var XTreeDB
     */
    var $_dict;
    /**
     * 规则集相关属性
     * - $_rs: 规则集资源
     * - $_rd: 规则集数据
     * - $_cs: 字符集
     * - $_ztab: 字符长度表
     * - $_mode: 分词模式
     * - $_txt: 待分词文本
     * - $_res: 分词结果
     * - $_zis: 是否使用单字分词（双向匹配）
     */
    var $_rs;
    var $_rd;
    var $_cs           = '';
    var $_ztab;
    var $_mode         = 0;
    var $_txt;
    var $_res;
    var $_zis;
    var $_off          = 0;
    var $_len          = 0;
    var $_wend         = 0;
    var $_wmap;
    var $_zmap;
    var $_res_callback = '';
    var $_dict_cache   = []; // 缓存字典查询结果

    // 构造函数
    function __construct($charset = 'utf8'){
        $this->_dict = false;
        $this->_rs = $this->_rd = [];
        $this->set_charset($charset);

        $this->set_dict(__DIR__ . '/dict/dict.utf8.xdb');
        $this->set_rule(__DIR__ . '/dict/rules.utf8.ini');
    }

    // FOR PHP5
    //function __construct() { $this->PSCWS4(); }
    function __destruct(){
        $this->close();
    }

    // 设置字符集(ztab)
    function set_charset($charset = 'utf8'){
        $charset = strtolower(trim($charset));
        if ($charset !== $this->_cs) {
            $this->_cs = $charset;

            // 字符长度映射表 utf-8 & gbk(big5)
            // 0x00-0x7f: 1字节字符（ASCII字符（0x00-0x80）都是单字节字符）
            $this->_ztab = array_fill(0, 0x81, 1);
            if ($charset == 'utf-8' || $charset == 'utf8') {
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
            } else {
                // GBK & BIG5 字符集 2字节字符
                $this->_ztab = array_pad($this->_ztab, 0xff, 2);
            }
            $this->_ztab[] = 1;
        }
    }

    // 设置词典
    function set_dict($fpath){
        $xdb = new XTreeDB();
        if (!$xdb->Open($fpath)) {
            return false;
        }
        $this->_dict = $xdb;
    }

    /**
     * 设置规则集文件
     *
     * 规则集文件是中文分词引擎的核心配置文件，包含了：
     * 1. 特殊词汇规则（如C++、.NET等技术术语）
     * 2. 停用词规则（如英文the、and等）
     * 3. 人名识别规则（姓氏前缀、名字后缀等）
     * 4. 地名识别规则（地名前缀后缀等）
     * 5. 中文特殊规则（数字、英文混合等）
     *
     * 规则文件格式：
     * - [规则名称]：定义规则块
     * - :参数名=参数值：设置规则参数
     * - 词汇列表：每行一个词汇或字符
     *
     * @param string $fpath 规则集文件路径
     * @return bool|void 成功返回true，失败返回false
     */
    function set_rule($fpath){
        // 检查文件是否存在并可读
        if (!file_exists($fpath) || !is_readable($fpath)) {
            return false;
        }

        // 尝试打开规则文件
        if (!($fd = fopen($fpath, 'r'))) {
            return false;
        }

        // 初始化规则集数组
        $this->_rs = [];
        // 默认规则项模板，避免重复创建数组
        $defaultItem = [
            'tf' => 5.0,      // 词频权重
            'idf' => 3.5,     // 逆文档频率权重
            'attr' => 'un',   // 词性标记
            'bit' => 0,       // 规则位标记
            'flag' => 0,      // 规则标志
            'zmin' => 0,      // 最小字符数
            'zmax' => 0,      // 最大字符数
            'inc' => 0,       // 包含规则
            'exc' => 0        // 排除规则
        ];

        // 第一阶段：快速扫描，识别所有规则名称并建立规则项
        $ruleCount = 0;
        $customRuleIndex = 0;
        $lastRule = '';
        while ($buf = fgets($fd, 512)) {
            $buf = trim($buf);
            if (empty($buf)) {
                continue;
            }
            $ch = substr($buf, 0, 1);

            // 跳过注释行（以分号开头）
            if ($ch == ';') {
                continue;
            }

            // 处理规则块开始标记
            if ($ch == '[') {
                $rule = '';
                if(preg_match('/^\[([^\]]+)\]$/', $buf, $matches)){
                    $rule = $matches[1];
                }
                if(strlen($rule) < 1 || strlen($rule) > 15){
                    continue; // 跳过不符合长度要求的规则名称
                }

                // 提取规则名称（转为小写）
                $rule = strtolower($rule);
                if (isset($this->_rs[$rule])) {
                    continue;  // 跳过重复的规则名称
                }

                // 初始化规则项，包含默认权重和各种参数
                $item = $defaultItem;

                // 设置特殊规则的位标记
                switch ($rule) {
                    case 'special':
                        $item['bit'] = PSCWS4_RULE_SPECIAL;
                        break;
                    case 'nostats':
                        $item['bit'] = PSCWS4_RULE_NOSTATS;
                        break;
                    default:
                        $item['bit'] = (1 << $customRuleIndex);
                        $customRuleIndex++;
                        break;
                }

                $this->_rs[$rule] = $item;

                // 限制规则数量，防止内存溢出
                if (++$ruleCount >= PSCWS4_RULE_MAX) {
                    break;
                }
            }
        }

        // 第二阶段：重新读取文件，解析规则参数和词汇数据
        rewind($fd);
        $rbl = false;    // 是否按行读取标志
        unset($currentItem);    // 当前处理的规则项引用

        while ($buf = fgets($fd, 512)) {
            $ch = substr($buf, 0, 1);

            // 跳过注释行（以分号开头）
            if ($ch == ';') {
                continue;
            }

            // 处理规则块开始标记
            if ($ch == '[') {
                unset($currentItem);
                if (($pos = strpos($buf, ']')) > 1) {
                    $key = strtolower(substr($buf, 1, $pos - 1));
                    if (isset($this->_rs[$key])) {
                        $rbl = true;    // 默认按行读取
                        $currentItem = &$this->_rs[$key];  // 引用当前规则项
                    }
                }
                continue;
            }

            // 处理规则参数设置（以冒号开头）
            // 支持的参数：line、znum、include、exclude、type、tf、idf、attr
            if ($ch == ':') {
                $buf = substr($buf, 1);
                if (!($pos = strpos($buf, '='))) {
                    continue;
                }

                [$pkey, $pval] = explode('=', $buf, 2);
                $pkey = trim($pkey);
                $pval = trim($pval);
                switch($pkey) {
                    case 'line':
                        $rbl = (strtolower(substr($pval, 0, 1)) == 'n' ? false : true);
                        break;
                    case 'tf':
                        $currentItem['tf'] = floatval($pval);  // 词频权重
                        break;
                    case 'idf':
                        $currentItem['idf'] = floatval($pval);  // 逆文档频率权重
                        break;
                    case 'attr':
                        $currentItem['attr'] = $pval;  // 词性标记
                        break;
                    case 'znum':
                        // 设置字符数范围：znum=min,max 或 znum=min
                        if ($pos = strpos($pval, ',')) {
                            $currentItem['zmax'] = intval(trim(substr($pval, $pos + 1)));
                            $currentItem['flag'] |= PSCWS4_ZRULE_RANGE;
                            $pval = substr($pval, 0, $pos);
                        }
                        $currentItem['zmin'] = intval($pval);
                        break;
                    case 'type':
                        // 设置匹配类型：prefix（前缀）或suffix（后缀）
                        if ($pval == 'prefix') {
                            $currentItem['flag'] |= PSCWS4_ZRULE_PREFIX;
                        }
                        if ($pval == 'suffix') {
                            $currentItem['flag'] |= PSCWS4_ZRULE_SUFFIX;
                        }
                        break;
                    case 'include':
                    case 'exclude':
                        // 处理包含/排除规则：include=rule1,rule2,rule3
                        $clude = 0;
                        foreach (explode(',', $pval) as $tmp) {
                            $tmp = strtolower(trim($tmp));
                            if (!isset($this->_rs[$tmp])) {
                                continue;
                            }
                            $clude |= $this->_rs[$tmp]['bit'];
                        }
                        if ($pkey == 'include') {
                            $currentItem['inc'] |= $clude;
                            $currentItem['flag'] |= PSCWS4_ZRULE_INCLUDE;
                        } else {
                            $currentItem['exc'] |= $clude;
                            $currentItem['flag'] |= PSCWS4_ZRULE_EXCLUDE;
                        }
                        break;

                }
                continue;
            }

            // 处理规则词汇数据
            if (!isset($currentItem)) {
                continue;
            }

            $buf = trim($buf);
            if (empty($buf)) {
                continue;
            }

            // 根据读取模式存储规则数据
            if ($rbl) {
                // 按行读取：整行作为一个词汇
                $this->_rd[$buf] = &$currentItem;
            } else {
                // 按字符读取：逐个字符存储（用于中文字符处理）
                $len = strlen($buf);
                for ($off = 0; $off < $len;) {
                    $ord = ord(substr($buf, $off, 1));
                    $zlen = $this->_ztab[$ord];  // 获取字符字节长度

                    if ($off + $zlen >= $len) {
                        break;
                    }

                    $zch = substr($buf, $off, $zlen);
                    $this->_rd[$zch] = &$currentItem;
                    $off += $zlen;
                }
            }
        }
        // 关闭文件句柄
        fclose($fd);
    }

    // 设置忽略符号与无用字符
    function set_ignore($yes){
        if ($yes === true) {
            $this->_mode |= PSCWS4_IGN_SYMBOL;
        } else {
            $this->_mode &= ~PSCWS4_IGN_SYMBOL;
        }
    }

    // 设置复合分词等级 ($level = 0,15)
    function set_multi($level){
        $level = (intval($level) << 12);

        $this->_mode &= ~PSCWS4_MULTI_MASK;
        if ($level & PSCWS4_MULTI_MASK) {
            $this->_mode |= $level;
        }
    }

    // 设置是否显示分词调试信息
    function set_debug($yes){
        if ($yes == true) {
            $this->_mode |= PSCWS4_DEBUG;
        } else {
            $this->_mode &= ~PSCWS4_DEBUG;
        }
    }

    // 设置是否自动将散字二元化
    function set_duality($yes){
        if ($yes == true) {
            $this->_mode |= PSCWS4_DUALITY;
        } else {
            $this->_mode &= ~PSCWS4_DUALITY;
        }
    }

    // 设置要分词的文本字符串
    function send_text($text){
        $this->_txt = (string)$text;
        $this->_len = strlen($this->_txt);
        $this->_off = 0;
    }

    // 取回一批分词结果(需要多次调用, 直到返回 false)
    function get_result($cb = ''){
        // 初始化结果回调函数
        $this->_res_callback = '';
        if ($cb && function_exists($cb)) {
            $this->_res_callback = $cb;
        }

        // 获取当前处理位置和文本信息
        $off        = $this->_off;    // 当前偏移位置
        $len        = $this->_len;    // 文本总长度
        $txt        = $this->_txt;    // 待分词文本
        $this->_res = [];             // 初始化结果数组

        // 跳过空白字符（ASCII <= 0x20的字符，如空格、制表符等）
        while (($off < $len) && (ord($txt[$off]) <= 0x20)) {
            // 遇到换行符时，标记为一个分词单位并返回
            if ($txt[$off] == "\r" || $txt[$off] == "\n") {
                $this->_off = $off + 1;
                $this->_put_res($off, 0, 1, 'un'); // 'un' 表示未知类型
                return $this->_res;
            }
            $off++;
        }

        // 如果已到文本末尾，返回 false 表示分词结束
        if ($off >= $len) {
            return false;
        }

        // 开始解析句子
        $this->_off = $off;
        $ch         = $txt[$off];     // 当前字符
        $cx         = ord($ch);       // 当前字符的ASCII值

        // 检查是否为特殊标记字符（如括号、引号等）
        if ($this->_char_token($ch)) {
            $this->_off++;
            $this->_put_res($off, 0, 1, 'un');
            return $this->_res;
        }

        // 确定当前字符的字节长度（用于处理多字节字符如中文）
        $clen = $this->_ztab[$cx];    // 从字符表中获取字符字节长度
        $zlen = 1;                    // 字符数量计数

        // 设置处理标志：多字节字符 | 字母数字字符
        $pflag = ($clen > 1 ? PSCWS4_PFLAG_WITH_MB : ($this->_is_alnum($cx) ? PSCWS4_PFLAG_ALNUM : 0));

        // 向前扫描，寻找完整的词语边界
        while (($off = ($off + $clen)) < $len) {
            $ch = $txt[$off];
            $cx = ord($ch);

            // 遇到空白字符或特殊标记时停止
            if ($cx <= 0x20 || $this->_char_token($ch)) {
                break;
            }

            $clen = $this->_ztab[$cx];

            // 处理纯单字节字符的情况
            if (!($pflag & PSCWS4_PFLAG_WITH_MB)) {
                if ($clen == 1) {
                    // 单字节字符：如果之前是字母数字，现在不是，则取消标志
                    if (($pflag & PSCWS4_PFLAG_ALNUM) && !$this->_is_alnum($cx)) {
                        $pflag ^= PSCWS4_PFLAG_ALNUM;
                    }
                } else {
                    // 单字节转多字节：检查是否允许混合
                    if (!($pflag & PSCWS4_PFLAG_ALNUM) || $zlen > 2) {
                        break;
                    }
                    $pflag |= PSCWS4_PFLAG_WITH_MB;
                }
            } // 处理多字节 + 单字节的混合情况（如：中文 + 英文）
            else {
                if (($pflag & PSCWS4_PFLAG_WITH_MB) && $clen == 1) {
                    // 只允许字母数字与中文混合
                    if (!$this->_is_alnum($cx)) {
                        break;
                    }

                    // 向前预读，验证后续字符的有效性
                    $pflag &= ~PSCWS4_PFLAG_VALID;
                    for ($i = $off + 1; $i < ($off + 3); $i++) {
                        $ch = $txt[$i];
                        $cx = ord($ch);
                        if (($i >= $len) || ($cx <= 0x20) || ($this->_ztab[$cx] > 1)) {
                            $pflag |= PSCWS4_PFLAG_VALID;
                            break;
                        }
                        if (!$this->_is_alnum($cx)) {
                            break;
                        }
                    }

                    if (!($pflag & PSCWS4_PFLAG_VALID)) {
                        break;
                    }
                    $clen += ($i - $off - 1);
                }
            }

            // 限制最大字符长度，防止过长的词语
            if (++$zlen >= PSCWS4_MAX_ZLEN) {
                break;
            }
        }

        // 处理半个字符的问题（防止多字节字符被截断）
        if (($ch = $off) > $len) {
            $off -= $clen;
        }

        // 执行实际的分词处理
        if ($off <= $this->_off) {
            return false;
        } else {
            if ($pflag & PSCWS4_PFLAG_WITH_MB) {
                $this->_msegment($off, $zlen);
            }      // 多字节字符分词（主要是中文）
            else {
                if (!($pflag & PSCWS4_PFLAG_ALNUM) || (($off - $this->_off) >= PSCWS4_MAX_EWLEN)) {
                    $this->_ssegment($off);
                }             // 单字节字符分词
                else {
                    // 处理英文单词
                    $zlen = $off - $this->_off;
                    $this->_put_res($this->_off, 2.5 * log($zlen), $zlen, 'en');
                }
            }
        }

        // 更新处理位置并返回结果
        $this->_off = ($ch > $len ? $len : $off);

        // 如果本次没有分词结果，递归调用继续处理
        if (is_array($this->_res) && count($this->_res) == 0) {
            return $this->get_result();
        }

        return $this->_res;
    }

    // 取回频率和权重综合最大的前 N 个词
    function get_tops($limit = 10, $xattr = ''){
        $ret = [];
        if (!$this->_txt) {
            return false;
        }

        $xmode = false;
        $attrs = [];
        if ($xattr != '') {
            if (substr($xattr, 0, 1) == '~') {
                $xattr = substr($xattr, 1);
                $xmode = true;
            }
            foreach (explode(',', $xattr) as $tmp) {
                $tmp = strtolower(trim($tmp));
                if (!empty($tmp)) {
                    $attrs[$tmp] = true;
                }
            }
        }

        // save the old offset
        $off        = $this->_off;
        $this->_off = $cnt = 0;
        $list       = [];

        while ($tmpa = $this->get_result()) {
            foreach ($tmpa as $tmp) {
                if ($tmp['idf'] < 0.2 || substr($tmp['attr'], 0, 1) == '#') {
                    continue;
                }

                // check attr filter
                if (count($attrs) > 0) {
                    if ($xmode && !isset($attrs[$tmp['attr']])) {
                        continue;
                    }
                    if (!$xmode && isset($attrs[$tmp['attr']])) {
                        continue;
                    }
                }

                // check stopwords
                $word = strtolower($tmp['word']);
                if ($this->_rule_checkbit($word, PSCWS4_RULE_NOSTATS)) {
                    continue;
                }

                // put to list
                if (isset($list[$word])) {
                    $list[$word]['weight'] += $tmp['idf'];
                    $list[$word]['times']++;
                } else {
                    $list[$word] = ['word' => $tmp['word'], 'times' => 1, 'weight' => $tmp['idf'], 'attr' => $tmp['attr']];
                }
            }
        }

        // restore the offset
        $this->_off = $off;

        // sort it & return
        if (function_exists('create_function')) {
            $cmp_func = create_function('$a,$b', 'return ($b[\'weight\'] > $a[\'weight\'] ? 1 : -1);');
            usort($list, $cmp_func);
        } else {
            usort($list, function ($a, $b){
                return ($b['weight'] > $a['weight'] ? 1 : -1);
            });
        }
        if (count($list) > $limit) {
            $list = array_slice($list, 0, $limit);
        }

        return $list;
    }

    // 关闭释放资源
    function close(){
        // free the dict
        if ($this->_dict) {
            $this->_dict->Close();
            $this->_dict = false;
        }

        // free the ruleset
        $this->_rd = [];
        $this->_rs = [];
    }

    // 版本
    function version(){
        return 'PSCWS/4.0 - by hightman';
    }

    ////////////////////////////////////////////
    // these are all private functions
    ////////////////////////////////////////////

    // get the ruleset
    private function _rule_get($str){
        if (!isset($this->_rd[$str])) {
            return false;
        }
        return $this->_rd[$str];
    }

    // check the bit with str
    private function _rule_checkbit($str, $bit){
        if (!isset($this->_rd[$str])) {
            return false;
        }
        $bit2 = $this->_rd[$str]['bit'];
        return (bool)($bit & $bit2);
    }

    // check the rule include | exclude
    private function _rule_check($rule, $str){
        if (($rule['flag'] & PSCWS4_ZRULE_INCLUDE) && !$this->_rule_checkbit($str, $rule['bit'])) {
            return false;
        }
        if (($rule['flag'] & PSCWS4_ZRULE_EXCLUDE) && $this->_rule_checkbit($str, $rule['bit'])) {
            return false;
        }
        return true;
    }

    // bulid res
    private function _put_res($o, $i, $l, $a){
        $word                = substr($this->_txt, $o, $l);
        if ($this->_res_callback) {
            // echo $word;
            if (is_array($this->_res) && $this->_res) {
                $tmp   = array_column($this->_res, 'word');
                $tmp[] = $word;
            } else {
                $tmp = [$word];
            }
            call_user_func($this->_res_callback, $tmp);
            $this->_res = true;
        } else {
            $this->_res[] = ['word' => $word, 'off' => $o, 'idf' => $i, 'len' => $l, 'attr' => $a];
        }
    }

    // alpha, numeric check by ORD value
    private function _is_alnum($c){
        return (($c >= 48 && $c <= 57) || ($c >= 65 && $c <= 90) || ($c >= 97 && $c <= 122));
    }

    private function _is_alpha($c){
        return (($c >= 65 && $c <= 90) || ($c >= 97 && $c <= 122));
    }

    private function _is_ualpha($c){
        return ($c >= 65 && $c <= 90);
    }

    private function _is_digit($c){
        return ($c >= 48 && $c <= 57);
    }

    private function _no_rule1($f){
        return (($f & (PSCWS4_ZFLAG_SYMBOL | PSCWS4_ZFLAG_ENGLISH)) || (($f & (PSCWS4_ZFLAG_WHEAD | PSCWS4_ZFLAG_NR2)) == PSCWS4_ZFLAG_WHEAD));
    }

    private function _no_rule2($f){
        // return (($f & PSCWS4_ZFLAG_ENGLISH) || (($f & (PSCWS4_ZFLAG_WHEAD|PSCWS4_ZFLAG_N2)) == PSCWS4_ZFLAG_WHEAD));
        return $this->_no_rule1($f);
    }

    private function _char_token($c){
        return ($c == '(' || $c == ')' || $c == '[' || $c == ']' || $c == '{' || $c == '}' || $c == ':' || $c == '"');
    }

    // query the dict
    public function _dict_query($word){
        if (!$this->_dict) {
            return false;
        }
        $value = false;
        if (!isset($this->_dict_cache[$word])) {
            $value = $this->_dict->Get($word);
            if ($value) {
                $value = unpack('ftf/fidf/Cflag/a3attr', $value);
                $value['attr'] = trim($value['attr']);
            }
            $this->_dict_cache[$word] = $value; // 缓存查询结果
        }
        if (!$value) {
            return false;
        }
        return $this->_dict_cache[$word];
    }

    /**
     * 单字节字符串分词方法
     *
     * 该方法专门处理单字节字符串（主要是英文、数字、符号），
     * 包括特殊词汇识别、缩写词识别、英文单词和数字的分割等功能。
     *
     * 处理流程：
     * 1. 检查特殊词汇（如 .NET、C++ 等）
     * 2. 检查英文缩写词（如 S.H.E、M.R. 等）
     * 3. 逐字符分析，识别连续的字母或数字
     * 4. 处理标点符号
     *
     * @param int $end 文本结束位置
     *
     * @return void
     */
    private function _ssegment($end){
        $start = $this->_off;            // 当前处理的起始位置
        $wlen  = $end - $start;          // 待处理文本的长度

        // 第一步：检查特殊词汇（如 .NET、C++、Q币 等）
        // 只有长度大于1的字符串才需要检查特殊词汇
        if ($wlen > 1) {
            // 转换为大写进行匹配（特殊词汇通常不区分大小写）
            $txt = strtoupper(substr($this->_txt, $start, $wlen));

            // 检查是否为预定义的特殊词汇
            if ($this->_rule_checkbit($txt, PSCWS4_RULE_SPECIAL)) {
                // 如果是特殊词汇，直接作为一个整体输出
                // idf=9.5 表示高权重，attr='nz' 表示其他专名
                $this->_put_res($start, 9.5, $wlen, 'nz');
                return;
            }
        }

        $txt = $this->_txt;             // 获取原始文本引用

        // 第二步：检查英文缩写词（如 S.H.E、M.R. 等）
        // 缩写词的模式：大写字母 + 点号 + 大写字母 + 点号 + ...
        if ($this->_is_ualpha(ord($txt[$start])) && $txt[$start + 1] == '.') {
            // 从第三个字符开始检查缩写词模式
            for ($ch = $start + 2; $ch < $end; $ch++) {
                // 检查是否为大写字母
                if (!$this->_is_ualpha(ord($txt[$ch]))) {
                    break;
                }
                $ch++;  // 移动到下一个字符

                // 检查是否为点号，如果到达末尾或不是点号则跳出
                if ($ch == $end || $txt[$ch] != '.') {
                    break;
                }
            }

            // 如果成功匹配到缩写词模式
            if ($ch == $end) {
                // 作为专名处理，idf=7.5 表示较高权重
                $this->_put_res($start, 7.5, $wlen, 'nz');
                return;
            }
        }

        // 第三步：逐字符分析，处理英文单词、数字和标点符号
        // 识别连续的字母或数字，允许特定的连接符
        while ($start < $end) {
            $ch = $txt[$start++];       // 获取当前字符并移动指针
            $cx = ord($ch);             // 获取字符的ASCII码

            // 处理字母数字字符
            if ($this->_is_alnum($cx)) {
                // 判断是数字还是字母，设置处理标志
                $pflag = $this->_is_digit($cx) ? PSCWS4_PFLAG_DIGIT : 0;
                $wlen  = 1;              // 当前词的长度

                // 向前扫描，收集连续的字母或数字
                while ($start < $end) {
                    $ch = $txt[$start];
                    $cx = ord($ch);

                    // 处理数字序列
                    if ($pflag & PSCWS4_PFLAG_DIGIT) {
                        if (!$this->_is_digit($cx)) {
                            // 如果不是数字，检查是否为小数点
                            // 条件：1）还没有添加过符号 2）当前字符是点号 3）下一个字符是数字
                            if (($pflag & PSCWS4_PFLAG_ADDSYM) ||
                                $cx != 0x2e ||  // 0x2e是点号'.'的ASCII码
                                !$this->_is_digit(ord($txt[$start + 1]))) {
                                break;  // 不符合条件，结束数字序列
                            }
                            $pflag |= PSCWS4_PFLAG_ADDSYM;  // 标记已添加符号
                        }
                    } // 处理字母序列
                    else {
                        if (!$this->_is_alpha($cx)) {
                            // 如果不是字母，检查是否为撇号（用于英文缩写，如 don't）
                            // 条件：1）还没有添加过符号 2）当前字符是撇号 3）下一个字符是字母
                            if (($pflag & PSCWS4_PFLAG_ADDSYM) ||
                                $cx != 0x27 ||  // 0x27是撇号'\''的ASCII码
                                !$this->_is_alpha(ord($txt[$start + 1]))) {
                                break;  // 不符合条件，结束字母序列
                            }
                            $pflag |= PSCWS4_PFLAG_ADDSYM;  // 标记已添加符号
                        }
                    }

                    $start++;               // 移动到下一个字符

                    // 增加词长度，但不能超过最大英文词长度限制
                    if (++$wlen >= PSCWS4_MAX_EWLEN) {
                        break;
                    }
                }

                // 将识别出的英文单词或数字作为一个词输出
                // 权重计算：2.5 * log(词长度)，词越长权重越高
                // attr='en' 表示英文词汇
                $this->_put_res($start - $wlen, 2.5 * log($wlen), $wlen, 'en');
            } // 处理标点符号和其他字符
            else {
                if (!($this->_mode & PSCWS4_IGN_SYMBOL)) {
                    // 如果没有设置忽略符号标志，则将符号作为单独的词输出
                    // idf=0 表示最低权重，attr='un' 表示未知类型
                    $this->_put_res($start - 1, 0, 1, 'un');
                }
            }
            // 如果设置了忽略符号标志，则跳过符号不处理
        }
    }

    // get one z by ZMAP
    private function _get_zs($i, $j = -1){
        if ($j == -1) {
            $j = $i;
        }
        return substr($this->_txt, $this->_zmap[$i]['start'], $this->_zmap[$j]['end'] - $this->_zmap[$i]['start']);
    }

    // mget_word
    private function _mget_word($i, $j){
        $wmap = $this->_wmap;

        if (!($wmap[$i][$i]['flag'] & PSCWS4_ZFLAG_WHEAD)) {
            return $i;
        }
        for ($r = $i, $k = $i + 1; $k <= $j; $k++) {
            if ($wmap[$i][$k] && ($wmap[$i][$k]['flag'] & PSCWS4_WORD_FULL)) {
                $r = $k;
            }
        }
        return $r;
    }

    // mset_word
    private function _mset_word($i, $j){
        $wmap = $this->_wmap;
        $zmap = $this->_zmap;
        $item = $wmap[$i][$j];

        // hightman.070705: 加入 item == null 判断, 防止超长词(255字以上)unsigned char溢出
        if (($item == false) || (($this->_mode & PSCWS4_IGN_SYMBOL)
                                 && !($item['flag'] & PSCWS4_ZFLAG_ENGLISH) && $item['attr'] == 'un')) {
            return;
        }

        // hightman.070701: 散字自动二元聚合
        if ($this->_mode & PSCWS4_DUALITY) {
            $k = $this->_zis;
            if ($i == $j && !($item['flag'] & PSCWS4_ZFLAG_ENGLISH) && $item['attr'] == 'un') {
                $this->_zis = $i;
                if ($k < 0) {
                    return;
                }

                $i = ($k & ~PSCWS4_ZIS_USED);
                if (($i != ($j - 1)) || (!($k & PSCWS4_ZIS_USED) && $this->_wend == $i)) {
                    $this->_put_res($zmap[$i]['start'], $wmap[$i][$i]['idf'], $zmap[$i]['end'] - $zmap[$i]['start'], $wmap[$i][$i]['attr']);
                    if ($i != ($j - 1)) {
                        return;
                    }
                }
                $this->_zis |= PSCWS4_ZIS_USED;
            } else {
                if (($k >= 0) && (!($k & PSCWS4_ZIS_USED) || ($j > $i))) {
                    $k &= ~PSCWS4_ZIS_USED;
                    $this->_put_res($zmap[$k]['start'], $wmap[$k][$k]['idf'], $zmap[$k]['end'] - $zmap[$k]['start'], $wmap[$k][$k]['attr']);
                }
                if ($j > $i) {
                    $this->_wend = $j + 1;
                }
                $this->_zis = -1;
            }
        }

        // save the res
        $this->_put_res($zmap[$i]['start'], $item['idf'], $zmap[$j]['end'] - $zmap[$i]['start'], $item['attr']);

        // hightman.070902: multi segment
        // step1: split to short words
        if (($j - $i) > 1) {
            $m = $i;
            if ($this->_mode & PSCWS4_MULTI_SHORT) {
                while ($m < $j) {
                    $k = $m;
                    for ($n = $m + 1; $n <= $j; $n++) {
                        if ($n == $j && $m == $i) {
                            break;
                        }
                        $item = $wmap[$m][$n];
                        if ($item && ($item['flag'] & PSCWS4_WORD_FULL)) {
                            $k = $n;
                            $this->_put_res($zmap[$m]['start'], $item['idf'], $zmap[$n]['end'] - $zmap[$m]['start'], $item['attr']);
                            if (!($item['flag'] & PSCWS4_WORD_PART)) {
                                break;
                            }
                        }
                    }
                    if ($k == $m) {
                        if ($m == $i) {
                            break;
                        }
                        $item = $wmap[$m][$m];
                        $this->_put_res($zmap[$m]['start'], $item['idf'], $zmap[$m]['end'] - $zmap[$m]['start'], $item['attr']);
                    }
                    if (($m = ($k + 1)) == $j) {
                        $m--;
                        break;
                    }
                }
            }
            if ($this->_mode & PSCWS4_MULTI_DUALITY) {
                while ($m < $j) {
                    $this->_put_res($zmap[$m]['start'], $wmap[$m][$m]['idf'], $zmap[$m + 1]['end'] - $zmap[$m]['start'], $wmap[$m][$m]['attr']);
                    $m++;
                }
            }
        }

        // step2, split to single char
        if (($j > $i) && ($this->_mode & (PSCWS4_MULTI_ZMAIN | PSCWS4_MULTI_ZALL))) {
            if (($j - $i) == 1 && !$wmap[$i][$j]) {
                if ($wmap[$i][$i]['flag'] & PSCWS4_ZFLAG_PUT) {
                    $i++;
                } else {
                    $wmap[$i][$i]['flag'] |= PSCWS4_ZFLAG_PUT;
                }
                $wmap[$j][$j]['flag'] |= PSCWS4_ZFLAG_PUT;
            }
            do {
                if ($wmap[$i][$i]['flag'] & PSCWS4_ZFLAG_PUT) {
                    continue;
                }
                if (!($this->_mode & PSCWS4_MULTI_ZALL) && !strchr("jnv", substr($wmap[$i][$i]['attr'], 0, 1))) {
                    continue;
                }
                $this->_put_res($zmap[$i]['start'], $wmap[$i][$i]['idf'], $zmap[$i]['end'] - $zmap[$i]['start'], $wmap[$i][$i]['attr']);
            } while (++$i <= $j);
        }
    }

    // mseg_zone
    private function _mseg_zone($f, $t){
        $weight = $nweight = 0.0;
        $wmap   = &$this->_wmap;
        $zmap   = $this->_zmap;
        $mpath  = $npath = false;

        for ($x = $i = $f; $i <= $t; $i++) {
            $j = $this->_mget_word($i, $t);
            if ($j == $i || $j <= $x || (/* $i > $x && */ ($wmap[$i][$j]['flag'] & PSCWS4_WORD_USED))) {
                continue;
            }

            // one word only
            if ($i == $f && $j == $t) {
                $mpath = [$j - $i, 0xff];
                break;
            }
            if ($i != $f && ($wmap[$i][$j]['flag'] & PSCWS4_WORD_RULE)) {
                continue;
            }

            // create the new path
            $wmap[$i][$j]['flag'] |= PSCWS4_WORD_USED;
            $nweight              = $wmap[$i][$j]['tf'] * ($j - $i + 1);
            if ($i == $f) {
                $nweight *= 1.2;
            } else {
                if ($j == $t) {
                    $nweight *= 1.4;
                }
            }

            // create the npath
            if ($npath == false) {
                $npath = array_fill(0, $t - $f + 2, 0xff);
            }

            // lookfor backward
            $x = 0;
            for ($m = $f; $m < $i; $m = $n + 1) {
                $n           = $this->_mget_word($m, $i - 1);
                $nweight     *= $wmap[$m][$n]['tf'] * ($n - $m + 1);
                $npath[$x++] = $n - $m;
                if ($n > $m) {
                    $wmap[$m][$n]['flag'] |= PSCWS4_WORD_USED;
                }
            }

            // my self
            $npath[$x++] = $j - $i;

            // lookfor forward
            for ($m = $j + 1; $m <= $t; $m = $n + 1) {
                $n           = $this->_mget_word($m, $t);
                $nweight     *= $wmap[$m][$n]['tf'] * ($n - $m + 1);
                $npath[$x++] = $n - $m;
                if ($n > $m) {
                    $wmap[$m][$n]['flag'] |= PSCWS4_WORD_USED;
                }
            }

            $npath[$x] = 0xff;
            $nweight   /= pow($x - 1, 4);

            // draw the path for debug
            if ($this->_mode & PSCWS4_DEBUG) {
                printf("PATH by keyword = %s, (weight=%.4f):\n", $this->_get_zs($i, $j), $nweight);
                for ($x = 0, $m = $f; ($n = $npath[$x]) != 0xff; $x++) {
                    $n += $m;
                    echo $this->_get_zs($m, $n) . " ";
                    $m = $n + 1;
                }
                echo "\n--\n";
            }

            $x = $j;

            // check better path
            if ($nweight > $weight) {
                $weight = $nweight;
                $swap   = $mpath;
                $mpath  = $npath;
                $npath  = $swap;
                unset($swap);
            }
        }

        // set the result, mpath != NULL
        if ($mpath == false) {
            return;
        }
        for ($x = 0, $m = $f; ($n = $mpath[$x]) != 0xff; $x++) {
            $n += $m;
            $this->_mset_word($m, $n);
            $m = $n + 1;
        }
    }

    /**
     * 多字节字符串分词的核心方法
     *
     * 该方法实现了中文分词的核心算法，采用N-路径最大概率法进行分词
     * 主要处理流程：
     * 1. 初始化词图和字符映射表
     * 2. 构建字符位置映射(zmap)和词语映射(wmap)
     * 3. 创建词语查询表，查找所有可能的词语组合
     * 4. 应用规则集进行人名、地名、数字等特殊词汇识别
     * 5. 执行实际的分词操作
     *
     * @param int $end  文本结束位置
     * @param int $zlen 字符长度
     *
     * @return void
     */
    private function _msegment($end, $zlen){
        // 初始化词图映射表和字符映射表
        // _wmap: 词语映射表，二维数组，存储从位置i到位置j的词语信息
        // _zmap: 字符映射表，存储每个字符的开始和结束位置
        $this->_wmap = array_fill(0, $zlen, array_fill(0, $zlen, false));
        $this->_zmap = array_fill(0, $zlen, false);
        $wmap        = &$this->_wmap;  // 引用赋值，提高性能
        $zmap        = &$this->_zmap;
        $txt         = $this->_txt;     // 待分词的文本
        $start       = $this->_off;     // 当前处理起始位置
        $this->_zis  = -1;              // 用于双字模式的字符索引

        // 第一阶段：构建字符位置映射(zmap)
        // 遍历文本，识别每个字符的类型和位置
        for ($i = 0; $start < $end; $i++) {
            $ch   = $txt[$start];      // 当前字符
            $cx   = ord($ch);          // 字符的ASCII码
            $clen = $this->_ztab[$cx]; // 字符的字节长度（中文2字节，英文1字节）

            // 处理单字节字符（英文、数字、标点）
            if ($clen == 1) {
                // 连续处理单字节字符，直到遇到多字节字符
                while ($start++ < $end) {
                    $cx = ord($txt[$start]);
                    if ($this->_ztab[$cx] > 1) {
                        break;
                    }  // 遇到多字节字符时跳出
                    $clen++;
                }
                // 将连续的英文字符作为一个单元处理
                $wmap[$i][$i] = ['tf' => 0.5, 'idf' => 0, 'flag' => PSCWS4_ZFLAG_ENGLISH, 'attr' => 'un'];
            } else {
                // 处理多字节字符（中文字符）
                // 查询词典，获取字符的词性和权重信息
                $query = $this->_dict_query(substr($txt, $start, $clen));
                if (!$query) {
                    // 词典中未找到该字符，设置默认值
                    $wmap[$i][$i] = ['tf' => 0.5, 'idf' => 0, 'flag' => 0, 'attr' => 'un'];
                } else {
                    // 如果词性以'#'开头，标记为符号
                    if (substr($query['attr'], 0, 1) == '#') {
                        $query['flag'] |= PSCWS4_ZFLAG_SYMBOL;
                    }
                    $wmap[$i][$i] = $query;
                }
                $start += $clen;
            }

            // 记录字符的位置信息
            $zmap[$i] = ['start' => $start - $clen, 'end' => $start];
        }

        // 修正实际的字符长度
        $zlen = $i;

        // 第二阶段：创建词语查询表
        // 查找所有可能的词语组合（从单字到多字词语）
        for ($i = 0; $i < $zlen; $i++) {
            $k = 0;
            for ($j = $i + 1; $j < $zlen; $j++) {
                // 获取从位置i到位置j的字符串
                $query = $this->_dict_query($this->_get_zs($i, $j));
                if (!$query) {
                    break;
                }  // 词典中未找到，结束当前循环

                $ch = $query['flag'];
                // 如果是完整词语
                if ($ch & PSCWS4_WORD_FULL) {
                    $wmap[$i][$j]         = $query;
                    $wmap[$i][$i]['flag'] |= PSCWS4_ZFLAG_WHEAD;  // 标记为词头

                    // 标记中间字符为词的组成部分
                    for ($k = $i + 1; $k <= $j; $k++) {
                        $wmap[$k][$k]['flag'] |= PSCWS4_ZFLAG_WPART;
                    }
                }
                // 如果不是词的部分，停止查找
                if (!($ch & PSCWS4_WORD_PART)) {
                    break;
                }
            }

            // 处理双字人名识别
            if ($k--) {
                // 对于双字词语，如果是人名，设置特殊标记
                if ($k == ($i + 1)) {
                    if ($wmap[$i][$k]['attr'] == 'nr') {
                        $wmap[$i][$i]['flag'] |= PSCWS4_ZFLAG_NR2;
                    }
                }

                // 清除最后一个词的PART标志
                if ($k < $j) {
                    $wmap[$i][$k]['flag'] ^= PSCWS4_WORD_PART;
                }
            }
        }

        // 第三阶段：应用规则集匹配
        // 进行人名、地名、数字等特殊词汇的识别
        if (count($this->_rd) > 0) {
            // 单字规则匹配
            for ($i = 0; $i < $zlen; $i++) {
                if ($this->_no_rule1($wmap[$i][$i]['flag'])) {
                    continue;
                }

                // 获取当前字符的规则
                $r1 = $this->_rule_get($this->_get_zs($i));
                if (!$r1) {
                    continue;
                }

                $clen = max(1, $r1['zmin']);

                // 处理前缀规则（如姓氏+名字）
                if (($r1['flag'] & PSCWS4_ZRULE_PREFIX) && ($i < ($zlen - $clen))) {
                    // 检查最小字符数范围内是否符合规则
                    for ($ch = 1; $ch <= $clen; $ch++) {
                        $j = $i + $ch;
                        if ($j >= $zlen || $this->_no_rule2($wmap[$j][$j]['flag'])) {
                            break;
                        }
                        if (!$this->_rule_check($r1, $this->_get_zs($j))) {
                            break;
                        }
                    }
                    if ($ch <= $clen) {
                        continue;
                    }

                    // 在最大字符数范围内继续匹配
                    $j = $i + $ch;
                    while (true) {
                        if ((!$r1['zmax'] && $r1['zmin']) || ($r1['zmax'] && ($clen >= $r1['zmax']))) {
                            break;
                        }
                        if ($j >= $zlen || $this->_no_rule2($wmap[$j][$j]['flag'])) {
                            break;
                        }
                        if (!$this->_rule_check($r1, $this->_get_zs($j))) {
                            break;
                        }
                        $clen++;
                        $j++;
                    }

                    // 处理双字人名的特殊情况
                    if ($wmap[$i][$i]['flag'] & PSCWS4_ZFLAG_NR2) {
                        if ($clen == 1) {
                            continue;
                        }
                        $wmap[$i][$i + 1]['flag'] |= PSCWS4_WORD_PART;
                    }

                    // 创建规则匹配的词语
                    $k                    = $i + $clen;
                    $wmap[$i][$k]         = ['tf' => $r1['tf'], 'idf' => $r1['idf'], 'flag' => (PSCWS4_WORD_RULE | PSCWS4_WORD_FULL), 'attr' => $r1['attr']];
                    $wmap[$i][$i]['flag'] |= PSCWS4_ZFLAG_WHEAD;
                    for ($j = $i + 1; $j <= $k; $j++) {
                        $wmap[$j][$j]['flag'] |= PSCWS4_ZFLAG_WPART;
                    }

                    if (!($wmap[$i][$i]['flag'] & PSCWS4_ZFLAG_WPART)) {
                        $i = $k;
                    }
                    continue;
                }

                // 处理后缀规则（如地名+后缀）
                if (($r1['flag'] & PSCWS4_ZRULE_SUFFIX) && ($i >= $clen)) {
                    // 向前检查是否符合规则
                    for ($ch = 1; $ch <= $clen; $ch++) {
                        $j = $i - $ch;
                        if ($j < 0 || $this->_no_rule2($wmap[$j][$j]['flag'])) {
                            break;
                        }
                        if (!$this->_rule_check($r1, $this->_get_zs($j))) {
                            break;
                        }
                    }
                    if ($ch <= $clen) {
                        continue;
                    }

                    // 在范围内继续匹配
                    $j = $i - $ch;
                    while (true) {
                        if ((!$r1['zmax'] && $r1['zmin']) || ($r1['zmax'] && ($clen >= $r1['zmax']))) {
                            break;
                        }
                        if ($j < 0 || $this->_no_rule2($wmap[$j][$j]['flag'])) {
                            break;
                        }
                        if (!$this->_rule_check($r1, $this->_get_zs($j))) {
                            break;
                        }
                        $clen++;
                        $j--;
                    }

                    // 创建后缀匹配的词语
                    $k = $i - $clen;
                    if ($wmap[$k][$i] != false) {
                        continue;
                    }
                    $wmap[$k][$i]         = ['tf' => $r1['tf'], 'idf' => $r1['idf'], 'flag' => PSCWS4_WORD_FULL, 'attr' => $r1['attr']];
                    $wmap[$k][$k]['flag'] |= PSCWS4_ZFLAG_WHEAD;
                    for ($j = $k + 1; $j <= $i; $j++) {
                        $wmap[$j][$j]['flag'] |= PSCWS4_ZFLAG_WPART;
                        if (($j != $i) && ($wmap[$k][$j] != false)) {
                            $wmap[$k][$j]['flag'] |= PSCWS4_WORD_PART;
                        }
                    }
                    continue;
                }
            }

            // 双字规则匹配（如复姓+名字）
            for ($i = $zlen - 2; $i >= 0; $i--) {
                // 必须是完整词语且不是词的部分
                if (($wmap[$i][$i + 1] == false) || ($wmap[$i][$i + 1]['flag'] & PSCWS4_WORD_PART)) {
                    continue;
                }

                $k  = $i + 1;
                $r1 = $this->_rule_get($this->_get_zs($i, $k));
                if (!$r1) {
                    continue;
                }

                $clen = $r1['zmin'] > 0 ? $r1['zmin'] : 1;

                // 处理前缀规则
                if (($r1['flag'] & PSCWS4_ZRULE_PREFIX) && ($k < ($zlen - $clen))) {
                    // 检查最小字符数范围
                    for ($ch = 1; $ch <= $clen; $ch++) {
                        $j = $k + $ch;
                        if ($j >= $zlen || $this->_no_rule2($wmap[$j][$j]['flag'])) {
                            break;
                        }
                        if (!$this->_rule_check($r1, $this->_get_zs($j))) {
                            break;
                        }
                    }
                    if ($ch <= $clen) {
                        continue;
                    }

                    // 在最大字符数范围内继续匹配
                    $j = $k + $ch;
                    while (true) {
                        if ((!$r1['zmax'] && $r1['zmin']) || ($r1['zmax'] && ($clen >= $r1['zmax']))) {
                            break;
                        }
                        if ($j >= $zlen || $this->_no_rule2($wmap[$j][$j]['flag'])) {
                            break;
                        }
                        if (!$this->_rule_check($r1, $this->_get_zs($j))) {
                            break;
                        }
                        $clen++;
                        $j++;
                    }

                    // 创建双字前缀匹配的词语
                    $k                        = $k + $clen;
                    $wmap[$i][$k]             = ['tf' => $r1['tf'], 'idf' => $r1['idf'], 'flag' => PSCWS4_WORD_FULL, 'attr' => $r1['attr']];
                    $wmap[$i][$i + 1]['flag'] |= PSCWS4_WORD_PART;
                    for ($j = $i + 2; $j <= $k; $j++) {
                        $wmap[$j][$j]['flag'] |= PSCWS4_ZFLAG_WPART;
                    }
                    $i--;
                    continue;
                }

                // 处理后缀规则
                if (($r1['flag'] & PSCWS4_ZRULE_SUFFIX) && ($i >= $clen)) {
                    // 向前检查是否符合规则
                    for ($ch = 1; $ch <= $clen; $ch++) {
                        $j = $i - $ch;
                        if ($j < 0 || $this->_no_rule2($wmap[$j][$j]['flag'])) {
                            break;
                        }
                        if (!$this->_rule_check($r1, $this->_get_zs($j))) {
                            break;
                        }
                    }
                    if ($ch <= $clen) {
                        continue;
                    }

                    // 在范围内继续匹配
                    $j = $i - $ch;
                    while (true) {
                        if ((!$r1['zmax'] && $r1['zmin']) || ($r1['zmax'] && ($clen >= $r1['zmax']))) {
                            break;
                        }
                        if ($j < 0 || $this->_no_rule2($wmap[$j][$j]['flag'])) {
                            break;
                        }
                        if (!$this->_rule_check($r1, $this->_get_zs($j))) {
                            break;
                        }
                        $clen++;
                        $j--;
                    }

                    // 创建双字后缀匹配的词语
                    $k                    = $i - $clen;
                    $i                    = $i + 1;
                    $wmap[$k][$i]         = ['tf' => $r1['tf'], 'idf' => $r1['idf'], 'flag' => PSCWS4_WORD_FULL, 'attr' => $r1['attr']];
                    $wmap[$k][$k]['flag'] |= PSCWS4_ZFLAG_WHEAD;
                    for ($j = $k + 1; $j <= $i; $j++) {
                        $wmap[$j][$j]['flag'] |= PSCWS4_ZFLAG_WPART;
                        if ($wmap[$k][$j] != false) {
                            $wmap[$k][$j]['flag'] |= PSCWS4_WORD_PART;
                        }
                    }
                    $i -= ($clen + 1);
                    continue;
                }
            }
        }

        // 第四阶段：执行实际分词
        // 寻找容易分割的断点，进行分词处理
        for ($i = 0, $j = 0; $i < $zlen; $i++) {
            // 跳过词的组成部分
            if ($wmap[$i][$i]['flag'] & PSCWS4_ZFLAG_WPART) {
                continue;
            }

            // 处理区间分词
            if ($i > $j) {
                $this->_mseg_zone($j, $i - 1);
            }

            $j = $i;
            // 如果不是词头，直接作为单字处理
            if (!($wmap[$i][$i]['flag'] & PSCWS4_ZFLAG_WHEAD)) {
                $this->_mset_word($i, $i);
                $j++;
            }
        }

        // 处理最后一个分词区间
        if ($i > $j) {
            $this->_mseg_zone($j, $i - 1);
        }

        // 双字模式的最后处理
        // 如果启用了双字模式且有未使用的字符，将其作为单字输出
        if (($this->_mode & PSCWS4_DUALITY) && ($this->_zis >= 0) && !($this->_zis & PSCWS4_ZIS_USED)) {
            $i = $this->_zis;
            $this->_put_res($zmap[$i]['start'], $wmap[$i][$i]['idf'], $zmap[$i]['end'] - $zmap[$i]['start'], $wmap[$i][$i]['attr']);
        }
    }
}