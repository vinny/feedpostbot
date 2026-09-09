<?php
/**
 * Feed post bot. An extension for the phpBB Forum Software package.
 * @copyright (c) 2017, Ger, https://github.com/GerB
 * @license GNU General Public License, version 2 (GPL-2.0)
 */

namespace ger\feedpostbot\tests;

class lock_test extends \phpbb_test_case
{
	public function test_abandoned_lock_expires_and_is_released_by_its_owner()
	{
		$config = new \phpbb\config\config(array('feedpostbot_locked' => time() - 3601));
		$db = $this->getMockBuilder('\phpbb\db\driver\driver_interface')->getMock();
		$lock = new \phpbb\lock\db('feedpostbot_locked', $config, $db);
		$this->assertTrue($lock->acquire());
		$contender = new \phpbb\lock\db('feedpostbot_locked', $config, $db);
		$this->assertFalse($contender->acquire());
		$lock->release();
		$this->assertSame('0', $config['feedpostbot_locked']);
	}

	public function test_old_worker_cannot_release_new_owners_lock()
	{
		$config = new \phpbb\config\config(array('feedpostbot_locked' => 0));
		$db = $this->getMockBuilder('\phpbb\db\driver\driver_interface')->getMock();
		$lock = new \phpbb\lock\db('feedpostbot_locked', $config, $db);
		$this->assertTrue($lock->acquire());
		$config['feedpostbot_locked'] = time() . ' replacement-owner';
		$lock->release();
		$this->assertStringContainsString('replacement-owner', $config['feedpostbot_locked']);
	}
}
