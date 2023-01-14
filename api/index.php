<?php

/**
 * Easy Blog (EB) Api file
 *
 * support：notion api + php + vercel + github
 *
 * notion docs
 * https://developers.notion.com/reference
 *
 * vercel
 * https://vercel.com/
 *
 *
 */

header("Access-Control-Allow-Origin: *");

class Index
{
    # notion token
    private string $token;
    # notion database id
    private string $databaseId;
    # aes encryption and encryption key, character length must be 32 bits
    private string $aesKey;
    # aes encryption and encryption offset, character length must be 16 bits
    private string $aesIv;
    # method name
    private string $method;
    # notion page id
    private string $pageId;
    # notion number of pages
    private int $pageSize = 20;
    # notion page
    private string $page;
    # whether backend model
    private bool $isBackend;
    # operate key [Role: data change verification]
    private string $operateKey;

    public function __construct()
    {
        error_reporting(E_ALL ^ E_NOTICE);
        $this->token = $_ENV['NOTION_TOKEN'] ?? '';
        $this->databaseId = $_ENV['DATABASE_ID'] ?? '';
        $this->aesKey = $_ENV['AES_KEY'] ?? '';
        $this->aesIv = $_ENV['AES_IV'] ?? '';
        $this->operateKey = $_ENV['OPERATE_KEY'] ?? '';
        if (empty($this->token) && empty($this->databaseId) && empty($this->aesKey) && empty($this->aesIv) && empty($this->operateKey)) {
            $this->retJson([],"Missing required environment variables",400);
        }
        $this->method = $this->getParam('m');
        if(in_array(strtolower($this->method),['create','edit','delete'])){
            $this->verifyToken();
        }
        $this->pageId = $this->getParam('pid');
        $this->pageSize = (int)$this->getParam('size') ?? 20;
        $this->page = $this->getParam('current');
        $this->isBackend = $this->getParam('backend');
    }

    public function handle()
    {
        # call the specified method
        return method_exists($this, $this->method) ? call_user_func([$this, $this->method]) : $this->retJson([],"{$this->method} is not exist",400);
    }

    # Anti-sql injection
    private function getParam($param)
    {
        return !empty($_REQUEST[$param]) ? htmlentities(urldecode($_REQUEST[$param]), ENT_QUOTES, 'UTF-8') : '';
    }

    # list
    public function rows()
    {
        $params = $this->filterCriteria();
        $res = $this->curlSend('https://api.notion.com/v1/databases/'.$this->databaseId.'/query',$params);
        $list = [];
        if (!empty($res['results'])) {
            foreach ($res['results'] as $v) {
                $list[] = [
                    'id'=>$v['id'],
                    'created_at'=>$this->isoDateToDateTime($v['properties']['created_at']['created_time']),
                    'updated_at'=>$this->isoDateToDateTime($v['properties']['updated_at']['last_edited_time']),
                    'status'=>$v['properties']['status']['number'],
                    'tags'=>$v['properties']['tags']['rich_text'][0]['plain_text'],
                    'title'=>(strlen($v['properties']['title']['title'][0]['plain_text'])>100) ? mb_substr($v['properties']['title']['title'][0]['plain_text'], 0, 100).'...' : $v['properties']['title']['title'][0]['plain_text'],
                ];
            }
        }
        $this->retJson(
            [
                'next_cursor'=>$res['next_cursor'] ?? '',
                'list'=>$list,
            ]
        );
    }

