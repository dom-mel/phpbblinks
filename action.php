<?php
/*
 * This plugin finds phpbb links to a thread or Topic from a speceficboard
 * and if there is no title set it adds the thread title
 * 
 * @author     Dominik Eckelmann <deckelmann@gmail.com>
 */

if(!defined('DOKU_INC')) die();
if(!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN',DOKU_INC.'lib/plugins/');
if(!defined('DOKU_DATA')) define('DOKU_DATA',DOKU_INC.'data/');


require_once(DOKU_PLUGIN.'action.php');

class action_plugin_phpbblinks extends DokuWiki_Action_Plugin
{

	var $con;
	var $pre;

    /**
     * return some info
     */
    function getInfo()
	{
        return confToHash(dirname(__FILE__).'/info.txt');
    }

    function register(&$controller) 
	{
        $controller->register_hook('ACTION_ACT_PREPROCESS', 'BEFORE',  $this, 'replace', array());
    }

    function replace(&$event, $param) 
	{

		// if the action is not save or preview -> we are not interested
		if (!is_array($event->data)) return false;
		if ( !isset($event->data['preview']) && !isset($event->data['save']))
		{
			return false;
		}

		// ignore admin interface
		global $ACT;
		if ($ACT == 'admin')
		{
			return false;
		}

		// if not enabled -> return
		if (!$this->getConf('enable'))
		{
			return false;
		}

		// get the board url
		$url = $this->_getUrl();

		// no url given and the plugin has nothing to do
		if (!$url)
		{
			$this->admMsg('No BoardURL is set',-1);
			return;
		}

		// no db connection
		if (!$this->_connect())
		{
			return false;
		}

		global $TEXT;
		
		// we take the requested text and split it into pices
		$text = $TEXT;
		$textpice = preg_split('/[\s]/',$text);

		// prepare some stuff
		$urlc = strlen($url);
		$urla = parse_url($url);
		$search = array();
		$replace = array();

		// start to check every word
		foreach ($textpice as $word)
		{
			// ignore empty lines
			if (empty($word))
			{
				continue;
			}

			// prepare some more stuff
			$args = array();
			$enclose = false;
			$org = $word;

			// if the word is a link
			if (substr($word,0,2) == '[[' && substr($word,-2) == ']]' )
			{
				$enclose = true;
				if (strpos($word,'|'))
				{
					// link has already a title - ignore
					continue;
				}
				$word = substr($word,2,-2);
				if (strlen($word) <= $urlc)
				{
					// word is to short to be a real interesting url
					continue;
				}
				if (substr($word,0,$urlc) != $url)
				{
					// not the right url
					continue;
				}
				// get url args
				$pices = parse_url($word);


				if (!$pices['query'])
				{
					continue; // no args are given
				}

				// get the args
				parse_str($pices['query'],$args);
			} else {
				$wu = parse_url($word);

				// compare links
				$same = true;
				foreach (array('scheme','host','port','user','pass','path') as $k)
				{
					if (!isset($urla[$k]))
					{
						if (isset($wu[$k]))
						{
							$same = false;
							break;
						}
						continue;
					}
					if ($wu[$k] != $urla[$k])
					{
						// links are not the same
						$same = false;
						break;
					}
				}
				if (!$same)
				{

					continue;
				}

				// get the url args
				parse_str($wu['query'],$args);
			}
			
			// args make smbting

			if (isset($args['p']))
			{
				// get post title
				$p = $args['p'];
				$txt = $this->_getTopicByPostId($p);
				if (!$txt) continue;
				$txt = $this->getConf('linktxt') . $txt;
				$search[]  = '/(\s)?'.str_replace('/','\\/',preg_quote($org)).'(\s)?/';
				$replace[] = "\\1[[$url?p=$p#$p|$txt]]\\2";
			} 
			elseif (isset($args['t']))
			{
				// get thread title
				$t = $args['t'];
				$txt = $this->_getTopicByTopicId($t);
				if (!$txt) continue;
				$txt = $this->getConf('linktxt') . $txt;
				$search[]  = '/(\s)?'.str_replace('/','\\/',preg_quote($org)).'(\s)?/';
				$replace[] = "\\1[[$url?t=$t|$txt]]\\2";
			}
			else continue;
		}
		ksort($search);
		ksort($replace);
		$TEXT = preg_replace($search,$replace,$TEXT);

		$this->_disconnect();
    }

	function _getUrl() {
		$url = $this->getConf('boardurl');
		if (empty($url)) return false;

		// add a final /

		if (substr($url,-1) != '/') {
			$url .= '/';
		}

		// add the viewtopic file
		$url .= 'viewtopic.php';
		return $url;
	}

	function _getTopicByTopicId($id) {
		if (!$this->con) return '';
		$q = sprintf(
			'SELECT topic_title FROM %1$stopics WHERE topic_id=%2$d
			',$this->pre,intval($id));
		$r = $this->_query($q);
		if (!$r) return false;
		$r = $r['topic_title'];
		return $r;
	}
	function _getTopicByPostId($id) {
		if (!$this->con) return '';
		$q = sprintf('
			SELECT
				%1$stopics.topic_title
			FROM
				%1$sposts , %1$stopics
			WHERE
				%1$sposts.post_id=%2$d AND
				%1$sposts	.topic_id = %1$stopics.topic_id
			',$this->pre,intval($id));
		$r = $this->_query($q);
		if (!$r) return false;
		$r = $r['topic_title'];
		return $r;
	}
	function _connect() {
		$host = '';
		$user = '';
		$pass = '';
		$dbu   = '';
		$pre  = '';
		if ($this->getConf('integration'))
		{
			global $conf;
			global $table_prefix;
			$host = $conf['auth']['mysql']['server'];
			$user = $conf['auth']['mysql']['user'];
			$pass = $conf['auth']['mysql']['password'];
			$dbu  = $conf['auth']['mysql']['database'];
			$pre  = $table_prefix;
		}
		else
		{
			$host = $this->getConf('host');
			$user = $this->getConf('user');
			$pass = $this->getConf('pass');
			$dbu  = $this->getConf('db');
			$pre  = $this->getConf('pre');
		}
		$this->pre = $pre;
		$con = @mysql_connect( $host , $user , $pass) or $err = @mysql_error();
		if (!$con)
		{
			$this->admMsg('Database connection failed '.$err,-1);
			return false;
		}
		$this->con = $con;
		$db = @mysql_select_db($dbu,$this->con);
		if ($db)
		{
			return true;
		}
		$this->admMsg('Database selection failed '.@mysql_error($this->con),-1);
		return false;
	}
	function _disconnect() {
		mysql_close($this->con);
	}
	function _query($q) {
		$r = @mysql_query($q,$this->con);
		if (!$r)
		{
			$this->admMsg('Database Error '.@mysql_error($this->con),-1);
			return array();
		}
		$r = mysql_fetch_assoc($r);
		return $r;
	}
	function _esc($s) {
		return mysql_real_escape_string($s,$this->con);
	}

	function admMsg($msg,$type=0)
	{
		global $INFO;
		if (!$INFO['isadmin'] && !$INFO['ismanager'])
		{
			return;
		}
		msg("PHPBBLinks: $msg",$type);
	}
}



?>
