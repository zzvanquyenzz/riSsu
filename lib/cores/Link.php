<?php
/*
 * ZenMagick - Smart e-commerce
 * Copyright (C) 2006-2010 zenmagick.org
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or (at
 * your option) any later version.
 *
 * This program is distributed in the hope that it will be useful, but
 * WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
 * General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street - Fifth Floor, Boston, MA  02110-1301, USA.
 */
?>
<?php

namespace plugins\riSsu\cores;

use plugins\riPlugin\Plugin;

/**
 * SSU rewriter.
 *
 * @author yellow1912
 * @package org.zenmagick.plugins.ssu
 */
class Link {

    private $pages = array(), $aliases = array();
    
  
    public function __construct(){
        $this->pages = Plugin::get('riPlugin.Settings')->get('riSsu.pages');   
        $default = Plugin::get('riPlugin.Settings')->get('riSsu.default');

        foreach($this->pages as $page => $options){
            if(is_array($this->pages[$page]))
                $this->pages[$page] = array_merge($default, $this->pages[$page]);
            else 
                $this->pages[$page] = $default;  
            if(!empty($options['alias']) && $options['alias'] != $page)
                $this->aliases[$options['alias']] = $page;

            // lets load the identifer, shall we?
            if(isset($options['identifier']) && !empty($options['identifier']))
                Plugin::get('riSsu.'.$this->pages[$page]['parser'])->addIdentifier($page, $options['identifier']);
        }
    }
    
