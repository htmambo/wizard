<?php

namespace App\Components\Search;

use App\Repositories\Document;
use Illuminate\Support\Facades\Log;

/**
 * XunSearchDriver
 *
 * https://docs.xunsearch.com/
 */
class XunSearchDriver implements Driver
{
    /**
     * @var Client
     */
    private $client = null;

    /**
     * Index Object
     *
     * @var XSIndex
     */
    private $index = null;

    /**
     * Search Object
     *
     * @var XSSearch
     */
    private $search = null;

    /**
     * 搜索类型
     *
     * @var string
     */
    private $searchType = 'matchphrase';

    public function __construct()
    {
        $cfg = [
            'project.name' => 'wizard',
            'project.default_charset' => 'utf-8',
            'server.index' => config('wizard.search.drivers.xun.index_server'),
            'server.search' => config('wizard.search.drivers.xun.search_server'),
            'fuzzy' => config('wizard.search.drivers.xun.fuzzy'),
            'id' => [
                'type' => 'id'
            ],
            'type' => [
                'type' => 'numeric'
            ],
            'title' => [
                'type' => 'title',
                'index' => 'both'
            ],
            'content' => [
                'type' => 'body',
                'index' => 'both'
            ]
        ];
        //创建XS对象 只要是对索引操作 都必须使用XS对象
        $this->client       = new XS($cfg);
    }

    /**
     * 删除文档索引
     *
     * @param $id
     *
     * @return void
     */
    public function deleteIndex($id)
    {
        //获取索引对象(用来增删改的)
        $index = $this->client->getIndex();
        $index->del($id);
    }


    /**
     * 同步索引
     *
     * @param Document $doc
     *
     * @return string
     * @throws \Exception
     */
    public function syncIndex(Document $doc)
    {
        //获取索引对象(用来增删改的)
        $index = $this->client->getIndex();

        $doc = new XSDocument([
            'id'      => $doc->id,
            'type'    => $doc->type,
            'title'   => $doc->title,
            'content' => $doc->content,
        ]);
        //更新索引数据
        $index->update($doc);
        $index->flushIndex();
        return 'OK';
    }


    /**
     * 执行文档搜索
     *
     * @param string $keyword
     * @param int    $page
     * @param int    $perPage
     *
     * @return Result|null
     */
    public function search(string $keyword, int $page, int $perPage): ?Result
    {
        try {
            $starttime = microtime(4);
            $search = $this->client->getSearch();
            //搜索
            $docs = $search
                //设置是否开启模糊查询
                ->setFuzzy($this->client->getConfig()['fuzzy'])
                //设置是否开启同义词查询
                ->setAutoSynonyms(false)
                //设置查询结果显示条数
                ->setLimit($perPage, ($page - 1) * $perPage)
                //查询关键字
                ->setQuery($keyword)
                ->search();

//            echo('搜索内容：' . $keyword);
            $tokenizer = new XSTokenizerScws;
            $words = $tokenizer->getResult($keyword);
//            $corrected = $search->getCorrectedQuery();
//            if (count($corrected) !== 0)
//            {
//                // 有纠错建议，列出来看看；此情况就会得到 "测试" 这一建议
//                echo "您是不是要找：\n";
//                foreach ($corrected as $word)
//                {
//                    echo $word . "\n";
//                }
//            }
//            echo('分词结果：' . implode(',', array_column($words, 'word')));
//            exit;
//            $msg = '';
            $finded = $search->getLastCount();
//            if($finded>$perPage) {
//                $msg = '找到大约' . $finded . '条记录，';
//            } else if ($finded>0) {
//                $msg = '找到' . $finded .'条记录，';
//            } else {
//                $msg = '没有找到合适的记录，';
//            }
//            $msg .= '数据库中现有记录' . $search->getDbTotal() . '条，耗时：' . round(microtime(4) - $starttime, 4) . '秒';
//            echo $msg;
            $list = [];
            foreach($docs  as $doc)
            {
                $row = [];
                $row['id'] = $doc->f('id');
                $row['title'] = $doc->f('title');
                $row['type'] = $doc->f('type');
                $row['content'] = $search->highlight($doc->content);
                $row['rank'] = $doc->rank();
                $row['weight'] = round($doc->weight(), 2);
                $row['percent'] = $doc->percent();
                $list[] = $row;
            }
            return new Result(array_column($list, 'id'),
                array_column($words, 'word'),
                $finded
            );
        } catch (\Exception $ex) {
            Log::error('search failed', ['message' => $ex->getMessage()]);
        }

        return null;
    }
}