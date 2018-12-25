<?php

use QL\QueryList;
use GuzzleHttp\Exception\RequestException;

class FatRatCrawl
{

    protected $wpdb;
    protected $table_post;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table_post = $wpdb->prefix . 'fr_post';
        $this->table_options = $wpdb->prefix . 'fr_options';
    }

    /**
     * todo 安全域名
     * todo 编码
     * todo 阅读全文
     * todo 入库 合并
     * todo 验证是否重复 合并
     *
     * todo 计时任务
     * todo 内容 -
     * todo 链接去除
     * todo 关键字替换
     * todo 图片跳转的URL处理
     */
    public function crawl_run($option_id)
    {
        if (empty($option_id)){
            return false;
        }

        $option = $this->option_info($option_id);
        if (empty($option)){
            return false;
        }

        $articles = $this->_QueryList($option['collect_list_url'], $option['collect_remove_head'])
            ->range($option['collect_list_range'])
            ->encoding('UTF-8')
            ->rules( $this->rulesFormat($option['collect_list_rules']) )
            ->query(function($item) use ($option) {
                // 新闻详情

                // 目前只爬当前域名
                if (!empty($item['link']) && parse_url($item['link'])['host'] == parse_url($option['collect_list_url'])['host']) {
                    try {
                        $ql = $this->_QueryList($item['link'], $option['collect_remove_head'])
                                ->range($option['collect_content_range'])
                                ->encoding('UTF-8')
                                ->rules( $this->rulesFormat($option['collect_content_rules']) )
                                ->queryData();
                    } catch (RequestException $e) {
                        self::log($e, 'error');
                        return false;
                    }

                    $ql = current($ql);
                    $item = array_merge($item, $ql);

                    // 图片本地化
                    $item = $this->matching_img($item);

                    return $item;
                }
                return false;
            })
        ->getData();

        // 过滤
        if ($articles->isEmpty()){
            return false;
        }

        $sign = $this->wpdb->get_results(
            "select md5(`link`) as `sign` from $this->table_post where `post_type` = $option_id order by id desc limit 200",
            ARRAY_A
        );
        $last_sign_array = array_column($sign, 'sign');

        $articles = $articles->filter(function ($item) use ($last_sign_array) {
            if ($item != false && !in_array(md5($item['link']), $last_sign_array)) {
                return true;
            }
            return false;
        });

        // 写
        $http = new \GuzzleHttp\Client();
        $articles->map(function ($article, $i) use ($http, $option_id) {
            if ($article != false && !empty($article['title']) && !empty($article['content'])) {
                $data['title'] = $this->text_keyword_replace($article['title'], $option_id);
                $data['content'] = $this->text_keyword_replace($article['content'], $option_id);
                $data['image'] = isset($article['image']) ? $article['image'] : '';
                $data['post_type'] = $option_id;
                $data['link'] = $article['link'];
                $data['author'] = get_current_user_id();
                $data['created'] = date('Y-m-d H:i:s');
                // 入库
                $this->wpdb->insert($this->table_post, $data);
                // 下载图片
                $this->download_img($article['download_img']);
            }
        });

        return true;
    }

    protected function _QueryList($url, $remove_head){
        if ( $remove_head == 1 ){
            return QueryList::get($url)->removeHead();
        }

        return QueryList::get($url);
    }

    /**
     * 微信爬取
     * @param string $collect_wx_urls
     * @return bool
     */
    public function crawl_wx_run($collect_wx_urls = ''){

        if ($collect_wx_urls == ''){
            return false;
        }


        $ql = QueryList::range('#img-content')
            ->range('#img-content')
            ->encoding('UTF-8')
            ->rules([
                'title' => ['h2', 'text'],
                'content' => ['#js_content', 'html'],
            ]);

        collect(explode(' ', $collect_wx_urls))->map(function($collect_wx_url) use ($ql) {
            $article = $ql->get($collect_wx_url)->queryData();
            $article = current($article);

            // 图片本地化
            $article = $this->matching_img($article, 'data-src');

            $data['title'] = $article['title'];
            $data['content'] = $article['content'];
            $data['image'] = 'wx';
            $data['post_type'] = 'wx';
            $data['link'] = $collect_wx_url;
            $data['author'] = get_current_user_id();
            $data['created'] = date('Y-m-d H:i:s');
            $this->wpdb->insert($this->table_post, $data);

            // 图片下载
            $this->download_img($article['download_img']);
        });

        return true;
    }

    protected function matching_img($article, $img_attr = 'src'){
        // 图片本地化
        $doc = phpQuery::newDocumentHTML($article['content']);
        $images = collect();
        foreach (pq($doc)->find('img') as $img) {
            // 图片名
            $originImg = pq($img)->attr($img_attr);
            $suffix = '';
            if (in_array(strtolower(strrchr($originImg, '.')), ['.jpg', '.png', '.jpeg', '.gif', '.swf'])){
                $suffix = strrchr($originImg, '.');
            } else {
                switch (getimagesize($originImg)[2]){
                    case IMAGETYPE_GIF:
                        $suffix = '.gif';
                        break;
                    case IMAGETYPE_JPEG:
                        $suffix = '.jpeg';
                        break;
                    case IMAGETYPE_PNG:
                        $suffix = '.png';
                        break;
                    case IMAGETYPE_SWF:
                        $suffix = '.swf';
                        break;
                }
            }
            $newImg = 'frc-' . md5($originImg) . $suffix;

            $article['content'] = str_replace($originImg, '/wp-content/uploads' . wp_upload_dir()['subdir'] . DIRECTORY_SEPARATOR . $newImg, $article['content']);
            // data-src to src
            if ($img_attr != 'src'){
                $article['content'] = str_replace('data-src="', 'src="', $article['content']);
            }
            // 存起来。图片下载
            $images->put($newImg, $originImg);
        }
        $article['download_img'] = $images;

        return $article;
    }

    protected function download_img(Illuminate\Support\Collection $download_img){
        $http = new \GuzzleHttp\Client();
        $download_img->map(function ($url, $imgName) use ($http) {
            try {
                $data = $http->request('get', $url)->getBody()->getContents();
                file_put_contents(wp_upload_dir()['path'] . DIRECTORY_SEPARATOR . $imgName, $data);
            } catch (\Exception $e) {
                self::log($e, 'error');
            }
        });
    }

    public function option_list()
    {
        return $this->wpdb->get_results("select * from $this->table_options",ARRAY_A);
    }

    public function option_info($option_id)
    {
        return $this->wpdb->get_row("select * from $this->table_options where `id` = $option_id",ARRAY_A);
    }

    private function rulesFormat($rules)
    {
        $resRule = [];
        collect( explode(")(", $rules) )->map(function ($item) use (&$resRule){
            list($key, $value) = explode("%", $item);
            list($label, $rule, $filter) = explode("|", $value);
            $label == 'null' && $label = null;
            $rule == 'null' && $rule = null;
            $filter == 'null' && $filter = null;
            $resRule[$key] = [$label, $rule, $filter];
        });

        return $resRule;
    }

    private function text_keyword_replace($text, $option_id)
    {
        if (!$text || !$option_id) {
            return $text;
        }
        $options = $this->wpdb->get_row("select * from $this->table_options where `id` = $option_id limit 1", ARRAY_A);
        $keywords_array = explode(" ", trim($options['collect_keywords_replace_rule']));

        collect($keywords_array)->map(function ($keywords) use (&$text) {
            list($string, $replace) = explode('=', $keywords);
            $text = str_replace($string, $replace, $text);
        });

        return $text;
    }

    public static function log($log_info, $log_type = 'info')
    {
        global $wpdb;
        $wpdb->insert($wpdb->prefix . 'fr_log', [
            'log_type' => $log_type,
            'log_info' => json_encode($log_info)
        ]);
    }

}

