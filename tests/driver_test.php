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

if (!function_exists('utf8_htmlspecialchars'))
{
	function utf8_htmlspecialchars($value)
	{
		return htmlspecialchars((string) $value, ENT_COMPAT, 'UTF-8');
	}
}

class testable_driver extends \ger\feedpostbot\classes\driver
{
	protected $mock_content = null;

	public function set_mock_content($content)
	{
		$this->mock_content = $content;
	}

	protected function get_content($url, $timeout = self::FEED_TIMEOUT_DEFAULT, $useragent_override = false, $force_file_get_contents = false)
	{
		if ($this->mock_content !== null)
		{
			return $this->mock_content;
		}
		return parent::get_content($url, $timeout, $useragent_override, $force_file_get_contents);
	}
}

class driver_test extends \phpbb_test_case
{
	/** @var testable_driver */
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

		$this->driver = new testable_driver(
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
		$this->driver->set_mock_content($rss_xml);
		$type = $this->driver->detect_feed_type('https://example.com/feed.xml');
		$this->assertEquals('rss', $type);
	}

	public function test_detect_feed_type_atom()
	{
		$atom_xml = '<?xml version="1.0"?><feed xmlns="http://www.w3.org/2005/Atom"><title>Atom Feed</title></feed>';
		$this->driver->set_mock_content($atom_xml);
		$type = $this->driver->detect_feed_type('https://example.com/atom.xml');
		$this->assertEquals('atom', $type);
	}

	public function test_detect_feed_type_rdf()
	{
		$rdf_xml = '<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#"><channel></channel></rdf:RDF>';
		$this->driver->set_mock_content($rdf_xml);
		$type = $this->driver->detect_feed_type('https://example.com/rdf.xml');
		$this->assertEquals('rdf', $type);
	}

	public function test_get_content_scheme_blocking()
	{
		$this->driver->set_mock_content(null);
		// Local file schemes must be blocked by get_content
		$result = $this->driver->detect_feed_type('file:///etc/passwd');
		$this->assertFalse($result);

		// FTP schemes must be blocked by get_content
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

