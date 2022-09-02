<?php

namespace App\Console\Commands;

use App\Components\Search\Search;
use App\Repositories\Document;
use Illuminate\Console\Command;

/**
 * 文档索引同步到搜索引擎
 */
class SyncDocumentToIndex extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sync-index:document';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'sync document to index server for search';


    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        Document::withTrashed()->chunk(10, function ($docs) {
            /** @var Document $doc */
            foreach ($docs as $doc) {
                try {
                    if (!empty($doc->deleted_at)) {
                        $result = Search::get()->deleteIndex($doc->id);
                        $msg = '删除';
                    } else {
                        $result = Search::get()->syncIndex($doc);
                        $msg = '更新';
                    }
                    $msg.= sprintf('文档索引 %d:%s', $doc->id, $doc->title);
                    if($result === false || $result === 'ERR') {
                        $this->error($msg . ' 失败');
                    } else {
                        $this->info($msg . ' 成功');
                    }
                } catch (\Exception $ex) {
                    $this->error("{$ex->getFile()}:{$ex->getLine()} {$ex->getMessage()}");
                }
            }
        });
    }

}