    public function decode() {
        
        if(!Plugin::get('riPlugin.Settings')->get('riSsu.status')) return false;
        
        global $request_type;
        // do not do anything inside admin
        //if (ZMSettings::get('isAdmin')) return false;
        // get out if this is not an index.php page
        if (basename($_SERVER["SCRIPT_FILENAME"]) != 'index.php') return false;

        // do not decode if this is in the excluded list
        if(isset($_GET['main_page'])){
            
            // there are certain pages we need to assign new key to it
            $page = $_GET['main_page'];
            if($_GET['main_page'] == 'index' && isset($_GET['cPath']))
                $page = 'categories';
                
            if (!array_key_exists($page, Plugin::get('riPlugin.Settings')->get('riSsu.pages'))) return false;
        }
        
        // remove the catalog dir from the link
        $catalog_dir = ($request_type == 'NONSSL') ? DIR_WS_CATALOG : DIR_WS_HTTPS_CATALOG;

        $regex = array('/'.str_replace('/','\/', $catalog_dir).'/');

        $_request_uri = Plugin::get('riUtility.Uri')->getCurrent();

        $request_uri = rawurldecode($_request_uri);

        // we need to remove the extension first
        $extension = Plugin::get('riPlugin.Settings')->get('riSsu.file_extension', '');
        if(!empty($extension)){
            $request_uri = str_replace($extension, '', $request_uri);
        }

        $original_uri = trim($catalog_dir=='/' ? $request_uri : preg_replace($regex,'', $request_uri, 1), '/');

        $first_param = current(explode('/', $original_uri, 2));

        // if the index.php is in the url, lets see if we need to rebuild the path and redirect.
        if((strpos($original_uri, 'index.php') !== false)){
            if(!isset($_GET['main_page']) || empty($_GET['main_page'])){  
                // we can redirect to the shop url without the index.php
                $this->redirect($this->getPageBase());
            }
            else{                
                if (($link = $this->link($_GET['main_page'], http_build_query($_GET), $request_type)) != false) {
                    $this->redirect($link);
                }
                // else we should redirect to a page not found

            }
        }

        // if we are using multi-lang, then we should have language code at the very beginning
        if(Plugin::get('riPlugin.Settings')->get('riSsu.multi_language_status')){
            $languages_code = $first_param;

            $is_languages_code = array_key_exists($languages_code, Plugin::get('riPlugin.Settings')->get('riSsu.languages'));

            // if this is the default language, we may need redirection here
            if(Plugin::get('riPlugin.Settings')->get('riSsu.default_language_status')){
                if($languages_code == DEFAULT_LANGUAGE)
                $redirect_type = 1;
                elseif(!$is_languages_code)
                $languages_code = DEFAULT_LANGUAGE;
                // a quick hack to redirect site.com/ru to site.com/ru/ to be consistent
                elseif(strpos($current_page_url = $this->getUri(), "$languages_code/") === false){
                    $this->redirect($current_page_url.'/');
                }
            }
            elseif(!$is_languages_code){
                $languages_code = DEFAULT_LANGUAGE;
                $redirect_type = 1;
            }

            if($is_languages_code){
                $original_uri = trim(substr($original_uri, 2), '/');
                $_SESSION['languages_code'] = $languages_code;
                $_SESSION['languages_id'] = $this->getLanguagesID($languages_code);
            }
            // we commented this out and assign the language directly here because zencart will do a redirect if the language exists in GET
            // $ssu_get['language'] = $languages_code;
        }

        $ssu_get = array();

        if(empty($original_uri)){
            $ssu_get['main_page'] = 'index';
        } else {
            // if we have a link like this http://site.com/en/?blah=blahblah, we assume it is an index page
            if(substr($original_uri, 0, 1) == '?'){
                parse_str(trim($original_uri, '?'), $temp_ssu_get);
                $ssu_get = array_merge($temp_ssu_get, $ssu_get);
                $this->rebuildENV($ssu_get, $catalog_dir);
                $redirect_type = 1;
                return false;
            }

            // if we are using link alias, lets attempt to get the parsed content from cache
            if(Plugin::get('riPlugin.Settings')->get('riSsu.alias_status')){
                $uri_parts = explode('?', $original_uri);
                if(Plugin::get('riSsu.Alias')->linkToAlias($uri_parts[0]) > 0){
                    $this->redirect($this->getPageBase().implode('&', $uri_parts));
                }
                else{
                    Plugin::get('riSsu.Alias')->aliasToLink($uri_parts[0]);
                    $original_uri = isset($uri_parts[1]) ? $uri_parts[0].'?'.$uri_parts[1] : $uri_parts[0];
                }
            }

            $original_uri = str_replace(array('&amp;','&','=','?'),'/', $original_uri);

            // explode the params link into an array
            $parts = explode('/', preg_replace('/\/\/+/', '/', $original_uri));

            // we may have to convert back from alias
            if(array_key_exists($parts[0], $this->aliases))
                $parts[0] = $this->aliases[$parts[0]];
            
            // lets see if the page is in our list
            if(array_key_exists($parts[0], $this->pages)){
                $ssu_get['main_page'] = $parts[0];
                unset($parts[0]);
            }
            else{
                if(!isset($ssu_get['main_page'])){
                    foreach($this->pages as $page => $options){
                        if(Plugin::get('riSsu.'.$options['parser'])->identifyPage($parts, $ssu_get) !== false){                            
                            
                            $redirect_type = 1;
                            
                            break;
                        }
                    }
                    // found nothing?
                    if(!isset($ssu_get['main_page'])){
                        $ssu_get['main_page'] = $parts[0];
                        unset($parts[0]);
                    }
                }
    
                // we want to make sure there is no extra main_page query left
                if(($pos = array_search('main_page', $parts)) !== false){
                    unset($parts[$pos]);
                    unset($parts[$pos+1]);
                }
            }
            
            /*
             * This is where we loop thru the query parts and put things into their places
             * We need to do it this way because we want to keep the generated GET array in the correct order.
             */
            $parts = array_values($parts);
            $parts_count = count($parts);
            for($counter = 0; $counter < $parts_count; $counter++){
                $parser_encountered = false;
                foreach($this->pages as $page => $options){
                    if(!empty($options['identifier']) && strpos($parts[$counter], $options['identifier']) !== false){
                        $ssu_get = array_merge($ssu_get, Plugin::get('riSsu.'.$options['parser'])->reverseProcessParameter($parts[$counter]));
                        $redirect_type = 1;
                        $parser_encountered = true;                        
                        break;
                    }
                }
                if(!$parser_encountered)
                $ssu_get[$parts[$counter]] = isset($parts[$counter+1]) ? $parts[++$counter] : '';
            }

            $this->rebuildENV($ssu_get, $catalog_dir);
        }

        $this->validateLink($ssu_get, $_request_uri);
        return true;
    }


    /*
     * If our current link contains names, we want to make sure the names are correct,
     * otherwise we do a redirection
     */
    private function validateLink($_get, $_request_uri){
        global $request_type;
        $params = '';
        // here we will attempt to rebuild the link using $_get array, and see if it matches the current link
        // we want to take out zenid however
        $page = '';

        if(isset($_get['main_page'])) {$page = $_get['main_page']; unset($_get['main_page']);}
        if(Plugin::get('riPlugin.Settings')->get('riSsu.multilang_status') && $_SESSION['languages_code'] == $_get['language']) {unset($_get['language']);}

        // no need to include session id
        $session_name = zen_session_name();
        if(isset($_get[$session_name])) unset($_get[$session_name]);

        foreach($_get as $key => $value)
        $params .= '&' . $key . '=' . $value;

        $regenerated_link = $this->link($page, $params, $request_type, true, true, false, true, false);

        $current_url = trim($this->getPageBase(), '/') . $_request_uri;
        //echo $regenerated_link;echo $current_url;die();
        if($regenerated_link != '' && ($current_url != $regenerated_link)){
            $this->redirect($regenerated_link);
        }
    }

