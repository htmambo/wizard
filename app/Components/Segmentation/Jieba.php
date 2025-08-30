<?php
/**
 * Jieba.php
 *
 * PHP version 5
 *
 * @category PHP
 * @package  /src/class/
 * @author   Fukuball Lin <fukuball@gmail.com>
 * @license  MIT Licence
 * @version  GIT: <fukuball/jieba-php>
 * @link     https://github.com/fukuball/jieba-php
 */

namespace App\Components\Segmentation;
defined('MIN_FLOAT') || define("MIN_FLOAT", -3.14e+100);

/**
 * Jieba
 *
 * @category PHP
 * @package  /src/class/
 * @author   Fukuball Lin <fukuball@gmail.com>
 * @license  MIT Licence
 * @version  Release: <0.16>
 * @link     https://github.com/fukuball/jieba-php
 */
class Jieba
{
    public static $total = 0.0;
    public static $trie;
    public static $FREQ = [];
    public static $min_freq = 0.0;
    public static $route = array();
    public static $dictname;
    public static $user_dictname = array();
    public static $cjk_all = false;
    public static $dag_cache = array();
    public static $checked = [];
    public static $ioTimes = 0;
    public static $custom_rules = [];

    /**
     * Static method init
     *
     * @param array $options # other options
     *
     * @return void
     */
    public static function init($options = array())
    {
        $defaults = array(
            'mode'=>'default',
            'dict'=>'normal',
            'cjk'=>'chinese'
        );

        $options = array_merge($defaults, $options);
        $f_name = "dict.txt";
        self::$dictname="dict.txt";
        if ($options['cjk']=='all') {
            self::$cjk_all = true;
        } else {
            self::$cjk_all = false;
        }

        $t1 = microtime(true);
        self::$dag_cache = array();
        self::$trie = self::genTrie(dirname(dirname(__FILE__))."/dict/".$f_name);

        // 初始化规则解析器
        RuleParser::init();
        // 加载自定义规则
        foreach (RuleParser::getRules() as $name => $rule) {
            self::addCustomRule($name, $rule['pattern'], $rule['priority']);
        }

        if ($options['mode']=='test') {
            echo "loading model cost ".(microtime(true) - $t1)." seconds.\n";
            echo "Trie has been built succesfully.\n";
        }
    }// end function init

    /**
     * Static method calc
     *
     * @param string $sentence # input sentence
     * @param array  $DAG      # DAG
     * @param array  $options  # other options
     *
     * @return array self::$route
     */
    public static function calc($sentence, $DAG, $options = array())
    {
        $N = mb_strlen($sentence, 'UTF-8');
        self::$route = array();
        self::$route[$N] = array($N => 0.0);
        for ($i=($N-1); $i>=0; $i--) {
            $candidates = array();
            foreach ($DAG[$i] as $x) {
                $w_c = mb_substr($sentence, $i, (($x+1)-$i), 'UTF-8');
                self::checkWord($w_c);
                $previous_freq = current(self::$route[$x+1]);
                if (isset(self::$FREQ[$w_c])) {
                    $current_freq = (float) $previous_freq + log(self::$FREQ[$w_c] / self::$total);
                } else {
                    $current_freq = (float) $previous_freq + self::$min_freq;
                }
                $candidates[$x] = $current_freq;
            }
            arsort($candidates);
            $max_prob = reset($candidates);
            $max_key = key($candidates);
            self::$route[$i] = array($max_key => $max_prob);
        }

        return self::$route;
    }// end function calc

    /**
     * Static method genTrie
     *
     * @param string $f_name  # input f_name
     * @param array  $options # other options
     *
     * @return object self::$trie
     */
    public static function genTrie($f_name, $options = array())
    {
        $xdb = new \XTreeDB;
        $xdb->setReversedCfg('iiii', 'ItfTotal/ItfMax/ItfMin/ItotalWords');
        if (!$xdb->Open($f_name . '.xdb')) {
            throw new \Exception("Can't open dictionary file: " . $f_name);
        }
        self::$total = $xdb->headerInfo['tfTotal'];
        self::$trie = $xdb;
        self::$checked = [];
        self::$ioTimes = 0;

        return self::$trie;
    }