/**
 * 微信爬虫
 */
function frc_ajax_frc_wx_spider_run()
{
    $collect_wx_urls = !empty($_REQUEST['collect_wx_urls']) ? sanitize_text_field($_REQUEST['collect_wx_urls']) : 0 ;

    $crawl = new FatRatCrawl($collect_wx_urls);
    if ($crawl->crawl_wx_run($collect_wx_urls)){
        wp_send_json(['code' => 0, 'msg' => 'ok！']);
    } else {
        wp_send_json(['code' => 0, 'msg' => 'no!']);
    }
    wp_die();
}
add_action('wp_ajax_frc_wx_spider_run', 'frc_ajax_frc_wx_spider_run');

/**
 * 启动一个爬虫
 */
function frc_ajax_frc_spider_run()
{
    $option_id = !empty($_REQUEST['option_id']) ? sanitize_text_field($_REQUEST['option_id']) : 0 ;

    $crawl = new FatRatCrawl();
    if ($crawl->crawl_run($option_id)){
        wp_send_json(['code' => 0, 'msg' => '成功。']);
    } else {
        wp_send_json(['code' => 0, 'msg' => '失败了']);
    }
    wp_die();
}
add_action('wp_ajax_frc_spider_run', 'frc_ajax_frc_spider_run');


