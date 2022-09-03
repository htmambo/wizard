<?php

namespace App\Components\Search;

use App\Repositories\Document;
use Illuminate\Support\Facades\Log;

/**
 * 基于数据库的搜索
 */
class NullDriver implements Driver
{

    public function deleteIndex($id)
    {
        // TODO: Implement deleteIndex() method.
    }

    public function syncIndex(Document $doc)
    {
        // TODO: Implement syncIndex() method.
    }

    public function search(string $keyword, int $page, int $perPage): ?Result
    {
        try{
            $documentModel = Document::query();
            // 先按手动分词处理
            $keywords = explode(' ', $keyword);
            if(count($keywords) == 1) {
                $documentModel->where('title', 'like', "%{$keyword}%", 'or');
                $documentModel->where('content', 'like', "%{$keyword}%", 'or');
            } else {
                $documentModel->orWhere(function ($query) use($keywords) {
                    foreach($keywords as $keyword) {
                        $query->where('title', 'like', "%{$keyword}%");
                    }
                });
                $documentModel->orWhere(function ($query) use($keywords) {
                    foreach($keywords as $keyword) {
                        $query->where('content', 'like', "%{$keyword}%");
                    }
                });
            }
            $ids = $documentModel->select('id')->get()->toArray();
            return new Result(array_column($ids, 'id'),
                $keywords,
                count($ids)
            );
        } catch (\Exception $ex) {
            Log::error('search failed', ['message' => $ex->getMessage()]);
        }

        return null;
    }
}