    private function redirect($link){
        // Set POST form info / alpha testing
        if($_SERVER["REQUEST_METHOD"] == 'POST')
        $_SESSION['ssu_post'] = $_POST;
        Header( "HTTP/1.1 301 Moved Permanently" );
        Header( "Location: $link" );
        exit();
    }

    /**
     * Enter description here...
     *
     * @param unknown_type $_get
     */
    private function rebuildENV($ssu_get, $catalog_dir){
        $_GET = array_merge($_GET, $ssu_get);
        $_REQUEST = array_merge($_REQUEST, $_GET);
        // rebuild $PHP_SELF which is used by ZC in several places
        $GLOBALS['PHP_SELF'] = $catalog_dir.'index.php';

        // Catch POST form info in case we were redirected here / alpha testing
        if(isset($_SESSION['ssu_post'])){
            $_POST = $_SESSION['ssu_post'];
            $_REQUEST = array_merge($_REQUEST, $_POST);
            unset($_SESSION['ssu_post']);
        }
    }

    private function getPageBase() {
        global $request_type;
        if($request_type == 'SSL' && ENABLE_SSL == 'true')
        $pageURL = HTTPS_SERVER;
        else
        $pageURL = HTTP_SERVER;

        return $pageURL;
    }

    /*
     * Builds the ssu links
     * Takes the same params as zencart zen_href_link function
     */
    public function link($page = '', $parameters = '', $connection = 'NONSSL', $add_session_id = true, $search_engine_safe = true, $static = false, $use_dir_ws_catalog = true, $browser_safe = true){
        
        if(!Plugin::get('riPlugin.Settings')->get('riSsu.status')) return false;
        
        global $request_type, $session_started, $http_domain, $https_domain;
        
        $this->curent_page = $link = $sid = '';

        $languages_code = isset($_SESSION['languages_code']) ? $_SESSION['languages_code'] : DEFAULT_LANGUAGE;
        
        // if this is anything other than index.php, dont ssu it
        if(strpos($page, '.php') !== false && strpos($page, 'index.php') === false) return false;

        if(!empty($parameters) || !empty($page)){
            // this is for the way ZC builds ezpage links. $page is empty and $parameters contains main_page
            // remember. non-static links always have index.php?main_page=
            // so first we check if this is static
            if(strpos($page, 'main_page=') !== false){
                $parameters = $page;
            }

            // remove index.php? if exists
            if(($index_start = strpos($parameters, 'index.php?')) !== false) $parameters = substr($parameters, $index_start+10);

            // put the "page" into $page, and the rest into $parameters
            if((strpos($parameters, 'main_page=')) !== false){
                parse_str($parameters, $_get);
                $page = $_get['main_page'];
                unset($_get['main_page']);
                $parameters = http_build_query($_get);
            }
            elseif($static && empty($parameters)){
                return false;
            }

            // if we reach this step with an empty $page, let zen handle the job
            if (empty($page)) return false;

            if($page == 'index' && strpos($parameters, 'cPath=') !== false)
                $page = 'categories';
                
            $this->curent_page = $page;
            // if this page is our exclude list, let zen handle the job
            if (!array_key_exists($page, Plugin::get('riPlugin.Settings')->get('riSsu.pages'))) return false;

            $parameters = $this->parseParams($languages_code, $page, $parameters, $extension);
        }

        // Build session id        
        if ( ($add_session_id == true) && ($session_started == true) && (SESSION_FORCE_COOKIE_USE == 'False') ) {
            if (defined('SID') && zen_not_null(SID)) {
                $sid = SID;
            } elseif ( ($connection == 'SSL' && ENABLE_SSL == 'true') || ($connection == 'NONSSL') ) {
                if ($http_domain != $https_domain) { // @todo this will never work!
                    $sid = zen_session_name() . '=' . zen_session_id();
                }
            }
        }

        if(empty($parameters['static']) && $page == 'index')
        $page = '';

        // build the http://www.site.com
        $link = ($connection == 'SSL' && ENABLE_SSL == 'true') ? HTTPS_SERVER . DIR_WS_HTTPS_CATALOG : HTTP_SERVER . DIR_WS_CATALOG;

        $link = trim($link, '/');

        $languages_code = Plugin::get('riPlugin.Settings')->get('riSsu.multilang_status') ? $languages_code : '';

        // append language code if:
        // multi lang is on AND we use default lang id OR We dont use default lang id but the current lang is not default
        if(Plugin::get('riPlugin.Settings')->get('riSsu.multilang_status') &&
        (!Plugin::get('riPlugin.Settings')->get('riSsu.multilang_default_identifier') ||
        (Plugin::get('riPlugin.Settings')->get('riSsu.multilang_default_identifier') && $languages_code != DEFAULT_LANGUAGE)))
        $link .= "/$languages_code";

        if(!empty($page))
        $link .= '/'.$page;

        if(!empty($parameters['static']))
        $link .= '/'.$parameters['static'];

        if(!empty($page) || !empty($parameters['static'])){
            if(!empty($extension))
            $link .= $extension;
            else
            $link .= '/';
        }
        else
        $link .= '/';

        // append sid
        if(!empty($sid))
        if(empty($parameters['dynamic']))
        $parameters['dynamic'] = $sid;
        else
        $parameters['dynamic'] .= '&'.$sid;

        if(!empty($parameters['dynamic']))
        $link .= '?'.$parameters['dynamic'];

        return $browser_safe ? str_replace('&', '&amp;', $link) : $link;
    }