/**
 * debug 规则
 */
function frc_ajax_frc_debug_option() {

    $debug = [];
    $debug['debug_url']             = !empty($_REQUEST['debug_url']) ? esc_url($_REQUEST['debug_url']) : '';
    $debug['debug_range']           = !empty($_REQUEST['debug_range']) ? sanitize_text_field($_REQUEST['debug_range']) : '';
    $debug['debug_rules_origin']    = !empty($_REQUEST['debug_rules']) ? sanitize_text_field($_REQUEST['debug_rules']) : '';
    $debug['debug_remove_head']     = !empty($_REQUEST['debug_remove_head']) ? sanitize_text_field($_REQUEST['debug_remove_head']) : 0;
    $debug['debug_rules_new']       = !empty($_REQUEST['debug_rules']) ? rulesFormat($debug['debug_rules_origin']) : '';

    if ($debug['debug_remove_head'] == 1)
        $ql = QueryList::get($debug['debug_url'])->removeHead();
    else
        $ql = QueryList::get($debug['debug_url']);

    $info = $ql
        ->range($debug['debug_range'])
        ->encoding('UTF-8')
        ->rules( rulesFormat($debug['debug_rules_origin']) )
        ->queryData();

    $debug['result'] = $info;

    wp_send_json($debug);
    wp_die();
}
add_action( 'wp_ajax_frc_debug_option', 'frc_ajax_frc_debug_option' );

function rulesFormat($rules)
{
    $resRule = [];
    collect( explode(")(", $rules) )->map(function ($item) use (&$resRule){
        list($key, $value) = explode("%", $item);
        list($label, $rule, $filter) = explode("|", $value);
        $label == 'null' && $label = null;
        $rule == 'null' && $rule = null;
        $filter == 'null' && $filter = null;
        $resRule[$key] = [$label, $rule, $filter];
    });

    return $resRule;
}


/**
 * 定时爬取 cron
 */
if (!wp_next_scheduled('frc_cron_spider_hook')) {
    wp_schedule_event(time(), 'twicedaily', 'frc_cron_spider_hook');
}

function frc_spider_timing_task()
{
    $crawl = new FatRatCrawl();
    $options = $crawl->option_list();
    foreach ($options as $option){
        $crawl->crawl_run($option['id']);
    }

    FatRatCrawl::log(['message' => 'frc_spider_timing_task', 'date' => date('Y-m-d H:i:s')] , 'auto');
}
add_action('frc_cron_spider_hook', 'frc_spider_timing_task');
//wp_clear_scheduled_hook('frc_cron_spider_hook');


