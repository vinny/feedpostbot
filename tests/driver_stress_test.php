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

class driver_stress_test extends \phpbb_test_case
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

	/**
	 * Test SSRF and dangerous URI schemes blocking
	 */
	public function test_stress_ssrf_and_scheme_blocking()
	{
		$dangerous_urls = array(
			'file:///etc/passwd',
			'file:///C:/Windows/win.ini',
			'ftp://anonymous:secret@example.com/evil.xml',
			'gopher://127.0.0.1:70/',
			'dict://127.0.0.1:11211/',
			'ldap://127.0.0.1:389/',
			'javascript:alert(document.domain)',
			'data:text/xml;base64,PHJzcz48L3Jzcz4=',
			'php://filter/read=convert.base64-encode/resource=index.php',
			'phar://evil.phar/test.xml',
		);

		foreach ($dangerous_urls as $url)
		{
			$instance = $this->driver->get_simplepie_instance($url);
			$this->assertNull($instance, "Dangerous URL {$url} should return null from get_simplepie_instance");

			$detected = $this->driver->detect_feed_type($url);
			$this->assertFalse($detected, "Dangerous URL {$url} should return false from detect_feed_type");
		}
	}

	/**
	 * Test stored XSS sanitization stress on clean_title()
	 */
	public function test_stress_xss_clean_title()
	{
		$payloads = array(
			'<script>alert("XSS")</script>Title' => 'Title',
			'<SCRIPT SRC=http://evil.com/xss.js></SCRIPT>Normal' => 'Normal',
			'<img src=x onerror=alert(1)>Breaking News' => 'Breaking News',
			'<a href="javascript:alert(1)">Click Me</a>' => 'Click Me',
			'<svg onload=alert(1)>SVG Title</svg>' => 'SVG Title',
			'<style>body{background:red}</style>Clean Style' => 'Clean Style',
			'Emoji 😀 Test 🚀 Post 🎉' => 'Emoji Test Post',
			'Multiple    spaces    and   tabs	here' => 'Multiple spaces and tabs here',
			'Normal & "quotes" <tag>' => 'Normal &amp; &quot;quotes&quot;',
		);

		foreach ($payloads as $input => $expected)
		{
			$sanitized = $this->driver->clean_title($input);
			$this->assertEquals($expected, $sanitized, "Failed sanitizing XSS payload: {$input}");
			$this->assertStringNotContainsString('<script', strtolower($sanitized));
			$this->assertStringNotContainsString('onerror', strtolower($sanitized));
			$this->assertStringNotContainsString('<img', strtolower($sanitized));
		}
	}

	/**
	 * Test corrupt, broken and malformed XML feeds with SimplePie
	 */
	public function test_stress_malformed_xml_resilience()
	{
		// Unclosed HTML tags inside XML description (recoverable by SimplePie)
		$recoverable_xml = '<?xml version="1.0" encoding="UTF-8"?>
		<rss version="2.0">
			<channel>
				<title>Recoverable Feed</title>
				<link>https://example.com</link>
				<item>
					<title>News Item &amp; More</title>
					<link>https://example.com/item-1</link>
					<description><![CDATA[Broken <img src=x onerror=alert(1)> text with unclosed tags <p><div>]]></description>
					<pubDate>Mon, 01 Jan 2024 12:00:00 GMT</pubDate>
				</item>
			</channel>
		</rss>';

		$type = $this->driver->detect_feed_type('https://example.com/feed.xml', $recoverable_xml);
		$this->assertEquals('rss', $type);

		$feed = $this->driver->get_simplepie_instance('https://example.com/feed.xml', 5, $recoverable_xml);
		$this->assertNotNull($feed);
		$items = $feed->get_items();
		$this->assertCount(1, $items);

		// Completely invalid/corrupt XML structure should be safely caught without throwing Fatal Errors
		$corrupted_xml = '<rss><channel><title>Broken<item><title><![CDATA[Unclosed';
		$corrupted_type = $this->driver->detect_feed_type('https://example.com/feed.xml', $corrupted_xml);
		$this->assertFalse($corrupted_type);
	}

	/**
	 * Test empty and zero-byte feeds
	 */
	public function test_stress_empty_feed_handling()
	{
		$empty_xml = '';
		$type = @$this->driver->detect_feed_type('https://example.com/feed.xml', $empty_xml);
		$this->assertFalse($type);

		$whitespace_xml = "   \n\t  ";
		$type_ws = @$this->driver->detect_feed_type('https://example.com/feed.xml', $whitespace_xml);
		$this->assertFalse($type_ws);
	}

	/**
	 * Test large volume feed with 500 items
	 */
	public function test_stress_high_volume_items()
	{
		$item_nodes = '';
		for ($i = 1; $i <= 500; $i++)
		{
			$item_nodes .= "
			<item>
				<title>Article #{$i} &amp; News</title>
				<link>https://example.com/article-{$i}</link>
				<guid>guid-{$i}</guid>
				<description><![CDATA[This is the description for article {$i} with some <b>bold</b> and <a href=\"https://example.com/more\">link</a>.]]></description>
				<pubDate>Mon, 01 Jan 2024 12:00:00 GMT</pubDate>
			</item>";
		}

		$large_xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?><rss version=\"2.0\"><channel><title>Big Feed</title>{$item_nodes}</channel></rss>";

		$feed = $this->driver->get_simplepie_instance('https://example.com/feed.xml', 10, $large_xml);
		$this->assertNotNull($feed);
		$items = $feed->get_items();
		$this->assertCount(500, $items);

		$first_item = $items[0];
		$this->assertStringContainsString('Article #', $this->driver->prop_to_string($first_item->get_title()));
	}

	/**
	 * Test HTML to BBCode transformation and tag balancing under stress
	 */
	public function test_stress_html2bbcode_and_tag_balancing()
	{
		$complex_html = '<div class="content"><p>Paragraph with <b>bold <i>italic</i></b> text.</p><ul><li>Item 1</li><li>Item 2 with <a href="https://phpbb.com">link</a></li></ul><img src="https://example.com/image.png" alt="img" /><iframe src="https://example.com/video"></iframe></div>';
		$bbcode = $this->driver->html2bbcode($complex_html);

		$this->assertStringContainsString('[b]bold [i]italic[/i][/b]', $bbcode);
		$this->assertStringContainsString('[list]', $bbcode);
		$this->assertStringContainsString('[*]Item 1', $bbcode);
		$this->assertStringContainsString('[url=https://phpbb.com]link[/url]', $bbcode);
		$this->assertStringContainsString('[img]https://example.com/image.png[/img]', $bbcode);
		$this->assertStringNotContainsString('<div', $bbcode);
		$this->assertStringNotContainsString('<iframe', $bbcode);

		// Unclosed HTML tags balanced by closetags
		$unclosed_html = '<div><p><span><b>Unclosed deep tags';
		$balanced = $this->driver->closetags($unclosed_html);
		$this->assertStringContainsString('</b></span></p></div>', $balanced);
	}
}