    /**
     * 添加自定义分词规则
     *
     * @param string $name 规则名称
     * @param string $pattern 正则表达式模式
     * @param int $priority 优先级，数字越小优先级越高
     * @param callable $handler 处理函数，接收匹配的文本，返回分词结果数组
     *
     * @return void
     */
    public static function addCustomRule($name, $pattern, $priority = 100, $handler = null)
    {
        self::$custom_rules[$name] = [
            'pattern' => $pattern,
            'handler' => $handler,
            'priority' => $priority
        ];

        // 按优先级排序
        uasort(self::$custom_rules, function($a, $b) {
            return $a['priority'] - $b['priority'];
        });
    }

    /**
     * 移除自定义分词规则
     *
     * @param string $name 规则名称
     *
     * @return void
     */
    public static function removeCustomRule($name)
    {
        unset(self::$custom_rules[$name]);
    }

    /**
     * 应用自定义分词规则
     *
     * @param string $text 输入文本
     *
     * @return array 返回处理后的分词结果，如果没有匹配的规则则返回null
     */
    public static function applyCustomRules($text)
    {
        $results = [];
        foreach (self::$custom_rules as $rule) {
            if (preg_match_all('/' . $rule['pattern'] . '/iu', $text, $matches)) {
                if($rule['handler'] && is_callable($rule['handler'])) {
                    // 调用处理函数
                    $result = call_user_func($rule['handler'], $text, $matches);
                } else {
                    $result = $matches[0];
                }
                if ($result !== null && is_array($result)) {
                    $results = array_merge($results, $result);
                }
            }
        }
        return $results;
    }// end function genTrie

    /**
     * Static method loadUserDict
     *
     * @param string $f_name  # input f_name
     * @param array  $options # other options
     *
     * @return array self::$trie
     */
    public static function loadUserDict($f_name, $options = array())
    {
        self::$user_dictname[] = $f_name;
        $content = fopen($f_name, "r");
        self::$dag_cache = array();
        while (($line = fgets($content)) !== false) {
            $explode_line = explode(" ", trim($line));
            $word = $explode_line[0];
            $freq = isset($explode_line[1]) ? $explode_line[1] : 1;
            $tag = isset($explode_line[2]) ? $explode_line[2] : null;
            $freq = (float) $freq;
            self::addWord($word, $freq, $tag);
        }
        fclose($content);
        return self::$trie;
    }// end function loadUserDict

    /**
     * Static method addWord
     * TODO 需要重新实现
     * @param string $word
     * @param float  $freq
     * @param string $tag
     *
     * @return array self::$trie
     */
    public static function addWord($word, $freq, $tag = '', $options = array())
    {
        self::checkWord($word);
        if (isset(self::$FREQ[$word])) {
            self::$total -= self::$FREQ[$word];
        }
        self::$checked[$word] = ['tf' => $freq, 'end' => 1];
        self::$FREQ[$word] = $freq;
        self::$total += $freq;
        $len = mb_strlen($word, 'UTF-8');
        for($i=0;$i<$len;$i++) {
            $tmp = mb_substr($word, 0, $i, 'UTF-8');
            if (!isset(self::$checked[$tmp])) {
                self::$checked[$tmp] = ['tf' => 0, 'end' => 0];
            } else {
                self::$checked[$tmp]['end'] = 0;
            }
        }
        return self::$trie;
    }

    /**
     * Static method tokenize
     *
     * @param string $sentence
     *
     * @return array
     */
    public static function tokenize($sentence, $options = array("HMM" => true))
    {
        $seg_list = self::cut($sentence, false, array("HMM" => $options["HMM"]));
        $tokenize_list = [];
        $start = 0;
        $end = 0;
        foreach ($seg_list as $seg) {
            $end = $start+mb_strlen($seg, 'UTF-8');
            $tokenize = [
                'word' => $seg,
                'start' => $start,
                'end' => $end
            ];
            $start = $end;
            $tokenize_list[] = $tokenize;
        }
        return $tokenize_list;
    }

    /**
     * Static method __cutAll
     *
     * @param string $sentence # input sentence
     * @param array  $options  # other options
     *
     * @return array $words
     */
    public static function __cutAll($sentence, $options = array())
    {
        $defaults = array(
            'mode'=>'default'
        );

        $options = array_merge($defaults, $options);

        $words = array();

        $DAG = self::getDAG($sentence);
        $old_j = -1;

        foreach ($DAG as $k => $L) {
            if (count($L) == 1 && $k > $old_j) {
                $word = mb_substr($sentence, $k, (($L[0]-$k)+1), 'UTF-8');
                $words[] = $word;
                $old_j = $L[0];
            } else {
                foreach ($L as $j) {
                    if ($j > $k) {
                        $word = mb_substr($sentence, $k, ($j-$k)+1, 'UTF-8');
                        $words[] = $word;
                        $old_j = $j;
                    }
                }
            }
        }

        return $words;
    }// end function __cutAll

