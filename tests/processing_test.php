<?php
/**
 * Feed post bot. An extension for the phpBB Forum Software package.
 * @copyright (c) 2017, Ger, https://github.com/GerB
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace ger\feedpostbot\tests;

class processing_test extends \phpbb_test_case
{
	protected $driver;
	protected $user;
	protected $auth;
	protected $config;
	protected $config_text;
	protected $db;
	protected $saved;
	protected $original;

	public function setUp(): void
	{
		parent::setUp();
		$this->config = new \phpbb\config\config(array('feedpostbot_locked' => 0));
		$this->config_text = $this->getMockBuilder('\phpbb\config\db_text')->disableOriginalConstructor()->getMock();
		$this->config_text->method('get')->willReturnCallback(function () { return json_encode($this->saved); });
		$this->config_text->method('set')->willReturnCallback(function ($key, $value) { $this->saved = json_decode($value, true); });
		$this->user = $this->getMockBuilder('\phpbb\user')->disableOriginalConstructor()->getMock();
		$this->user->data = array('user_id' => 2, 'username' => 'Administrator', 'user_lang' => 'en', 'is_registered' => true);
		$this->user->timezone = new \DateTimeZone('UTC');
		$this->user->date_format = 'Y-m-d';
		$this->user->lang_name = 'en';
		$this->original = $this->user->data;
		$language = $this->getMockBuilder('\phpbb\language\language')->disableOriginalConstructor()->getMock();
		$this->auth = $this->getMockBuilder('\phpbb\auth\auth')->disableOriginalConstructor()->getMock();
		$this->auth->acl = array('original' => true);
		$this->auth->method('acl')->willReturnCallback(function (&$data) { $this->auth->acl = array('bot' => true); });
		$this->db = $this->getMockBuilder('\phpbb\db\driver\driver_interface')->getMock();
		$log = $this->getMockBuilder('\phpbb\log\log')->disableOriginalConstructor()->getMock();
		$dispatcher = $this->getMockBuilder('\phpbb\event\dispatcher')->disableOriginalConstructor()->getMock();
		$this->driver = new processing_fixture($this->config, $this->config_text, $this->user, $language, $this->auth, $this->db, $log, './', 'php', $dispatcher);
		$this->saved = array(array('url' => 'https://example.com/feed', 'type' => 'rss', 'user_id' => 3,
			'forum_id' => 5, 'timeout' => 3, 'textlimit' => 0, 'append_link' => 1,
			'latest' => array('guid' => '', 'link' => '', 'pubDate' => 0), 'handled' => array()));
		$this->driver->current_state = $this->saved;
	}

	protected function destinations($user_exists = true, $forum_type = FORUM_POST)
	{
		$user = array('user_id' => 3, 'username' => 'Feed bot', 'user_lang' => 'nl', 'user_type' => USER_NORMAL,
			'user_timezone' => 'Europe/Amsterdam', 'user_dateformat' => 'd/m/Y');
		$forum = array('forum_id' => 5, 'forum_name' => 'News', 'forum_type' => $forum_type);
		$rows = $user_exists ? array($user, false, $forum, false) : array(false, $forum, false);
		$this->db->expects($this->exactly(2))->method('sql_query');
		$this->db->method('sql_fetchrow')->willReturnOnConsecutiveCalls(...$rows);
	}

	protected function item($id, $date = 100)
	{
		return array('guid' => $id, 'link' => 'https://example.com/' . $id, 'pubDate' => $date);
	}

	public function test_missing_user_never_posts_as_administrator()
	{
		$this->destinations(false);
		$this->assertSame(0, $this->driver->fetch_items(array($this->item('new')), 0));
		$this->assertSame(array(), $this->driver->posted);
		$this->assertSame($this->original, $this->user->data);
		$this->assertSame($this->saved, $this->driver->current_state);
	}

	public function test_non_posting_forum_is_rejected()
	{
		$this->destinations(true, FORUM_CAT);
		$this->assertSame(0, $this->driver->fetch_items(array($this->item('new')), 0));
		$this->assertSame(array(), $this->driver->posted);
	}

	public function test_timezone_user_and_acl_are_restored()
	{
		$this->destinations();
		$timezone = $this->user->timezone;
		$this->assertSame(1, $this->driver->fetch_items(array($this->item('new')), 0));
		$this->assertSame(3, $this->driver->posted[0]['user_id']);
		$this->assertInstanceOf('\DateTimeZone', $this->driver->posted[0]['timezone']);
		$this->assertSame($timezone, $this->user->timezone);
		$this->assertSame($this->original, $this->user->data);
		$this->assertSame('Y-m-d', $this->user->date_format);
		$this->assertSame('en', $this->user->lang_name);
		$this->assertSame(array('original' => true), $this->auth->acl);
	}

	public function test_equal_dates_and_late_entries_are_not_lost_or_reposted()
	{
		$this->destinations();
		$this->assertSame(1, $this->driver->fetch_items(array($this->item('old')), 0));
		$this->assertSame(2, $this->driver->fetch_items(array($this->item('same-date'), $this->item('old'), $this->item('late', 50)), 0));
		$this->assertSame(0, $this->driver->fetch_items(array($this->item('same-date'), $this->item('old'), $this->item('late', 50)), 0));
		$this->assertCount(3, $this->driver->posted);
	}

	public function test_failed_item_retries_without_reposting_successful_items()
	{
		$this->destinations();
		$this->driver->results = array('/post/1', false);
		$items = array($this->item('new'), $this->item('old'));
		$this->assertSame(1, $this->driver->fetch_items($items, 0));
		$this->assertSame('old', $this->driver->current_state[0]['latest']['guid']);
		$this->assertSame(1, $this->driver->fetch_items($items, 0));
		$this->assertSame(0, $this->driver->fetch_items($items, 0));
	}

	public function test_event_skip_is_consumed_but_not_counted()
	{
		$this->destinations();
		$this->driver->results = array(true);
		$this->assertSame(0, $this->driver->fetch_items(array($this->item('ignored')), 0));
		$this->assertSame(0, $this->driver->fetch_items(array($this->item('ignored')), 0));
		$this->assertCount(1, $this->driver->posted);
	}

	public function test_exception_preserves_progress_and_releases_lock()
	{
		$this->destinations();
		$this->driver->items = array($this->item('new'), $this->item('old'));
		$this->driver->results = array('/post/1', new \RuntimeException('Simulated posting failure'));
		$this->assertSame(1, $this->driver->fetch_all());
		$this->assertTrue($this->driver->lock->released);
		$this->assertSame('old', $this->saved[0]['latest']['guid']);
		$this->assertSame($this->original, $this->user->data);
	}

	public function test_progress_save_failure_still_releases_lock()
	{
		$this->destinations();
		$this->driver->items = array($this->item('new'));
		$this->config_text->method('set')->willThrowException(new \RuntimeException('Simulated storage failure'));
		try
		{
			$this->driver->fetch_all();
			$this->fail('Expected storage failure');
		}
		catch (\RuntimeException $error)
		{
			$this->assertTrue($this->driver->lock->released);
			$this->assertSame($this->original, $this->user->data);
		}
	}

	public function test_progress_does_not_overwrite_acp_edits()
	{
		$this->destinations();
		$this->driver->items = array($this->item('new'));
		$this->driver->on_post = function () { $this->saved[0]['forum_id'] = 8; $this->saved[0]['prefix'] = 'Edited'; };
		$this->driver->fetch_all();
		$this->assertSame(8, $this->saved[0]['forum_id']);
		$this->assertSame('Edited', $this->saved[0]['prefix']);
		$this->assertSame('new', $this->saved[0]['latest']['guid']);
	}

	public function test_legacy_marker_initializes_history_once()
	{
		$this->destinations();
		unset($this->driver->current_state[0]['handled']);
		$this->driver->current_state[0]['latest'] = $this->item('old');
		$this->assertSame(1, $this->driver->fetch_items(array($this->item('new'), $this->item('old'), $this->item('older', 50)), 0));
		$this->assertSame(0, $this->driver->fetch_items(array($this->item('new'), $this->item('old'), $this->item('older', 50)), 0));
	}
}

class processing_fixture extends \ger\feedpostbot\classes\driver
{
	public $posted = array();
	public $results = array();
	public $items = array();
	public $lock;
	public $on_post;

	protected function post_message($item, $source_id)
	{
		$this->posted[] = array('item' => $item, 'user_id' => $this->user->data['user_id'], 'timezone' => $this->user->timezone);
		if ($this->on_post)
		{
			call_user_func($this->on_post);
		}
		$result = $this->results ? array_shift($this->results) : '/post/1';
		if ($result instanceof \Throwable)
		{
			throw $result;
		}
		return $result;
	}

	public function parse_feed($url, $type, $timeout = self::FEED_TIMEOUT_PARSE)
	{
		return $this->items;
	}

	protected function create_lock()
	{
		$this->lock = new processing_lock_fixture();
		return $this->lock;
	}
}

class processing_lock_fixture
{
	public $released = false;
	public function acquire()
	{
		return true;
	}
	public function release()
	{
		$this->released = true;
	}
}
