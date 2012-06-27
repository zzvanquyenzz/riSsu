<?php

namespace plugins\riSsu\cores;

use plugins\riPlugin\Plugin;

/**
 * @package Pages
 * @copyright Copyright 2003-2006 Zen Cart Development Team
 * @copyright Portions Copyright 2003 osCommerce
 * @license http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 * @version $Id: alias.php 337 2010-06-27 06:32:47Z yellow1912 $
 */
class Alias{
	// store all
	private  $aliases = array();
	private  $links = array();

	// store only enabled aliases
	private  $_aliases = array();
	private  $_links = array();

	private $no_search = array('link_alias'=>array(), 'link_url' => array());

    public function retrieveAliases(){
		global $db;
		$aliases = $db->Execute('SELECT * FROM '.TABLE_LINKS_ALIASES);	
		$result = array();
		while(!$aliases->EOF){
			$temp_array = array();
			foreach($aliases->fields as $key => $value)
				$temp_array[$key] = $value;
			$result[] = $temp_array;
			$aliases->MoveNext();
		}
		return $result;				
	}
	
	// Aliases needed to be loaded on demand
	public function retrieveAliasesOnDemand($params, $field, $compare, $from, $to, $status=null){
		$params = explode('/',$params);
		foreach($params as $key => $value){
			$params[$key] = "/$value/";
			if(in_array($params[$key], $this->no_search[$field]))
			unset($params[$key]);
		}
		$elements_to_query = array_diff($params, $this->$compare);
		$id_list = array();
		if(count($elements_to_query) > 0)   {
			foreach($elements_to_query as $element){
				$element = addslashes($element);
				$conditions[] = "$field LIKE '%$element%' ";
			}
			$conditions = implode(' OR ', $conditions);
			$query_string = 'SELECT DISTINCT link_url, link_alias, id FROM '.TABLE_LINKS_ALIASES." WHERE ($conditions)";
			$query_string .= !empty($status) ? " AND status = $status" : '';
			$query_string .= " ORDER BY length(link_alias) DESC";
			global $db;
			$alias_result = $db->Execute($query_string);
			while(!$alias_result->EOF){
				array_push($this->$from, $alias_result->fields['link_url']);
				array_push($this->$to, $alias_result->fields['link_alias']);


				$id_list[] = $alias_result->fields['id'];

				unset($elements_to_query[$alias_result->fields[$field]]);
				$alias_result->MoveNext();
			}
				
			foreach ($elements_to_query as $element)
			$this->no_search[$field][] = $element;
		}

		return $id_list;
	}

	public function aliasToLink(&$params){
		$count = 0;
		$this->retrieveAliasesOnDemand($params, 'link_alias', 'aliases', 'links', 'aliases');
		$params = trim(str_replace($this->aliases, $this->links, "/$params/", $count), '/');
		return $count;
	}

	public function linkToAlias(&$params){
		$_params = $params = trim($params, '/');
		$count = 0;
		$this->retrieveAliasesOnDemand($params, 'link_url', '_links', '_links', '_aliases', 1);
		$_params = str_replace($this->_links, $this->_aliases, "/$params/", $count);
		
		if($count > 0 && $_params != $params){
			$params = trim($_params, '/');
			return true;
		}
		return false;
	}

	public function autoAlias($id, $identifier, $name_field, $name, &$_name){
		// if the alias happens to be the same with a define page, return.
		if(is_dir(DIR_WS_MODULES."pages/$_name"))
		return false;
			
		global $db;
			
		// if we are generating aliases, make sure we use the product name without the attribute string
		// $name = current(explode(':', $name));
		$slashed_name = zen_db_input("/$name/");
		$slashed__name = zen_db_input("/$_name/");
		$id = (int)$id;
        // do we have any permanent link?
        $count = $db->Execute("SELECT count(*) as count FROM ".TABLE_LINKS_ALIASES." WHERE referring_id='$id' AND alias_type='$name_field' AND permanent_link = 1 AND STATUS = 1 LIMIT 1");
        if($count->fields['count'] > 0) return true;

		// always update first
		$db->Execute("UPDATE ".TABLE_LINKS_ALIASES." SET link_url = '$slashed_name' WHERE referring_id='$id' AND link_alias='$slashed__name' AND alias_type='$name_field'");

		// check if we already have this alias, then do nothing
		$count = $links_aliases = $db->Execute("SELECT count(*) as count FROM ".TABLE_LINKS_ALIASES." WHERE referring_id='$id' AND alias_type='$name_field' AND link_url = '$slashed_name' AND link_alias = '$slashed__name' LIMIT 1");
		if($count->fields['count'] > 0) return true;

		// this name is used in case of conflict
		$temp = '/' . $_name . $identifier . $id . '/';
		
		// check if the alias with the corresponding reffering_id and type is already there
		$links_aliases = $db->Execute("SELECT id, link_url, link_alias FROM ".TABLE_LINKS_ALIASES." WHERE referring_id='$id' AND alias_type='$name_field' AND status = '1'");
		if($links_aliases->RecordCount() > 0){
			while(!$links_aliases->EOF){
				// only if we dont have this exact key pair in the database yet
				if($links_aliases->fields['link_url'] == $slashed_name && $links_aliases->fields['link_alias'] != $slashed__name){
				    
				    // now, it is very possible that this was caused by the name confliction
				    if($temp != $links_aliases->fields['link_alias']){
    					// disable the current link-alias
	    				$db->Execute("UPDATE ".TABLE_LINKS_ALIASES." SET status = '0' WHERE id='{$links_aliases->fields['id']}'");
		    			// add a new one in
			    		$db->Execute("INSERT INTO ".TABLE_LINKS_ALIASES." (link_url, link_alias, alias_type, referring_id) VALUES('$slashed_name', '$slashed__name', '$name_field', '$id')");				    	
				    }
				    else{
				        $_name = trim($temp, '//');
				    }
				    return true;
				}
				$links_aliases->MoveNext();
			}
		}

		// otherwise insert the new alias
		$links_aliases = $db->Execute("SELECT COUNT(*) AS count FROM ".TABLE_LINKS_ALIASES." WHERE link_url='$slashed_name' OR link_alias ='$slashed__name'");
		if($links_aliases->fields['count'] == 0){
		    $db->Execute("INSERT INTO ".TABLE_LINKS_ALIASES." (link_url, link_alias, alias_type, referring_id) VALUES('$slashed_name', '$slashed__name', '$name_field', '$id')");
		    return true;
		}
		else{
		    // try to append the id to the alias and see if that works		    
		    $links_aliases = $db->Execute("SELECT COUNT(*) AS count FROM ".TABLE_LINKS_ALIASES." WHERE link_url='$slashed_name' OR link_alias ='$temp'");
    		if($links_aliases->fields['count'] == 0){
    		    $slashed__name = $temp;
    		    $_name = trim($slashed__name, '//');
    		    $db->Execute("INSERT INTO ".TABLE_LINKS_ALIASES." (link_url, link_alias, alias_type, referring_id) VALUES('$slashed_name', '$slashed__name', '$name_field', '$id')");    		    
    		    return true;
    		}
		}
		
		return false;
		//
	}

	private function insertCacheToDB($id_list, $pc_file_name, $alias_file_name){
		global $db;
		foreach ($id_list as $id){
			$db->Execute('INSERT INTO '.TABLE_SSU_ALIAS_CACHE."(links_aliases_id, file) VALUES($id, $pc_file_name, $alias_file_name)");
		}
	}
}