    /**
     * Static method getDAG
     *
     * @param string $sentence # input sentence
     * @param array  $options  # other options
     *
     * @return array $DAG
     */
    public static function getDAG($sentence, $options = array())
    {
        $defaults = array(
            'mode'=>'default'
        );

        $options = array_merge($defaults, $options);

        $N = mb_strlen($sentence, 'UTF-8');
        $i = 0;
        $j = 0;
        $DAG = array();
        $word_c = array();

        while ($i < $N) {
            $c = mb_substr($sentence, $j, 1, 'UTF-8');
            if (count($word_c)==0) {
                $next_word_key = $c;
            } else {
                $next_word_key = implode('', $word_c).$c;
            }

            if (isset(self::$dag_cache[$next_word_key])) {
                if (self::$dag_cache[$next_word_key]['exist']) {
                    $word_c[] = $c;
                    if (self::$dag_cache[$next_word_key]['end']) {
                        if (!isset($DAG[$i])) {
                            $DAG[$i] = array();
                        }
                        $DAG[$i][] = $j;
                    }
                    $j += 1;
                    if ($j >= $N) {
                        $word_c = array();
                        $i += 1;
                        $j = $i;
                    }
                } else {
                    $word_c = array();
                    $i += 1;
                    $j = $i;
                }
                continue;
            }
            if($nextWordInfo = self::checkWord($next_word_key)) {
                self::$dag_cache[$next_word_key] = array('exist' => true, 'end' => false);
                $word_c[] = $c;
                if($nextWordInfo['tf'] && $nextWordInfo['end']) {
                    self::$dag_cache[$next_word_key]['end'] = true;
                    if (!isset($DAG[$i])) {
                        $DAG[$i] = [];
                    }
                    $DAG[$i][] = $j;
                }
                $j += 1;
                if ($j >= $N) {
                    $word_c = array();
                    $i += 1;
                    $j = $i;
                }
            } else {
                $word_c = array();
                $i += 1;
                $j = $i;
                self::$dag_cache[$next_word_key] = array('exist' => false);
            }
        }

        for ($i=0; $i<$N; $i++) {
            if (!isset($DAG[$i])) {
                $DAG[$i] = array($i);
            }
        }

        return $DAG;
    }// end function getDAG

    /**
     * 静态方法：使用DAG（有向无环图）进行中文分词
     * 这是结巴分词的核心算法，使用动态规划找到最优分词路径
     *
     * @param string $sentence 输入的句子
     * @param array  $options  其他选项
     *
     * @return array $words 分词结果数组
     */
    public static function __cutDAG($sentence, $options = array())
    {
        // 设置默认选项
        $defaults = array(
            'mode'=>'default'
        );

        // 合并用户选项和默认选项
        $options = array_merge($defaults, $options);

        // 初始化分词结果数组
        $words = array();

        // 获取句子长度（UTF-8字符数）
        $N = mb_strlen($sentence, 'UTF-8');
        // 构建DAG（有向无环图），找出所有可能的词
        $DAG = self::getDAG($sentence);

        // 使用动态规划计算最优分词路径
        self::calc($sentence, $DAG);

        // 初始化遍历位置和缓冲区
        // 当前处理位置
        $x = 0;
        // 单字符缓冲区
        $buf = '';

        // 遍历句子，按照最优路径进行分词
        while ($x < $N) {
            // 获取当前位置的最优路径信息
            $current_route_keys = array_keys(self::$route[$x]);
            // 下一个分词位置
            $y = $current_route_keys[0]+1;
            // 当前词
            $l_word = mb_substr($sentence, $x, ($y-$x), 'UTF-8');

            if (($y-$x)==1) {
                // 如果当前是单字符，添加到缓冲区
                $buf = $buf.$l_word;
            } else {
                // 如果当前是多字符词，先处理缓冲区中的单字符
                if (mb_strlen($buf, 'UTF-8')>0) {
                    if (mb_strlen($buf, 'UTF-8')==1) {
                        // 缓冲区只有一个字符，直接添加
                        $words[] = $buf;
                        $buf = '';
                    } else {
                        // 缓冲区有多个字符，需要进一步分词
                        if (! isset(self::$FREQ[$buf])) {
                            // 如果缓冲区内容不在词频表中，使用HMM进行分词
                            $regognized = JiebaFinalseg::cut($buf);
                            foreach ($regognized as $key => $word) {
                                $words[] = $word;
                            }
                        } else {
                            // 如果在词频表中，拆分为单字符
                            $elem_array = preg_split('//u', $buf, -1, PREG_SPLIT_NO_EMPTY);
                            foreach ($elem_array as $word) {
                                $words[] = $word;
                            }
                        }
                        $buf = '';
                    }
                }
                // 添加当前识别的词
                $words[] = $l_word;
            }
            // 移动到下一个位置
            $x = $y;
        }

        // 处理剩余的缓冲区内容
        if (mb_strlen($buf, 'UTF-8')>0) {
            if (mb_strlen($buf, 'UTF-8')==1) {
                // 缓冲区只有一个字符，直接添加
                $words[] = $buf;
            } else {
                // 缓冲区有多个字符，需要进一步分词
                if (! isset(self::$FREQ[$buf])) {
                    // 如果缓冲区内容不在词频表中，使用HMM进行分词
                    $regognized = JiebaFinalseg::cut($buf);
                    foreach ($regognized as $key => $word) {
                        $words[] = $word;
                    }
                } else {
                    // 如果在词频表中，拆分为单字符
                    $elem_array = preg_split('//u', $buf, -1, PREG_SPLIT_NO_EMPTY);
                    foreach ($elem_array as $word) {
                        $words[] = $word;
                    }
                }
            }
        }

        // 返回分词结果
        return $words;
    }// 结束__cutDAG方法

