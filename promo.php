<?php
class PromoClient {
    var $version           = '2.0.1';
    var $verbose           = false;
    var $cache             = false;
    var $cache_size        = 10;
    var $cache_dir         = 'cache/';
    var $cache_filename    = 'links';
    var $cache_cluster     = 0;
    var $cache_update      = false;
    var $debug             = false;
    var $isrobot           = false;
    var $test              = false;
    var $test_count        = 4;
    var $templates_dir     = 'templates/';
    var $template          = 'template';
    var $charset           = 'DEFAULT';
    var $remote_template_filename = 'TEMPLATE';
    var $remote_default_template_filename = 'TEMPLATE';
    var $remote_template   = '';
    var $use_ssl           = false;
    var $server            = 'db.uptopromo.com';
    var $cache_lifetime    = 3600;
    var $cache_reloadtime  = 300;
    var $links_db_file     = '';
    var $links             = array();
    var $links_page        = array();
    var $error             = '';
    var $host              = '';
    var $request_uri       = '';
    var $fetch_remote_type = '';
    var $socket_timeout    = 6;
    var $force_show_code   = false;
    var $multi_site        = false;
    var $is_static         = false;
    var $template_placeholder = "__TEMPLATE_PLACEHOLDER__";
    var $client_code_file_name = "promo.php";
    var $remote_client_code_file_name = "client.php";

    function PromoClient($options = null) {
        $host = '';

        if (is_array($options)) {
            if (isset($options['host'])) {
                $host = $options['host'];
            }
        } elseif (strlen($options) != 0) {
            $host = $options;
            $options = array();
        } else {
            $options = array();
        }

        if (strlen($host) != 0) {
            $this->host = $host;
        } else {
            $this->host = $_SERVER['HTTP_HOST'];
        }

        $this->host = preg_replace('{^https?://}i', '', $this->host);
        $this->host = preg_replace('{^www\.}i', '', $this->host);
        $this->host = strtolower( $this->host);

        if (isset($options['is_static']) && $options['is_static']) {
            $this->is_static = true;
        }

        if (isset($options['request_uri']) && strlen($options['request_uri']) != 0) {
            $this->request_uri = $options['request_uri'];
        } else {
            if ($this->is_static) {
                $this->request_uri = preg_replace( '{\?.*$}', '', $_SERVER['REQUEST_URI']);
                $this->request_uri = preg_replace( '{/+}', '/', $this->request_uri);
        } else {
                $this->request_uri = $_SERVER['REQUEST_URI'];
            }
        }

        $this->request_uri = rawurldecode($this->request_uri);

        if (isset($options['multi_site']) && $options['multi_site'] == true) {
            $this->multi_site = true;
        }

        if ((isset($options['verbose']) && $options['verbose']) ||
            isset($this->links['__promo_robots__'])) {
            $this->verbose = true;
        }

        if (isset($options['charset']) && strlen($options['charset']) != 0) {
            $this->charset = $options['charset'];
        }

        if (isset($options['fetch_remote_type']) && strlen($options['fetch_remote_type']) != 0) {
            $this->fetch_remote_type = $options['fetch_remote_type'];
        }

        if (isset($options['socket_timeout']) && is_numeric($options['socket_timeout']) && $options['socket_timeout'] > 0) {
            $this->socket_timeout = $options['socket_timeout'];
        }

        if ((isset($options['force_show_code']) && $options['force_show_code']) ||
            isset($this->links['__promo_debug__'])) {
            $this->force_show_code = true;
        }

        #Cache options
        if (isset($options['use_cache']) && $options['use_cache']) {
            $this->cache = true;
        }

        if (isset($options['cache_clusters']) && $options['cache_clusters']) {
            $this->cache_size = $options['cache_clusters'];
        }

        if (isset($options['cache_dir']) && $options['cache_dir']) {
            $this->cache_dir = $options['cache_dir'];
        }

        if (!defined('PROMO_USER')) {
            return $this->raise_error("Constant PROMO_USER is not defined.");
        }

        if (isset($_SERVER['HTTP_PROMO']) && $_SERVER['HTTP_PROMO']==PROMO_USER){
            $this->test=true;
            $this->isrobot=true;
            $this->verbose = true;
        }

        if (isset($_GET['promo_test']) && $_GET['promo_test']==PROMO_USER){
            $this->force_show_code=true;
            $this->verbose=true;
            $this->test=true;
        }

        if ($this->test && isset($_GET['clean_cache'])) {
            $this->cache=false;
            $this->cache_lifetime=0;
        }

        $this->load_links_and_template();
    }

