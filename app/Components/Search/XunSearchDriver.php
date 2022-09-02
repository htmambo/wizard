<?php

namespace App\Components\Search;

use App\Repositories\Document;
use GuzzleHttp\Client;
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
            'server.index' => config('wizard.search.drivers.xun.index_server', '192.168.50.50:8383'),
            'server.search' => config('wizard.search.drivers.xun.search_server', '192.168.50.50:8384'),
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
     * @return void
     * @throws \Exception
     */
    public function syncIndex(Document $doc)
    {

        //获取索引对象(用来增删改的)
        $index = $this->client->getIndex();

        $req = [
            'id'      => $doc->id,
            'type'    => $doc->type,
            'title'   => $doc->title,
            'content' => $doc->content,
        ];
        //更新索引数据
        $index->update($req);
        $index->flushIndex();
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
            $search = $this->client->getSearch();
            $fuzzy = 'on';
            //搜索
            $docs = $search
                //设置是否开启模糊查询
                ->setFuzzy($fuzzy=='on'?false:true)
                //设置是否开启同义词查询
                ->setAutoSynonyms(false)
                //设置查询结果显示条数
                ->setLimit($perPage, ($page - 1) * $perPage)
                //查询关键字
                ->setQuery($keyword)
                ->search();

//            pre('搜索内容：' . $keyword);
//            $tokenizer = new \org\XSTokenizerScws;
//            $words = $tokenizer->getResult($keyword);
//            pre('分词结果：' . implode(',', array_column($words, 'word')));
            $msg = '';
            $finded = $search->getLastCount();
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
            return new Result($list,
                [$keyword],
                $finded
            );
        } catch (\Exception $ex) {
            Log::error('search failed', ['message' => $ex->getMessage()]);
        }

        return null;
    }
}