    # list filter
    private function filterCriteria()
    {
        $filterParams = [
            'tf' => $this->getParam('tf'),// title filter
            'tbf' => $this->getParam('tbf'),// tags filter
            'sf' => isset($_REQUEST['sf']) ? $_REQUEST['sf'] : '',// status filter
        ];
        $params['page_size'] = $this->pageSize;
        if($this->page) {
            $params['start_cursor'] = $this->page;
        }
        $filter = [];
        if (!$this->isBackend) {
            $filter[] = [
                "property"=> "status",
                "number"=> [
                    "equals"=> 1
                ]
            ];
        } else {
            if(is_numeric($filterParams['sf'])){
                $filter[] = [
                    "property"=> "status",
                    "number"=> [
                        "equals"=> (int)$filterParams['sf']
                    ]
                ];
            }
        }
        if($filterParams['tf'] || $filterParams['tbf']) {
            if($filterParams['tf']){
                $filter[] = [
                    "property"=> "title",
                    "title"=> [
                        "contains"=> $filterParams['tf']
                    ]
                ];
            }
            if($filterParams['tbf']){
                $filter[] = [
                    "property"=> "tags",
                    "rich_text"=> [
                        "contains"=> $filterParams['tbf']
                    ]
                ];
            }
        }
        if($filter) {
            $params['filter'] = ["and" => $filter];
        }
        // to sort
        $params['sorts'] = [
            ['property'=> 'updated_at', 'direction'=> 'descending'],
            ['property'=> 'created_at', 'direction'=> 'descending'],
        ];

        return $params;
    }

    # detail
    public function detail()
    {
        if (empty($this->pageId)) $this->retJson([],'Unknown object',400);
        $preview = $this->curlSend('https://api.notion.com/v1/pages/'.$this->pageId,[],'GET');
        $block = $this->curlSend('https://api.notion.com/v1/blocks/'.$this->pageId.'/children',[],'GET');
        $properties = $preview['properties'] ?? [];
        if (empty($properties)) $this->retJson([],'Unknown object',400);
        if (count($block['results']) > 1) {
            $content = '';
            foreach ($block['results'] as $v) {
                $content .= $v['paragraph']['rich_text'][0]['plain_text'];
            }
        } else {
            $content = $block['results'][0]['paragraph']['rich_text'][0]['plain_text'];
        }
        $this->retJson([
            'pid'=>$this->pageId,
            'status'=>$properties['status']['number'],
            'tags'=>$properties['tags']['rich_text'][0]['plain_text'],
            'title'=>$properties['title']['title'][0]['plain_text'],
            'content'=>$content,
        ]);
    }

    # create
    public function create()
    {
        if(empty($this->getParam('title'))) $this->retJson([],'Please complete this required field.',400);
        if(empty($this->getParam('tags'))) $this->retJson([],'Please complete this required field.',400);
        if(empty($this->getParam('content'))) $this->retJson([],'Please complete this required field.',400);
        $data = [
            'parent'=>[
                'type' => 'database_id',
                'database_id' => $this->databaseId
            ],
        ];
        $data = array_merge($data,$this->structure());
        $res = $this->curlSend('https://api.notion.com/v1/pages',$data);
        $this->retJson($res,'Created successfully');
    }