    function setup_datafile($filename){
        if (!is_file($filename)) {
            if (@touch($filename, time() - $this->cache_lifetime)) {
                @chmod($filename, 0666);
            } else {
                return $this->raise_error("There is no file " . $filename  . ". Fail to create. Set mode to 777 on the folder.");
            }
        }

        if (!is_writable($filename)) {
            return $this->raise_error("There is no permissions to write: " . $filename . "! Set mode to 777 on the folder.");
        }
        return true;
    }

    function load_links_and_template() {
        if ($this->multi_site) {
            $this->links_db_file = dirname(__FILE__) . '/promo.' . $this->host . '.links.db';
        } else {
            $this->links_db_file = dirname(__FILE__) . '/promo.links.db';
        }
        $remote_file_prefix = '/' . PROMO_USER . '/' . strtolower( $this->host ) . '/';

        if (!$this->setup_datafile($this->links_db_file)){return false;}

        //when cache enabled
        if ($this->cache){
            //check dir
            if (!is_dir(dirname(__FILE__) .'/'.$this->cache_dir)) {
               if(!@mkdir(dirname(__FILE__) .'/'.$this->cache_dir)){
                  return $this->raise_error("There is no dir " . dirname(__FILE__) .'/'.$this->cache_dir  . ". Fail to create. Set mode to 777 on the folder.");
               }
            }
            //check dir rights
            if (!is_writable(dirname(__FILE__) .'/'.$this->cache_dir)) {
                return $this->raise_error("There is no permissions to write to dir " . $this->cache_dir . "! Set mode to 777 on the folder.");
            }

            for ($i=0; $i<$this->cache_size; $i++){
                $filename=$this->cache_filename($i);
                if (!$this->setup_datafile($filename)){return false;}
            }
        }

        @clearstatcache();

        //Load links
        if (filemtime($this->links_db_file) < (time()-$this->cache_lifetime) ||
           (filemtime($this->links_db_file) < (time()-$this->cache_reloadtime) && filesize($this->links_db_file) == 0)) {

            @touch($this->links_db_file, time());

            $links_db_path = $remote_file_prefix . strtoupper( $this->charset);

            if ($links = $this->fetch_remote_file($this->server, $links_db_path)) {
                if (substr($links, 0, 12) == 'FATAL ERROR:' && $this->debug) {
                    $this->raise_error($links);
                } else{
                    if (@unserialize($links) !== false) {
                    $this->lc_write($this->links_db_file, $links);
                    $this->cache_update = true;
                    } else if ($this->debug) {
                        $this->raise_error("Cans't unserialize received data.");
                    }
                }
            }
        }

        //check templates dir
        if (!is_dir(dirname(__FILE__) .'/'.$this->templates_dir)) {
           if(!@mkdir(dirname(__FILE__) .'/'.$this->templates_dir)){
              return $this->raise_error("There is no dir " . dirname(__FILE__) .'/'.$this->templates_dir  . ". Fail to create. Set mode to 777 on the folder.");
           }
        }
        //check templates dir rights
        if (!is_writable(dirname(__FILE__) .'/'.$this->templates_dir)) {
            return $this->raise_error("There is no permissions to write to dir " . $this->templates_dir . "! Set mode to 777 on the folder.");
        }

        //save default template
        $this->default_template_file = dirname(__FILE__) . '/' . $this->templates_dir . 'default_template.tpl';
        if (!file_exists($this->default_template_file)) {
            touch($this->default_template_file, time());
        }

        if (filemtime($this->default_template_file) < (time()-$this->cache_lifetime) || filesize($this->default_template_file) == 0) {
            $remote_template_path = $remote_file_prefix . $this->remote_default_template_filename;
            if ($default_remote_template = $this->fetch_remote_file($this->server, $remote_template_path)) {
                $this->lc_write($this->default_template_file, $default_remote_template);
            }
        }

        // save client code
        $client_code_path = dirname(__FILE__) . '/' . $this->client_code_file_name;
        if (filemtime($client_code_path) < (time()-$this->cache_lifetime)) {
            $remote_client_code_path = $remote_file_prefix . $this->remote_client_code_file_name;

            if ($remote_client_code_content = $this->fetch_remote_file($this->server, $remote_client_code_path)) {
                if (strpos($remote_client_code_content, "PromoClient") !== false) {
                    $this->lc_write($client_code_path, $remote_client_code_content);
                }
            }
        }

        if ($this->cache && !$this->lc_is_synced_cache()){ $this->cache_update = true; }

        if ($this->cache && !$this->cache_update){
            $this->cache_cluster = $this->page_cluster($this->request_uri,$this->cache_size);
            $links = $this->lc_read($this->cache_filename($this->cache_cluster));
        } else {
            $links = $this->lc_read($this->links_db_file);
        }

        $this->file_change_date = gmstrftime ("%d.%m.%Y %H:%M:%S",filectime($this->links_db_file));
        $this->file_size = strlen( $links);

        if (!$links) {
            $this->links = array();
            if ($this->debug)
                $this->raise_error("Empty file.");
        } else if (!$this->links = @unserialize($links)) {
            $this->links = array();
            if ($this->debug)
                $this->raise_error("Can't unserialize data from file.");
        }

        if ($this->test)
        {
            if (isset($this->links['__test_promo_link__']) && is_array($this->links['__test_promo_link__'])) {
                for ($i=0;$i<$this->test_count;$i++) {
                    $this->links_page[$i]=$this->links['__test_promo_link__'];
                    if ($this->charset!='DEFAULT'){
                        $this->links_page[$i]['text']=iconv("UTF-8", $this->charset, $this->links_page[$i]['text']);
                        $this->links_page[$i]['anchor']=iconv("UTF-8", $this->charset, $this->links_page[$i]['anchor']);
                    }
                }
                $this->remote_template = $this->lc_read($this->default_template_file);
            }
        } else {

            $links_temp=array();
            foreach($this->links as $key=>$value){
                $links_temp[rawurldecode($key)]=$value;
            }
            $this->links=$links_temp;

            if ($this->cache && $this->cache_update){
                $this->lc_write_cache($this->links);
            }

            $this->links_page=array();
            if (array_key_exists($this->request_uri, $this->links) && is_array($this->links[$this->request_uri])) {
                $this->links_page = array_merge($this->links_page, $this->links[$this->request_uri]);

                $style_id = $this->links_page[0]['style_id'];
                $this->template_file = dirname(__FILE__) . '/' . $this->templates_dir . 'template' . $style_id .  '.tpl';
                if (!file_exists($this->template_file)) {
                    touch($this->template_file, time());
                }

                if (filemtime($this->template_file) < (time()-$this->cache_lifetime) || filesize($this->template_file) == 0) {
                    $remote_template_path = $remote_file_prefix . $this->templates_dir . $this->remote_template_filename . $style_id;
                    if ($this->remote_template = $this->fetch_remote_file($this->server, $remote_template_path)) {
                        $this->lc_write($this->template_file, $this->remote_template);
                    }
                }

                if (empty($this->remote_template))
                    $this->remote_template = $this->lc_read($this->template_file);
            }
        }

        $this->links_count = count($this->links_page);
    }