    /*
     * Takes the parameters in the query string and turns that to our nice looking link
     */
    function parseParams(&$languages_code, &$page, $parameters, &$extension){;
        $set_alias_cache = $set_cache = false;
        $params = $excluded_queries = array();
        $languages_id = isset($_SESSION['languages_id']) ? (int)$_SESSION['languages_id'] : 1;
        $_get = array('main_page' => $page);
        $extension = Plugin::get('riPlugin.Settings')->get('riSsu.file_extension');

        // parse into an array
        parse_str($parameters, $parameters);

        // parse language
        if(isset($parameters['language']) && !empty($parameters['language']) && ($languages_id = $this->getLanguagesID($parameters['language'])) !== false){
            $languages_code = $parameters['language'];
            if(Plugin::get('riPlugin.Settings')->get('riSsu.multilang_status')){
                unset($parameters['language']);
            }
        }

        // do we find this page in the parser mapping?
        if(($mapped_page = Plugin::get('riPlugin.Settings')->get('riSsu.pages.' . $page)) != null){
            $options = $this->pages[$page];
                        
            $params['dynamic'] = http_build_query(Plugin::get('riSsu.'.$options['parser'])->getDynamicQueryKeys($parameters));

            $cache_filename = md5(Plugin::get('riSsu.'.$options['parser'])->getCacheKey($page, $parameters)).'_'.$languages_code;

            if(($cache = Plugin::get('riSsu.Cache')->read($cache_filename, 'pc', true)) !== false){
                list($page, $params['static'], $extension) = explode("|", $cache);
                return array('static' => $params['static'], 'dynamic' => $params['dynamic']);
            }
            
            Plugin::get('riSsu.'.$options['parser'])->processPage($page, $mapped_page['alias']);
                        
            $params['static'] = implode('/', Plugin::get('riSsu.'.$options['parser'])->getStaticQueryKeys($parameters, $options['identifier'], $languages_id, $languages_code));
            $extension = isset($mapped_page['extension']) ? $mapped_page['extension'] : Plugin::get('riPlugin.Settings')->get('riSsu.file_extension');

            $set_cache = true;
        }
        else
        $params['dynamic'] = http_build_query($parameters);

        // some alias stuffs
        if(Plugin::get('riPlugin.Settings')->get('riSsu.alias_status')){
            if(!empty($params['static']))
            Plugin::get('riSsu.Alias')->linkToAlias($params['static']);
            if(!empty($page))
            Plugin::get('riSsu.Alias')->linkToAlias($page);
        }

        if($set_cache){
            Plugin::get('riSsu.Cache')->write($cache_filename, 'pc', $page.'|'.$params['static'].'|'.$extension, true);
        }

        // here we will attempt to get the cache
        // note that there is a draw back here: we are attemthing to read cache file for every single link
        // but on another hand we may avoid querying the database for aliases
        return array('static' => isset($params['static']) ? $params['static'] : '', 'dynamic' => isset($params['dynamic']) ? $params['dynamic'] : '');
    }

    function getLanguagesID($languages_code){
        if(!isset($_SESSION['ssu_languages_code'][$languages_code])){
            global $db;
            $languages_query = "select languages_id from " . TABLE_LANGUAGES . "
			                          WHERE code = '$languages_code' LIMIT 1";

            $languages = $db->Execute($languages_query);
            if($languages->RecordCount() > 0)
            $_SESSION['ssu_languages_code'][$languages_code] = $languages->fields['languages_id'];
            else
            $_SESSION['ssu_languages_code'][$languages_code] = false;

        }
        return $_SESSION['ssu_languages_code'][$languages_code];
    }

    function rel(){
        if (defined('ROBOTS_PAGES_TO_SKIP') && in_array($this->current_page, explode(",", constant('ROBOTS_PAGES_TO_SKIP')))
        || $current_page_base=='down_for_maintenance') return "'nofollow'";
    }
}
