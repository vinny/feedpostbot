<?php
/**
 *
 * Feed post bot. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2017, Ger, https://github.com/GerB
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace ger\feedpostbot\classes;

class driver
{
	const FEED_TIMEOUT_DEFAULT = 10;
	const FEED_TIMEOUT_PARSE = 3;
	const LOG_CRITICAL = 'critical';
	const LOG_ADMIN = 'admin';
	const LOG_FEED_FETCHED = 'FPB_LOG_FEED_FETCHED';
	const LOG_FEED_TIMEOUT = 'FPB_LOG_FEED_TIMEOUT';
	const LOG_FEED_ERROR = 'FPB_LOG_FEED_ERROR';
	const LANG_READ_MORE = 'FPB_READ_MORE';
	const LANG_SOURCE = 'FPB_SOURCE';

	protected $config;
	protected $config_text;
	protected $user;
	protected $language;
	protected $auth;
	protected $db;
	protected $log;
	protected $phpbb_root_path;
	protected $php_ext;
	protected $phpbb_dispatcher;
	public $current_state;

	/** @var array Cache for forum names */
	protected $forum_name_cache = array();

	/** @var array Cache for user data rows */
	protected $user_data_cache = array();

	/**
	 * Constructor
	 *
	 * @param \phpbb\config\config							$config				Config object
	 * @param \phpbb\config\db_text							$config_text		Config text object
	 * @param \phpbb\user									$user				User object
	 * @param \phpbb\language\language						$language			Language object
	 * @param \phpbb\auth\auth								$auth				Auth object
	 * @param \phpbb\db\driver\driver_interface				$db					DB object
	 * @param string										$phpbb_root_path
	 * @param string										$php_ext
	 * @param \phpbb\event\dispatcher						$phpbb_dispatcher
	 */
	public function __construct(\phpbb\config\config $config, \phpbb\config\db_text $config_text, \phpbb\user $user, \phpbb\language\language $language, \phpbb\auth\auth $auth, \phpbb\db\driver\driver_interface $db, \phpbb\log\log $log, $phpbb_root_path, $php_ext, \phpbb\event\dispatcher $phpbb_dispatcher)
	{
		$this->config = $config;
		$this->config_text = $config_text;
		$this->user = $user;
		$this->language = $language;
		$this->auth = $auth;
		$this->db = $db;
		$this->log = $log;
		$this->phpbb_root_path = $phpbb_root_path;
		$this->php_ext = $php_ext;
		$this->phpbb_dispatcher = $phpbb_dispatcher;
	}

	/**
	 * Set and return current state
	 */
	public function init_current_state()
	{
		$ct = $this->config_text->get('ger_feedpostbot_current_state');
		if (empty($ct) || $ct === 'null')
		{
			$this->current_state = array();
		}
		else
		{
			$decoded = json_decode($ct, true);
			if (!is_array($decoded))
			{
				$this->current_state = array();
			}
			else
			{
				$this->current_state = $decoded;
				$this->check_state_parameters();
			}
		}
		return $this->current_state;
	}

	/**
	 * Make sure we have all parameters set
	 */
	private function check_state_parameters()
	{
		if (!is_array($this->current_state))
		{
			$this->current_state = array();
			return;
		}

		$modified = false;
		$new_state = array();
		foreach ($this->current_state as $id => $source)
		{
			if (is_array($source) && isset($source['append_link']))
			{
				$new_state[$id] = $source;
			}
			else if (is_array($source))
			{
				$new = $source;
				$new['append_link'] = 1;
				$new_state[$id] = $new;
				$modified = true;
			}
		}
		$this->current_state = $new_state;
		if ($modified)
		{
			$this->config_text->set('ger_feedpostbot_current_state', json_encode($new_state));
		}
	}

	/**
	 * Fetch all feeds
	 * This is called by the cron handler
	 * @return int
	 */
	public function fetch_all()
	{
		if (empty($this->current_state))
		{
			$this->init_current_state();
		}
		$lock = (int) $this->config['feedpostbot_locked'];
		if ($lock > 0)
		{
			return 0;
		}
		$counter = 0;
		$active_user = $this->user->data['user_id'];
		if (empty($this->current_state))
		{
			return 0;
		}
		if (!$this->config->set_atomic('feedpostbot_locked', 0, time(), false))
		{
			return 0;
		}
		foreach ($this->current_state as $id => $source)
		{
			// Only proceed if not disabled in ACP
			if (!empty($source['forum_id']))
			{
				$counter += $this->fetch_items($this->parse_feed($source['url'], $source['type'], $source['timeout']), $id);

				// Switch back to original user after processing each feed to avoid context leaking
				$this->switch_user($active_user);
			}
		}
		$this->config_text->set('ger_feedpostbot_current_state', json_encode($this->current_state));
		$this->switch_user($active_user);
		$this->config->set('feedpostbot_locked', 0, false);
		return $counter;
	}


	/**
	 * Get and configure a SimplePie instance
	 *
	 * @param string $url
	 * @param int $timeout
	 * @param string|null $raw_data
	 * @return \SimplePie\SimplePie|null
	 */
	public function get_simplepie_instance($url, $timeout = self::FEED_TIMEOUT_DEFAULT, $raw_data = null)
	{
		if (!class_exists('\SimplePie\SimplePie') && !class_exists('\SimplePie'))
		{
			$autoloader = __DIR__ . '/../vendor/autoload.php';
			if (file_exists($autoloader))
			{
				require_once $autoloader;
			}
			else if (file_exists($this->phpbb_root_path . 'ext/ger/feedpostbot/vendor/autoload.php'))
			{
				require_once $this->phpbb_root_path . 'ext/ger/feedpostbot/vendor/autoload.php';
			}
			else if (file_exists($this->phpbb_root_path . 'vendor/autoload.php'))
			{
				require_once $this->phpbb_root_path . 'vendor/autoload.php';
			}
		}

		if (!class_exists('\SimplePie\SimplePie') && !class_exists('\SimplePie'))
		{
			return null;
		}

		if ($raw_data === null && !empty($url))
		{
			$parts = parse_url($url);
			if (empty($parts['scheme']) || !in_array(strtolower($parts['scheme']), array('http', 'https'), true))
			{
				return null;
			}
		}

		/** @var \SimplePie\SimplePie $feed */
		$feed = class_exists('\SimplePie\SimplePie') ? new \SimplePie\SimplePie() : new \SimplePie();
		$feed->set_timeout((int) $timeout);
		$feed->set_useragent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
		$feed->enable_cache(false);

		if ($raw_data !== null)
		{
			$feed->set_raw_data($raw_data);
		}
		else
		{
			$feed->set_feed_url($url);
		}

		$feed->force_feed(true);
		$feed->set_output_encoding('UTF-8');
		$feed->init();
		$feed->handle_content_type();

		return $feed;
	}

	/**
	 * Autodetect feed type using SimplePie
	 *
	 * @param string $url
	 * @param string|null $raw_data
	 * @return string|false
	 */
	public function detect_feed_type($url, $raw_data = null)
	{
		$feed = $this->get_simplepie_instance($url, self::FEED_TIMEOUT_DEFAULT, $raw_data);
		if (!$feed || $feed->error())
		{
			return false;
		}

		$type = (int) $feed->get_type();
		$atom_mask = defined('\SimplePie\SimplePie::TYPE_ATOM_ALL') ? \SimplePie\SimplePie::TYPE_ATOM_ALL : (defined('SIMPLEPIE_TYPE_ATOM_ALL') ? SIMPLEPIE_TYPE_ATOM_ALL : 768);
		$rdf_mask = defined('\SimplePie\SimplePie::TYPE_RSS_RDF') ? \SimplePie\SimplePie::TYPE_RSS_RDF : (defined('SIMPLEPIE_TYPE_RSS_RDF') ? SIMPLEPIE_TYPE_RSS_RDF : 65);

		if ($type === 64 || $type === 65 || ($type !== 1023 && ($type & $rdf_mask)))
		{
			return 'rdf';
		}

		if ($type !== 1023 && ($type & $atom_mask))
		{
			return 'atom';
		}

		if ($type === 1023 && !empty($raw_data))
		{
			if (stripos($raw_data, '<rdf:RDF') !== false)
			{
				return 'rdf';
			}
			else if (stripos($raw_data, '<feed') !== false)
			{
				return 'atom';
			}
		}

		return 'rss';
	}

	/**
	 * Parse a feed via SimplePie and return formatted item array
	 *
	 * @param string $url
	 * @param string $type
	 * @param int $timeout
	 * @return array
	 */
	public function parse_feed($url, $type, $timeout = self::FEED_TIMEOUT_PARSE)
	{
		$feed = $this->get_simplepie_instance($url, $timeout);
		if (!$feed)
		{
			return array();
		}

		if ($feed->error())
		{
			$this->log_feed_error($url, (string) $feed->error());
			return array();
		}

		$items = $feed->get_items();
		if (empty($items))
		{
			$this->log_feed_fetched($url);
			return array();
		}

		$return = array();
		$feed_type = strtolower((string) $type);
		if (empty($feed_type) || !in_array($feed_type, array('rss', 'atom', 'rdf'), true))
		{
			$type_flags = $feed->get_type();
			if (defined('SIMPLEPIE_TYPE_ATOM_10') && ($type_flags & (SIMPLEPIE_TYPE_ATOM_10 | SIMPLEPIE_TYPE_ATOM_03)))
			{
				$feed_type = 'atom';
			}
			else if (defined('SIMPLEPIE_TYPE_RSS_10') && ($type_flags & SIMPLEPIE_TYPE_RSS_10))
			{
				$feed_type = 'rdf';
			}
			else
			{
				$feed_type = 'rss';
			}
		}

		foreach ($items as $item)
		{
			$guid = $this->prop_to_string($item->get_id());
			$title = $this->prop_to_string($item->get_title());
			$link = $this->prop_to_string($item->get_permalink());
			$description = $item->get_content(true);
			if ($description === null || $description === '')
			{
				$description = $item->get_description(true);
			}
			if ($description === null || $description === '')
			{
				$description = $item->get_title();
			}
			$description = $this->prop_to_string($description);
			$date_u = $item->get_date('U');
			$pubDate = ($date_u !== null && $date_u !== false) ? (int) $date_u : 0;
			$author_obj = $item->get_author();
			$author = $author_obj ? $this->prop_to_string($author_obj->get_name()) : '';

			$append = array(
				'guid'        => $guid,
				'title'       => $title,
				'link'        => $link,
				'description' => $description,
				'pubDate'     => $pubDate,
				'author'      => $author,
			);

			/**
			 * Modify the fetched feed item before it's added to the return list
			 *
			 * @event ger.feedpostbot.parse_item_append
			 * @var  object item   item as found in source
			 * @var  array  append Array of properties to be sent to the post_message function
			 * @since 1.1.0
			 */
			$vars = array('item', 'append');
			$event_data = $this->phpbb_dispatcher->trigger_event('ger.feedpostbot.parse_item_append', compact($vars));
			if (is_array($event_data) || $event_data instanceof \ArrayAccess)
			{
				extract((array) $event_data);
			}

			/**
			 * Modify the fetched feed item before it's added to the return list (Backwards-compatible event)
			 *
			 * @event ger.feedpostbot.parse_rss_append
			 * @event ger.feedpostbot.parse_atom_append
			 * @event ger.feedpostbot.parse_rdf_append
			 * @var  object item   item as found in source
			 * @var  array  append Array of properties to be sent to the post_message function
			 * @since 1.0.1
			 */
			$event_name = 'ger.feedpostbot.parse_' . $feed_type . '_append';
			$event_data = $this->phpbb_dispatcher->trigger_event($event_name, compact($vars));
			if (is_array($event_data) || $event_data instanceof \ArrayAccess)
			{
				extract((array) $event_data);
			}

			$return[] = $append;
		}

		$this->log_feed_fetched($url);
		return $return;
	}


	/**
	 * Fetch the new content in feed
	 *
	 * @param array $items
	 * @param int $source_id
	 * @return int
	 */
	public function fetch_items($items, $source_id)
	{
		$posted = 0;
		// Improved check for items to handle false values properly
		if (empty($items) || !is_array($items))
		{
			return $posted;
		}

		$new_latest = array(
			'link' => $this->prop_to_string($items[0]['link']),
			'pubDate' => $this->prop_to_string($items[0]['pubDate']),
			'guid' => empty($items[0]['guid']) ? '' : $items[0]['guid'],
		);

		$to_post = array();
		// Added proper check before foreach
		if (!empty($items) && is_array($items))
		{
			foreach ($items as $item)
			{
				if ($this->is_handled($item, $this->current_state[$source_id]['latest']))
				{
					// We've had this one and all below
					$this->current_state[$source_id]['latest'] = $new_latest;
					break;
				}
				else
				{
					$to_post[] = $item;
				}
			}
		}
		if (!empty($to_post))
		{
			$this->switch_user($this->current_state[$source_id]['user_id']);

			// Reverse array to make sure that the latest item is also the newest
			$to_post = array_reverse($to_post);
			foreach ($to_post as $item)
			{
				$this->post_message($item, $source_id);
				$posted++;
			}
		}

		$this->current_state[$source_id]['latest'] = $new_latest;
		return $posted;
	}

	/**
	 * Check if this is the latest item
	 * Use guid if available, fallback to pubDate & link
	 *
	 * @param array $item
	 * @param array $current
	 * @return bool
	 */
	private function is_handled($item, $current)
	{
		if (empty($current['link']) && empty($current['pubDate']) && empty($current['guid']))
		{
			return false;
		}
		if (!empty($item['guid']) && !empty($current['guid']) && ((string) $item['guid'] === (string) $current['guid']))
		{
			return true;
		}
		if (!empty($item['link']) && !empty($current['link']) && ((string) $item['link'] === (string) $current['link']))
		{
			if (!empty($item['pubDate']) && !empty($current['pubDate']))
			{
				return (string) $item['pubDate'] === (string) $current['pubDate'];
			}
			return true;
		}
		if (!empty($item['pubDate']) && !empty($current['pubDate']) && (int) $item['pubDate'] <= (int) $current['pubDate'])
		{
			return true;
		}
		return false;
	}

	/**
	 * Create a topic for new RSS item
	 *
	 * @param array $rss_item
	 * @param int $source_id
	 * @return string
	 */
	private function post_message($rss_item, $source_id)
	{
		if (empty($rss_item))
		{
			return false;
		}
		if (!function_exists('generate_text_for_storage'))
		{
			include($this->phpbb_root_path . 'includes/functions_content.' . $this->php_ext);
		}
		if (!function_exists('submit_post'))
		{
			$include_result = include($this->phpbb_root_path . 'includes/functions_posting.' . $this->php_ext);
			if (!$include_result || !function_exists('submit_post'))
			{
				return false;
			}
		}
		$source = $this->current_state[$source_id];

		// Make sure we have UTF-8 and handle HTML
		$description = $rss_item['description'];
		$title = $this->clean_title($rss_item['title']);
		if (!empty($source['prefix']))
		{
			$title = $this->clean_title($source['prefix']) . ' ' . $title;
		}

		// Only show excerpt of feed if a text limit is given, but make it nice
		if (!empty($source['textlimit']))
		{
			$post_text = $this->html2bbcode($this->closetags($this->character_limiter($description, $source['textlimit'])));
			if (!empty($source['append_link']))
			{
				$post_text .= "\n\n" . '[url=' . $rss_item['link'] . ']' . $this->user->lang(self::LANG_READ_MORE) . '[/url]';
			}
		}
		else
		{
			$post_text = $this->html2bbcode($description);
			if (!empty($source['append_link']))
			{
				$post_text .= "\n\n" . $this->user->lang(self::LANG_SOURCE) . ' [url]' .  $rss_item['link'] . '[/url]';
			}
		}

		if (is_numeric($source['forum_id']))
		{
			// Prep posting
			$poll = array();
			$uid = $bitfield = $options = '';
			$allow_bbcode = $allow_urls = $allow_smilies = true;
			generate_text_for_storage($post_text, $uid, $bitfield, $options, $allow_bbcode, $allow_urls, $allow_smilies);

			$post_time = 0;
			if (empty($source['curdate']) && !empty($rss_item['pubDate']))
			{
				$ts = is_numeric($rss_item['pubDate']) ? (int) $rss_item['pubDate'] : strtotime((string) $rss_item['pubDate']);
				if ($ts !== false && $ts > 0)
				{
					$post_time = (int) $ts;
				}
			}

			$data = array(
				// General Posting Settings
				'forum_id'		 => (int) $source['forum_id'], // The forum ID in which the post will be placed. (int)
				'topic_id'		 => 0, // Post a new topic or in an existing one? Set to 0 to create a new one, if not, specify your topic ID here instead.
				'icon_id'		 => false, // The Icon ID in which the post will be displayed with on the viewforum, set to false for icon_id. (int)
				// Defining Post Options
				'enable_bbcode'	 => true, // Enable BBcode in this post. (bool)
				'enable_smilies'	 => true, // Enabe smilies in this post. (bool)
				'enable_urls'	 => true, // Enable self-parsing URL links in this post. (bool)
				'enable_sig'	 => true, // Enable the signature of the poster to be displayed in the post. (bool)
				// Message Body
				'message'		 => $post_text, // Your text you wish to have submitted. It should pass through generate_text_for_storage() before this. (string)
				'message_md5'	 => md5($post_text), // The md5 hash of your message
				// Values from generate_text_for_storage()
				'bbcode_bitfield'	 => $bitfield, // Value created from the generate_text_for_storage() function.
				'bbcode_uid'	 => $uid, // Value created from the generate_text_for_storage() function.
				// Other Options
				'post_edit_locked'	 => 0, // Disallow post editing? 1 = Yes, 0 = No
				'topic_title'	 => $title,
				'notify_set'	 => true, // (bool)
				'notify'		 => true, // (bool)
				'post_time'		 => $post_time, // Set a specific time, use 0 to let submit_post() take care of getting the proper time (int)
				'forum_name'	 => $this->get_forum_name($source['forum_id']), // For identifying the name of the forum in a notification email. (string)    // Indexing
				'enable_indexing'	 => true, // Allow indexing the post? (bool)    // 3.0.6
			);
		}
		// Maybe an extension handles the content other than by posting
		$do_post = true;

		/**
		 * Modify the post data array before post is submitted
		 *
		 * @event ger.feedpostbot.submit_post_before
		 * @var  array   data      Data array sent to the submit_post function
		 * @var  array   rss_item  Complete feed item as fetched by parse_{method}
		 * @var  array   source    Source settings
		 * @var  string  title     Topic title
		 * @var  bool    do_post   Set to false if you do not want to post
		 * @since 1.0.1
		 */
		$vars = array('data', 'rss_item', 'source', 'title', 'do_post');
		$event_data = $this->phpbb_dispatcher->trigger_event('ger.feedpostbot.submit_post_before', compact($vars));
		if (is_array($event_data) || $event_data instanceof \ArrayAccess)
		{
			extract((array) $event_data);
		}

		if ($do_post)
		{
			return submit_post('post', $title, $this->user->data['username'], POST_NORMAL, $poll, $data);
		}
		return true;
	}

	/**
	 * Make sure we have a string
	 * @param mixed $prop
	 * @return string
	 */
	public function prop_to_string($prop)
	{
		if (is_null($prop))
		{
			return '';
		}
		if (is_array($prop))
		{
			$prop = isset($prop[0]) ? (string) $prop[0] : '';
		}
		else
		{
			$prop = (string) $prop;
		}
		return html_entity_decode($prop, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
	}

	/**
	 * Clean title: strip HTML tags, remove emojis, normalize spaces, and ensure HTML safety
	 *
	 * @param string $string
	 * @return string
	 */
	public function clean_title($string)
	{
		if (empty($string))
		{
			return '';
		}

		// Remove script and style tags along with their contents completely
		$string = preg_replace('/<(script|style)\b[^>]*>(.*?)<\/\1>/is', '', (string) $string);

		// Strip remaining HTML tags completely to prevent any stored XSS markup
		$string = strip_tags($string);

		// Remove 4-byte UTF-8 characters (emojis) for database compatibility
		$string = preg_replace('/[\x{10000}-\x{10FFFF}]/u', ' ', $string);

		// Collapse multiple whitespaces into a single space
		$string = preg_replace('/\s+/', ' ', $string);

		// Ensure HTML special characters are safely escaped for topic title storage
		if (function_exists('utf8_htmlspecialchars'))
		{
			return trim(utf8_htmlspecialchars($string));
		}
		return trim(htmlspecialchars((string) $string, ENT_COMPAT, 'UTF-8'));
	}

	/**
	 * Switch to the RSS source user
	 * @param int $new_user_id
	 * @return bool
	 */
	private function switch_user($new_user_id)
	{
		$new_user_id = (int) $new_user_id;
		if (isset($this->user->data['user_id']) && $this->user->data['user_id'] == $new_user_id)
		{
			$this->language->add_lang('info_acp_feedpostbot', 'ger/feedpostbot');
			return true;
		}
		$cur_lang = isset($this->user->data['user_lang']) ? $this->user->data['user_lang'] : (isset($this->config['default_lang']) ? $this->config['default_lang'] : 'en');

		if (!isset($this->user_data_cache[$new_user_id]))
		{
			$sql = 'SELECT *
					FROM ' . USERS_TABLE . '
					WHERE user_id = ' . (int) $new_user_id;
			$result = $this->db->sql_query($sql);
			$row = $this->db->sql_fetchrow($result);
			$this->db->sql_freeresult($result);

			if (!$row || !is_array($row))
			{
				// Target user not found (e.g. deleted account), skip user switch
				return false;
			}
			$this->user_data_cache[$new_user_id] = $row;
		}

		$row = $this->user_data_cache[$new_user_id];
		$row['is_registered'] = true;
		$this->user->data = array_merge($this->user->data, $row);
		$this->user->timezone = isset($row['user_timezone']) ? $row['user_timezone'] : $this->user->timezone;

		if (isset($row['user_lang']) && $cur_lang != $row['user_lang'])
		{
			$this->language->set_user_language($row['user_lang'], true);
		}
		$this->auth->acl($this->user->data);
		$this->language->add_lang('info_acp_feedpostbot', 'ger/feedpostbot');
		return true;
	}

	/**
	 * Get forum name by id (for notifications)
	 * @param int $id
	 * @return string
	 */
	public function get_forum_name($id)
	{
		$id = (int) $id;
		if (isset($this->forum_name_cache[$id]))
		{
			return $this->forum_name_cache[$id];
		}

		$sql = 'SELECT forum_name
				FROM ' . FORUMS_TABLE . '
				WHERE forum_id = ' . (int) $id;
		$result = $this->db->sql_query($sql, 3600);
		$row = $this->db->sql_fetchrow($result);
		$this->db->sql_freeresult($result);

		$name = empty($row['forum_name']) ? '' : $row['forum_name'];
		$this->forum_name_cache[$id] = $name;
		return $name;
	}

	/**
	 * Elegant word wrap
	 * @param string $str
	 * @param int $n
	 * @param string $end_char
	 * @return string
	 */
	public function character_limiter($str, $n = 300, $end_char = '...')
	{
		if (is_null($str) || $str === '')
		{
			return '';
		}

		$str = (string) $str;
		if (strlen($str) < $n)
		{
			return $str;
		}

		$str = preg_replace("/\s+/", ' ', str_replace(array("\r\n", "\r", "\n"), ' ', $str));

		if (strlen($str) <= $n)
		{
			return $str;
		}

		$out = "";
		foreach (explode(' ', trim($str)) as $val)
		{
			$out .= $val . ' ';

			if (strlen($out) >= $n)
			{
				$out = trim($out);
				return (strlen($out) == strlen($str)) ? $out : $out . $end_char;
			}
		}
		return trim($out) !== '' ? trim($out) . $end_char : $str;
	}

	/**
	 * Close open HTML tags
	 * @param string $html
	 * @return string
	 */
	public function closetags($html)
	{
		if (empty($html))
		{
			return '';
		}
		$html = (string) $html;

		// put all opened tags into an array
		preg_match_all("#<([a-z]+)( .*)?(?!/)>#iU", $html, $result);
		$openedtags = isset($result[1]) ? $result[1] : array();

		// put all closed tags into an array
		preg_match_all("#</([a-z]+)>#iU", $html, $result);
		$closedtags = isset($result[1]) ? $result[1] : array();
		$len_opened = count($openedtags);

		// all tags are closed
		if (count($closedtags) == $len_opened)
		{
			return $html;
		}

		$openedtags = array_reverse($openedtags);
		// close tags
		for ($i = 0; $i < $len_opened; $i++)
		{
			if (!in_array($openedtags[$i], $closedtags))
			{
				$html .= "</" . $openedtags[$i] . ">";
			}
			else
			{
				$key = array_search($openedtags[$i], $closedtags);
				if ($key !== false)
				{
					unset($closedtags[$key]);
				}
			}
		}
		return $html;
	}


	/**
	 * Simple HTML to BBcode conversion
	 * @param string $html_string
	 * @return string
	 */
	public function html2bbcode($html_string)
	{
		if (empty($html_string))
		{
			return '';
		}
		$html_string = (string) $html_string;

		$convert = array(
			"/[\r\n]+/" => " ",
			"/\<ul(.*?)\>(.*?)\<\/ul\>/is" => "[list]$2[/list]",
			"/\<ol(.*?)\>(.*?)\<\/ol\>/is" => "[list]$2[/list]",
			"/\<b(.*?)\>(.*?)\<\/b\>/is" => "[b]$2[/b]",
			"/\<i(.*?)\>(.*?)\<\/i\>/is" => "[i]$2[/i]",
			"/\<u(.*?)\>(.*?)\<\/u\>/is" => "[u]$2[/u]",
			"/\<li(.*?)\>(.*?)\<\/li\>/is" => "[*]$2",
			'/\<img(.*?) src=["\']?([^"\'>]+)["\']?(.*?)\>/is' => "\n[img]$2[/img]\n",
			"/\<div(.*?)\>(.*?)\<\/div\>/is" => "$2",
			"/\<p(.*?)\>(.*?)\<\/p\>/is" => "\n$2\n",
			"/[\s]*\<br(.*?)\>[\s]*/is" => "\n",
			"/\<strong(.*?)\>(.*?)\<\/strong\>/is" => "[b]$2[/b]",
			'/<a(.+?)href=["\']?([^"\'>]+)["\']?(.*?)>(.*?)\<\/a\>/is' => "[url=$2]$4[/url]",
			'/\<iframe (.*?)src=["\']?([^"\'>]+)["\']?(.*?)<\/iframe\>/is' => "\n$2\n",
			'/\n{3,}/s' => "\n\n",
		);

		/**
		* Modify the fetched RSS item before it's added to the return list
		*
		* @event ger.feedpostbot.html2bbcode_convert
		* @var  array   convert      regex array
		* @var  string  html_string  input string
		* @since 1.0.12
		*/
		$vars = array('convert', 'html_string');
		$event_data = $this->phpbb_dispatcher->trigger_event('ger.feedpostbot.html2bbcode_convert', compact($vars));
		if (is_array($event_data) || $event_data instanceof \ArrayAccess)
		{
			extract((array) $event_data);
		}

		// Replace main stuff and strip anything else
		$result = preg_replace(array_keys($convert), array_values($convert), $html_string);
		return strip_tags($result !== null ? $result : '');
	}

	/**
	 * Log feed error messages
	 *
	 * @param string $url
	 * @param string $error_msg
	 * @return void
	 */
	private function log_feed_error($url, $error_msg = '')
	{
		$this->log->add(self::LOG_CRITICAL, $this->user->data['user_id'], $this->user->ip, self::LOG_FEED_ERROR, time(), array($url, (string) $error_msg));
	}

	/**
	 * Log that a feed has been fetched
	 * @param string $url
	 * @return void
	 */
	private function log_feed_fetched($url)
	{
		if (!empty($this->config['feedpostbot_enable_logs']))
		{
			$this->log->add(self::LOG_ADMIN, $this->user->data['user_id'], $this->user->ip, self::LOG_FEED_FETCHED, time(), array($url));
		}
	}
}