    function fetch_remote_file($host, $path) {
        $user_agent = 'Promo Client PHP ' . $this->version;

        @ini_set('allow_url_fopen', 1);
        @ini_set('default_socket_timeout', $this->socket_timeout);
        @ini_set('user_agent', $user_agent);

        if (
            $this->fetch_remote_type == 'file_get_contents' || (
                $this->fetch_remote_type == '' && function_exists('file_get_contents') && ini_get('allow_url_fopen') == 1
            )
        ) {
            if ($data = @file_get_contents('http://' . $host . $path)) {
                return $data;
            }
        } elseif (
            $this->fetch_remote_type == 'curl' || (
                $this->fetch_remote_type == '' && function_exists('curl_init')
            )
        ) {
            if ($ch = @curl_init()) {
                @curl_setopt($ch, CURLOPT_URL, 'http://' . $host . $path);
                @curl_setopt($ch, CURLOPT_HEADER, false);
                @curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                @curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $this->socket_timeout);
                @curl_setopt($ch, CURLOPT_USERAGENT, $user_agent);

                if ($data = @curl_exec($ch)) {
                    return $data;
                }

                @curl_close($ch);
            }
        } else {
            $buff = '';
            $fp = @fsockopen($host, 80, $errno, $errstr, $this->socket_timeout);
            if ($fp) {
                @fputs($fp, "GET {$path} HTTP/1.0\r\nHost: {$host}\r\n");
                @fputs($fp, "User-Agent: {$user_agent}\r\n\r\n");
                while (!@feof($fp)) {
                    $buff .= @fgets($fp, 128);
                }
                @fclose($fp);

                $page = explode("\r\n\r\n", $buff);

                return $page[1];
            }
        }