    # Get the created structure
    private function structure($isEdit = false)
    {
        $content = $this->getParam('content');
        $limit_str = 2000;
        $strLen = mb_strlen($content,'UTF-8');
        if ($strLen > $limit_str) {
            $format = [];
            $ret = array();
            for ($i = 0; $i < $strLen; $i += $limit_str) {
                $ret[] = mb_substr($content, $i, $limit_str,'UTF-8');
            }
            if (!empty($ret)) {
                foreach ($ret as $v) {
                    if ($isEdit) {
                        $format[] = [
                            "paragraph"=> [
                                "rich_text"=> [
                                    [
                                        "type"=> "text",
                                        "text"=> [
                                            "content"=> $v,
                                            "link"=> null
                                        ]
                                    ]
                                ]
                            ],
                        ];
                    } else {
                        $format[] = [
                            'object'=>'block',
                            "type"=> "paragraph",
                            "paragraph"=> [
                                "rich_text"=> [
                                    [
                                        "type"=> "text",
                                        "text"=> [
                                            "content"=> $v,
                                            "link"=> null
                                        ]
                                    ]
                                ]
                            ],
                        ];
                    }
                }
            }
        } else {
            if ($isEdit) {
                $format = [
                    [
                        "paragraph"=> [
                            "rich_text"=> [
                                [
                                    "type"=> "text",
                                    "text"=> [
                                        "content"=> $content,
                                        "link"=> null
                                    ]
                                ]
                            ]
                        ],
                    ]
                ];
            } else {
                $format = [
                    [
                        'object'=>'block',
                        "type"=> "paragraph",
                        "paragraph"=> [
                            "rich_text"=> [
                                [
                                    "type"=> "text",
                                    "text"=> [
                                        "content"=> $content,
                                        "link"=> null
                                    ]
                                ]
                            ]
                        ],
                    ]
                ];
            }
        }
        return [
            'properties'=>[
                'title' => [
                    'id' => 'title',
                    'type' => 'title',
                    'title' => [
                        [
                            'type'=>'text',
                            'text'=>[
                                'content'=>$this->getParam('title'),
                                'link'=>null
                            ],
                        ]
                    ],
                ],
                'tags'=>[
                    'type'=>'rich_text',
                    'rich_text'=>[
                        [
                            'type'=>'text',
                            'text'=>[
                                'content'=>$this->getParam('tags'),
                                'link'=>null
                            ],
                        ]
                    ]
                ],
                'status'=>[
                    'number' => (int)$this->getParam('status')
                ]
            ],
            'children'=>$format,
        ];
    }

    # edit
    public function edit()
    {
        if (empty($this->pageId)) $this->retJson([],'Unknown object',400);
        if(empty($this->getParam('title'))) $this->retJson([],'Please complete this required field.',400);
        if(empty($this->getParam('tags'))) $this->retJson([],'Please complete this required field.',400);
        if(empty($this->getParam('content'))) $this->retJson([],'Please complete this required field.',400);

        $page = $this->curlSend('https://api.notion.com/v1/pages/'.$this->pageId,[],'GET');
        $block = $this->curlSend('https://api.notion.com/v1/blocks/'.$this->pageId.'/children',[],'GET');
        if (empty($page['properties'])&&empty($block['results'][0]['id'])) $this->retJson([],'Unknown object',400);

        $data = $this->structure(true);

        foreach ($block['results'] as $v) {
            $this->curlSend('https://api.notion.com/v1/blocks/'.$v['id'],[],'DELETE');
        }

        // update page data
        $res = $this->curlSend('https://api.notion.com/v1/pages/'.$this->pageId,['properties'=>$data['properties']],'PATCH');
        // update block data
        $res1 = $this->curlSend('https://api.notion.com/v1/blocks/'.$this->pageId.'/children',['children'=>$data['children']],'PATCH');
        if (isset($res['status']) && $res['status'] == 400) {
            $this->retJson($res,'Update failed',400);
        }
        if (isset($res1['status']) && $res1['status'] == 400) {
            $this->retJson($res1,'Update failed',400);
        }
        $this->retJson([],'Update successfully');
    }

    # delete
    public function delete()
    {
        $res = $this->curlSend('https://api.notion.com/v1/blocks/'.$this->pageId,[],'DELETE');
        $this->retJson($res,'Deleted successfully');
    }

    private function curlSend($url = '', $data = [], $method = 'POST')
    {
        $curl = curl_init();

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => [
                'Notion-Version: 2022-06-28',
                'accept: application/json',
                'authorization: Bearer ' . $this->token,
                'content-type: application/json'
            ],
        ];
        if (!empty($data)) {
            $options[CURLOPT_POSTFIELDS] = json_encode($data,JSON_UNESCAPED_UNICODE);
        }

        curl_setopt_array($curl, $options);

        $response = curl_exec($curl);
        $err = curl_error($curl);

        curl_close($curl);

