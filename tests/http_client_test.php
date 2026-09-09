<?php
/**
 * Feed post bot. An extension for the phpBB Forum Software package.
 * @copyright (c) 2017, Ger, https://github.com/GerB
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace ger\feedpostbot\tests;

class http_client_test extends \phpbb_test_case
{
	public function test_rejects_private_and_alternative_addresses()
	{
		foreach (array('http://[::1]/feed', 'http://[fd00::1]/feed', 'http://[::ffff:127.0.0.1]/feed',
			'http://127.1/feed', 'http://2130706433/feed', 'http://0x7f000001/feed', 'http://0177.0.0.1/feed',
			'http://100.64.0.1/feed', 'http://192.0.2.1/feed', 'http://198.18.0.1/feed', 'http://224.0.0.1/feed',
			'http://localhost/feed', 'http://user:password@example.com/feed', 'file:///etc/passwd',
			"https://example.com/\r\nHost: localhost") as $url)
		{
			$this->assertFalse(\ger\feedpostbot\classes\http_client::valid_url($url), $url);
		}
		$this->assertTrue(\ger\feedpostbot\classes\http_client::valid_url('https://example.com/feed?a=1&b=2'));
		$this->assertTrue(\ger\feedpostbot\classes\http_client::public_ip('8.8.8.8'));
		$this->assertTrue(\ger\feedpostbot\classes\http_client::public_ip('2606:4700:4700::1111'));
	}

	public function test_mixed_dns_answers_are_rejected_before_connecting()
	{
		$client = new http_client_fixture();
		$client->addresses = array('8.8.8.8', '127.0.0.1');
		try
		{
			$client->get('https://example.com/feed', 3);
			$this->fail('Unsafe DNS answers were accepted');
		}
		catch (\RuntimeException $error)
		{
			$this->assertSame('FPB_HTTP_UNSAFE', $error->getMessage());
			$this->assertCount(0, $client->requests);
		}
	}

	public function test_redirect_to_internal_address_is_rejected()
	{
		$client = new http_client_fixture();
		$client->responses = array(array('status' => 302, 'location' => 'http://[::1]/private', 'body' => ''));
		try
		{
			$client->get('https://example.com/feed', 3);
			$this->fail('Unsafe redirect was accepted');
		}
		catch (\RuntimeException $error)
		{
			$this->assertSame('FPB_HTTP_UNSAFE', $error->getMessage());
			$this->assertCount(1, $client->requests);
		}
	}

	public function test_relative_redirect_keeps_query_and_pins_each_connection()
	{
		$client = new http_client_fixture();
		$client->responses = array(array('status' => 302, 'location' => '/news?a=1&b=2', 'body' => ''),
			array('status' => 200, 'location' => '', 'body' => '<rss/>'));
		$this->assertSame('<rss/>', $client->get('https://example.com/feed', 3));
		$this->assertSame('https://example.com/news?a=1&b=2', $client->get_final_url());
		$this->assertSame('8.8.8.8', $client->requests[0][1]);
		$this->assertSame('8.8.8.8', $client->requests[1][1]);
		$this->assertSame(2, $client->resolutions);
		$this->assertLessThanOrEqual($client->requests[0][2], $client->requests[1][2]);
	}

	public function test_redirect_chain_is_bounded()
	{
		$client = new http_client_fixture();
		$client->responses = array_fill(0, 6, array('status' => 302, 'location' => '/again', 'body' => ''));
		try
		{
			$client->get('https://example.com/feed', 3);
			$this->fail('Redirect loop was accepted');
		}
		catch (\RuntimeException $error)
		{
			$this->assertCount(6, $client->requests);
		}
	}

	public function test_acp_duplicate_zero_and_final_batch()
	{
		$module = new \ger\feedpostbot\acp\main_module();
		$method = new \ReflectionMethod($module, 'validate_feed');
		$method->setAccessible(true);
		$state = array(array('url' => 'https://example.com/feed'));
		$this->assertFalse($method->invoke($module, $state, 'https://example.com/feed'));
		$this->assertTrue($method->invoke($module, $state, 'https://example.com/feed', 0));
		$this->assertFalse($method->invoke($module, $state, 'https://example.com/feed', 1));
	}

	public function test_numeric_boundaries()
	{
		foreach (array('0', '-1', '61', 'abc', '1.5') as $value)
		{
			$this->assertFalse(\ger\feedpostbot\classes\driver::valid_number($value, 1, 60));
		}
		$this->assertTrue(\ger\feedpostbot\classes\driver::valid_number('1', 1, 60));
		$this->assertTrue(\ger\feedpostbot\classes\driver::valid_number('60', 1, 60));
	}

	public function test_request_entities_and_relative_feed_links()
	{
		$driver = (new \ReflectionClass(http_driver_fixture::class))->newInstanceWithoutConstructor();
		$driver->client = new http_client_fixture();
		$driver->client->responses = array(array('status' => 200, 'location' => '',
			'body' => '<rss version="2.0"><channel><title>Test</title><item><title>Entry</title><link>/entry</link></item></channel></rss>'));
		$feed = $driver->get_simplepie_instance('https://example.com/feed?a=1&amp;b=2');
		$this->assertSame('https://example.com/feed?a=1&b=2', $driver->client->requests[0][0]);
		$this->assertSame('https://example.com/entry', $feed->get_items()[0]->get_permalink());
		$this->assertCount(1, $driver->client->requests);
	}
}

class http_driver_fixture extends \ger\feedpostbot\classes\driver
{
	public $client;
	protected function create_http_client()
	{
		return $this->client;
	}
}

class http_client_fixture extends \ger\feedpostbot\classes\http_client
{
	public $addresses = array('8.8.8.8');
	public $responses = array();
	public $requests = array();
	public $resolutions = 0;

	protected function resolve($host)
	{
		$this->resolutions++;
		return $this->addresses;
	}

	protected function request($url, $ip, $timeout)
	{
		$this->requests[] = array($url, $ip, $timeout);
		return array_shift($this->responses);
	}
}