function frc_spider()
{
    $crawl = new FatRatCrawl();
    $options = $crawl->option_list();
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('胖鼠爬虫', 'Far Rat Collect') ?></h1>
        <p></p>
        <span>胖鼠采集 一个可以定时采集列表新闻的采集小工具</span>
        <p></p>
        <div>

            <!-- bootstrap tabs -->
            <ul class="nav nav-tabs">
                <li class="active"><a href="#single" data-toggle="tab">微信爬虫</a></li>
                <li><a href="#list" data-toggle="tab">批量采集爬虫配置列表</a></li>
                <li><a href="#todolist" data-toggle="tab">ToDo List</a></li>
            </ul>
            <div class="tab-content">
                <input type="hidden" hidden id="request_url" value="<?php echo admin_url('admin-ajax.php'); ?>">
<!--                微信爬虫-->
                <div class="tab-pane fade in active" id="single">
                    <table class="form-table">
                        <tr>
                            <th>微信文章地址</th>
                            <td>
                                <textarea name="collect_wx_urls" cols="80" rows="14" placeholder="多篇文章使用回车区分,一行一个。每次不要太多、要对自己的服务器心里要有数"></textarea>
                            </td>
                        </tr>
                        <tr>
                            <th colspan="2">
                                <!-- bootstrap进度条 -->
                                <div class="progress progress-striped active">
                                    <div id="bootstrop-progress-bar" class="progress-bar progress-bar-success wx-spider-run-button" role="progressbar"
                                         aria-valuenow="60" aria-valuemin="0" aria-valuemax="100"
                                         style="width: 0%;">
                                        <span class="sr-only">90% 完成（成功）</span>
                                    </div>
                                </div>
                                <input class="button button-primary" type="button" id="wx-spider-run-button" value="运行"/>
                            </th>
                        </tr>
                    </table>
                </div>
<!--                列表爬虫-->
                <div class="tab-pane fade" id="list">
                    <div class="list-group">
                        <p></p>
                        <a disabled class="list-group-item active">
                            <h5 class="list-group-item-heading">
                                爬虫配置列表(点击手动执行)
                            </h5>
                        </a>
                        <?php
                        if (!$options) {
                            echo '<a href="' . admin_url('admin.php?page=frc-options-add-edit') . '" class="list-group-item"><h4 class="list-group-item-heading">注意: 你目前没有任何一个批量的爬虫配置。</h4><p class="list-group-item-text">点击去创建去一个列表爬虫规则</p></a>';
                        } else {
                            foreach ($options as $option) {
                                echo '<a href="#" data-id="' . $option['id'] . '" class="spider-run-button list-group-item"><h5 class="list-group-item-heading">' . $option['collect_name'] . '</h5></a>';
                            }
                        }
                        ?>
                        <p></p>
                        <!-- bootstrap进度条 -->
                        <div class="progress progress-striped active">
                            <div id="bootstrop-progress-bar" class="progress-bar progress-bar-success list-spider-run-button" role="progressbar"
                                 aria-valuenow="60" aria-valuemin="0" aria-valuemax="100"
                                 style="width: 0%;">
                                <span class="sr-only">90% 完成（成功）</span>
                            </div>
                        </div>
                    </div>
                    <br/>
                    <br/>
                    <div>注释: </div>
                    <div>// 点击手动执行一次对单独网站的爬取。</div>
                    <div>// 定时爬取已开启！ 一日两次（每次间隔12小时）爬取配置中所有的网站。(后期是优化用户可以自定义时间)</div>
                    <div>// 默认给你创建了五个网站的列表爬取配置。供参考学习</div>
                    <div>// 没爬到 失败的原因: 配置错误 - 采集超时 - 没有图片目录权限 - 文章被滤重了</div>
                    <div>// 图片是本地自动化</div>
                    <div>// 文章滤重 同一个配置 只滤近期200篇文章以内的重复文章</div>
                    <div>// 17173 列表页 有文章也有论坛帖子。暂时只抓文章。 论坛帖子内容是ajax 所以17173可能抓到文章比较少</div>
                    <div>// 图片目前默认使用相对路径。</div>
                </div>
                <div class="tab-pane fade" id="todolist">
                    <h1>ToDo</h1>
                    <div>Todo: 发布时增加发布分类功能</div>
                    <div>Todo: 发布时增加选择作者功能</div>
                    <div>Todo: Img 指定标签是 src 还是 data-src</div>
                    <div>Todo: 关键字替换有BUG 抽空改改</div>
                    <div>Todo: </div>
                </div>
            </div>
        </div>
    </div>
    <?php
}