    /**
     * Static method __cutDAGNoHMM
     *
     * @param string $sentence # input sentence
     * @param array  $options  # other options
     *
     * @return array $words
     */
    public static function __cutDAGNoHMM($sentence, $options = array())
    {
        $defaults = array(
            'mode'=>'default'
        );

        $options = array_merge($defaults, $options);

        $words = array();

        $N = mb_strlen($sentence, 'UTF-8');
        $DAG = self::getDAG($sentence);

        self::calc($sentence, $DAG);

        $x = 0;
        $buf = '';

        $re_eng_pattern = '[a-zA-Z+#]+';

        while ($x < $N) {
            $current_route_keys = array_keys(self::$route[$x]);
            $y = $current_route_keys[0]+1;
            $l_word = mb_substr($sentence, $x, ($y-$x), 'UTF-8');

            if (preg_match('/'.$re_eng_pattern.'/u', $l_word)) {
                $buf = $buf.$l_word;
                $x = $y;
            } else {
                if (mb_strlen($buf, 'UTF-8')>0) {
                    $words[] = $buf;
                    $buf = '';
                }
                $words[] = $l_word;
                $x = $y;
            }
        }

        if (mb_strlen($buf, 'UTF-8')>0) {
            $words[] = $buf;
            $buf = '';
        }

        return $words;
    }// end function __cutDAGNoHMM

