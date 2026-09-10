<?php
/**
 *
 * Feed post bot. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2017, Ger, https://github.com/GerB
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace ger\feedpostbot\tests;

if (!function_exists('\ger\feedpostbot\tests\utf8_htmlspecialchars') && !function_exists('utf8_htmlspecialchars'))
{
	function utf8_htmlspecialchars($value)
	{
		return htmlspecialchars((string) $value, ENT_COMPAT, 'UTF-8');
	}
}

class driver_test extends \phpbb_test_case
{
	/** @var \ger\feedpostbot\classes\driver */
	protected $driver;

	protected $config;
	protected $config_text;
	protected $user;
	protected $language;
	protected $auth;
	protected $db;
	protected $log;
	protected $dispatcher;

	public function setUp(): void
	{
		parent::setUp();

		$this->config = $this->getMockBuilder('\phpbb\config\config')
			->disableOriginalConstructor()
			->getMock();

		$this->config_text = $this->getMockBuilder('\phpbb\config\db_text')
			->disableOriginalConstructor()
			->getMock();

		$this->user = $this->getMockBuilder('\phpbb\user')
			->disableOriginalConstructor()
			->getMock();
		$this->user->data = array(
			'user_id'   => 2,
			'user_lang' => 'en',
			'user_ip'   => '127.0.0.1',
		);

		$this->language = $this->getMockBuilder('\phpbb\language\language')
			->disableOriginalConstructor()
			->getMock();

		$this->auth = $this->getMockBuilder('\phpbb\auth\auth')
			->disableOriginalConstructor()
			->getMock();

		$this->db = $this->getMockBuilder('\phpbb\db\driver\driver_interface')
			->disableOriginalConstructor()
			->getMock();

		$this->log = $this->getMockBuilder('\phpbb\log\log')
			->disableOriginalConstructor()
			->getMock();

		$this->dispatcher = $this->getMockBuilder('\phpbb\event\dispatcher')
			->disableOriginalConstructor()
			->getMock();

		$this->driver = new \ger\feedpostbot\classes\driver(
			$this->config,
			$this->config_text,
			$this->user,
			$this->language,
			$this->auth,
			$this->db,
			$this->log,
			'./',
			'php',
			$this->dispatcher
		);
	}

	public function test_legacy_feed_events_receive_and_apply_item_changes()
	{
		$xml = '<rss version="2.0"><channel><title>Feed</title><item><guid>1</guid><title>Original</title><description>Body</description></item></channel></rss>';
		$feed = $this->driver->get_simplepie_instance('https://example.com/feed', 3, $xml);
		$driver = $this->getMockBuilder('\ger\feedpostbot\classes\driver')
			->setConstructorArgs(array($this->config, $this->config_text, $this->user, $this->language,
				$this->auth, $this->db, $this->log, './', 'php', $this->dispatcher))
			->setMethods(array('get_simplepie_instance'))->getMock();
		$driver->method('get_simplepie_instance')->willReturn($feed);
		$this->dispatcher->expects($this->exactly(6))->method('trigger_event')->willReturnCallback(function ($event, $data) {
			$this->assertArrayHasKey('item', $data);
			$this->assertArrayHasKey('append', $data);
			$this->assertInstanceOf('\SimplePie\Item', $data['item']);
			$data['append']['title'] .= '|' . $event;
			return $data;
		});
		foreach (array('rss', 'atom', 'rdf') as $type)
		{
			$items = $driver->parse_feed('https://example.com/feed', $type);
			$this->assertSame('Original|ger.feedpostbot.parse_item_append|ger.feedpostbot.parse_' . $type . '_append', $items[0]['title']);
		}
	}

	public function test_all_translations_preserve_both_log_arguments()
	{
		foreach (array('en', 'ar', 'nl', 'sl') as $locale)
		{
			$lang = array();
			include __DIR__ . '/../language/' . $locale . '/info_acp_feedpostbot.php';
			$message = sprintf($lang['FPB_LOG_FEED_ERROR'], 'URL_SENTINEL', 'ERROR_SENTINEL');
			$this->assertStringContainsString('URL_SENTINEL', $message);
			$this->assertStringContainsString('ERROR_SENTINEL', $message);
		}
	}

	public function test_error_details_are_escaped_and_cli_actor_is_supported()
	{
		$this->user->data = array();
		$this->language->method('lang')->willReturnArgument(0);
		$this->log->expects($this->once())->method('add')->with('critical', ANONYMOUS,
			$this->user->ip, 'FPB_LOG_FEED_ERROR', $this->anything(),
			array('https://example.com/feed', '&lt;img src=x onerror=alert(1)&gt;'));
		$method = new \ReflectionMethod($this->driver, 'log_feed_error');
		$method->setAccessible(true);
		$method->invoke($this->driver, 'https://example.com/feed', '<img src=x onerror=alert(1)>');
	}

	public function test_character_limiter_short_text()
	{
		$text = 'Short text';
		$result = $this->driver->character_limiter($text, 300);
		$this->assertEquals('Short text', $result);
	}

	public function test_character_limiter_null_guard()
	{
		$result = $this->driver->character_limiter(null, 300);
		$this->assertEquals('', $result);
	}

	public function test_character_limiter_truncation()
	{
		$text = 'This is a long feed description that needs to be truncated to test the character limiter functionality.';
		$result = $this->driver->character_limiter($text, 25);
		$this->assertStringEndsWith('...', $result);
	}

	public function test_closetags_balanced()
	{
		$html = '<p><strong>Test</strong></p>';
		$result = $this->driver->closetags($html);
		$this->assertEquals($html, $result);
	}

	public function test_closetags_unclosed()
	{
		$html = '<p><strong>Test';
		$result = $this->driver->closetags($html);
		$this->assertStringContainsString('</strong>', $result);
		$this->assertStringContainsString('</p>', $result);
	}

	public function test_closetags_empty()
	{
		$result = $this->driver->closetags('');
		$this->assertEquals('', $result);
	}

	public function test_html2bbcode_formatting()
	{
		$html = '<p>Hello <b>World</b></p><br><a href="https://example.com">Link</a>';
		$result = $this->driver->html2bbcode($html);
		$this->assertStringContainsString('[b]World[/b]', $result);
		$this->assertStringContainsString('[url=https://example.com]Link[/url]', $result);
	}

	public function test_html2bbcode_empty()
	{
		$result = $this->driver->html2bbcode(null);
		$this->assertEquals('', $result);
	}

	public function test_detect_feed_type_rss()
	{
		$rss_xml = '<?xml version="1.0"?><rss version="2.0"><channel><title>Test Feed</title></channel></rss>';
		$type = $this->driver->detect_feed_type('https://example.com/feed.xml', $rss_xml);
		$this->assertEquals('rss', $type);
	}

	public function test_detect_feed_type_atom()
	{
		$atom_xml = '<?xml version="1.0"?><feed xmlns="http://www.w3.org/2005/Atom"><title>Atom Feed</title></feed>';
		$type = $this->driver->detect_feed_type('https://example.com/atom.xml', $atom_xml);
		$this->assertEquals('atom', $type);
	}

	public function test_detect_feed_type_rdf()
	{
		$rdf_xml = '<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#" xmlns="http://purl.org/rss/1.0/"><channel rdf:about="https://example.com"><title>RDF Feed</title></channel></rdf:RDF>';
		$type = $this->driver->detect_feed_type('https://example.com/rdf.xml', $rdf_xml);
		$this->assertEquals('rdf', $type);
	}

	public function test_get_content_scheme_blocking()
	{
		// Local file schemes must be blocked
		$result = $this->driver->detect_feed_type('file:///etc/passwd');
		$this->assertFalse($result);

		// FTP schemes must be blocked
		$result = $this->driver->detect_feed_type('ftp://example.com/feed.xml');
		$this->assertFalse($result);
	}

	public function test_clean_title_xss_img_onerror()
	{
		$dirty_title = '<img src=x onerror=alert(1)>';
		$clean_title = $this->driver->clean_title($dirty_title);
		$this->assertStringNotContainsString('<img', $clean_title);
		$this->assertStringNotContainsString('onerror', $clean_title);
		$this->assertEquals('', $clean_title);
	}

	public function test_clean_title_xss_script()
	{
		$dirty_title = '<script>alert("xss")</script>Important Headline';
		$clean_title = $this->driver->clean_title($dirty_title);
		$this->assertStringNotContainsString('<script', $clean_title);
		$this->assertEquals('Important Headline', $clean_title);
	}

	public function test_clean_title_special_chars_escaped()
	{
		$title = 'Tom & Jerry > Mickey Mouse';
		$clean_title = $this->driver->clean_title($title);
		$this->assertEquals('Tom &amp; Jerry &gt; Mickey Mouse', $clean_title);
	}

	public function test_clean_title_emojis_stripped()
	{
		$title = 'Great News! 🔥🚀';
		$clean_title = $this->driver->clean_title($title);
		$this->assertEquals('Great News!', $clean_title);
	}
}
