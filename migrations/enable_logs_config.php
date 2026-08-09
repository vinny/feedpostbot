<?php
/**
 *
 * Feed post bot. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2017, Ger, https://github.com/GerB
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace ger\feedpostbot\migrations;

class enable_logs_config extends \phpbb\db\migration\migration
{
	public function effectively_installed()
	{
		return isset($this->config['feedpostbot_enable_logs']);
	}

	static public function depends_on()
	{
		return array('\ger\feedpostbot\migrations\cron_frequency_config');
	}

	public function update_data()
	{
		return array(
			array('config.add', array('feedpostbot_enable_logs', 0)),
		);
	}
}