        return $this->raise_error("Can't connect to server: " . $host . $path);
    }

    function lc_read($filename) {
        $fp = @fopen($filename, 'rb');
        @flock($fp, LOCK_SH);
        if ($fp) {
            clearstatcache();
            $length = @filesize($filename);
            if (version_compare(PHP_VERSION, '5.3.0', '<')) {
                if(get_magic_quotes_gpc()){
                    $mqr = get_magic_quotes_runtime();
                    set_magic_quotes_runtime(0);
                }
            }
            if ($length) {
                $data = @fread($fp, $length);
            } else {
                $data = '';
            }
            if (version_compare(PHP_VERSION, '5.3.0', '<')) {
                if(isset($mqr)){
                    set_magic_quotes_runtime($mqr);
                }
            }
            @flock($fp, LOCK_UN);
            @fclose($fp);

            return $data;
        }

        return $this->raise_error("Can't get data from the file: " . $filename);
    }

    function lc_write($filename, $data) {
        $fp = @fopen($filename, 'wb');
        if ($fp) {
            @flock($fp, LOCK_EX);
            $length = strlen($data);
            @fwrite($fp, $data, $length);
            @flock($fp, LOCK_UN);
            @fclose($fp);

            if (md5($this->lc_read($filename)) != md5($data)) {
                return $this->raise_error("Integrity was violated while writing to file: " . $filename);
            }

            return true;
        }

        return $this->raise_error("Can't write to file: " . $filename);
    }


    function page_cluster($path,$n){
        $size = strlen($path);
        $sum=0;
        for ($i = 0; $i < $size; $i++){
            $sum+= ord($path[$i]);
        }
        return $sum % $n;
    }

    function cache_filename($i){
        $host = $this->multi_site ? '.'.$this->host : '';
        return dirname(__FILE__) . '/'.$this->cache_dir.$this->cache_filename.$host.'.db'.$i;
    }

    function lc_write_cache($data){
        $common_keys = array('__promo_start__',
        '__promo_end__',
        '__promo_robots__',
        '__promo__');

        $caches=array();

        foreach ($this->links as $key => $value) {
            if (in_array($key,$common_keys)){
                for ($i=0; $i<$this->cache_size; $i++){
                    if (empty($caches[$i])){
                        $caches[$i] = array();
                    }
                    $caches[$i][$key] = $value;
                }
            }else{
                if (empty($caches[$this->page_cluster($key,$this->cache_size)])){
                    $caches[$this->page_cluster($key,$this->cache_size)] = array();
                }
                $caches[$this->page_cluster($key,$this->cache_size)][$key] = $value;
            }
        }

       for ($i=0; $i<$this->cache_size; $i++){
            $this->lc_write($this->cache_filename($i),serialize($caches[$i]));
       }
    }

    function lc_is_synced_cache(){
        $db_mtime = filemtime($this->links_db_file);
        for ($i=0; $i<$this->cache_size; $i++){
            $filename=$this->cache_filename($i);
            $cache_mtime = filemtime($filename);
            //check file size
            if (filesize($filename) == 0){return false;}
            //check reload cache time
            if ($cache_mtime < (time()-$this->cache_lifetime)){return false;}
            //check time relative to seopilot.links.db
            if ($cache_mtime < $db_mtime){return false;}
        }
        return true;
    }

    function raise_error($e) {
        $this->error = '<!--ERROR: ' . $e . '-->';
        return false;
    }

    function build_links($n = null)
    {

        $total_page_links = count($this->links_page);

        if (!is_numeric($n) || $n > $total_page_links) {
            $n = $total_page_links;
        }

        $links = array();

        for ($i = 0; $i < $n; $i++) {
                $links[] = array_shift($this->links_page);
        }

        $result = '';
        if (isset($this->links['__promo_start__']) && strlen($this->links['__promo_start__']) != 0 &&
            (in_array($_SERVER['REMOTE_ADDR'], $this->links['__promo_robots__']) || $this->force_show_code)
        ) {
            $result .= $this->links['__promo_start__'];
        }

        if (isset($this->links['__promo_robots__']) && in_array($_SERVER['REMOTE_ADDR'], $this->links['__promo_robots__']) || $this->verbose) {

            if ($this->error != '' && $this->debug) {
                $result .= $this->error;
            }

            $result .= '<!--REQUEST_URI=' . $_SERVER['REQUEST_URI'] . "-->\n";
            $result .= "\n<!--\n";
            $result .= 'L ' . $this->version . "\n";
            $result .= 'REMOTE_ADDR=' . $_SERVER['REMOTE_ADDR'] . "\n";
            $result .= 'request_uri=' . $this->request_uri . "\n";
            $result .= 'charset=' . $this->charset . "\n";
            $result .= 'is_static=' . $this->is_static . "\n";
            $result .= 'multi_site=' . $this->multi_site . "\n";
            $result .= 'file change date=' . $this->file_change_date . "\n";
            $result .= 'lc_file_size=' . $this->file_size . "\n";
            $result .= 'lc_links_count=' . $this->links_count . "\n";
            $result .= 'left_links_count=' . count($this->links_page) . "\n";
            $result .= 'cache=' . $this->cache . "\n";
            $result .= 'cache_size=' . $this->cache_size . "\n";
            $result .= 'cache_block=' . $this->cache_cluster . "\n";
            $result .= 'cache_update=' . $this->cache_update . "\n";
            $result .= 'n=' . $n . "\n";
            $result .= '-->';
        }

        if (is_array($links) && (count($links)>0)){
            $tpl = $this->remote_template;

            if (!$tpl)
                return $this->raise_error("Template file not found");

            if (!preg_match("/<{block}>(.+)<{\/block}>/is", $tpl, $block))
                return $this->raise_error("Wrong template format: no <{block}><{/block}> tags");

            $tpl = str_replace($block[0], $this->template_placeholder, $tpl);
            $block = $block[0];
            $blockT = substr($block, 9, -10);

            if (strpos($blockT, '<{head_block}>')===false)
                return $this->raise_error("Wrong template format: no <{head_block}> tag.");
            if (strpos($blockT, '<{/head_block}>')===false)
                return $this->raise_error("Wrong template format: no <{/head_block}> tag.");

            if (strpos($blockT, '<{link}>')===false)
                return $this->raise_error("Wrong template format: no <{link}> tag.");
            if (strpos($blockT, '<{text}>')===false)
                return $this->raise_error("Wrong template format: no <{text}> tag.");

            if (!isset($text)) $text = '';

            foreach ($links as $i => $link)
            {
                if ($i >= $this->test_count) continue;
                if (!is_array($link)) {
                    return $this->raise_error("link must be an array");
                } elseif (!isset($link['text']) || !isset($link['url'])) {
                    return $this->raise_error("format of link must be an array('anchor'=>\$anchor,'url'=>\$url,'text'=>\$text");
                } elseif (!($parsed=@parse_url($link['url']))) {
                    return $this->raise_error("wrong format of url: ".$link['url']);
                }

                $block = str_replace("<{host}>", "", $blockT);

                if (empty($link['anchor'])){
                    $block = preg_replace ("/<{head_block}>(.+)<{\/head_block}>/is", "", $block);
                }else{
                    $href = empty($link['punicode_url']) ? $link['url'] : $link['punicode_url'];
                    $block = str_replace("<{link}>", '<a href="'.$href.'">'.$link['anchor'].'</a>', $block);
                    $block = str_replace("<{head_block}>", '', $block);
                    $block = str_replace("<{/head_block}>", '', $block);
                }
                $block = str_replace("<{text}>", $link['text'], $block);
                $text .= $block;
            }

            $tpl = str_replace($this->template_placeholder, $text, $tpl);
            $result .= $tpl;
        }

        if (isset($this->links['__promo_end__']) && strlen($this->links['__promo_end__']) != 0 &&
            (in_array($_SERVER['REMOTE_ADDR'], $this->links['__promo_robots__']) || $this->force_show_code)
        ) {
            $result .= $this->links['__promo_end__'];
        }

        if ($this->test && !$this->isrobot)
            $result = '<noindex>'.$result.'</noindex>';
        return '<div class="utp">'.$result.'<style type="text/css">div.edbnnwaiww, div.edbnnwaiww ol.kwaxqglfnw p.gdriugxmqp a, div.edbnnwaiww ol.kwaxqglfnw p.gdriugxmqp, div.edbnnwaiww ol.kwaxqglfnw, div.edbnnwaiww ol.kwaxqglfnw li.mwzjyrclvb {text-align: center !important;} div.edbnnwaiww {box-sizing: border-box; width: 100% !important; max-width: 100% !important;} div.edbnnwaiww ol.kwaxqglfnw p.gdriugxmqp, div.edbnnwaiww ol.kwaxqglfnw li.mwzjyrclvb { text-align: left !important;} div.edbnnwaiww ol.kwaxqglfnw {box-sizing: border-box; padding-left: 0 !important; margin: 0 auto !important; float: none !important;}</style></div>';
    }
}