    /**
     * 静态方法：中文分词
     *
     * @param string  $sentence 输入的句子
     * @param boolean $cut_all  是否全模式分词
     * @param array   $options  其他选项
     *
     * @return array $seg_list 分词结果数组
     */
    public static function cut($sentence, $cut_all = false, $options = array("HMM" => true))
    {
        // 设置默认选项
        $defaults = array(
            'mode'=>'default'
        );

        // 合并用户选项和默认选项
        $options = array_merge($defaults, $options);

        // 初始化分词结果数组
        $seg_list = array();

        // 定义各种正则表达式模式
        // 1. 中文汉字
        $re_han_pattern = '([\x{4E00}-\x{9FA5}]+)';
        // 2. 中文汉字+ASCII字符
        $re_han_with_ascii_pattern = '([\x{4E00}-\x{9FA5}\x{0020}-\x{007E}]+)';
        // 3. 日文平假名+汉字
        $re_kanjikana_pattern = '([\x{3040}-\x{309F}\x{4E00}-\x{9FA5}]+)';
        // 4. 日文片假名
        $re_katakana_pattern = '([\x{30A0}-\x{30FF}]+)';
        // 5. 韩文
        $re_hangul_pattern = '([\x{AC00}-\x{D7AF}]+)';
        // 6. ASCII字符
        $re_ascii_pattern = '([\x{0020}-\x{007E}]+)';
        // 7. 空白字符
        $re_skip_pattern = '(\s+)';

        // 如果是全模式分词，修改跳过模式
        if ($cut_all) {
            $re_skip_pattern = '([a-zA-Z0-9+#&=\._\r\n]+)';
        }

        // 标点符号模式（包含中文标点和其他特殊符号）
        $re_punctuation_pattern = '([\x{ff5e}\x{ff01}\x{ff08}\x{ff09}\x{300e}'.
                                  '\x{300c}\x{300d}\x{300f}\x{3001}\x{ff1a}\x{ff1b}'.
                                  '\x{2018}\x{2019}\x{201c}\x{201d}\x{2103}\x{2109}'.
				  '\x{00b0}\x{00a9}\x{00ae}\x{2122}\x{00a3}\x{00a5}'.
                                  '\x{20ac}\x{0024}\x{00a2}\x{ff0c}\x{ff1f}\x{3002}]+)';

        // 其他符号模式（捕获未被其他模式匹配的符号）
        $re_other_symbols_pattern = '([^\x{4E00}-\x{9FA5}\x{3040}-\x{309F}\x{30A0}-\x{30FF}'.
                                   '\x{AC00}-\x{D7AF}\x{ff5e}\x{ff01}\x{ff08}\x{ff09}'.
                                   '\x{300e}\x{300c}\x{300d}\x{300f}\x{3001}\x{ff1a}\x{ff1b}'.
                                   '\x{ff0c}\x{ff1f}\x{3002}\x{2018}\x{2019}\x{201c}\x{201d}'.
                                   '\x{2103}\x{2109}\x{00b0}\x{00a9}\x{00ae}\x{2122}'.
                                   '\x{00a3}\x{00a5}\x{20ac}\x{0024}\x{00a2}]+)';

        // 根据是否支持所有CJK字符来设置过滤模式
        if (self::$cjk_all) {
            // 支持日文平假名、片假名和韩文
            $filter_pattern = $re_kanjikana_pattern.
                              '|'.$re_katakana_pattern.
                              '|'.$re_hangul_pattern;
        } else {
            // 只支持中文汉字和ASCII字符
            $filter_pattern = $re_han_with_ascii_pattern;
        }
        $full_custom_result = [];
        // 首先尝试应用自定义分词规则
        $custom_result = self::applyCustomRules($sentence);
        if ($custom_result !== null) {
            // 如果自定义规则处理成功，直接使用结果
            foreach ($custom_result as $word) {
                // 先检查是否已经处理过
                $key = array_search($word, $full_custom_result);
                if ($key === false) {
                    $key = count($full_custom_result);
                    $full_custom_result[] = $word;
                }
                $sentence = str_replace($word, '{###CUST_'.$key.'###} ', $sentence);
            }
        }

        // 定义自定义标记模式 - 修正为精确匹配完整格式
        $re_custom_pattern = '([^\{###CUST_[0-9]+###\}$])';

        // 使用正则表达式将句子分割成不同类型的文本块
        preg_match_all(
            '/('.$re_custom_pattern.'|'.$filter_pattern.'|'.$re_ascii_pattern.'|'.$re_punctuation_pattern.'|'.$re_other_symbols_pattern.')/u',
            $sentence,
            $matches,
            PREG_PATTERN_ORDER
        );
        // 获取所有匹配的文本块
        $blocks = $matches[0];

        // 遍历每个文本块进行分词处理
        foreach ($blocks as $blk) {
            // 跳过空字符串
            if (mb_strlen($blk, 'UTF-8')==0) {
                continue;
            }

            // 重新设置过滤模式（在循环中可能需要调整）
            if (self::$cjk_all) {
                // 跳过韩文，只处理日文平假名和片假名
                $filter_pattern = $re_kanjikana_pattern.'|'.$re_katakana_pattern;
            } else {
                $filter_pattern = $re_han_with_ascii_pattern;
            }

            // 如果当前块匹配CJK字符模式，进行分词处理
            if (preg_match('/'.$filter_pattern.'/u', $blk)) {
                if (preg_match_all('/'.$re_custom_pattern.'/u', $blk, $matches)) {
                    print_r($matches);
                    exit;
                }

                if ($cut_all) {
                    // 全模式分词：找出所有可能的词
                    $words = self::__cutAll($blk);
                } else {
                    // 精确模式分词
                    if ($options['HMM']) {
                        // 使用HMM（隐马尔可夫模型）进行分词
                        $words = self::__cutDAG($blk);
                    } else {
                        // 不使用HMM进行分词
                        $words = self::__cutDAGNoHMM($blk);
                    }
                }

                // 将分词结果添加到结果数组中
                foreach ($words as $word) {
                    $seg_list[] = $word;
                }
            } elseif (preg_match('/'.$re_skip_pattern.'/u', $blk)) {
                // 处理跳过模式的字符（如空白字符或ASCII字符）
                preg_match_all(
                    '/('.$re_skip_pattern.')/u',
                    $blk,
                    $tmp,
                    PREG_PATTERN_ORDER
                );
                $tmp = $tmp[0];
                foreach ($tmp as $x) {
                    if (preg_match('/'.$re_skip_pattern.'/u', $x)) {
                        // 如果匹配跳过模式且不是纯空格，则添加到结果中
                        if (str_replace(' ', '', $x) != '') {
                            $seg_list[] = $x;
                        }
                    } else {
                        // 处理其他字符
                        if (!$cut_all) {
                            // 非全模式：将字符串拆分为单个字符
                            $xx_array = preg_split('//u', $x, -1, PREG_SPLIT_NO_EMPTY);
                            foreach ($xx_array as $xx) {
                                $seg_list[] = $xx;
                            }
                        } else {
                            // 全模式：直接添加整个字符串
                            $seg_list[] = $x;
                        }
                    }
                }
            } elseif (preg_match('/'.$re_punctuation_pattern.'/u', $blk)) {
                // 处理标点符号：直接添加到结果中
                $seg_list[] = $blk;
            } elseif (preg_match('/'.$re_other_symbols_pattern.'/u', $blk)) {
                // 处理其他特殊符号：直接添加到结果中
                $seg_list[] = $blk;
            } elseif (preg_match('/'.$re_custom_pattern.'/u', $blk)) {
                // 处理自定义标记：直接添加到结果中
                $seg_list[] = $blk;
            }// 结束CJK字符处理分支
        }// 结束文本块遍历循环
        // print_r($full_custom_result);
        // 替换自定义分词结果 - 修正为匹配完整的标记格式
        foreach ($full_custom_result as $key => $word) {
            $seg_list = array_map(function($w) use ($key, $word) {
                return str_replace('###CUST_'.$key.'###', $word, $w);
            }, $seg_list);
        }
        // 返回分词结果数组
        return $seg_list;
    }// 结束cut方法