        if ($err) {
            $this->retJson([],$err,400);
        } else {
            return json_decode(htmlspecialchars_decode($response),true);
        }
    }

    private function isoDateToDateTime($isoDate = '')
    {
        return date("Y-m-d H:i:s",strtotime($isoDate));
    }

    private function retJson($data = [], $msg = 'ok', $code = 200)
    {
        exit(json_encode(['code'=>$code,'msg'=>$msg,'data'=>$data]));
    }

    private function verifyToken()
    {
        $token = !empty($_SERVER['HTTP_TOKEN']) ? $_SERVER['HTTP_TOKEN'] : '';
        if(empty($token)) $this->retJson([],'Token invalid',403);
        $token = openssl_decrypt(base64_decode($token), 'AES-256-CBC', $this->aesKey, true, $this->aesIv);
        $data = !empty($token) ? json_decode($token) : null;
        if (empty($data)) $this->retJson([],'Token invalid',403);
        if(empty($data->exp) || $data->exp <= time()) $this->retJson([],'Token invalid',403);
        if(empty($data->iat) || $data->iat != $_SERVER['REMOTE_ADDR']) $this->retJson([],'Token invalid',403);
        if(empty($data->key) || $data->key != $this->operateKey) $this->retJson([],'Token invalid',403);
        return true;
    }

    public function login()
    {
        $operateKey = $this->getParam('operate_key');
        if (empty($operateKey) || $operateKey != $this->operateKey) {
            $this->retJson([],'Operation key is incorrect',400);
        }
        $this->retJson(
            [
                'token' => base64_encode(openssl_encrypt(
                    json_encode(['exp'=>time()+86400,'iat'=>$_SERVER['REMOTE_ADDR'],'key'=>$this->operateKey]),
                    'AES-256-CBC',
                    $this->aesKey,
                    true,
                    $this->aesIv
                ))
            ]
        );
    }
    
    # Get the last 15 days weather
    public function weather()
    {
        $url = $_ENV['WEATHER_URL'] ?? 'https://tianqi.moji.com/forecast15/china/guangdong/shenzhen';
        if(empty($url)) $this->retJson(['c'=>$addr],'Unknown location',400);
        $html = file_get_contents($url);
        $res = $this->get_tag_data($html,'ul','class','clearfix');
        if(empty($res)) $this->retJson([],'Get weather error location',400);
        $week = $this->get_tag_data($res[0],'span','class','week');
        $temperature = $this->get_tag_data($res[0],'div','class','tree clearfix');
        $wea = $this->get_tag_data($res[0],'span','class','wea');
        $cycle = [];
        if(!empty($week)) {
            foreach ($week as $k => $v){
                if($k%2!=0){
                    $cycle[$k-1]['week'] = $v;
                } else {
                    $cycle[$k]['date'] = $v;
                    if (!empty($wea[$k]) && !empty($wea[$k+1])) {
                        $cycle[$k]['wea'] = $wea[$k] === $wea[$k+1] ? $wea[$k] : $wea[$k] . ' => ' . $wea[$k+1];
                    }
                }
            }
        }
        $cycle = !empty($cycle) ? array_merge($cycle) : [];
        if(!empty($cycle)) {
            foreach ($cycle as $k => $v) {
                if(!empty($temperature[$k])) {
                    preg_match_all('/<b>(.*?)<\/b>/',$temperature[$k],$max);
                    preg_match_all('/<strong>(.*?)<\/strong>/',$temperature[$k],$min);
                }
                $cycle[$k]['max'] = !empty($max[1][0]) ? $max[1][0] : 'unknown';
                $cycle[$k]['min'] = !empty($min[1][0]) ? $min[1][0] : 'unknown';
            }
        }
        $this->retJson($cycle);
    }
    
    private function get_tag_data($html,$tag,$class,$value){
        // $value is empty, then get all the content of class=$class
        $regex = $value ? "/<$tag.*?$class=\"$value\".*?>(.*?)<\/$tag>/is" :  "/<$tag.*?$class=\".*?$value.*?\".*?>(.*?)<\/$tag>/is";
        preg_match_all($regex,$html,$matches);
        // The return value is an array, the contents of the tag found
        return $matches[1];
    }
}

print_r((new Index())->handle());
