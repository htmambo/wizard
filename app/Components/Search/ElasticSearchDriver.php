<?php

namespace App\Components\Search;

use App\Repositories\Document;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

/**
 * ElasticSearchDriver
 *
 * https://www.elastic.co/guide/en/elasticsearch/reference/current/index.html
 */
class ElasticSearchDriver implements Driver
{
    /**
     * @var Client
     */
    private $client = null;

    /**
     * Index name
     *
     * @var string
     */
    private $index = '';

    /**
     * Basic Auth username
     * @var string
     */
    private $authUsername = '';

    /**
     * Basic Auth password
     *
     * @var string
     */
    private $authPassword = '';

    public function __construct()
    {
        $this->client       = new Client([
            'base_uri' => config('wizard.search.drivers.elasticsearch.server', 'http://localhost:9200'),
            'timeout'  => 3.0,
        ]);
        $this->index        = config('wizard.search.drivers.elasticsearch.index', 'wizard');
        $this->authUsername = config('wizard.search.drivers.elasticsearch.username');
        $this->authPassword = config('wizard.search.drivers.elasticsearch.password');

        $url = '/' . $this->index . '/_doc/_mapping';
        // $this->client->delete($url, ['auth' => $this->auth()]);
        $cfg = [
            'properties' => [
                'title' => [
                    'type' => 'text',
                    'analyzer' => 'ik_max_word',
                    'search_analyzer' => 'ik_smart'
                ],
                'content' => [
                    'type' => 'text',
                    'analyzer' => 'ik_max_word',
                    'search_analyzer' => 'ik_smart'
                ],
            ]
        ];
        // $this->client->post($url, ['json' => $cfg, 'auth' => $this->auth()]);
    }

    /**
     * 鉴权
     *
     * @return array|null
     */
    private function auth(): ?array
    {
        if (empty($this->authUsername)) {
            return null;
        }

        return [$this->authUsername, $this->authPassword];
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
        $this->client->delete(
            "/{$this->index}/_doc/{$id}",
            [
                'auth' => $this->auth(),
            ]
        );
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
        $req = [
            'id'      => "DOCUMENT_" . trim($doc->id),
            'type'    => $doc->type,
            'title'   => $doc->title,
            'content' => $doc->content,
        ];

        $resp = $this->client->put("/{$this->index}/_doc/{$doc->id}", [
            'json' => $req,
            'auth' => $this->auth(),
        ]);
var_dump($resp->getBody()->getContents());

        if ($resp->getStatusCode() < 200 || $resp->getStatusCode() >= 300) {
            throw new \Exception("sync document to server failed: " . $resp->getReasonPhrase() . ", response: " . $resp->getBody()->getContents());
        }
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
        $req = [
            'query'   => [
                'bool' => [
                    'must' => [
                        [
                            'query_string' => [
                                'query'            => $keyword,
                                'analyze_wildcard' => true,
                            ],
                        ],
                    ],
                ],
            ],
            'from'    => $page * $perPage - $perPage,
            'size'    => $perPage * 2,
            '_source' => ['id', 'type'],
        ];

        try {
            $resp = $this->client->post("/{$this->index}/_search", [
                'json' => $req,
                'auth' => $this->auth(),
            ]);
            $result = $this->client->post('/_analyze', [
                'json' => [
                    'analyzer' => 'ik_smart',
                    'text' => $keyword
                ],
                'auth' => $this->auth()
            ]);
            $tokens = json_decode($result->getBody()->getContents(), true);
            $keywords = array_column($tokens['tokens'], 'token');
            if(!$keywords) $keywords = [$keyword];
            if ($resp->getStatusCode() !== 200) {
                return null;
            }

            $respBody = json_decode($resp->getBody()->getContents(), true);
            if (empty($respBody['error'])) {
                $sortIds = collect($respBody['hits']['hits'] ?? [])->map(function ($doc) {
                    return (int)$doc['_id'];
                })->toArray();

                if (is_numeric($respBody['hits']['total'])) {
                    $total = (int)$respBody['hits']['total'];
                } else {
                    $total = (int)($respBody['hits']['total']['value'] ?? 0);
                }

                return new Result(array_slice($sortIds, 0, $perPage), $keywords, $total);
            }

            return null;
        } catch (\Exception $ex) {
            Log::error('search failed', ['message' => $ex->getMessage()]);
        }

        return null;
    }
}