    /**
     * Static method cutForSearch
     *
     * @param string  $sentence # input sentence
     * @param array   $options  # other options
     *
     * @return array $seg_list
     */
    public static function cutForSearch($sentence, $options = array("HMM" => true))
    {
        $defaults = array(
            'mode'=>'default'
        );

        $options = array_merge($defaults, $options);

        $seg_list = array();

        $cut_seg_list = self::cut($sentence, false, array("HMM" => $options["HMM"]));

        foreach ($cut_seg_list as $w) {
            $len = mb_strlen($w, 'UTF-8');

            if ($len>2) {
                for ($i=0; $i<($len-1); $i++) {
                    $gram2 = mb_substr($w, $i, 2, 'UTF-8');

                    if (isset(self::$FREQ[$gram2])) {
                        $seg_list[] = $gram2;
                    }
                }
            }

            if (mb_strlen($w, 'UTF-8')>3) {
                for ($i=0; $i<($len-2); $i++) {
                    $gram3 = mb_substr($w, $i, 3, 'UTF-8');

                    if (isset(self::$FREQ[$gram3])) {
                        $seg_list[] = $gram3;
                    }
                }
            }

            $seg_list[] = $w;
        }

        return $seg_list;
    }// end function cutForSearch

    public static function checkWord($word) {
        $wordInfo = false;
        // $word = str_replace('.', '', $word);
        if(self::$user_dictname) {
            if(isset(self::$checked[$word])) {
                $wordInfo = self::$checked[$word];
            }
        }
        if(!isset(self::$FREQ[$word])) {
            if($wordInfo === false) {
                $wordInfo = [];
                self::$ioTimes++;
                if ($value = self::$trie->Get($word)) {
                    $wordInfo = unpack('ftf/Cflag/a3attr', $value);
                    $wordInfo['end'] = (bool)($wordInfo['flag'] & 0x01);
                }
            }
            if($wordInfo && ($wordInfo['tf'])) {
                self::$FREQ[$word]          = floatval($wordInfo['tf']);
            }
        } else {
            $wordInfo = [
                'tf' => self::$FREQ[$word],
                'end' => true
            ];
        }
        if(self::$user_dictname) {
            self::$checked[$word] = $wordInfo;
        }
        return $wordInfo;
    }

}// end of class Jieba
