<?php
namespace App\Components\Segmentation;

class RuleParser {
    private static $rules = [];
    public static $sections = [];

    /**
     * 初始化规则解析器
     *
     * @param null $ruleFile 规则文件路径
     *
     * @throws \Exception
     */
    public static function init($ruleFile = null) {
        if ($ruleFile === null) {
            $ruleFile = dirname(dirname(__FILE__)) . '/dict/jieba.rule.ini';
        }

        self::parseRuleFile($ruleFile);
    }

    /**
     * 解析规则文件
     *
     * @param string $file 规则文件路径
     *
     * @throws \Exception
     */
    private static function parseRuleFile($file) {
        if (!file_exists($file)) {
            throw new \Exception("Rule file not found: $file");
        }

        $content = file_get_contents($file);
        $lines = explode("\n", $content);

        $currentSection = null;
        $sectionConfig = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line) || $line[0] === ';') {
                continue;
            }

            // 处理节名
            if (preg_match('/^\[(.*?)\]$/', $line, $matches)) {
                if ($currentSection !== null) {
                    self::$sections[$currentSection] = $sectionConfig;
                }
                $currentSection = $matches[1];
                $sectionConfig = [];
                continue;
            }

            // 处理配置项
            if (strpos($line, ':') === 0) {
                $parts = explode('=', $line);
                if (count($parts) === 2) {
                    $key = trim(substr($parts[0], 1));
                    $value = trim($parts[1]);
                    $sectionConfig[$key] = $value;
                }
                continue;
            }

            // 处理规则内容
            if ($currentSection !== null) {
                if (isset($sectionConfig['type']) && $sectionConfig['type'] === 'rule') {
                    // 处理规则定义
                    if (strpos($line, '=') !== false) {
                        [$name, $pattern] = explode('=', $line, 2);
                        $name = trim($name);
                        $pattern = trim($pattern);
                        $priority = isset($sectionConfig['priority']) ? (int)$sectionConfig['priority'] : 100;
                        self::$rules[$name] = [
                            'pattern' => $pattern,
                            'priority' => $priority
                        ];
                    }
                } else {
                    // 处理普通内容
                    if (!empty($line)) {
                        if (!isset($sectionConfig['content'])) {
                            $sectionConfig['content'] = '';
                        }
                        $sectionConfig['content'] .= ($sectionConfig['content'] ? "\n" : '') . $line;
                    }
                }
            }
        }

        // 保存最后一个节
        if ($currentSection !== null) {
            self::$sections[$currentSection] = $sectionConfig;
        }
        // 格式化sections中的content
        foreach (self::$sections as $k=> &$section) {
            if(!isset($section['content']) || empty($section['content'])) {
                unset(self::$sections[$k]);
                continue;
            }
            if ($section['multi'] === 'no') {
                $section['content'] = preg_replace('/\s+/', '', $section['content']);
            } else {
                $section['content'] = preg_replace('/\s+/', '|', $section['content']);
            }
        }
        // 展开规则模式中的引用
        foreach (self::$rules as &$rule) {
            $rule['pattern'] = self::expandPattern($rule['pattern']);
        }
    }

    /**
     * 展开规则模式中的引用
     * @param string $pattern 原始模式
     * @return string 展开后的模式
     */
    private static function expandPattern($pattern) {
        return preg_replace_callback('/\{(.*?)\}/', function($matches) {
            $section = $matches[1];
            if (isset(self::$sections[$section])) {
                return self::$sections[$section]['content'];
            }
            return $matches[0];
        }, $pattern);
    }

    /**
     * 获取所有规则
     * @return array 规则数组
     */
    public static function getRules() {
        return self::$rules;
    }
}